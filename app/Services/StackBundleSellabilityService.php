<?php

namespace App\Services;

use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ProductInventorySnapshot;
use App\Models\Variant;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

final class StackBundleSellabilityService
{
    public function __construct(
        private readonly ProductSellabilityService $sellabilityService,
        private readonly ProductInventoryHistoryRecorder $historyRecorder,
        private readonly ProductInventorySyncService $inventorySyncService,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function enforce(?int $userId = null, bool $dryRun = false, array $options = []): array
    {
        $testOnly = (bool) ($options['test_only'] ?? false);
        $testToken = strtolower(trim((string) ($options['test_token'] ?? 'test')));
        $testToken = $testToken !== '' ? $testToken : 'test';
        $requireTestComponents = (bool) ($options['require_test_components'] ?? false);
        $refreshComponents = (bool) ($options['refresh_components'] ?? false);
        $refreshedComponentProductIds = [];
        $result = $this->emptyResult($dryRun);

        NewProductDraft::query()
            ->whereNotNull('bundle_product_ids')
            ->orderBy('id')
            ->chunkById(100, function ($drafts) use (
                &$result,
                &$refreshedComponentProductIds,
                $userId,
                $dryRun,
                $testOnly,
                $testToken,
                $requireTestComponents,
                $refreshComponents,
            ): void {
                foreach ($drafts as $draft) {
                    if (!$draft instanceof NewProductDraft) {
                        continue;
                    }

                    $this->mergeResult($result, $this->enforceDraftWithLock(
                        $draft,
                        $userId,
                        $dryRun,
                        $testOnly,
                        $testToken,
                        $requireTestComponents,
                        $refreshComponents,
                        $refreshedComponentProductIds,
                    ));
                }
            });

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function enforceForInventoryItem(string $inventoryItemId, ?int $userId = null, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $result = $this->emptyResult($dryRun);
        $inventoryItemId = trim($inventoryItemId);

        if ($inventoryItemId === '') {
            $result['missing_inventory_item_variant']++;

            return $result;
        }

        $variant = Variant::query()
            ->with('product.variants')
            ->whereIn('shopify_inventory_item_id', $this->inventoryItemCandidates($inventoryItemId))
            ->orderBy('id')
            ->first();

        if (!$variant instanceof Variant || !$variant->product instanceof Product) {
            $result['missing_inventory_item_variant']++;

            return $result;
        }

        $component = $variant->product;
        $result['component'] = $this->componentPayload($component);

        if ($this->isStackProduct($component)) {
            $result['skipped_stack_product_webhook']++;

            return $result;
        }

        $draftIds = NewProductDraft::query()
            ->whereJsonContains('bundle_product_ids', (int) $component->id)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        $summary = $this->enforceForAffectedStacks($draftIds, $userId, array_merge([
            'dry_run' => $dryRun,
            'refresh_components' => true,
        ], $options));

        $summary['component'] = $result['component'];

        return $summary;
    }

    /**
     * @param iterable<int, int> $draftIds
     * @return array<string, mixed>
     */
    public function enforceForAffectedStacks(iterable $draftIds, ?int $userId = null, array $options = []): array
    {
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $testOnly = (bool) ($options['test_only'] ?? false);
        $testToken = strtolower(trim((string) ($options['test_token'] ?? 'test')));
        $testToken = $testToken !== '' ? $testToken : 'test';
        $requireTestComponents = (bool) ($options['require_test_components'] ?? false);
        $refreshComponents = (bool) ($options['refresh_components'] ?? false);
        $refreshedComponentProductIds = [];
        $result = $this->emptyResult($dryRun);

        $ids = collect($draftIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return $result;
        }

        $drafts = NewProductDraft::query()
            ->whereIn('id', $ids->all())
            ->whereNotNull('bundle_product_ids')
            ->orderBy('id')
            ->get();

        $result['affected_stacks'] = $drafts->count();

        foreach ($drafts as $draft) {
            if (!$draft instanceof NewProductDraft) {
                continue;
            }

            $this->mergeResult($result, $this->enforceDraftWithLock(
                $draft,
                $userId,
                $dryRun,
                $testOnly,
                $testToken,
                $requireTestComponents,
                $refreshComponents,
                $refreshedComponentProductIds,
            ));
        }

        return $result;
    }

    /**
     * @param array<int, bool> $refreshedComponentProductIds
     * @return array<string, mixed>
     */
    private function enforceDraftWithLock(
        NewProductDraft $draft,
        ?int $userId,
        bool $dryRun,
        bool $testOnly,
        string $testToken,
        bool $requireTestComponents,
        bool $refreshComponents,
        array &$refreshedComponentProductIds,
    ): array {
        if ($dryRun) {
            return $this->enforceDraft(
                $draft,
                $userId,
                $dryRun,
                $testOnly,
                $testToken,
                $requireTestComponents,
                $refreshComponents,
                $refreshedComponentProductIds,
            );
        }

        $lock = Cache::lock('stack-sellability:draft:' . $draft->id, 120);

        if (!$lock->get()) {
            $result = $this->emptyResult($dryRun);
            $result['skipped_locked_stacks']++;

            return $result;
        }

        try {
            return $this->enforceDraft(
                $draft,
                $userId,
                $dryRun,
                $testOnly,
                $testToken,
                $requireTestComponents,
                $refreshComponents,
                $refreshedComponentProductIds,
            );
        } finally {
            $lock->release();
        }
    }

    /**
     * @param array<int, bool> $refreshedComponentProductIds
     * @return array<string, mixed>
     */
    private function enforceDraft(
        NewProductDraft $draft,
        ?int $userId,
        bool $dryRun,
        bool $testOnly,
        string $testToken,
        bool $requireTestComponents,
        bool $refreshComponents,
        array &$refreshedComponentProductIds,
    ): array {
        $result = $this->emptyResult($dryRun);
        $result['checked']++;

        if ($testOnly && !$this->isTestDraft($draft, $testToken)) {
            $result['skipped_non_test_stack']++;

            return $result;
        }

        $componentIds = $this->normalizeProductIds($draft->bundle_product_ids);
        if ($componentIds === []) {
            return $result;
        }

        $result['with_associations']++;
        $components = $this->loadComponents($componentIds);
        $missingComponentCount = count($componentIds) - $components->count();

        if ($missingComponentCount > 0) {
            $result['missing_components'] += $missingComponentCount;
        }

        if ($testOnly && $requireTestComponents) {
            $nonTestComponents = $components
                ->filter(fn (Product $product): bool => !$this->isTestProduct($product, $testToken))
                ->count();

            if ($missingComponentCount > 0 || $nonTestComponents > 0) {
                $result['skipped_non_test_components'] += $missingComponentCount + $nonTestComponents;

                return $result;
            }
        }

        if ($refreshComponents && !$dryRun) {
            $refresh = $this->refreshComponentsFromShopify($componentIds, $refreshedComponentProductIds, $userId);
            $result['shopify_component_refreshes'] += $refresh['refreshed'];
            $result['shopify_component_refresh_failures'] += $refresh['failed'];

            if ($refresh['failed'] > 0) {
                $result['shopify_refresh_failed_stacks']++;

                return $result;
            }

            $components = $this->loadComponents($componentIds);
            $missingComponentCount = count($componentIds) - $components->count();
        }

        $componentIssue = $this->firstComponentIssue($componentIds, $components);

        if ($componentIssue !== null) {
            return $this->forceUnsellableForIssue($draft, $componentIssue, $userId, $dryRun, $result);
        }

        $result['all_components_sellable']++;

        return $this->restoreSellable($draft, $userId, $dryRun, $result);
    }

    /**
     * @param array<string, mixed> $componentIssue
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function forceUnsellableForIssue(
        NewProductDraft $draft,
        array $componentIssue,
        ?int $userId,
        bool $dryRun,
        array $result,
    ): array {
        $stackProduct = $this->findProductForDraft($draft);
        if (!$stackProduct instanceof Product) {
            $result['missing_stack_product']++;
        }

        $changed = $this->draftNeedsUnsellableUpdate($draft)
            || ($stackProduct instanceof Product && $this->productNeedsUnsellableUpdate($stackProduct));

        if (!$changed) {
            $result['already_unsellable']++;

            return $result;
        }

        if (!$dryRun) {
            $this->forceDraftUnsellable($draft);

            if ($stackProduct instanceof Product) {
                $this->forceProductUnsellable($stackProduct);
                $this->recordStackSnapshot($stackProduct, $userId);
            }
        }

        $result['forced_unsellable']++;
        $result['changes'][] = [
            'action' => 'disabled',
            'stack' => $this->stackPayload($draft, $stackProduct),
            'component' => $componentIssue,
        ];

        return $result;
    }

    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    private function restoreSellable(NewProductDraft $draft, ?int $userId, bool $dryRun, array $result): array
    {
        $stackProduct = $this->findProductForDraft($draft);
        if (!$stackProduct instanceof Product) {
            $result['missing_stack_product']++;
        }

        $changed = $this->draftNeedsSellableRestore($draft)
            || ($stackProduct instanceof Product && $this->productNeedsSellableRestore($stackProduct));

        if (!$changed) {
            $result['already_sellable']++;

            return $result;
        }

        if (!$dryRun) {
            $this->restoreDraftSellable($draft);

            if ($stackProduct instanceof Product) {
                $this->restoreProductSellable($stackProduct);
                $this->recordStackSnapshot($stackProduct, $userId);
            }
        }

        $result['restored_sellable']++;
        $result['changes'][] = [
            'action' => 'restored',
            'stack' => $this->stackPayload($draft, $stackProduct),
            'component' => null,
        ];

        return $result;
    }

    /**
     * @param array<int, int> $componentIds
     * @return Collection<int, Product>
     */
    private function loadComponents(array $componentIds): Collection
    {
        return Product::query()
            ->with('variants')
            ->whereIn('id', $componentIds)
            ->get()
            ->keyBy('id');
    }

    /**
     * @param array<int, int> $componentIds
     * @param Collection<int, Product> $components
     * @return array<string, mixed>|null
     */
    private function firstComponentIssue(array $componentIds, Collection $components): ?array
    {
        foreach ($componentIds as $componentId) {
            $component = $components->get($componentId);

            if (!$component instanceof Product) {
                return [
                    'product_id' => $componentId,
                    'title' => 'Product #' . $componentId,
                    'handle' => null,
                    'sku' => null,
                    'reason' => 'Component product is missing locally',
                    'current_stock' => null,
                ];
            }

            if (!$this->sellabilityService->isLocallySellable($component)) {
                return $this->componentPayload($component);
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function componentPayload(Product $product): array
    {
        $variant = $this->primaryVariant($product);

        return [
            'product_id' => (int) $product->id,
            'title' => trim((string) ($product->title ?? '')) ?: 'Product #' . $product->id,
            'handle' => trim((string) ($product->handle ?? '')) ?: null,
            'sku' => $variant instanceof Variant ? (trim((string) ($variant->sku ?? '')) ?: null) : null,
            'reason' => $this->sellabilityService->eligibilityReason($product) ?? 'Component is not sellable',
            'current_stock' => $variant instanceof Variant && $variant->inventory_tracked !== false && $variant->inventory_qty !== null
                ? (int) $variant->inventory_qty
                : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function stackPayload(NewProductDraft $draft, ?Product $product): array
    {
        return [
            'draft_id' => (int) $draft->id,
            'product_id' => $product instanceof Product ? (int) $product->id : null,
            'title' => trim((string) ($product?->title ?? $draft->title ?? '')) ?: 'Stack #' . $draft->id,
            'handle' => trim((string) ($product?->handle ?? $draft->handle ?? '')) ?: null,
            'sku' => trim((string) ($draft->sku ?? '')) ?: null,
        ];
    }

    /**
     * @param array<int, int> $componentIds
     * @param array<int, bool> $refreshedComponentProductIds
     * @return array{refreshed:int,failed:int}
     */
    private function refreshComponentsFromShopify(array $componentIds, array &$refreshedComponentProductIds, ?int $userId): array
    {
        $idsToRefresh = [];

        foreach ($componentIds as $productId) {
            if (isset($refreshedComponentProductIds[$productId])) {
                continue;
            }

            $idsToRefresh[] = $productId;
        }

        if ($idsToRefresh === []) {
            return ['refreshed' => 0, 'failed' => 0];
        }

        $variants = Variant::query()
            ->whereIn('product_id', $idsToRefresh)
            ->orderBy('product_id')
            ->orderBy('id')
            ->get();

        if ($variants->isEmpty()) {
            foreach ($idsToRefresh as $productId) {
                $refreshedComponentProductIds[$productId] = true;
            }

            return ['refreshed' => 0, 'failed' => 0];
        }

        $summary = $this->inventorySyncService->refreshVariants($variants, $userId);
        $failed = (int) ($summary['failed'] ?? 0);

        if ($failed === 0) {
            foreach ($idsToRefresh as $productId) {
                $refreshedComponentProductIds[$productId] = true;
            }
        }

        return [
            'refreshed' => (int) ($summary['refreshed'] ?? 0),
            'failed' => $failed,
        ];
    }

    private function findProductForDraft(NewProductDraft $draft): ?Product
    {
        $shopifyId = trim((string) ($draft->shopify_id ?? ''));
        if ($shopifyId !== '') {
            $product = Product::query()
                ->where('shopify_id', $shopifyId)
                ->first();

            if ($product instanceof Product) {
                return $product;
            }
        }

        $handle = trim((string) ($draft->handle ?? ''));
        if ($handle === '') {
            return null;
        }

        return Product::query()
            ->where('handle', $handle)
            ->first();
    }

    private function draftNeedsUnsellableUpdate(NewProductDraft $draft): bool
    {
        return $draft->variant_inventory_qty !== 0;
    }

    private function draftNeedsSellableRestore(NewProductDraft $draft): bool
    {
        return $draft->variant_inventory_qty !== null;
    }

    private function productNeedsUnsellableUpdate(Product $product): bool
    {
        $variant = $this->primaryVariant($product);

        return $variant instanceof Variant
            && ($variant->inventory_tracked !== true || (int) ($variant->inventory_qty ?? 0) !== 0);
    }

    private function productNeedsSellableRestore(Product $product): bool
    {
        $variant = $this->primaryVariant($product);

        return $variant instanceof Variant
            && ($variant->inventory_tracked !== false || $variant->inventory_qty !== null);
    }

    private function forceDraftUnsellable(NewProductDraft $draft): void
    {
        if (!$this->draftNeedsUnsellableUpdate($draft)) {
            return;
        }

        NewProductDraft::withoutEvents(function () use ($draft): void {
            $draft->forceFill(['variant_inventory_qty' => 0])->save();
        });
    }

    private function restoreDraftSellable(NewProductDraft $draft): void
    {
        if (!$this->draftNeedsSellableRestore($draft)) {
            return;
        }

        NewProductDraft::withoutEvents(function () use ($draft): void {
            $draft->forceFill(['variant_inventory_qty' => null])->save();
        });
    }

    private function forceProductUnsellable(Product $product): void
    {
        $variant = $this->primaryVariant($product);
        if (!$variant instanceof Variant || !$this->productNeedsUnsellableUpdate($product)) {
            return;
        }

        InventoryOperationContext::run(function () use ($variant): void {
            $variant->inventory_tracked = true;
            $variant->inventory_qty = 0;
            $variant->inventory_sync_error = null;
            $variant->save();
        });
    }

    private function restoreProductSellable(Product $product): void
    {
        $variant = $this->primaryVariant($product);
        if (!$variant instanceof Variant || !$this->productNeedsSellableRestore($product)) {
            return;
        }

        InventoryOperationContext::run(function () use ($variant): void {
            $variant->inventory_tracked = false;
            $variant->inventory_qty = null;
            $variant->inventory_sync_error = null;
            $variant->save();
        });
    }

    private function recordStackSnapshot(Product $stackProduct, ?int $userId): void
    {
        $freshProduct = $stackProduct->fresh(['variants']);
        if (!$freshProduct instanceof Product) {
            return;
        }

        $this->historyRecorder->record(
            $freshProduct,
            $userId,
            ProductInventorySnapshot::SOURCE_BUNDLE_COMPONENT_RULE,
        );
    }

    private function primaryVariant(Product $product): ?Variant
    {
        $product = $product->relationLoaded('variants')
            ? $product
            : $product->loadMissing('variants');

        $variant = $product->variants
            ->filter(fn ($variant): bool => $variant instanceof Variant)
            ->sortBy('id')
            ->first();

        return $variant instanceof Variant ? $variant : null;
    }

    private function isStackProduct(Product $product): bool
    {
        if ((bool) ($product->is_bundle ?? false)) {
            return true;
        }

        $tags = TagNormalizer::parseTokens((string) ($product->tags ?? ''));
        foreach ($tags as $tag) {
            if (
                in_array($tag, ['bundle', 'bundles', 'stack', 'stacks'], true)
                || str_ends_with($tag, '-bundle')
                || str_ends_with($tag, '-bundles')
                || str_ends_with($tag, '-stack')
                || str_ends_with($tag, '-stacks')
            ) {
                return true;
            }
        }

        $shopifyId = trim((string) ($product->shopify_id ?? ''));
        $handle = trim((string) ($product->handle ?? ''));

        if ($shopifyId === '' && $handle === '') {
            return false;
        }

        return NewProductDraft::query()
            ->whereNotNull('bundle_product_ids')
            ->where(function ($query) use ($shopifyId, $handle): void {
                if ($shopifyId !== '') {
                    $query->orWhere('shopify_id', $shopifyId);
                }

                if ($handle !== '') {
                    $query->orWhere('handle', $handle);
                }
            })
            ->get()
            ->contains(fn (NewProductDraft $draft): bool => $this->normalizeProductIds($draft->bundle_product_ids) !== []);
    }

    private function isTestDraft(NewProductDraft $draft, string $testToken): bool
    {
        return $this->containsTestToken($draft->title, $testToken)
            || $this->containsTestToken($draft->handle, $testToken)
            || $this->containsTestToken($draft->sku, $testToken);
    }

    private function isTestProduct(Product $product, string $testToken): bool
    {
        $product = $product->relationLoaded('variants')
            ? $product
            : $product->loadMissing('variants');

        return $this->containsTestToken($product->title, $testToken)
            || $this->containsTestToken($product->handle, $testToken)
            || $product->variants->contains(fn (Variant $variant): bool => $this->containsTestToken($variant->sku, $testToken));
    }

    private function containsTestToken(mixed $value, string $testToken): bool
    {
        return str_contains(strtolower((string) $value), $testToken);
    }

    /**
     * @return array<int, int>
     */
    private function normalizeProductIds(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach ($value as $id) {
            $id = (int) $id;
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $normalized[] = $id;
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function inventoryItemCandidates(string $inventoryItemId): array
    {
        $id = trim($inventoryItemId);
        $candidates = [$id];

        if (preg_match('/^gid:\/\/shopify\/InventoryItem\/(\d+)$/', $id, $matches) === 1) {
            $candidates[] = $matches[1];
        } elseif (preg_match('/^\d+$/', $id) === 1) {
            $candidates[] = 'gid://shopify/InventoryItem/' . $id;
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyResult(bool $dryRun): array
    {
        return [
            'checked' => 0,
            'with_associations' => 0,
            'affected_stacks' => 0,
            'all_components_sellable' => 0,
            'missing_components' => 0,
            'forced_unsellable' => 0,
            'already_unsellable' => 0,
            'restored_sellable' => 0,
            'already_sellable' => 0,
            'missing_stack_product' => 0,
            'skipped_non_test_stack' => 0,
            'skipped_non_test_components' => 0,
            'skipped_locked_stacks' => 0,
            'skipped_stack_product_webhook' => 0,
            'missing_inventory_item_variant' => 0,
            'shopify_component_refreshes' => 0,
            'shopify_component_refresh_failures' => 0,
            'shopify_refresh_failed_stacks' => 0,
            'changes' => [],
            'dry_run' => $dryRun,
        ];
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $source
     */
    private function mergeResult(array &$target, array $source): void
    {
        foreach ($source as $key => $value) {
            if ($key === 'dry_run') {
                continue;
            }

            if ($key === 'changes' && is_array($value)) {
                $target['changes'] = array_merge($target['changes'], $value);
                continue;
            }

            if (is_int($value) && array_key_exists($key, $target)) {
                $target[$key] += $value;
            }
        }
    }
}
