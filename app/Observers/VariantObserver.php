<?php

namespace App\Observers;

use App\Models\Product;
use App\Models\Variant;
use App\Services\Normalizer;

class VariantObserver
{
    public function creating(Variant $variant): void
    {
        $this->applyLocalSyncState($variant, isCreating: true);
    }

    public function updating(Variant $variant): void
    {
        $dirty = array_keys($variant->getDirty());
        $meaningful = array_diff($dirty, ['updated_at', 'created_at']);
        if (empty($meaningful)) {
            return;
        }

        $this->applyLocalSyncState($variant);
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

    private function applyLocalSyncState(Variant $variant, bool $isCreating = false): void
    {
        $contentDirty = array_diff(array_keys($variant->getDirty()), [
            'updated_at',
            'created_at',
            'sync_state',
            'local_dirty',
            'last_shopify_seen_at',
            'last_synced_at',
        ]);

        if (!$isCreating && empty($contentDirty)) {
            return;
        }

        if ($variant->sync_state === Variant::SYNC_STATE_LOCAL_DELETED) {
            $variant->local_dirty = true;
            return;
        }

        if (blank($variant->shopify_id)) {
            $variant->sync_state = Variant::SYNC_STATE_LOCAL_NEW;
            $variant->local_dirty = true;
            return;
        }

        $variant->sync_state = Variant::SYNC_STATE_LOCAL_UPDATED;
        $variant->local_dirty = true;
    }
}
