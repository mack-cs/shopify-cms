<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Variant;
use App\Models\ShopifyAudit;
use Illuminate\Support\Collection;

final class ComplementaryProductReconciliationService
{
    public function __construct(
        private readonly ProductInventorySyncService $inventorySyncService,
        private readonly ProductSellabilityService $sellabilityService,
        private readonly ComplementaryDependencyService $dependencyService,
        private readonly ComplementaryProductAuditService $auditService,
        private readonly ComplementaryProductMaintenanceService $maintenanceService,
        private readonly ProductShopifyUpdater $shopifyUpdater,
    ) {
    }

    /**
     * @return array{
     *   products_checked:int,
     *   variants_refreshed:int,
     *   sellability_changed:int,
     *   complementary_synced:int,
     *   shortage_count:int,
     *   warnings:array<int, string>,
     *   failures:array<int, string>
     * }
     */
    public function run(?int $userId = null): array
    {
        $products = Product::query()
            ->select(['id', 'import_id', 'handle', 'shopify_id', 'status', 'title'])
            ->whereIn(\DB::raw('LOWER(COALESCE(status, ""))'), ['active', 'draft'])
            ->where(function ($query): void {
                $query->whereNotNull('shopify_id')
                    ->where('shopify_id', '!=', '')
                    ->orWhere(function ($handleQuery): void {
                        $handleQuery->whereNotNull('handle')
                            ->where('handle', '!=', '');
                    });
            })
            ->with(['variants' => fn ($query) => $query->orderBy('id')])
            ->get();

        $beforeSellability = [];
        foreach ($products as $product) {
            if (!$product instanceof Product) {
                continue;
            }

            $beforeSellability[(int) $product->id] = [
                'sellable' => $this->sellabilityService->isLocallySellable($product),
                'reason' => $this->sellabilityService->eligibilityReason($product),
            ];
        }

        $variants = $products
            ->flatMap(fn (Product $product): Collection => $product->variants instanceof Collection ? $product->variants : collect())
            ->filter(fn ($variant): bool => $variant instanceof Variant)
            ->values();

        $refreshResult = $this->inventorySyncService->refreshVariants($variants, $userId);

        $freshProducts = Product::query()
            ->select(['id', 'import_id', 'handle', 'shopify_id', 'status', 'title'])
            ->whereIn('id', $products->pluck('id')->all())
            ->with(['variants' => fn ($query) => $query->orderBy('id')])
            ->get()
            ->keyBy('id');

        $changedChildren = collect();

        foreach ($freshProducts as $productId => $product) {
            if (!$product instanceof Product) {
                continue;
            }

            $before = $beforeSellability[(int) $productId] ?? null;
            $afterSellable = $this->sellabilityService->isLocallySellable($product);
            $afterReason = $this->sellabilityService->eligibilityReason($product);

            if ($before === null) {
                continue;
            }

            if (
                (bool) ($before['sellable'] ?? false) !== $afterSellable
                || (string) ($before['reason'] ?? '') !== (string) ($afterReason ?? '')
            ) {
                $changedChildren->push($product);
            }
        }

        $syncTargets = collect();

        foreach ($changedChildren as $child) {
            if (!$child instanceof Product) {
                continue;
            }

            $syncTargets = $syncTargets->merge($this->dependencyService->productsReferencingProduct($child));
        }

        $attentionProducts = collect($this->auditService->productsNeedingShopifyComplementaryAttention());
        $syncTargets = $syncTargets
            ->merge($attentionProducts)
            ->filter(fn ($product): bool => $product instanceof Product)
            ->unique(fn (Product $product): int => (int) $product->id)
            ->values();

        $syncResult = [
            'updated' => 0,
            'warnings' => [],
            'failures' => [],
        ];

        if ($syncTargets->isNotEmpty()) {
            $syncResult = $this->shopifyUpdater->syncComplementaryProducts($syncTargets, $userId);
        }

        $this->maintenanceService->runDailyCheck();

        $shortageCount = ShopifyAudit::query()
            ->where('audit_type', ShopifyAudit::TYPE_COMPLEMENTARY_PRODUCTS)
            ->where('needs_attention', true)
            ->where(function ($query): void {
                $query
                    ->where('shopify_current_count', '<', ComplementaryProductAuditService::SHOPIFY_TARGET_COUNT)
                    ->orWhere('shopify_valid_count', '<', ComplementaryProductAuditService::SHOPIFY_TARGET_COUNT);
            })
            ->count();

        $warnings = array_values(array_filter(array_merge(
            $refreshResult['warnings'] ?? [],
            array_map(
                static fn (array $warning): string => (string) ($warning['warning'] ?? ''),
                is_array($syncResult['warnings'] ?? null) ? $syncResult['warnings'] : []
            )
        )));

        $failures = array_values(array_filter(array_merge(
            $refreshResult['failures'] ?? [],
            array_map(
                static fn (array $failure): string => (string) ($failure['details'] ?? ''),
                is_array($syncResult['failures'] ?? null) ? $syncResult['failures'] : []
            )
        )));

        return [
            'products_checked' => $products->count(),
            'variants_refreshed' => (int) ($refreshResult['refreshed'] ?? 0),
            'sellability_changed' => $changedChildren->count(),
            'complementary_synced' => (int) ($syncResult['updated'] ?? 0),
            'shortage_count' => $shortageCount,
            'warnings' => $warnings,
            'failures' => $failures,
        ];
    }
}
