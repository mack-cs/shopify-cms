<?php

namespace App\Observers;

use App\Models\ChangeLog;
use App\Models\Product;
use App\Services\Normalizer;
use App\Services\TagNormalizer;
use App\Models\Tag;
use Illuminate\Support\Facades\Auth;

class ProductObserver
{
    public function updating(Product $product): void
    {
        $dirty = $product->getDirty();
        if (empty($dirty)) return;

        // 1) Decide which fields should NOT reset approvals
        $ignoreForApprovalReset = [
            'updated_at',
            'created_at',
            // add internal-only fields you don't want to trigger re-approval
            // e.g. 'batch', 'notes'
            'batch',
            'is_bundle',
            'you_save',
        ];

        // 2) If any meaningful field changed, bump approval_version
        $dirtyKeys = array_keys($dirty);
        $meaningful = array_diff($dirtyKeys, $ignoreForApprovalReset);

        // IMPORTANT: don't treat approval_version itself as meaningful
        $meaningful = array_diff($meaningful, ['approval_version']);

        if (!empty($meaningful)) {
            $product->approval_version = ($product->approval_version ?? 1) + 1;

            // Also ensure we don't log approval_version as if user changed it
            $dirty['approval_version'] = $product->approval_version;
        }

        // 3) Log changes (skip approval_version + timestamps so logs stay clean)
        $ignoreForLogging = [
            'updated_at',
            'created_at',
            'approval_version', // recommended: it's system-driven
        ];

        $userId = Auth::id();

        foreach ($dirty as $field => $newValue) {
            if (in_array($field, $ignoreForLogging, true)) {
                continue;
            }

            ChangeLog::create([
                'import_id'   => $product->import_id,
                'product_id'  => $product->id,
                'changed_by'  => $userId,
                'model_type'  => Product::class,
                'model_id'    => $product->id,
                'field'       => $field,
                'old_value'   => (string) $product->getOriginal($field),
                'new_value'   => is_scalar($newValue) ? (string)$newValue : json_encode($newValue),
            ]);
        }
    }

    public function updated(Product $product): void
    {
        if ($product->wasChanged('tags')) {
            $normalized = TagNormalizer::normalizeString($product->tags);
            if ($normalized !== $product->tags) {
                Product::withoutEvents(function () use ($product, $normalized): void {
                    $product->forceFill(['tags' => $normalized])->save();
                });
            }
        }

        if ($product->wasChanged('tags')) {
            $tokens = TagNormalizer::parseTokens($product->tags);
            $isBundle = in_array('bundle', $tokens, true) || in_array('bundles', $tokens, true);
            if ($product->is_bundle !== $isBundle) {
                Product::withoutEvents(function () use ($product, $isBundle): void {
                    $product->forceFill(['is_bundle' => $isBundle])->save();
                });
            }
        }

        app(Normalizer::class)->recalculateErrorsForProduct($product);
        $this->syncTagsForProduct($product);
    }

    private function syncTagsForProduct(Product $product): void
    {
        $tokens = TagNormalizer::parseTokens($product->tags);
        if (empty($tokens)) {
            return;
        }

        foreach ($tokens as $token) {
            Tag::firstOrCreate(['name' => $token], ['active' => true]);
        }
    }
}
