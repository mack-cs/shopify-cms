<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\Variant;
use App\Services\Normalizer;

class VariantObserver
{
    public function updating(Variant $variant): void
    {
        $dirty = array_keys($variant->getDirty());
        $meaningful = array_diff($dirty, ['updated_at', 'created_at']);
        if (empty($meaningful)) {
            return;
        }

        $this->bumpProductApprovalVersion($variant->product_id);
    }

    public function saved(Variant $variant): void
    {
        $product = Product::find($variant->product_id);
        if (!$product) {
            return;
        }

        app(Normalizer::class)->recalculateErrorsForProduct($product);
    }

    public function deleted(Variant $variant): void
    {
        $product = Product::find($variant->product_id);
        if (!$product) {
            return;
        }

        app(Normalizer::class)->recalculateErrorsForProduct($product);
    }

    private function bumpProductApprovalVersion(?int $productId): void
    {
        if (!$productId) {
            return;
        }

        Product::withoutEvents(function () use ($productId): void {
            $product = Product::find($productId);
            if (!$product) {
                return;
            }

            $product->approval_version = ($product->approval_version ?? 1) + 1;
            $product->save();
        });
    }
}
