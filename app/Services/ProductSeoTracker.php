<?php

namespace App\Services;

use App\Models\Product;

class ProductSeoTracker
{
    /**
     * @return array{seo_updated_at:\Illuminate\Support\Carbon,seo_updated_by:int|null}
     */
    public function stampAttributes(Product $product, ?int $userId = null): array
    {
        $attributes = [
            'seo_updated_at' => now(),
            'seo_updated_by' => $userId,
        ];

        $product->forceFill($attributes);

        return $attributes;
    }

    public function markSeoUpdated(Product $product, ?int $userId = null): void
    {
        $attributes = [
            'seo_updated_at' => now(),
            'seo_updated_by' => $userId,
        ];

        Product::query()
            ->whereKey($product->getKey())
            ->update($attributes);

        $product->forceFill($attributes);
    }
}
