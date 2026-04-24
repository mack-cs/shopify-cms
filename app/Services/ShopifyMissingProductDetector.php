<?php

namespace App\Services;

use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyMissingProduct;

final class ShopifyMissingProductDetector
{
    public function detect(Import $currentImport, ?Import $previousImport): int
    {
        if (!$previousImport) {
            return 0;
        }

        ShopifyMissingProduct::query()
            ->where('import_id', $currentImport->id)
            ->delete();

        $currentProducts = Product::query()
            ->where('import_id', $currentImport->id)
            ->get(['handle', 'shopify_id']);

        $currentShopifyIds = $currentProducts->pluck('shopify_id')
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->all();

        $currentHandles = $currentProducts->pluck('handle')
            ->filter(fn ($value) => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->all();

        $missing = Product::query()
            ->where('import_id', $previousImport->id)
            ->where(function ($query) use ($currentShopifyIds, $currentHandles): void {
                $query->where(function ($shopifyIdQuery) use ($currentShopifyIds): void {
                    $shopifyIdQuery
                        ->whereNotNull('shopify_id')
                        ->where('shopify_id', '!=', '');

                    if ($currentShopifyIds !== []) {
                        $shopifyIdQuery->whereNotIn('shopify_id', $currentShopifyIds);
                    }
                });

                $query->orWhere(function ($handleQuery) use ($currentHandles): void {
                    $handleQuery
                        ->whereNotNull('handle')
                        ->where('handle', '!=', '');

                    if ($currentHandles !== []) {
                        $handleQuery->whereNotIn('handle', $currentHandles);
                    }
                });
            })
            ->get(['id', 'handle', 'title', 'shopify_id', 'vendor', 'status']);

        if ($missing->isEmpty()) {
            return 0;
        }

        $now = now();
        $rows = $missing->map(fn (Product $product): array => [
            'import_id' => $currentImport->id,
            'previous_import_id' => $previousImport->id,
            'previous_product_id' => $product->id,
            'handle' => $product->handle,
            'title' => $product->title,
            'shopify_id' => $product->shopify_id,
            'vendor' => $product->vendor,
            'status' => $product->status,
            'detected_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        ShopifyMissingProduct::insert($rows);

        return count($rows);
    }
}
