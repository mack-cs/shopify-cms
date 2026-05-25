<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Variant;
use Illuminate\Support\Collection;

final class ProductSellabilityService
{
    public function isLocallySellable(Product $product): bool
    {
        if (strtolower(trim((string) ($product->status ?? ''))) !== 'active') {
            return false;
        }

        $variants = $product->relationLoaded('variants')
            ? $product->variants
            : $product->variants()->orderBy('id')->get();

        if (!$variants instanceof Collection || $variants->isEmpty()) {
            return false;
        }

        foreach ($variants as $variant) {
            if (!$variant instanceof Variant) {
                continue;
            }

            if ($this->isVariantEligibleForComplementary($variant)) {
                return true;
            }
        }

        return false;
    }

    public function isVariantSellable(Variant $variant): bool
    {
        if ($variant->inventory_tracked === false) {
            return true;
        }

        if ($variant->inventory_tracked === null || $variant->inventory_qty === null) {
            return true;
        }

        return (int) $variant->inventory_qty > 0;
    }

    public function isVariantEligibleForComplementary(Variant $variant): bool
    {
        return $this->isVariantSellable($variant);
    }

    public function primaryVariantQuantity(Product $product): ?int
    {
        $variant = $product->variants()->orderBy('id')->first();

        if (!$variant instanceof Variant || $variant->inventory_tracked === false) {
            return null;
        }

        return $variant->inventory_qty !== null ? (int) $variant->inventory_qty : null;
    }

    public function eligibilityReason(Product $product): ?string
    {
        $status = strtolower(trim((string) ($product->status ?? '')));
        if ($status !== 'active') {
            return $status === '' ? 'Local status is not set' : 'Local status is ' . strtoupper($status);
        }

        $variants = $product->relationLoaded('variants')
            ? $product->variants
            : $product->variants()->orderBy('id')->get();

        if (!$variants instanceof Collection || $variants->isEmpty()) {
            return 'Missing active variant';
        }

        foreach ($variants as $variant) {
            if (!$variant instanceof Variant) {
                continue;
            }

            if ($variant->inventory_tracked === null || $variant->inventory_qty === null) {
                return null;
            }

            if ($variant->inventory_tracked === false) {
                return null;
            }

            if ($this->isVariantEligibleForComplementary($variant)) {
                return null;
            }
        }

        $hasExplicitZero = $variants->contains(
            fn (Variant $variant): bool => $variant->inventory_tracked === true
                && (int) ($variant->inventory_qty ?? 0) === 0
        );

        $hasNegative = $variants->contains(
            fn (Variant $variant): bool => $variant->inventory_tracked !== false
                && (int) ($variant->inventory_qty ?? 0) < 0
        );

        if ($hasNegative) {
            return 'Local inventory is below 0';
        }

        if ($hasExplicitZero) {
            return 'Local inventory is 0';
        }

        if ($variants->contains(fn (Variant $variant): bool => $variant->inventory_tracked === null || $variant->inventory_qty === null)) {
            return null;
        }

        return 'Local inventory is 0';
    }
}
