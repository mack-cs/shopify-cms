<?php

namespace App\Services\Shopify;

use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyStackInventoryMovement;
use App\Models\ShopifyStackInventoryReservation;
use App\Models\ShopifyStackOrderEvent;
use App\Models\Variant;
use Illuminate\Support\Facades\Cache;

final class StackOrderReservationService
{
    public function __construct(
        private readonly ShopifyInventoryAdjustmentService $inventory,
        private readonly StackInventoryMovementService $movements,
    ) {}

    /** @return array{stack_lines:int,reserved:int,released:int} */
    public function process(ShopifyStackOrderEvent $event): array
    {
        $lock = Cache::lock('stack-order-inventory:'.$event->shopify_order_id, 300);
        if (! $lock->get()) {
            throw new \RuntimeException('Stack order inventory is already being processed; retrying.');
        }

        try {
            return $this->processLocked($event);
        } finally {
            $lock->release();
        }
    }

    /** @return array{stack_lines:int,reserved:int,released:int} */
    private function processLocked(ShopifyStackOrderEvent $event): array
    {
        if ($event->status === ShopifyStackOrderEvent::STATUS_COMPLETED) {
            return ['stack_lines' => 0, 'reserved' => 0, 'released' => 0];
        }
        if ($event->shopify_updated_at !== null && ShopifyStackOrderEvent::query()
            ->where('shopify_order_id', $event->shopify_order_id)
            ->where('id', '!=', $event->id)
            ->where('status', ShopifyStackOrderEvent::STATUS_COMPLETED)
            ->where('shopify_updated_at', '>', $event->shopify_updated_at)
            ->exists()) {
            $event->forceFill([
                'status' => ShopifyStackOrderEvent::STATUS_COMPLETED,
                'processed_at' => now(),
                'error_message' => 'Ignored because a newer Shopify order event was already processed.',
            ])->save();

            return ['stack_lines' => 0, 'reserved' => 0, 'released' => 0];
        }

        $event->forceFill([
            'status' => ShopifyStackOrderEvent::STATUS_PROCESSING,
            'attempts' => (int) $event->attempts + 1,
            'error_message' => null,
        ])->save();

        $summary = ['stack_lines' => 0, 'reserved' => 0, 'released' => 0];
        try {
            if ($event->topic === 'orders/cancelled') {
                foreach (ShopifyStackInventoryReservation::query()
                    ->where('shopify_order_id', $event->shopify_order_id)->get() as $reservation) {
                    $this->releaseRemaining($reservation, 'cancel:'.$event->shopify_order_id);
                    $summary['released']++;
                }
            } else {
                foreach ((array) data_get($event->payload, 'line_items', []) as $lineItem) {
                    if (! is_array($lineItem)) {
                        continue;
                    }
                    $summary = $this->reconcileLine($event, $lineItem, $summary);
                }
            }
        } catch (\Throwable $exception) {
            $event->forceFill([
                'status' => ShopifyStackOrderEvent::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ])->save();
            throw $exception;
        }

        $event->forceFill([
            'status' => ShopifyStackOrderEvent::STATUS_COMPLETED,
            'processed_at' => now(),
            'error_message' => null,
        ])->save();

        return $summary;
    }

    private function reconcileLine(ShopifyStackOrderEvent $event, array $lineItem, array $summary): array
    {
        $lineId = $this->gid('LineItem', $lineItem['admin_graphql_api_id'] ?? $lineItem['id'] ?? null);
        if ($lineId === '') {
            return $summary;
        }

        $reservations = ShopifyStackInventoryReservation::query()
            ->where('shopify_order_id', $event->shopify_order_id)
            ->where('shopify_order_line_item_id', $lineId)
            ->get();
        $stackVariant = $this->findVariant($lineItem['variant_id'] ?? null);
        $draft = $stackVariant instanceof Variant ? $this->findStackDraft($stackVariant, $lineItem) : null;
        if ($reservations->isEmpty() && ! $draft instanceof NewProductDraft) {
            return $summary;
        }

        $summary['stack_lines']++;
        $desiredStackQuantity = max(0, (int) ($lineItem['current_quantity'] ?? $lineItem['quantity'] ?? 0));
        if ($reservations->isEmpty()) {
            foreach ($this->componentConfiguration($draft) as $componentProductId => $quantityPerStack) {
                $reservations->push($this->createReservation(
                    $event, $lineId, $lineItem, $stackVariant, $componentProductId,
                    $quantityPerStack, $desiredStackQuantity,
                ));
            }
        }

        foreach ($reservations as $reservation) {
            $required = $desiredStackQuantity * (int) $reservation->component_quantity_per_stack;
            $reservation->forceFill([
                'stack_quantity_ordered' => $desiredStackQuantity,
                'total_component_quantity_required' => $required,
            ])->save();

            $targetRemaining = max(0, $required - (int) $reservation->consumed_quantity);
            $remaining = $reservation->remainingReserved();
            if ($targetRemaining > $remaining) {
                $quantity = $targetRemaining - $remaining;
                $this->movements->execute(
                    $reservation,
                    ShopifyStackInventoryMovement::ACTION_RESERVE,
                    $quantity,
                    "order-target:{$event->shopify_order_id}:{$lineId}:{$required}:{$remaining}",
                );
                $summary['reserved']++;
            } elseif ($targetRemaining < $remaining) {
                $quantity = $remaining - $targetRemaining;
                $this->movements->execute(
                    $reservation,
                    ShopifyStackInventoryMovement::ACTION_RELEASE,
                    $quantity,
                    "order-target:{$event->shopify_order_id}:{$lineId}:{$required}:{$remaining}",
                );
                $summary['released']++;
            }
        }

        return $summary;
    }

    private function createReservation(
        ShopifyStackOrderEvent $event,
        string $lineId,
        array $lineItem,
        Variant $stackVariant,
        int $componentProductId,
        int $quantityPerStack,
        int $stackQuantity,
    ): ShopifyStackInventoryReservation {
        $component = Product::query()->with('variants')->find($componentProductId);
        if (! $component instanceof Product) {
            throw new \RuntimeException("Stack component product {$componentProductId} could not be found.");
        }
        $componentVariants = $component->variants
            ->filter(fn (Variant $variant): bool => trim((string) $variant->shopify_inventory_item_id) !== '')
            ->values();
        if ($componentVariants->count() !== 1) {
            throw new \RuntimeException("Stack component product {$componentProductId} must have exactly one inventory-tracked variant.");
        }
        /** @var Variant $componentVariant */
        $componentVariant = $componentVariants->first();
        $locationId = $this->gid('Location', $this->inventory->resolveLocationId($componentVariant));
        $ledgerUri = 'leighavenue-cms://stack-reservations/'.rawurlencode($event->shopify_order_id)
            .'/'.rawurlencode($lineId).'/'.$componentProductId;

        return ShopifyStackInventoryReservation::query()->firstOrCreate(
            [
                'shopify_order_id' => $event->shopify_order_id,
                'shopify_order_line_item_id' => $lineId,
                'configured_component_product_id' => $componentProductId,
            ],
            [
                'shopify_order_name' => $event->shopify_order_name,
                'stack_product_id' => $stackVariant->product_id,
                'stack_variant_id' => $stackVariant->id,
                'shopify_stack_product_id' => $stackVariant->product?->shopify_id,
                'shopify_stack_variant_id' => $stackVariant->shopify_id,
                'stack_sku' => trim((string) ($stackVariant->sku ?: ($lineItem['sku'] ?? ''))) ?: null,
                'stack_title' => $stackVariant->product?->title ?? ($lineItem['title'] ?? null),
                'stack_quantity_ordered' => $stackQuantity,
                'component_product_id' => $component->id,
                'component_variant_id' => $componentVariant->id,
                'shopify_component_product_id' => $component->shopify_id,
                'shopify_component_variant_id' => $componentVariant->shopify_id,
                'shopify_inventory_item_id' => $componentVariant->shopify_inventory_item_id,
                'component_sku' => $componentVariant->sku,
                'component_title' => $component->title,
                'component_quantity_per_stack' => $quantityPerStack,
                'total_component_quantity_required' => $stackQuantity * $quantityPerStack,
                'reserved_quantity' => max(0, (int) ($lineItem['_stack_baseline_fulfilled_quantity'] ?? 0)) * $quantityPerStack,
                'consumed_quantity' => max(0, (int) ($lineItem['_stack_baseline_fulfilled_quantity'] ?? 0)) * $quantityPerStack,
                'shopify_location_id' => $locationId,
                'ledger_document_uri' => $ledgerUri,
                'status' => ShopifyStackInventoryReservation::STATUS_PENDING_PROCESSING,
            ],
        );
    }

    private function releaseRemaining(ShopifyStackInventoryReservation $reservation, string $eventKey): void
    {
        $remaining = $reservation->remainingReserved();
        if ($remaining <= 0) {
            return;
        }
        $this->movements->execute(
            $reservation,
            ShopifyStackInventoryMovement::ACTION_RELEASE,
            $remaining,
            $eventKey.':remaining:'.$remaining,
        );
    }

    private function findVariant(mixed $shopifyVariantId): ?Variant
    {
        $id = trim((string) ($shopifyVariantId ?? ''));
        if ($id === '') {
            return null;
        }

        return Variant::query()->with('product')->whereIn('shopify_id', [$id, $this->gid('ProductVariant', $id)])->first();
    }

    private function findStackDraft(Variant $variant, array $lineItem): ?NewProductDraft
    {
        $product = $variant->product;
        $shopifyId = trim((string) ($product?->shopify_id ?? ''));
        $handle = trim((string) ($product?->handle ?? ''));
        $sku = trim((string) ($variant->sku ?: ($lineItem['sku'] ?? '')));

        return NewProductDraft::query()
            ->where(function ($query) use ($shopifyId, $handle, $sku): void {
                $query->where('shopify_id', $shopifyId);
                if ($handle !== '') {
                    $query->orWhere('handle', $handle);
                }
                if ($sku !== '') {
                    $query->orWhere('sku', $sku);
                }
            })
            ->whereNotNull('bundle_product_ids')
            ->first();
    }

    /** @return array<int, int> */
    private function componentConfiguration(NewProductDraft $draft): array
    {
        $quantities = collect((array) $draft->bundle_component_quantities)->mapWithKeys(function (mixed $row): array {
            $productId = is_array($row) ? (int) ($row['product_id'] ?? 0) : 0;

            return $productId > 0 ? [$productId => max(1, (int) ($row['quantity'] ?? 1))] : [];
        });
        $configured = [];
        foreach ((array) $draft->bundle_product_ids as $productId) {
            if ((int) $productId > 0) {
                $configured[(int) $productId] = (int) ($quantities[(int) $productId] ?? 1);
            }
        }

        return $configured;
    }

    private function gid(string $type, mixed $id): string
    {
        $id = trim((string) ($id ?? ''));
        if ($id === '') {
            return '';
        }

        return str_starts_with($id, 'gid://shopify/') ? $id : "gid://shopify/{$type}/{$id}";
    }
}
