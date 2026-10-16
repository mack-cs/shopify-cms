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
        private readonly StackOrderLineMapper $lines,
        private readonly StackOrderFulfillmentInspector $fulfillment,
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
        $skipNote = null;
        try {
            $live = $this->fulfillment->liveOrder($event->shopify_order_id);
            if (! $live['known']) {
                throw new \RuntimeException('Live Shopify order status could not be confirmed; no component stock was reserved.');
            }
            if ($live['fulfilled'] && ! $live['cancelled']) {
                $skipNote = 'Skipped reservation because live Shopify shows this order as fulfilled.';
            } elseif ($event->topic === 'orders/cancelled' || $live['cancelled']) {
                $summary = $this->releaseAll($event->shopify_order_id, 'cancel:'.$event->shopify_order_id, $summary);
            } else {
                foreach ((array) data_get($event->payload, 'line_items', []) as $lineItem) {
                    if (! is_array($lineItem)) {
                        continue;
                    }
                    $summary = $this->reconcileLine($event, $lineItem, $summary, $live);
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
            'error_message' => $skipNote,
        ])->save();

        return $summary;
    }

    /**
     * @param iterable<int, ShopifyStackInventoryReservation|int> $reservations
     * @return array{cancelled:int,skipped:int,failed:int,errors:array<int,string>}
     */
    public function cancelManually(iterable $reservations): array
    {
        $summary = ['cancelled' => 0, 'skipped' => 0, 'failed' => 0, 'errors' => []];
        foreach ($reservations as $reservation) {
            $record = $reservation instanceof ShopifyStackInventoryReservation
                ? $reservation
                : ShopifyStackInventoryReservation::query()->find($reservation);
            if (! $record instanceof ShopifyStackInventoryReservation) {
                $summary['skipped']++;

                continue;
            }
            try {
                if ($this->cancelOne($record)) {
                    $summary['cancelled']++;
                } else {
                    $summary['skipped']++;
                }
            } catch (\Throwable $exception) {
                $summary['failed']++;
                $summary['errors'][] = trim((string) ($record->shopify_order_name ?: $record->shopify_order_id))
                    .' '.$record->component_sku.': '.$exception->getMessage();
            }
        }

        return $summary;
    }

    private function cancelOne(ShopifyStackInventoryReservation $reservation): bool
    {
        $lock = Cache::lock('stack-order-inventory:'.$reservation->shopify_order_id, 300);
        if (! $lock->get()) {
            throw new \RuntimeException('Stack order inventory is already being processed; retrying.');
        }

        try {
            $locked = ShopifyStackInventoryReservation::query()->findOrFail($reservation->id);
            if ($locked->status === ShopifyStackInventoryReservation::STATUS_RELEASED
                && $locked->remainingReserved() <= 0) {
                return false;
            }
            if ($locked->status === ShopifyStackInventoryReservation::STATUS_COMPLETED
                && $locked->remainingReserved() <= 0) {
                return false;
            }
            if ($locked->remainingReserved() <= 0) {
                $locked->forceFill([
                    'status' => ShopifyStackInventoryReservation::STATUS_RELEASED,
                    'released_at' => $locked->released_at ?? now(),
                    'error_message' => 'Manually cancelled by a super admin.',
                ])->save();

                return true;
            }

            $locked->loadMissing('componentVariant');
            $variant = $locked->componentVariant;
            if (! $variant instanceof Variant) {
                throw new \RuntimeException('The snapshotted component variant no longer exists.');
            }
            $remaining = $locked->remainingReserved();
            $held = $this->inventory->reservedQuantity($variant, $locked->shopify_location_id);
            $move = min($remaining, max(0, $held));
            if ($move > 0) {
                $this->movements->execute(
                    $locked,
                    ShopifyStackInventoryMovement::ACTION_RELEASE,
                    $move,
                    'manual-cancel:'.$locked->id,
                );
            }
            $locked = $locked->fresh() ?? $locked;
            $unmoved = $locked->remainingReserved();
            if ($unmoved > 0) {
                $locked->released_quantity = (int) $locked->released_quantity + $unmoved;
            }
            $locked->released_at = $locked->released_at ?? now();
            $locked->refreshLedgerStatus();
            if ($locked->remainingReserved() <= 0) {
                $locked->status = ShopifyStackInventoryReservation::STATUS_RELEASED;
            }
            $locked->error_message = $unmoved > 0
                ? 'Manually closed. Live Shopify reserved stock did not cover this reservation, so no extra quantity was added.'
                : 'Manually cancelled by a super admin.';
            $locked->save();

            return true;
        } finally {
            $lock->release();
        }
    }

    /**
     * @param  array{lines:array<string, array{current:int, unfulfilled:?int}>}  $live
     * @param  array{stack_lines:int,reserved:int,released:int}  $summary
     * @return array{stack_lines:int,reserved:int,released:int}
     */
    private function reconcileLine(ShopifyStackOrderEvent $event, array $lineItem, array $summary, array $live): array
    {
        $lineId = $this->gid('LineItem', $lineItem['admin_graphql_api_id'] ?? $lineItem['id'] ?? null);
        if ($lineId === '') {
            return $summary;
        }

        $reservations = ShopifyStackInventoryReservation::query()
            ->where('shopify_order_id', $event->shopify_order_id)
            ->where('shopify_order_line_item_id', $lineId)
            ->get();
        $liveLine = $live['lines'][$lineId] ?? null;
        if (! is_array($liveLine) || $liveLine['unfulfilled'] === null) {
            return $summary;
        }

        $stackVariant = $this->findVariant($lineItem['variant_id'] ?? null);
        $draft = $stackVariant instanceof Variant ? $this->findStackDraft($stackVariant, $lineItem) : null;
        if ($reservations->isEmpty() && ! $draft instanceof NewProductDraft) {
            return $summary;
        }

        $currentStack = max(0, (int) $liveLine['current']);
        $unfulfilledStack = max(0, min($currentStack, (int) $liveLine['unfulfilled']));
        if ($reservations->isEmpty() && $unfulfilledStack === 0) {
            return $summary;
        }

        $summary['stack_lines']++;
        $orderCreatedAt = $this->lines->orderCreatedAt((array) $event->payload);
        $fulfilledBaseline = max(0, $currentStack - $unfulfilledStack);
        if ($reservations->isEmpty()) {
            foreach ($this->componentConfiguration($draft) as $componentProductId => $quantityPerStack) {
                $reservations->push($this->createReservation(
                    $event, $lineId, $lineItem, $stackVariant, $componentProductId,
                    $quantityPerStack, $currentStack, $fulfilledBaseline, $orderCreatedAt,
                ));
            }
        }

        foreach ($reservations as $reservation) {
            if ($reservation->status === ShopifyStackInventoryReservation::STATUS_RELEASED) {
                continue;
            }
            $perStack = (int) $reservation->component_quantity_per_stack;
            $required = $currentStack * $perStack;
            $reservation->forceFill(array_filter([
                'stack_quantity_ordered' => $currentStack,
                'total_component_quantity_required' => $required,
                'shopify_order_created_at' => $reservation->shopify_order_created_at ?? $orderCreatedAt,
            ], fn (mixed $value): bool => $value !== null))->save();

            $targetRemaining = $unfulfilledStack * $perStack;
            $pendingConsume = max(0, (($currentStack - $unfulfilledStack) * $perStack) - (int) $reservation->consumed_quantity);
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
            } else {
                $releasable = max(0, ($remaining - $targetRemaining) - $pendingConsume);
                if ($releasable > 0) {
                    $this->movements->execute(
                        $reservation,
                        ShopifyStackInventoryMovement::ACTION_RELEASE,
                        $releasable,
                        "order-target:{$event->shopify_order_id}:{$lineId}:{$required}:{$remaining}",
                    );
                    $summary['released']++;
                } else {
                    $reservation->refreshLedgerStatus();
                    $reservation->save();
                }
            }
        }

        return $summary;
    }

    /** @param array{stack_lines:int,reserved:int,released:int} $summary
     * @return array{stack_lines:int,reserved:int,released:int}
     */
    private function releaseAll(string $orderId, string $eventKey, array $summary): array
    {
        foreach (ShopifyStackInventoryReservation::query()->where('shopify_order_id', $orderId)->get() as $reservation) {
            if ($this->releaseRemaining($reservation, $eventKey)) {
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
        int $fulfilledBaseline,
        mixed $orderCreatedAt,
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
        $baseline = max(0, min($stackQuantity, $fulfilledBaseline)) * $quantityPerStack;

        $reservation = ShopifyStackInventoryReservation::query()->firstOrCreate(
            [
                'shopify_order_id' => $event->shopify_order_id,
                'shopify_order_line_item_id' => $lineId,
                'configured_component_product_id' => $componentProductId,
            ],
            [
                'shopify_order_name' => $event->shopify_order_name,
                'shopify_order_created_at' => $orderCreatedAt,
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
                'reserved_quantity' => $baseline,
                'consumed_quantity' => $baseline,
                'shopify_location_id' => $locationId,
                'ledger_document_uri' => $ledgerUri,
                'status' => ShopifyStackInventoryReservation::STATUS_PENDING_PROCESSING,
            ],
        );
        $reservation->refreshLedgerStatus();
        $reservation->save();

        return $reservation;
    }

    private function releaseRemaining(ShopifyStackInventoryReservation $reservation, string $eventKey): bool
    {
        $remaining = $reservation->remainingReserved();
        if ($remaining <= 0) {
            return false;
        }
        $this->movements->execute(
            $reservation,
            ShopifyStackInventoryMovement::ACTION_RELEASE,
            $remaining,
            $eventKey.':remaining:'.$remaining,
        );

        return true;
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
