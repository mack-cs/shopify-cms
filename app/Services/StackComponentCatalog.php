<?php

namespace App\Services;

use App\Models\NewProductDraft;
use App\Models\Product;

/** The draft bundle list is the only component-to-stack relationship used for discovery and rebuilds. */
final class StackComponentCatalog
{
    /**
     * @return array<int, int>
     */
    public function componentIds(Product $stack): array
    {
        $draft = $this->draftForStack($stack);

        return $this->ids($draft?->bundle_product_ids);
    }

    /**
     * @return array<int, int>
     */
    public function stackIdsForComponent(int $componentProductId): array
    {
        if ($componentProductId < 1) {
            return [];
        }

        $drafts = NewProductDraft::query()
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get(['id', 'shopify_id', 'handle', 'bundle_product_ids', 'updated_at']);

        $ids = [];
        $seenStacks = [];
        foreach ($drafts as $draft) {
            if (trim((string) $draft->shopify_id) === '' && trim((string) $draft->handle) === '') {
                continue;
            }
            $product = Product::query()
                ->where(function ($query) use ($draft): void {
                    $shopifyId = trim((string) $draft->shopify_id);
                    $handle = trim((string) $draft->handle);
                    if ($shopifyId !== '') {
                        $query->where('shopify_id', $shopifyId);
                    }
                    if ($handle !== '') {
                        $shopifyId !== '' ? $query->orWhere('handle', $handle) : $query->where('handle', $handle);
                    }
                })
                ->orderBy('id')
                ->first();
            if (! $product instanceof Product || ! $product->isStackProduct() || isset($seenStacks[$product->id])) {
                continue;
            }
            $seenStacks[$product->id] = true;
            if (! in_array($componentProductId, $this->ids($draft->bundle_product_ids), true)) {
                continue;
            }
            $ids[] = (int) $product->id;
        }

        return array_values(array_unique($ids));
    }

    private function draftForStack(Product $stack): ?NewProductDraft
    {
        $shopifyId = trim((string) ($stack->shopify_id ?? ''));
        $handle = trim((string) ($stack->handle ?? ''));
        if ($shopifyId === '' && $handle === '') {
            return null;
        }

        return NewProductDraft::query()
            ->where(function ($query) use ($shopifyId, $handle): void {
                if ($shopifyId !== '') {
                    $query->where('shopify_id', $shopifyId);
                }
                if ($handle !== '') {
                    $shopifyId !== '' ? $query->orWhere('handle', $handle) : $query->where('handle', $handle);
                }
            })
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<int, int>
     */
    private function ids(mixed $value): array
    {
        return collect(is_array($value) ? $value : [])
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();
    }
}
