<?php

namespace App\Services;

use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ProductInventorySnapshot;
use App\Models\Variant;

final class StackBundleSellabilityService
{
    public function __construct(
        private readonly ProductSellabilityService $sellabilityService,
        private readonly ProductInventoryHistoryRecorder $historyRecorder,
        private readonly ProductInventorySyncService $inventorySyncService,
    ) {
    }

    /**
     * @return array{
     *   checked:int,
     *   with_associations:int,
     *   all_components_sellable:int,
     *   missing_components:int,
     *   forced_unsellable:int,
     *   already_unsellable:int,
     *   missing_stack_product:int,
     *   skipped_non_test_stack:int,
     *   skipped_non_test_components:int,
     *   shopify_component_refreshes:int,
     *   shopify_component_refresh_failures:int,
     *   shopify_refresh_failed_stacks:int,
     *   dry_run:bool
     * }
     */
    public function enforce(?int $userId = null, bool $dryRun = false, array $options = []): array
    {
        $testOnly = (bool) ($options['test_only'] ?? false);
        $testToken = strtolower(trim((string) ($options['test_token'] ?? 'test')));
        $testToken = $testToken !== '' ? $testToken : 'test';
        $requireTestComponents = (bool) ($options['require_test_components'] ?? false);
        $refreshComponents = (bool) ($options['refresh_components'] ?? false);
        $refreshedComponentProductIds = [];

        $result = [
            'checked' => 0,
            'with_associations' => 0,
            'all_components_sellable' => 0,
            'missing_components' => 0,
            'forced_unsellable' => 0,
            'already_unsellable' => 0,
            'missing_stack_product' => 0,
            'skipped_non_test_stack' => 0,
            'skipped_non_test_components' => 0,
            'shopify_component_refreshes' => 0,
            'shopify_component_refresh_failures' => 0,
            'shopify_refresh_failed_stacks' => 0,
            'dry_run' => $dryRun,
        ];

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

                    $result['checked']++;
                    if ($testOnly && !$this->isTestDraft($draft, $testToken)) {
                        $result['skipped_non_test_stack']++;
                        continue;
                    }

                    $componentIds = $this->normalizeProductIds($draft->bundle_product_ids);
                    if ($componentIds === []) {
                        continue;
                    }

                    $result['with_associations']++;
                    $components = Product::query()
                        ->with('variants')
                        ->whereIn('id', $componentIds)
                        ->get()
                        ->keyBy('id');

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
                            continue;
                        }
                    }

                    if ($refreshComponents && !$dryRun) {
                        $refresh = $this->refreshComponentsFromShopify($componentIds, $refreshedComponentProductIds, $userId);
                        $result['shopify_component_refreshes'] += $refresh['refreshed'];
                        $result['shopify_component_refresh_failures'] += $refresh['failed'];

                        if ($refresh['failed'] > 0) {
                            $result['shopify_refresh_failed_stacks']++;
                            continue;
                        }

                        $components = Product::query()
                            ->with('variants')
                            ->whereIn('id', $componentIds)
                            ->get()
                            ->keyBy('id');
                    }

                    $hasUnsellableComponent = $missingComponentCount > 0
                        || $components->contains(fn (Product $product): bool => !$this->sellabilityService->isLocallySellable($product));

                    if (!$hasUnsellableComponent) {
                        $result['all_components_sellable']++;
                        continue;
                    }

                    $stackProduct = $this->findProductForDraft($draft);
                    if (!$stackProduct instanceof Product) {
                        $result['missing_stack_product']++;
                    }

                    $changed = $this->draftNeedsUnsellableUpdate($draft)
                        || ($stackProduct instanceof Product && $this->productNeedsUnsellableUpdate($stackProduct));

                    if (!$changed) {
                        $result['already_unsellable']++;
                        continue;
                    }

                    if (!$dryRun) {
                        $this->forceDraftUnsellable($draft);

                        if ($stackProduct instanceof Product) {
                            $this->forceProductUnsellable($stackProduct);
                            $freshProduct = $stackProduct->fresh(['variants']);
                            if ($freshProduct instanceof Product) {
                                $this->historyRecorder->record(
                                    $freshProduct,
                                    $userId,
                                    ProductInventorySnapshot::SOURCE_BUNDLE_COMPONENT_RULE,
                                );
                            }
                        }
                    }

                    $result['forced_unsellable']++;
                }
            });

        return $result;
    }

    /**
     * @param array<int,int> $componentIds
     * @param array<int,bool> $refreshedComponentProductIds
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

    private function productNeedsUnsellableUpdate(Product $product): bool
    {
        $variant = $this->primaryVariant($product);

        return $variant instanceof Variant
            && ($variant->inventory_tracked !== true || (int) ($variant->inventory_qty ?? 0) !== 0);
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
     * @return array<int,int>
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
}
