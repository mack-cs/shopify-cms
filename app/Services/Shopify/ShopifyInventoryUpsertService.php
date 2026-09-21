<?php

namespace App\Services\Shopify;

use App\Models\ShopifyInventorySnapshot;
use App\Models\ShopifySyncIssue;
use App\Models\ShopifySyncRun;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

final class ShopifyInventoryUpsertService
{
    /**
     * @param array<string, mixed> $payload
     */
    public function upsertSnapshot(array $payload, ShopifySyncRun $run): ShopifyInventorySnapshot
    {
        $inventoryItemId = trim((string) ($payload['shopify_inventory_item_id'] ?? ''));
        if ($inventoryItemId === '') {
            throw new \InvalidArgumentException('Inventory snapshot payload is missing shopify_inventory_item_id.');
        }

        return ShopifyInventorySnapshot::query()->updateOrCreate(
            [
                'sync_run_id' => $run->id,
                'shopify_inventory_item_id' => $inventoryItemId,
                'shopify_location_id' => trim((string) ($payload['shopify_location_id'] ?? '')),
            ],
            array_merge($payload, [
                'sync_run_id' => $run->id,
                'business_date' => $run->business_date?->toDateString(),
                'snapshot_requested_at' => $run->started_at,
                'snapshot_completed_at' => $run->shopify_completed_at ?? now(),
            ]),
        );
    }

    public function updateCurrentStateForRun(ShopifySyncRun $run): array
    {
        $updated = 0;
        $unmatched = 0;
        $duplicates = 0;

        ShopifyInventorySnapshot::query()
            ->where('sync_run_id', $run->id)
            ->orderBy('id')
            ->get()
            ->groupBy('shopify_inventory_item_id')
            ->each(function (Collection $snapshots) use (&$updated, &$unmatched, &$duplicates, $run): void {
                $result = $this->updateCurrentStateForSnapshots($snapshots, $run);
                $updated += $result['updated'];
                $unmatched += $result['unmatched'];
                $duplicates += $result['duplicates'];
            });

        return [
            'updated' => $updated,
            'unmatched' => $unmatched,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * @param Collection<int, ShopifyInventorySnapshot> $snapshots
     * @return array{updated:int,unmatched:int,duplicates:int}
     */
    private function updateCurrentStateForSnapshots(Collection $snapshots, ShopifySyncRun $run): array
    {
        /** @var ShopifyInventorySnapshot|null $first */
        $first = $snapshots->first();
        if (!$first instanceof ShopifyInventorySnapshot) {
            return ['updated' => 0, 'unmatched' => 0, 'duplicates' => 0];
        }

        $match = $this->matchVariant($first);
        if ($match instanceof EloquentCollection) {
            $this->issue($run, ShopifySyncIssue::TYPE_DUPLICATE_SKU, 'Multiple local variants matched inventory SKU; current inventory was not updated.', $first, [
                'variant_ids' => $match->pluck('id')->values()->all(),
            ]);

            return ['updated' => 0, 'unmatched' => 0, 'duplicates' => 1];
        }

        if (!$match instanceof Variant) {
            $this->issue($run, ShopifySyncIssue::TYPE_UNMATCHED_INVENTORY, 'No local variant matched Shopify inventory snapshot.', $first);

            return ['updated' => 0, 'unmatched' => 1, 'duplicates' => 0];
        }

        $snapshotCompletedAt = $first->snapshot_completed_at ?? now();
        if ($match->inventory_last_synced_at !== null && $match->inventory_last_synced_at->gt($snapshotCompletedAt)) {
            return ['updated' => 0, 'unmatched' => 0, 'duplicates' => 0];
        }

        $quantity = fn (string $column): ?int => $this->sumNullable($snapshots, $column);
        $available = $quantity('available');

        Variant::withoutEvents(function () use ($match, $first, $snapshotCompletedAt, $available, $quantity, $snapshots): void {
            $match->forceFill([
                'shopify_id' => $first->shopify_variant_id ?: $match->shopify_id,
                'shopify_inventory_item_id' => $first->shopify_inventory_item_id ?: $match->shopify_inventory_item_id,
                'inventory_tracked' => $first->tracked,
                'inventory_qty' => $first->tracked === false ? null : $available,
                'current_inventory_quantity' => $available,
                'current_available_quantity' => $available,
                'current_on_hand_quantity' => $quantity('on_hand'),
                'current_committed_quantity' => $quantity('committed'),
                'current_incoming_quantity' => $quantity('incoming'),
                'current_reserved_quantity' => $quantity('reserved'),
                'current_damaged_quantity' => $quantity('damaged'),
                'current_quality_control_quantity' => $quantity('quality_control'),
                'current_safety_stock_quantity' => $quantity('safety_stock'),
                'inventory_location_count' => $snapshots->pluck('shopify_location_id')->filter()->unique()->count(),
                'inventory_last_synced_at' => $snapshotCompletedAt,
                'inventory_local_dirty' => false,
                'inventory_sync_error' => null,
            ])->save();
        });

        return ['updated' => 1, 'unmatched' => 0, 'duplicates' => 0];
    }

    private function matchVariant(ShopifyInventorySnapshot $snapshot): Variant|EloquentCollection|null
    {
        $variantId = trim((string) ($snapshot->shopify_variant_id ?? ''));
        if ($variantId !== '') {
            $variant = Variant::query()->active()->where('shopify_id', $variantId)->first();
            if ($variant instanceof Variant) {
                return $variant;
            }
        }

        $inventoryItemId = trim((string) ($snapshot->shopify_inventory_item_id ?? ''));
        if ($inventoryItemId !== '') {
            $variant = Variant::query()->active()->where('shopify_inventory_item_id', $inventoryItemId)->first();
            if ($variant instanceof Variant) {
                return $variant;
            }
        }

        $sku = $this->normalizeSku($snapshot->sku);
        if ($sku === null) {
            return null;
        }

        $matches = Variant::query()
            ->active()
            ->whereRaw('UPPER(TRIM(COALESCE(sku, ""))) = ?', [$sku])
            ->get();

        if ($matches->count() > 1) {
            return $matches;
        }

        return $matches->first();
    }

    /**
     * @param Collection<int, ShopifyInventorySnapshot> $snapshots
     */
    private function sumNullable(Collection $snapshots, string $column): ?int
    {
        $values = $snapshots
            ->map(fn (ShopifyInventorySnapshot $snapshot): mixed => $snapshot->getAttribute($column))
            ->filter(fn (mixed $value): bool => $value !== null);

        if ($values->isEmpty()) {
            return null;
        }

        return (int) $values->sum(fn (mixed $value): int => (int) $value);
    }

    private function normalizeSku(mixed $sku): ?string
    {
        $sku = strtoupper(trim((string) ($sku ?? '')));

        return $sku === '' ? null : $sku;
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function issue(ShopifySyncRun $run, string $type, string $message, ShopifyInventorySnapshot $snapshot, array $extra = []): void
    {
        ShopifySyncIssue::query()->updateOrCreate([
            'sync_run_id' => $run->id,
            'dataset' => ShopifySyncRun::DATASET_INVENTORY,
            'issue_type' => $type,
            'shopify_id' => $snapshot->shopify_inventory_item_id,
            'sku' => $snapshot->sku,
        ], [
            'message' => $message,
            'payload' => array_merge([
                'shopify_variant_id' => $snapshot->shopify_variant_id,
                'shopify_product_id' => $snapshot->shopify_product_id,
                'shopify_location_id' => $snapshot->shopify_location_id,
            ], $extra),
        ]);
    }
}
