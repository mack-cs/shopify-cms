<?php

namespace App\Services;

use App\Models\Product;
use App\Models\ProductInventoryEvent;
use App\Models\ProductInventorySnapshot;
use App\Models\Variant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

final class ProductInventoryHistoryRecorder
{
    public function __construct(
        private readonly ProductSellabilityService $sellabilityService,
    ) {
    }

    public function record(Product $product, ?int $userId = null, string $source = ProductInventorySnapshot::SOURCE_SHOPIFY_REFRESH): ProductInventorySnapshot
    {
        $product = $product->fresh(['variants']) ?? $product->loadMissing('variants');
        $checkedAt = now();
        $previous = ProductInventorySnapshot::query()
            ->where('product_id', $product->id)
            ->latest('checked_at')
            ->latest('id')
            ->first();

        $snapshot = ProductInventorySnapshot::query()->create(
            $this->snapshotPayload($product, $checkedAt, $userId, $source)
        );

        $this->recordEvents($snapshot, $previous);

        return $snapshot;
    }

    /**
     * @return array<string, mixed>
     */
    private function snapshotPayload(Product $product, Carbon $checkedAt, ?int $userId, string $source): array
    {
        $variants = $this->variants($product);
        $status = strtolower(trim((string) ($product->status ?? '')));
        $isSellable = $this->sellabilityService->isLocallySellable($product);
        $tracked = $variants->filter(fn (Variant $variant): bool => $variant->inventory_tracked === true);
        $totalInventoryQty = $tracked
            ->filter(fn (Variant $variant): bool => $variant->inventory_qty !== null)
            ->sum(fn (Variant $variant): int => (int) $variant->inventory_qty);
        $hasKnownTrackedInventory = $tracked->contains(fn (Variant $variant): bool => $variant->inventory_qty !== null);
        $isOutOfStock = $this->isOutOfStock($status, $isSellable, $variants);

        return [
            'product_id' => $product->id,
            'observed_by' => $userId,
            'product_title' => $this->nullIfBlank($product->title),
            'product_handle' => $this->nullIfBlank($product->handle),
            'product_shopify_id' => $this->nullIfBlank($product->shopify_id),
            'checked_at' => $checkedAt,
            'checked_date' => $checkedAt->toDateString(),
            'source' => $source,
            'product_status' => $status !== '' ? $status : null,
            'is_sellable' => $isSellable,
            'is_out_of_stock' => $isOutOfStock,
            'sellability_reason' => $this->sellabilityService->eligibilityReason($product),
            'variant_count' => $variants->count(),
            'tracked_variant_count' => $tracked->count(),
            'untracked_variant_count' => $variants->filter(fn (Variant $variant): bool => $variant->inventory_tracked === false)->count(),
            'unknown_inventory_variant_count' => $variants->filter(fn (Variant $variant): bool => $variant->inventory_tracked === null || ($variant->inventory_tracked === true && $variant->inventory_qty === null))->count(),
            'sellable_variant_count' => $variants->filter(fn (Variant $variant): bool => $this->sellabilityService->isVariantSellable($variant))->count(),
            'out_of_stock_variant_count' => $variants->filter(fn (Variant $variant): bool => $variant->inventory_tracked === true && (int) ($variant->inventory_qty ?? 0) <= 0)->count(),
            'total_inventory_qty' => $hasKnownTrackedInventory ? (int) $totalInventoryQty : null,
            'primary_variant_qty' => $this->sellabilityService->primaryVariantQuantity($product),
            'variant_summary' => $variants
                ->map(fn (Variant $variant): array => [
                    'id' => $variant->id,
                    'shopify_id' => $variant->shopify_id,
                    'sku' => $variant->sku,
                    'tracked' => $variant->inventory_tracked,
                    'quantity' => $variant->inventory_qty,
                    'sellable' => $this->sellabilityService->isVariantSellable($variant),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return Collection<int, Variant>
     */
    private function variants(Product $product): Collection
    {
        $variants = $product->relationLoaded('variants')
            ? $product->variants
            : $product->variants()->orderBy('id')->get();

        return $variants instanceof Collection
            ? $variants->filter(fn ($variant): bool => $variant instanceof Variant)->values()
            : collect();
    }

    /**
     * @param Collection<int, Variant> $variants
     */
    private function isOutOfStock(string $status, bool $isSellable, Collection $variants): bool
    {
        if ($status !== 'active' || $isSellable || $variants->isEmpty()) {
            return false;
        }

        return $variants->every(fn (Variant $variant): bool => $variant->inventory_tracked === true
            && $variant->inventory_qty !== null
            && (int) $variant->inventory_qty <= 0);
    }

    private function recordEvents(ProductInventorySnapshot $snapshot, ?ProductInventorySnapshot $previous): void
    {
        if (!$previous instanceof ProductInventorySnapshot) {
            if (!$snapshot->is_sellable) {
                $this->createEvent(ProductInventoryEvent::TYPE_FIRST_SEEN_UNSELLABLE, $snapshot, null);
            }

            if ($snapshot->is_out_of_stock) {
                $this->createEvent(ProductInventoryEvent::TYPE_FIRST_SEEN_OUT_OF_STOCK, $snapshot, null);
            }

            return;
        }

        if ((bool) $previous->is_sellable !== (bool) $snapshot->is_sellable) {
            $this->createEvent(
                $snapshot->is_sellable ? ProductInventoryEvent::TYPE_BECAME_SELLABLE : ProductInventoryEvent::TYPE_BECAME_UNSELLABLE,
                $snapshot,
                $previous,
            );
        }

        if ((bool) $previous->is_out_of_stock !== (bool) $snapshot->is_out_of_stock) {
            $this->createEvent(
                $snapshot->is_out_of_stock ? ProductInventoryEvent::TYPE_BECAME_OUT_OF_STOCK : ProductInventoryEvent::TYPE_LEFT_OUT_OF_STOCK,
                $snapshot,
                $previous,
            );
        }

        if ((string) ($previous->product_status ?? '') !== (string) ($snapshot->product_status ?? '')) {
            $this->createEvent(ProductInventoryEvent::TYPE_STATUS_CHANGED, $snapshot, $previous);
        }
    }

    private function createEvent(string $eventType, ProductInventorySnapshot $snapshot, ?ProductInventorySnapshot $previous): void
    {
        ProductInventoryEvent::query()->create([
            'product_id' => $snapshot->product_id,
            'product_inventory_snapshot_id' => $snapshot->id,
            'previous_product_inventory_snapshot_id' => $previous?->id,
            'observed_by' => $snapshot->observed_by,
            'product_title' => $snapshot->product_title,
            'product_handle' => $snapshot->product_handle,
            'product_shopify_id' => $snapshot->product_shopify_id,
            'event_type' => $eventType,
            'occurred_at' => $snapshot->checked_at,
            'source' => $snapshot->source,
            'from_is_sellable' => $previous?->is_sellable,
            'to_is_sellable' => $snapshot->is_sellable,
            'from_is_out_of_stock' => $previous?->is_out_of_stock,
            'to_is_out_of_stock' => $snapshot->is_out_of_stock,
            'from_status' => $previous?->product_status,
            'to_status' => $snapshot->product_status,
            'from_reason' => $previous?->sellability_reason,
            'to_reason' => $snapshot->sellability_reason,
            'metadata' => [
                'previous_total_inventory_qty' => $previous?->total_inventory_qty,
                'total_inventory_qty' => $snapshot->total_inventory_qty,
                'previous_primary_variant_qty' => $previous?->primary_variant_qty,
                'primary_variant_qty' => $snapshot->primary_variant_qty,
            ],
        ]);
    }

    private function nullIfBlank(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
