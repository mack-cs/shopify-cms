<?php

namespace App\Observers;

use App\Models\ChangeLog;
use App\Models\Product;
use App\Models\Variant;
use App\Services\InventoryDraftMirrorService;
use App\Services\InventoryOperationContext;
use App\Services\Normalizer;
use Illuminate\Support\Facades\Auth;

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

        if (!$this->shouldSkipApprovalBump($variant, $meaningful)) {
            $this->bumpProductApprovalVersion($variant->product_id);
        }

        $this->logChanges($variant);
    }

    public function saved(Variant $variant): void
    {
        $product = Product::find($variant->product_id);
        if (!$product) {
            return;
        }

        app(Normalizer::class)->recalculateErrorsForProduct($product);

        if ($variant->wasChanged(['inventory_qty', 'inventory_tracked'])) {
            app(InventoryDraftMirrorService::class)->syncProduct($product->loadMissing('variants'));
        }
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

    /**
     * @param array<int, string> $meaningful
     */
    private function shouldSkipApprovalBump(Variant $variant, array $meaningful): bool
    {
        if (!InventoryOperationContext::active()) {
            return false;
        }

        return count(array_diff($meaningful, ['inventory_qty', 'inventory_tracked', 'inventory_sync_error'])) === 0;
    }

    private function logChanges(Variant $variant): void
    {
        $ignoreForLogging = [
            'updated_at',
            'created_at',
            'sync_state',
            'local_dirty',
            'last_shopify_seen_at',
            'last_synced_at',
            'inventory_last_synced_at',
            'inventory_sync_batch_id',
            'inventory_local_dirty',
            'inventory_sync_error',
        ];

        $userId = Auth::id();
        $product = Product::find($variant->product_id);

        foreach ($variant->getDirty() as $field => $newValue) {
            if (in_array($field, $ignoreForLogging, true)) {
                continue;
            }

            ChangeLog::create([
                'import_id' => $product?->import_id,
                'product_id' => $product?->id,
                'changed_by' => $userId,
                'model_type' => Variant::class,
                'model_id' => $variant->id,
                'field' => $field,
                'old_value' => $this->stringifyValue($variant->getOriginal($field)),
                'new_value' => $this->stringifyValue($newValue),
            ]);
        }
    }

    private function stringifyValue(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_scalar($value) || $value === null) {
            return (string) $value;
        }

        return (string) json_encode($value);
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
            if ($this->hasInventoryDirtyFields($contentDirty)) {
                $variant->inventory_local_dirty = true;
            }
            return;
        }

        $variant->sync_state = Variant::SYNC_STATE_LOCAL_UPDATED;
        $variant->local_dirty = true;
        if ($this->hasInventoryDirtyFields($contentDirty)) {
            $variant->inventory_local_dirty = true;
        }
    }

    /**
     * @param array<int, string> $contentDirty
     */
    private function hasInventoryDirtyFields(array $contentDirty): bool
    {
        return array_intersect($contentDirty, ['inventory_qty', 'inventory_tracked']) !== [];
    }
}
