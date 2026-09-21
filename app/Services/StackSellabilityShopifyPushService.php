<?php

namespace App\Services;

use App\Jobs\InventorySyncJob;
use App\Models\Variant;

final class StackSellabilityShopifyPushService
{
    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    public function queuePushForChangedStacks(array $summary, ?int $userId = null): array
    {
        $productIds = collect($summary['changes'] ?? [])
            ->filter(fn ($change): bool => is_array($change))
            ->map(fn (array $change): int => (int) data_get($change, 'stack.product_id', 0))
            ->filter(fn (int $productId): bool => $productId > 0)
            ->unique()
            ->values();

        if ($productIds->isEmpty()) {
            return array_merge($summary, [
                'shopify_push_queued_products' => 0,
                'shopify_push_queued_variants' => 0,
            ]);
        }

        $variantIds = Variant::query()
            ->whereIn('product_id', $productIds->all())
            ->orderBy('product_id')
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($variantIds === []) {
            return array_merge($summary, [
                'shopify_push_queued_products' => $productIds->count(),
                'shopify_push_queued_variants' => 0,
            ]);
        }

        InventorySyncJob::dispatch(
            $variantIds,
            'push',
            $userId,
            'stack_sellability_' . now()->format('YmdHis'),
        );

        return array_merge($summary, [
            'shopify_push_queued_products' => $productIds->count(),
            'shopify_push_queued_variants' => count($variantIds),
        ]);
    }
}
