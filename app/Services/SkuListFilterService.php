<?php

namespace App\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class SkuListFilterService
{
    /**
     * @return array<int, string>
     */
    public function parse(mixed $value): array
    {
        if (! is_string($value)) {
            return [];
        }

        $tokens = preg_split('/[\s,;]+/', trim($value)) ?: [];

        return array_values(array_unique(array_filter(array_map(
            static fn (string $sku): string => strtolower(trim($sku)),
            $tokens
        ))));
    }

    public function applyToDrafts(Builder $query, mixed $value): Builder
    {
        $skus = $this->parse($value);
        if ($skus === []) {
            return $query;
        }

        return $query->where(function (Builder $skuQuery) use ($skus): void {
            $skuQuery
                ->whereIn(DB::raw('LOWER(TRIM(new_product_drafts.sku))'), $skus)
                ->orWhereHas('product.variants', fn (Builder $variantQuery): Builder => $variantQuery
                    ->whereIn(DB::raw('LOWER(TRIM(variants.sku))'), $skus));
        });
    }

    public function applyToProducts(Builder $query, mixed $value): Builder
    {
        $skus = $this->parse($value);
        if ($skus === []) {
            return $query;
        }

        return $query->whereHas('variants', fn (Builder $variantQuery): Builder => $variantQuery
            ->whereIn(DB::raw('LOWER(TRIM(variants.sku))'), $skus));
    }
}
