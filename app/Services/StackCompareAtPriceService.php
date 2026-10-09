<?php

namespace App\Services;

use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\Variant;

class StackCompareAtPriceService
{
    public function syncFromDraftChange(NewProductDraft $draft): void
    {
        $changes = array_keys($draft->getChanges());
        $recentlyCreated = $draft->wasRecentlyCreated;

        if ($this->componentIds($draft) !== []) {
            $this->applyToDraft($draft);
        }

        $priceChanged = $recentlyCreated || array_intersect($changes, [
            'variant_price',
            'bundle_product_ids',
            'sku',
            'handle',
            'shopify_id',
        ]) !== [];
        if (! $priceChanged) {
            return;
        }

        $productId = $this->productIdForDraft($draft);
        if ($productId === null) {
            return;
        }

        $stacks = NewProductDraft::query()
            ->whereKeyNot($draft->id)
            ->where(function ($query) use ($productId): void {
                $query->whereJsonContains('bundle_product_ids', $productId)
                    ->orWhereJsonContains('bundle_product_ids', (string) $productId);
            })
            ->get();

        foreach ($stacks as $stack) {
            if ($stack instanceof NewProductDraft) {
                $this->applyToDraft($stack);
            }
        }
    }

    /**
     * @return array{updated:bool, compare_at:?string, message:string}
     */
    public function applyToDraft(NewProductDraft $draft): array
    {
        $componentIds = $this->componentIds($draft);
        $label = trim((string) ($draft->sku ?: $draft->handle ?: 'Draft #'.$draft->id));
        if ($componentIds === []) {
            return ['updated' => false, 'compare_at' => null, 'message' => ''];
        }

        $compareAt = $this->compareAtForComponentIds($componentIds);
        if ($compareAt === null) {
            return [
                'updated' => false,
                'compare_at' => null,
                'message' => "{$label}: compare-at price was left unchanged because a component has no price.",
            ];
        }

        $draftMatches = $this->moneyEquals($draft->variant_compare_at_price, $compareAt);
        if (! $draftMatches) {
            NewProductDraft::withoutEvents(function () use ($draft, $compareAt): void {
                $draft->forceFill([
                    'variant_compare_at_price' => $compareAt,
                ])->save();
            });
            $draft->refresh();
        }

        $warningCleared = $this->clearCompareAtClash($draft);
        $productUpdated = $this->writeProductCompareAt($draft, $compareAt);
        if (! $draftMatches) {
            app(NewProductDraftProductSync::class)->syncToExistingProduct(
                $draft->fresh() ?? $draft,
                ensureApprovalReset: true,
                attributes: ['variant_compare_at_price'],
            );
        }

        if ($draftMatches && ! $warningCleared && ! $productUpdated) {
            return ['updated' => false, 'compare_at' => $compareAt, 'message' => ''];
        }

        return [
            'updated' => true,
            'compare_at' => $compareAt,
            'message' => "{$label}: compare-at price set to {$compareAt} from component prices.",
        ];
    }

    /**
     * @param  array<int, int>  $componentIds
     */
    public function compareAtForComponentIds(array $componentIds): ?string
    {
        if ($componentIds === []) {
            return null;
        }

        $total = 0.0;
        foreach ($componentIds as $componentId) {
            $price = Variant::query()
                ->where('product_id', $componentId)
                ->orderBy('id')
                ->value('price');
            if ($price === null || trim((string) $price) === '') {
                return null;
            }

            $total += (float) $price;
        }

        return number_format($total, 2, '.', '');
    }

    /**
     * @return array<int, int>
     */
    private function componentIds(NewProductDraft $draft): array
    {
        $ids = [];
        foreach ((array) $draft->bundle_product_ids as $id) {
            if (is_numeric($id) && (int) $id > 0) {
                $ids[] = (int) $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private function productIdForDraft(NewProductDraft $draft): ?int
    {
        $shopifyId = trim((string) $draft->shopify_id);
        if ($shopifyId !== '') {
            $id = Product::query()->where('shopify_id', $shopifyId)->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        $handle = trim((string) $draft->handle);
        if ($handle !== '') {
            $id = Product::query()->where('handle', $handle)->value('id');
            if ($id) {
                return (int) $id;
            }
        }

        $sku = trim((string) $draft->sku);
        if ($sku !== '') {
            $id = Variant::query()
                ->whereRaw('LOWER(TRIM(sku)) = ?', [strtolower($sku)])
                ->value('product_id');
            if ($id) {
                return (int) $id;
            }
        }

        return null;
    }

    private function writeProductCompareAt(NewProductDraft $draft, string $compareAt): bool
    {
        $productId = $this->productIdForDraft($draft);
        if ($productId === null) {
            return false;
        }

        $updated = false;
        foreach (Variant::query()->where('product_id', $productId)->orderBy('id')->get() as $variant) {
            if ($this->moneyEquals($variant->compare_at_price, $compareAt) && $variant->sync_state !== Variant::SYNC_STATE_CONFLICT) {
                continue;
            }

            $updates = ['compare_at_price' => $compareAt];
            if ($variant->sync_state === Variant::SYNC_STATE_CONFLICT) {
                $updates['sync_state'] = Variant::SYNC_STATE_LOCAL_UPDATED;
                $updates['local_dirty'] = true;
            }
            $variant->update($updates);
            $updated = true;
        }

        return $updated;
    }

    private function moneyEquals(mixed $current, string $next): bool
    {
        if ($current === null || trim((string) $current) === '') {
            return false;
        }

        return number_format((float) $current, 2, '.', '') === $next;
    }

    private function clearCompareAtClash(NewProductDraft $draft): bool
    {
        if (! NewProductDraft::supportsShopifySyncWarningsColumn()) {
            return false;
        }

        $draft->refresh();
        $remaining = [];
        foreach ($draft->shopifySyncWarnings() as $warning) {
            if (! is_array($warning)) {
                continue;
            }

            if (trim((string) ($warning['field'] ?? '')) === 'variant_compare_at_price') {
                continue;
            }

            $remaining[] = $warning;
        }

        if (count($remaining) === count($draft->shopifySyncWarnings())) {
            return false;
        }

        NewProductDraft::withoutEvents(function () use ($draft, $remaining): void {
            $draft->forceFill([
                'shopify_sync_warnings' => $remaining === [] ? null : $remaining,
            ])->save();
        });

        return true;
    }
}
