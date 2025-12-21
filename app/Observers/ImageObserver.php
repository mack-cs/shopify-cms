<?php

namespace App\Observers;

use App\Models\Image;
use App\Models\Product;

class ImageObserver
{
    public function updating(Image $image): void
    {
        $dirty = array_keys($image->getDirty());
        $meaningful = array_diff($dirty, ['updated_at', 'created_at']);
        if (empty($meaningful)) {
            return;
        }

        $this->bumpProductApprovalVersion($image->product_id);
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
