<?php

namespace App\Services;

use App\Models\ChangeLog;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyRow;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ShopYourVibeComplementaryService
{
    public function __construct(
        private readonly ComplementaryProductAuditService $audit,
        private readonly ProductSellabilityService $sellability,
        private readonly ProductShopifyUpdater $productUpdater,
    ) {}

    public function productForGid(string $productGid): Product
    {
        $product = Product::query()
            ->where('shopify_id', $productGid)
            ->latest('id')
            ->first();

        if (! $product instanceof Product) {
            throw new RuntimeException('This product is not available locally. Refresh from Shopify and retry.');
        }

        return $product;
    }

    /**
     * @return array{product:array<string,mixed>,selected:array<int,array<string,mixed>>,errors:array<int,string>}
     */
    public function stateForProductGid(string $productGid): array
    {
        $product = $this->productForGid($productGid);

        return [
            'product' => $this->productSummary($product),
            'selected' => $this->selectedForProduct($product),
            'errors' => [],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function selectedForProduct(Product $product): array
    {
        $ids = $this->audit->resolveProductIdsFromTokens(
            $this->audit->parseReferenceTokens($this->audit->localComplementaryValueForProduct($product))
        );

        $rows = Product::query()
            ->whereKey($ids)
            ->with(['images' => fn ($query) => $query->orderBy('position')->orderBy('id'), 'variants' => fn ($query) => $query->orderBy('id')])
            ->get()
            ->keyBy('id')
            ->pipe(function (Collection $products) use ($ids): array {
                $rows = [];
                foreach ($ids as $id) {
                    $product = $products->get($id);
                    if ($product instanceof Product) {
                        $rows[] = $this->productSummary($product);
                    }
                }

                return $rows;
            });

        return $this->withSyncStatuses($rows);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, ?string $excludeGid = null, array $selectedGids = []): array
    {
        $needle = trim($query);
        if ($needle === '') {
            return [];
        }

        $selected = array_fill_keys(array_filter(array_map('strval', $selectedGids)), true);

        return Product::query()
            ->whereNotNull('shopify_id')
            ->when($excludeGid, fn (Builder $query): Builder => $query->where('shopify_id', '!=', $excludeGid))
            ->where(function (Builder $query) use ($needle): void {
                $query->where('title', 'like', '%' . $needle . '%')
                    ->orWhereHas('variants', fn (Builder $variants): Builder => $variants->where('sku', 'like', '%' . $needle . '%'));
            })
            ->with(['images' => fn ($query) => $query->orderBy('position')->orderBy('id'), 'variants' => fn ($query) => $query->orderBy('id')])
            ->orderBy('title')
            ->limit(20)
            ->get()
            ->unique('shopify_id')
            ->reject(fn (Product $product): bool => isset($selected[(string) $product->shopify_id]))
            ->map(fn (Product $product): array => $this->productSummary($product))
            ->values()
            ->all();
    }

    /**
     * @param array<int, string> $currentGids
     * @return array{selected:array<int, array<string, mixed>>,errors:array<int, string>}
     */
    public function addTokens(string $ownerGid, array $currentGids, string $rawTokens): array
    {
        $errors = [];
        $selectedIds = $this->idsForGids($currentGids);
        $selected = Product::query()
            ->whereKey($selectedIds)
            ->with(['images' => fn ($query) => $query->orderBy('position')->orderBy('id'), 'variants' => fn ($query) => $query->orderBy('id')])
            ->get()
            ->keyBy('id');

        foreach ($this->audit->parseReferenceTokens($rawTokens) as $token) {
            $product = $this->resolveToken($token);
            if (! $product instanceof Product) {
                $errors[] = "Could not find product for {$token}.";
                continue;
            }

            if ((string) $product->shopify_id === $ownerGid) {
                $errors[] = "Skipped {$token}: a product cannot complement itself.";
                continue;
            }

            if (in_array((int) $product->id, $selectedIds, true)) {
                $errors[] = "Skipped {$token}: already selected.";
                continue;
            }

            $selectedIds[] = (int) $product->id;
            $selected->put((int) $product->id, $product->loadMissing(['images', 'variants']));
        }

        $rows = [];
        foreach ($selectedIds as $id) {
            $product = $selected->get($id);
            if ($product instanceof Product) {
                $rows[] = $this->productSummary($product);
            }
        }

        return ['selected' => $this->withSyncStatuses($rows), 'errors' => $errors];
    }

    /**
     * @param array<int, string> $orderedGids
     * @return array{product:Product,selected:array<int,array<string,mixed>>}
     */
    public function saveLocal(string $productGid, array $orderedGids, ?int $userId = null): array
    {
        $product = $this->productForGid($productGid);
        $row = $this->primaryRow($product);
        if (! $row instanceof ShopifyRow) {
            $row = ShopifyRow::create([
                'import_id' => $product->import_id,
                'row_index' => (int) (ShopifyRow::query()->where('import_id', $product->import_id)->max('row_index') ?? 0) + 1,
                'handle' => $product->handle,
                'row_type' => 'product_primary',
                'data' => [],
            ]);
        }

        $before = trim((string) ($row->get(HeaderStore::COMPLEMENTARY_PRODUCTS, '') ?? ''));
        $gids = collect($orderedGids)
            ->map(fn (mixed $gid): string => trim((string) $gid))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if (in_array($productGid, $gids, true)) {
            throw new RuntimeException('A product cannot complement itself.');
        }

        $products = Product::query()
            ->whereIn('shopify_id', $gids)
            ->with(['images' => fn ($query) => $query->orderBy('position')->orderBy('id'), 'variants' => fn ($query) => $query->orderBy('id')])
            ->get()
            ->keyBy('shopify_id');

        $missing = collect($gids)->reject(fn (string $gid): bool => $products->has($gid))->values()->all();
        if ($missing !== []) {
            throw new RuntimeException('One or more selected complementary products are no longer available locally.');
        }

        $ordered = [];
        foreach ($gids as $gid) {
            $ordered[] = $gid;
        }
        $value = $ordered === [] ? '[]' : implode('; ', $ordered);

        DB::transaction(function () use ($product, $row, $value, $before, $userId): void {
            $row->set(HeaderStore::COMPLEMENTARY_PRODUCTS, $value);
            $row->save();
            $this->mirrorDrafts($product, $value);
            $this->logChange($product, $before, $value, $userId);
        });

        app(Normalizer::class)->recalculateErrorsForProduct($product->fresh());

        return [
            'product' => $product->fresh(),
            'selected' => $this->selectedForProduct($product->fresh()),
        ];
    }

    public function pushToShopify(string $productGid, ?int $userId = null): array
    {
        $product = $this->productForGid($productGid);

        return $this->productUpdater->syncComplementaryProducts(collect([$product]), $userId);
    }

    /**
     * @param array<int, array<string, mixed>> $products
     * @return array<string, int>
     */
    public function countsByGid(array $products): array
    {
        $gids = collect($products)->pluck('id')->filter()->map(fn ($gid): string => trim((string) $gid))->filter()->unique()->values();
        if ($gids->isEmpty()) {
            return [];
        }

        return Product::query()
            ->whereIn('shopify_id', $gids->all())
            ->get(['id', 'shopify_id', 'import_id', 'handle'])
            ->mapWithKeys(function (Product $product): array {
                $ids = $this->audit->resolveProductIdsFromTokens(
                    $this->audit->parseReferenceTokens($this->audit->localComplementaryValueForProduct($product))
                );

                return [(string) $product->shopify_id => count($ids)];
            })
            ->all();
    }

    /**
     * @param array<int, string> $gids
     * @return array<int, int>
     */
    private function idsForGids(array $gids): array
    {
        if ($gids === []) {
            return [];
        }

        $idsByGid = Product::query()
            ->whereIn('shopify_id', $gids)
            ->pluck('id', 'shopify_id');

        return collect($gids)
            ->map(fn (string $gid): ?int => $idsByGid[$gid] ?? null)
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->values()
            ->all();
    }

    private function resolveToken(string $token): ?Product
    {
        $needle = trim($token);
        if ($needle === '') {
            return null;
        }

        $normalized = mb_strtolower($needle);

        $variantProductId = Variant::query()
            ->whereRaw('LOWER(TRIM(sku)) = ?', [$normalized])
            ->orderBy('id')
            ->value('product_id');

        if ($variantProductId) {
            return Product::query()->whereKey($variantProductId)->first();
        }

        return Product::query()
            ->where(function (Builder $query) use ($needle, $normalized): void {
                $query->where('shopify_id', $needle)
                    ->orWhereRaw('LOWER(TRIM(handle)) = ?', [$normalized])
                    ->orWhereRaw('LOWER(TRIM(title)) = ?', [$normalized]);
            })
            ->orderByDesc('id')
            ->first();
    }

    private function primaryRow(Product $product): ?ShopifyRow
    {
        return ShopifyRow::query()
            ->where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->where('row_type', 'product_primary')
            ->latest('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function productSummary(Product $product): array
    {
        $product->loadMissing(['images' => fn ($query) => $query->orderBy('position')->orderBy('id'), 'variants' => fn ($query) => $query->orderBy('id')]);
        $available = $this->sellability->isLocallySellable($product);

        return [
            'id' => (string) $product->shopify_id,
            'title' => (string) ($product->title ?? ''),
            'handle' => (string) ($product->handle ?? ''),
            'sku' => (string) ($product->variants->first()?->sku ?? ''),
            'image' => (string) ($product->images->first()?->src ?? ''),
            'available' => $available,
            'status' => (string) ($product->status ?? ''),
            'availability_label' => $available ? 'Sellable' : 'Not currently sellable',
            'will_sync' => false,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function withSyncStatuses(array $rows): array
    {
        $syncableSeen = 0;

        foreach ($rows as $index => $row) {
            $available = (bool) ($row['available'] ?? false);
            if ($available && $syncableSeen < ComplementaryProductAuditService::SHOPIFY_TARGET_COUNT) {
                $syncableSeen++;
                $rows[$index]['will_sync'] = true;
                $rows[$index]['availability_label'] = 'Will sync to Shopify';
                continue;
            }

            $rows[$index]['will_sync'] = false;
            $rows[$index]['availability_label'] = $available
                ? 'CMS only after first 3 sellable'
                : 'Skipped until sellable';
        }

        return $rows;
    }

    private function mirrorDrafts(Product $product, string $value): void
    {
        NewProductDraft::query()
            ->where(function ($query) use ($product): void {
                $shopifyId = trim((string) ($product->shopify_id ?? ''));
                $handle = trim((string) ($product->handle ?? ''));

                if ($shopifyId !== '') {
                    $query->where('shopify_id', $shopifyId);
                }
                if ($handle !== '') {
                    $query->orWhere('handle', $handle);
                }
            })
            ->update(['complementary_products' => $value !== '' ? $value : null]);
    }

    private function logChange(Product $product, string $before, string $after, ?int $userId): void
    {
        if ($before === $after) {
            return;
        }

        ChangeLog::create([
            'import_id' => $product->import_id,
            'product_id' => $product->id,
            'changed_by' => $userId,
            'source' => 'shop_your_vibe',
            'field' => 'complementary_products',
            'old_value' => $before,
            'new_value' => $after,
        ]);
    }
}
