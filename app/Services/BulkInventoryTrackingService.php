<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductInventorySnapshot;
use App\Models\Variant;
use Illuminate\Support\Collection;
use InvalidArgumentException;

final class BulkInventoryTrackingService
{
    public function __construct(
        private readonly ProductInventoryHistoryRecorder $historyRecorder,
    ) {
    }

    /**
     * @param Collection<int, Variant> $variants
     * @return array{
     *     updated:int,
     *     already_tracked:int,
     *     unknown_skipped:int,
     *     changed_variant_ids:array<int, int>,
     *     snapshots:int
     * }
     */
    public function startTracking(Collection $variants, int $quantity, ?int $userId = null): array
    {
        if ($quantity < 0) {
            throw new InvalidArgumentException('Starting inventory quantity cannot be negative.');
        }

        $result = [
            'updated' => 0,
            'already_tracked' => 0,
            'unknown_skipped' => 0,
            'changed_variant_ids' => [],
            'snapshots' => 0,
        ];
        $changedProductIds = [];

        foreach ($variants as $variant) {
            if (!$variant instanceof Variant) {
                continue;
            }

            if ($variant->inventory_tracked === true) {
                $result['already_tracked']++;
                continue;
            }

            if ($variant->inventory_tracked === null) {
                $result['unknown_skipped']++;
                continue;
            }

            InventoryOperationContext::run(function () use ($variant, $quantity): void {
                $variant->inventory_tracked = true;
                $variant->current_on_hand_quantity = $quantity;
                $variant->inventory_sync_error = null;
                $variant->save();
            });

            $result['updated']++;
            $result['changed_variant_ids'][] = (int) $variant->id;
            $changedProductIds[(int) $variant->product_id] = true;
        }

        foreach (array_keys($changedProductIds) as $productId) {
            $product = Product::query()->with('variants')->find($productId);
            if (!$product instanceof Product) {
                continue;
            }

            $this->historyRecorder->record(
                $product,
                $userId,
                ProductInventorySnapshot::SOURCE_LOCAL_UPDATE,
            );
            $result['snapshots']++;
        }

        return $result;
    }
}
