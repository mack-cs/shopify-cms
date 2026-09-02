<?php

namespace App\Services\Shopify;

use App\Models\NewProductDraft;
use App\Models\ShopifyFulfillment;
use App\Models\ShopifyStackInventoryMovement;
use App\Models\ShopifyStackInventoryReservation;
use App\Models\Variant;
use Illuminate\Support\Facades\Cache;

final class StackFulfillmentInventoryService
{
    public function __construct(private readonly StackInventoryMovementService $movements) {}

    /** @return array{stack_lines:int,consumed:int,already_completed:int} */
    public function process(ShopifyFulfillment $fulfillment): array
    {
        if ($fulfillment->processing_status === ShopifyFulfillment::STATUS_COMPLETED) {
            return ['stack_lines' => 0, 'consumed' => 0, 'already_completed' => 1];
        }
        if (strtolower(trim((string) $fulfillment->shopify_status)) !== 'success') {
            $fulfillment->forceFill([
                'processing_status' => ShopifyFulfillment::STATUS_IGNORED,
                'error_message' => 'Fulfillment status is not success; no reserved inventory was consumed.',
                'processed_at' => now(),
            ])->save();

            return ['stack_lines' => 0, 'consumed' => 0, 'already_completed' => 0];
        }

        $orderId = $this->gid('Order', $fulfillment->shopify_order_id);
        $lock = Cache::lock('stack-order-inventory:'.$orderId, 300);
        if (! $lock->get()) {
            throw new \RuntimeException('Stack order inventory is already being processed; retrying.');
        }

        try {
            return $this->processLocked($fulfillment, $orderId);
        } finally {
            $lock->release();
        }
    }

    /** @return array{stack_lines:int,consumed:int,already_completed:int} */
    private function processLocked(ShopifyFulfillment $fulfillment, string $orderId): array
    {
        $fulfillment->forceFill([
            'processing_status' => ShopifyFulfillment::STATUS_PROCESSING,
            'attempts' => (int) $fulfillment->attempts + 1,
            'processing_started_at' => now(),
            'error_message' => null,
        ])->save();
        $summary = ['stack_lines' => 0, 'consumed' => 0, 'already_completed' => 0];

        try {
            foreach ((array) data_get($fulfillment->payload, 'line_items', []) as $lineItem) {
                if (! is_array($lineItem) || (int) ($lineItem['quantity'] ?? 0) <= 0) {
                    continue;
                }
                $lineId = $this->gid('LineItem', $lineItem['admin_graphql_api_id'] ?? $lineItem['id'] ?? null);
                $reservations = ShopifyStackInventoryReservation::query()
                    ->where('shopify_order_id', $orderId)
                    ->where('shopify_order_line_item_id', $lineId)
                    ->get();
                if ($reservations->isEmpty()) {
                    if ($this->isConfiguredStack($lineItem)) {
                        throw new \RuntimeException("No component reservation exists for fulfilled Stack line {$lineId}; retrying after the order webhook.");
                    }

                    continue;
                }

                $summary['stack_lines']++;
                foreach ($reservations as $reservation) {
                    $quantity = (int) $lineItem['quantity'] * (int) $reservation->component_quantity_per_stack;
                    $source = "fulfillment:{$fulfillment->shopify_fulfillment_id}:{$lineId}";
                    $eventKey = hash('sha256', implode('|', [
                        $source, $reservation->id, ShopifyStackInventoryMovement::ACTION_CONSUME, $quantity,
                    ]));
                    if ($reservation->movements()->where('event_key', $eventKey)
                        ->where('status', ShopifyStackInventoryMovement::STATUS_COMPLETED)->exists()) {
                        $summary['already_completed']++;

                        continue;
                    }
                    if ($quantity > $reservation->remainingReserved()) {
                        throw new \RuntimeException(
                            "Stack component {$reservation->component_sku} needs {$quantity} reserved units but only {$reservation->remainingReserved()} remain."
                        );
                    }
                    $this->movements->execute(
                        $reservation,
                        ShopifyStackInventoryMovement::ACTION_CONSUME,
                        $quantity,
                        $source,
                    );
                    $summary['consumed']++;
                }
            }
        } catch (\Throwable $exception) {
            $fulfillment->forceFill([
                'processing_status' => ShopifyFulfillment::STATUS_FAILED,
                'error_message' => $exception->getMessage(),
            ])->save();
            throw $exception;
        }

        $fulfillment->forceFill([
            'processing_status' => ShopifyFulfillment::STATUS_COMPLETED,
            'processed_at' => now(),
            'error_message' => null,
        ])->save();

        return $summary;
    }

    private function isConfiguredStack(array $lineItem): bool
    {
        $variantId = trim((string) ($lineItem['variant_id'] ?? ''));
        if ($variantId === '') {
            return false;
        }
        $variant = Variant::query()->with('product')->whereIn('shopify_id', [
            $variantId, $this->gid('ProductVariant', $variantId),
        ])->first();
        if (! $variant instanceof Variant) {
            return false;
        }

        return NewProductDraft::query()->whereNotNull('bundle_product_ids')->where(function ($query) use ($variant): void {
            $query->where('shopify_id', $variant->product?->shopify_id)
                ->orWhere('handle', $variant->product?->handle)
                ->orWhere('sku', $variant->sku);
        })->exists();
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
