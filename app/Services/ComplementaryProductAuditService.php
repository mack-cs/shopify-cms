<?php

namespace App\Services;

use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyMetafield;
use App\Models\ShopifyRow;
use App\Models\Variant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class ComplementaryProductAuditService
{
    public const LOCAL_TARGET_COUNT = 4;
    public const SHOPIFY_TARGET_COUNT = 3;
    public const APP_NAMESPACE = '$app';
    public const APP_KEY = 'complementary_products';
    public const STANDARD_NAMESPACE = 'shopify--discovery--product_recommendation';
    public const STANDARD_KEY = 'complementary_products';

    /** @var array<string, int>|null */
    private ?array $productReferenceMap = null;

    /** @var array<string, array<int, array{gid:string,handle:string,title:string,status:string,available:bool,reason:string|null}>> */
    private array $liveComplementaryStatesByShopifyId = [];

    /** @var array<string, array{gid:string,handle:string,title:string,status:string,available:bool,reason:string|null}> */
    private array $liveProductStatesByGid = [];

    public function __construct(
        private readonly ShopifyApiClient $shopifyApiClient,
        private readonly ProductSellabilityService $sellabilityService,
    ) {
    }

    /**
     * @return array{
     *   local_total:int,
     *   local_good:bool,
     *   local_ids:array<int, int>,
     *   local_primary_ids:array<int, int>,
     *   local_eligible_ids:array<int, int>,
     *   local_eligible_gids:array<int, string>,
     *   local_ineligible:array<int, array{gid:string,handle:string,title:string,status:string,available:bool,reason:string|null}>,
     *   shopify_total:int,
     *   shopify_eligible:int,
     *   shopify_good:bool,
     *   shopify_ids:array<int, int>,
     *   shopify_eligible_ids:array<int, int>,
     *   shopify_missing_local_ids:array<int, int>,
     *   shopify_missing_local:array<int, array{gid:string,handle:string,title:string,status:string,available:bool,reason:string|null}>,
     *   shopify_ineligible:array<int, array{gid:string,handle:string,title:string,status:string,available:bool,reason:string|null}>,
     *   desired_shopify_gids:array<int, string>
     * }
     */
    public function analyzeProduct(
        Product $product,
        ?string $localValue = null,
    ): array {
        $localTokens = $this->parseReferenceTokens($localValue ?? $this->localComplementaryValueForProduct($product));

        $localIds = $this->resolveProductIdsFromTokens($localTokens);
        $localStatesById = $this->localStatesForProductIds($localIds);
        $localEligibleIds = array_values(array_map(
            fn (int $productId): int => $productId,
            array_keys(array_filter(
                $localStatesById,
                static fn (array $state): bool => (bool) ($state['available'] ?? false)
            ))
        ));
        $localPrimaryIds = array_slice($localEligibleIds, 0, self::SHOPIFY_TARGET_COUNT);
        $localIneligible = array_values(array_filter(
            array_map(
                fn (int $productId): ?array => ($localStatesById[$productId]['available'] ?? false) ? null : ($localStatesById[$productId] ?? null),
                $localIds
            )
        ));

        $shopifyStates = $this->liveComplementaryStatesForProduct($product);
        $shopifyIds = [];
        $shopifyEligibleIds = [];
        $shopifyIneligible = [];

        foreach ($shopifyStates as $state) {
            $resolvedId = $this->resolveProductIdFromLiveState($state);
            if ($resolvedId !== null && !in_array($resolvedId, $shopifyIds, true)) {
                $shopifyIds[] = $resolvedId;
            }

            if (($state['available'] ?? false) === true) {
                if ($resolvedId !== null && !in_array($resolvedId, $shopifyEligibleIds, true)) {
                    $shopifyEligibleIds[] = $resolvedId;
                }
            } else {
                $shopifyIneligible[] = $state;
            }
        }

        $shopifyMissingLocalIds = [];
        $shopifyMissingLocal = [];

        foreach ($shopifyStates as $state) {
            $resolvedId = $this->resolveProductIdFromLiveState($state);
            if ($resolvedId === null || in_array($resolvedId, $localPrimaryIds, true)) {
                continue;
            }

            if (!in_array($resolvedId, $shopifyMissingLocalIds, true)) {
                $shopifyMissingLocalIds[] = $resolvedId;
                $shopifyMissingLocal[] = $state;
            }
        }

        return [
            'local_total' => count($localIds),
            'local_good' => count($localIds) >= self::LOCAL_TARGET_COUNT,
            'local_ids' => $localIds,
            'local_primary_ids' => $localPrimaryIds,
            'local_eligible_ids' => $localEligibleIds,
            'local_eligible_gids' => $this->productIdsToShopifyGids($localEligibleIds),
            'local_ineligible' => $localIneligible,
            'shopify_total' => count($shopifyStates),
            'shopify_eligible' => count($shopifyEligibleIds),
            'shopify_good' => $shopifyIneligible === [] && $shopifyMissingLocalIds === [],
            'shopify_ids' => $shopifyIds,
            'shopify_eligible_ids' => $shopifyEligibleIds,
            'shopify_missing_local_ids' => $shopifyMissingLocalIds,
            'shopify_missing_local' => $shopifyMissingLocal,
            'shopify_ineligible' => $shopifyIneligible,
            'desired_shopify_gids' => $this->productIdsToShopifyGids($localPrimaryIds),
        ];
    }

    /**
     * @return array{
     *   local_total:int,
     *   local_good:bool,
     *   local_ids:array<int, int>,
     *   local_primary_ids:array<int, int>,
     *   local_eligible_ids:array<int, int>,
     *   local_eligible_gids:array<int, string>,
     *   local_ineligible:array<int, array{gid:string,handle:string,title:string,status:string,available:bool,reason:string|null}>,
     *   shopify_total:int,
     *   shopify_eligible:int,
     *   shopify_good:bool,
     *   shopify_ids:array<int, int>,
     *   shopify_eligible_ids:array<int, int>,
     *   shopify_missing_local_ids:array<int, int>,
     *   shopify_missing_local:array<int, array{gid:string,handle:string,title:string,status:string,available:bool,reason:string|null}>,
     *   shopify_ineligible:array<int, array{gid:string,handle:string,title:string,status:string,available:bool,reason:string|null}>,
     *   desired_shopify_gids:array<int, string>
     * }
     */
    public function analyzeDraft(NewProductDraft $draft): array
    {
        $localTokens = $this->parseReferenceTokens($draft->complementary_products);
        $localIds = $this->resolveProductIdsFromTokens($localTokens);
        $localStatesById = $this->localStatesForProductIds($localIds);
        $localEligibleIds = array_values(array_map(
            fn (int $productId): int => $productId,
            array_keys(array_filter(
                $localStatesById,
                static fn (array $state): bool => (bool) ($state['available'] ?? false)
            ))
        ));
        $localPrimaryIds = array_slice($localEligibleIds, 0, self::SHOPIFY_TARGET_COUNT);
        $localIneligible = array_values(array_filter(
            array_map(
                fn (int $productId): ?array => ($localStatesById[$productId]['available'] ?? false) ? null : ($localStatesById[$productId] ?? null),
                $localIds
            )
        ));

        $linkedProduct = $this->linkedProductForDraft($draft);
        if (!$linkedProduct instanceof Product) {
            return [
                'local_total' => count($localIds),
                'local_good' => count($localIds) >= self::LOCAL_TARGET_COUNT,
                'local_ids' => $localIds,
                'local_primary_ids' => $localPrimaryIds,
                'local_eligible_ids' => $localEligibleIds,
                'local_eligible_gids' => $this->productIdsToShopifyGids($localEligibleIds),
                'local_ineligible' => $localIneligible,
                'shopify_total' => 0,
                'shopify_eligible' => 0,
                'shopify_good' => false,
                'shopify_ids' => [],
                'shopify_eligible_ids' => [],
                'shopify_missing_local_ids' => [],
                'shopify_missing_local' => [],
                'shopify_ineligible' => [],
                'desired_shopify_gids' => $this->productIdsToShopifyGids($localPrimaryIds),
            ];
        }

        $analysis = $this->analyzeProduct($linkedProduct, $draft->complementary_products);
        $analysis['local_total'] = count($localIds);
        $analysis['local_good'] = count($localIds) >= self::LOCAL_TARGET_COUNT;
        $analysis['local_ids'] = $localIds;
        $analysis['local_primary_ids'] = $localPrimaryIds;
        $analysis['local_eligible_ids'] = $localEligibleIds;
        $analysis['local_eligible_gids'] = $this->productIdsToShopifyGids($localEligibleIds);
        $analysis['local_ineligible'] = $localIneligible;
        $analysis['desired_shopify_gids'] = $this->productIdsToShopifyGids($localPrimaryIds);

        return $analysis;
    }

    /**
     * @return array<int, int>
     */
    public function productIdsMatchingLocalStatus(string $status): array
    {
        $matchingIds = [];

        Product::query()
            ->select(['id', 'import_id', 'handle', 'shopify_id', 'status'])
            ->chunkById(200, function (Collection $products) use (&$matchingIds, $status): void {
                $localValues = $this->localComplementaryValuesForProducts($products);

                foreach ($products as $product) {
                    if (!$product instanceof Product) {
                        continue;
                    }

                    $analysis = $this->analyzeProduct($product, $localValues[$this->productKey($product)] ?? null);

                    if ($this->matchesLocalStatus($analysis['local_good'], $status)) {
                        $matchingIds[] = (int) $product->id;
                    }
                }
            });

        return $matchingIds;
    }

    /**
     * @return array<int, int>
     */
    public function productIdsMatchingShopifyStatus(string $status): array
    {
        $matchingIds = [];

        Product::query()
            ->select(['id', 'import_id', 'handle', 'shopify_id', 'status'])
            ->chunkById(200, function (Collection $products) use (&$matchingIds, $status): void {
                $localValues = $this->localComplementaryValuesForProducts($products);

                foreach ($products as $product) {
                    if (!$product instanceof Product) {
                        continue;
                    }

                    $analysis = $this->analyzeProduct($product, $localValues[$this->productKey($product)] ?? null);

                    if ($this->matchesShopifyStatus($analysis['shopify_good'], $status)) {
                        $matchingIds[] = (int) $product->id;
                    }
                }
            });

        return $matchingIds;
    }

    /**
     * @return array<int, int>
     */
    public function draftIdsMatchingLocalStatus(string $status): array
    {
        $matchingIds = [];

        NewProductDraft::query()
            ->select(['id', 'handle', 'shopify_id', 'complementary_products'])
            ->chunkById(200, function (Collection $drafts) use (&$matchingIds, $status): void {
                foreach ($drafts as $draft) {
                    if (!$draft instanceof NewProductDraft) {
                        continue;
                    }

                    $analysis = $this->analyzeDraft($draft);

                    if ($this->matchesLocalStatus($analysis['local_good'], $status)) {
                        $matchingIds[] = (int) $draft->id;
                    }
                }
            });

        return $matchingIds;
    }

    /**
     * @return array<int, int>
     */
    public function draftIdsMatchingShopifyStatus(string $status): array
    {
        $matchingIds = [];

        NewProductDraft::query()
            ->select(['id', 'handle', 'shopify_id', 'complementary_products'])
            ->chunkById(200, function (Collection $drafts) use (&$matchingIds, $status): void {
                foreach ($drafts as $draft) {
                    if (!$draft instanceof NewProductDraft) {
                        continue;
                    }

                    $linkedProduct = $this->linkedProductForDraft($draft);
                    if (!$linkedProduct instanceof Product) {
                        continue;
                    }

                    $analysis = $this->analyzeDraft($draft);

                    if ($this->matchesShopifyStatus($analysis['shopify_good'], $status)) {
                        $matchingIds[] = (int) $draft->id;
                    }
                }
            });

        return $matchingIds;
    }

    /**
     * @return array<int, Product>
     */
    public function productsNeedingShopifyComplementaryAttention(): array
    {
        $products = [];

        Product::query()
            ->select(['id', 'import_id', 'handle', 'shopify_id', 'status'])
            ->whereRaw('LOWER(COALESCE(status, "")) = ?', ['active'])
            ->chunkById(200, function (Collection $chunk) use (&$products): void {
                $localValues = $this->localComplementaryValuesForProducts($chunk);

                foreach ($chunk as $product) {
                    if (!$product instanceof Product) {
                        continue;
                    }

                    $analysis = $this->analyzeProduct($product, $localValues[$this->productKey($product)] ?? null);

                    if (!$analysis['shopify_good']) {
                        $products[] = $product;
                    }
                }
            });

        return $products;
    }

    public function localComplementaryValueForProduct(Product $product): ?string
    {
        $handle = trim((string) ($product->handle ?? ''));
        if ($handle === '') {
            return null;
        }

        $row = ShopifyRow::query()
            ->where('import_id', $product->import_id)
            ->where('handle', $handle)
            ->where('row_type', 'product_primary')
            ->first();

        if ($row instanceof ShopifyRow) {
            $value = trim((string) ($row->get(HeaderStore::COMPLEMENTARY_PRODUCTS, '') ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return $this->localComplementaryMetafieldValue(
            (int) ($product->import_id ?? 0),
            $handle
        );
    }

    public function shopifyComplementaryValueForProduct(Product $product): ?string
    {
        $states = $this->liveComplementaryStatesForProduct($product);

        if ($states === []) {
            return null;
        }

        return json_encode(array_values(array_filter(array_map(
            static fn (array $state): ?string => trim((string) ($state['gid'] ?? '')) ?: trim((string) ($state['handle'] ?? '')),
            $states
        ))));
    }

    /**
     * @return array<int, string>
     */
    public function parseReferenceTokens(?string $value): array
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $this->parseReferenceTokens(implode('; ', array_map('strval', $decoded)));
        }

        $parts = preg_split('/[,\n\r;]+/', $raw) ?: [];

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $parts
        ), static fn (string $item): bool => $item !== '')));
    }

    /**
     * @param array<int, string> $tokens
     * @return array<int, int>
     */
    public function resolveProductIdsFromTokens(array $tokens): array
    {
        $resolved = [];
        $referenceMap = $this->productReferenceMap();

        foreach ($tokens as $token) {
            $normalized = $this->normalizeReferenceToken($token);
            if ($normalized === '') {
                continue;
            }

            $productId = $referenceMap[$normalized] ?? null;
            if ($productId === null) {
                continue;
            }

            if (!in_array($productId, $resolved, true)) {
                $resolved[] = $productId;
            }
        }

        return $resolved;
    }

    /**
     * @param array<int, int> $productIds
     * @return array<int, string>
     */
    public function productIdsToShopifyGids(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $gidById = Product::query()
            ->whereKey($productIds)
            ->whereNotNull('shopify_id')
            ->pluck('shopify_id', 'id')
            ->all();

        $resolved = [];
        foreach ($productIds as $productId) {
            $gid = trim((string) ($gidById[$productId] ?? ''));
            if ($gid !== '') {
                $resolved[] = $gid;
            }
        }

        return array_values(array_unique($resolved));
    }

    private function matchesLocalStatus(bool $isGood, string $status): bool
    {
        return match ($status) {
            'good' => $isGood,
            'bad' => !$isGood,
            default => false,
        };
    }

    private function matchesShopifyStatus(bool $isGood, string $status): bool
    {
        return match ($status) {
            'healthy' => $isGood,
            'flagged' => !$isGood,
            default => false,
        };
    }

    /**
     * @return array<string, int>
     */
    private function productReferenceMap(): array
    {
        if ($this->productReferenceMap !== null) {
            return $this->productReferenceMap;
        }

        $map = [];

        Product::query()
            ->select(['id', 'shopify_id', 'handle', 'title'])
            ->chunkById(500, function (Collection $products) use (&$map): void {
                foreach ($products as $product) {
                    if (!$product instanceof Product) {
                        continue;
                    }

                    foreach ([
                        trim((string) ($product->shopify_id ?? '')),
                        trim((string) ($product->handle ?? '')),
                        trim((string) ($product->title ?? '')),
                    ] as $value) {
                        $normalized = $this->normalizeReferenceToken($value);
                        if ($normalized !== '' && !isset($map[$normalized])) {
                            $map[$normalized] = (int) $product->id;
                        }
                    }
                }
            });

        Variant::query()
            ->select(['product_id', 'sku'])
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->orderBy('id')
            ->chunk(500, function (Collection $variants) use (&$map): void {
                foreach ($variants as $variant) {
                    if (!$variant instanceof Variant) {
                        continue;
                    }

                    $normalized = $this->normalizeReferenceToken($variant->sku);
                    if ($normalized !== '' && !isset($map[$normalized])) {
                        $map[$normalized] = (int) $variant->product_id;
                    }
                }
            });

        $this->productReferenceMap = $map;

        return $map;
    }

    /**
     * @param Collection<int, Product> $products
     * @return array<string, string>
     */
    private function localComplementaryValuesForProducts(Collection $products): array
    {
        $handles = $products
            ->map(fn (Product $product): string => trim((string) ($product->handle ?? '')))
            ->filter()
            ->values()
            ->all();

        $importIds = $products
            ->map(fn (Product $product): int => (int) ($product->import_id ?? 0))
            ->filter(fn (int $importId): bool => $importId > 0)
            ->values()
            ->all();

        if ($handles === [] || $importIds === []) {
            return [];
        }

        $values = ShopifyRow::query()
            ->whereIn('import_id', $importIds)
            ->whereIn('handle', $handles)
            ->where('row_type', 'product_primary')
            ->get(['import_id', 'handle', 'data'])
            ->mapWithKeys(function (ShopifyRow $row): array {
                $value = trim((string) ($row->get(HeaderStore::COMPLEMENTARY_PRODUCTS, '') ?? ''));
                if ($value === '') {
                    return [];
                }

                return [
                    ((int) $row->import_id) . '|' . trim((string) $row->handle) => $value,
                ];
            })
            ->all();

        $missingHandlesByImport = [];
        foreach ($products as $product) {
            if (!$product instanceof Product) {
                continue;
            }

            $importId = (int) ($product->import_id ?? 0);
            $handle = trim((string) ($product->handle ?? ''));
            if ($importId <= 0 || $handle === '') {
                continue;
            }

            $key = $importId . '|' . $handle;
            if (!isset($values[$key])) {
                $missingHandlesByImport[$importId][] = $handle;
            }
        }

        foreach ($missingHandlesByImport as $importId => $missingHandles) {
            $metafieldValues = ShopifyMetafield::query()
                ->where('import_id', $importId)
                ->whereIn('handle', array_values(array_unique($missingHandles)))
                ->where('namespace', self::STANDARD_NAMESPACE)
                ->where('key', self::STANDARD_KEY)
                ->pluck('value', 'handle');

            foreach ($metafieldValues as $handle => $value) {
                $trimmed = trim((string) $value);
                if ($trimmed === '') {
                    continue;
                }

                $values[$importId . '|' . trim((string) $handle)] = $trimmed;
            }
        }

        return $values;
    }

    private function localComplementaryMetafieldValue(int $importId, string $handle): ?string
    {
        if ($importId <= 0 || $handle === '') {
            return null;
        }

        $value = ShopifyMetafield::query()
            ->where('import_id', $importId)
            ->where('handle', $handle)
            ->where('namespace', self::STANDARD_NAMESPACE)
            ->where('key', self::STANDARD_KEY)
            ->value('value');

        $trimmed = is_string($value) ? trim($value) : '';

        return $trimmed !== '' ? $trimmed : null;
    }

    private function linkedProductForDraft(NewProductDraft $draft): ?Product
    {
        $shopifyId = trim((string) ($draft->shopify_id ?? ''));
        if ($shopifyId !== '') {
            $product = Product::query()->where('shopify_id', $shopifyId)->first();
            if ($product instanceof Product) {
                return $product;
            }
        }

        $handle = trim((string) ($draft->handle ?? ''));
        if ($handle === '') {
            return null;
        }

        $product = Product::query()->where('handle', $handle)->first();

        return $product instanceof Product ? $product : null;
    }

    private function normalizeReferenceToken(?string $value): string
    {
        $trimmed = trim((string) ($value ?? ''));
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('#gid://shopify/Product/([0-9]+)#i', $trimmed, $matches)) {
            return 'gid://shopify/product/' . $matches[1];
        }

        if (preg_match('#/products/([0-9]+)(?:[/?\\#].*)?$#i', $trimmed, $matches)) {
            return 'gid://shopify/product/' . $matches[1];
        }

        if (preg_match('#(?:^|/)products/([a-z0-9][a-z0-9\\-]*)(?:[/?\\#].*)?$#i', $trimmed, $matches)) {
            $trimmed = $matches[1];
        }

        $trimmed = strtolower($trimmed);
        $trimmed = str_replace('&', 'and', $trimmed);
        $trimmed = preg_replace('/[^a-z0-9]+/', '-', $trimmed) ?? $trimmed;
        $trimmed = preg_replace('/-+/', '-', $trimmed) ?? $trimmed;

        return trim($trimmed, '-');
    }

    private function productKey(Product $product): string
    {
        return ((int) ($product->import_id ?? 0)) . '|' . trim((string) ($product->handle ?? ''));
    }

    /**
     * @param array<int, int> $productIds
     * @return array<int, array{gid:string,handle:string,title:string,status:string,available:bool,reason:string|null}>
     */
    private function localStatesForProductIds(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $products = Product::query()
            ->whereKey($productIds)
            ->with(['variants' => fn ($query) => $query->orderBy('id')])
            ->get()
            ->keyBy('id');

        $resolved = [];
        foreach ($productIds as $productId) {
            $product = $products->get($productId);
            if (!$product instanceof Product) {
                $resolved[$productId] = [
                    'gid' => '',
                    'handle' => '',
                    'title' => '',
                    'status' => 'missing',
                    'available' => false,
                    'reason' => 'Missing local product',
                ];
                continue;
            }

            $resolved[$productId] = [
                'gid' => trim((string) ($product->shopify_id ?? '')),
                'handle' => trim((string) ($product->handle ?? '')),
                'title' => trim((string) ($product->title ?? '')),
                'status' => strtolower(trim((string) ($product->status ?? ''))),
                'available' => $this->sellabilityService->isLocallySellable($product),
                'reason' => $this->sellabilityService->eligibilityReason($product),
            ];
        }

        return $resolved;
    }

    /**
     * @return array<int, array{gid:string,handle:string,title:string,status:string,available:bool,reason:string|null}>
     */
    private function liveComplementaryStatesForProduct(Product $product): array
    {
        $shopifyId = trim((string) ($product->shopify_id ?? ''));
        if ($shopifyId === '') {
            return [];
        }

        if (isset($this->liveComplementaryStatesByShopifyId[$shopifyId])) {
            return $this->liveComplementaryStatesByShopifyId[$shopifyId];
        }

        $data = $this->shopifyApiClient->graphql($this->productComplementaryQuery(), [
            'id' => $shopifyId,
        ]);

        $standardNodes = data_get($data, 'product.standardComplementary.references.nodes', []);
        $appNodes = data_get($data, 'product.appComplementary.references.nodes', []);
        $nodes = $standardNodes !== [] ? $standardNodes : $appNodes;

        $states = [];
        foreach ($nodes as $node) {
            if (($node['id'] ?? null) === null) {
                continue;
            }

            $state = $this->stateFromShopifyProductNode($node);
            $states[] = $state;
            $this->liveProductStatesByGid[$state['gid']] = $state;
        }

        $this->liveComplementaryStatesByShopifyId[$shopifyId] = $states;

        return $states;
    }

    /**
     * @param array<string, mixed> $node
     * @return array{gid:string,handle:string,title:string,status:string,available:bool,reason:string|null}
     */
    private function stateFromShopifyProductNode(array $node): array
    {
        $gid = trim((string) ($node['id'] ?? ''));
        $status = strtolower(trim((string) ($node['status'] ?? '')));
        $title = trim((string) ($node['title'] ?? ''));
        $handle = trim((string) ($node['handle'] ?? ''));
        $variantNodes = data_get($node, 'variants.nodes', []);
        $hasSellableVariant = collect($variantNodes)->contains(
            fn (array $variant): bool => (bool) ($variant['availableForSale'] ?? false)
        );

        $available = $status === 'active' && $hasSellableVariant;

        $reason = null;
        if ($status !== 'active') {
            $reason = $status === '' ? 'Not active on Shopify' : 'Shopify status: ' . strtoupper($status);
        } elseif (!$hasSellableVariant) {
            $reason = 'Out of stock on Shopify';
        }

        return [
            'gid' => $gid,
            'handle' => $handle,
            'title' => $title,
            'status' => $status,
            'available' => $available,
            'reason' => $reason,
        ];
    }

    /**
     * @param array{gid:string,handle:string,title:string,status:string,available:bool,reason:string|null} $state
     */
    private function resolveProductIdFromLiveState(array $state): ?int
    {
        $referenceMap = $this->productReferenceMap();

        foreach ([
            trim((string) ($state['gid'] ?? '')),
            trim((string) ($state['handle'] ?? '')),
            trim((string) ($state['title'] ?? '')),
        ] as $value) {
            $normalized = $this->normalizeReferenceToken($value);
            if ($normalized !== '' && isset($referenceMap[$normalized])) {
                return (int) $referenceMap[$normalized];
            }
        }

        return null;
    }

    private function productComplementaryQuery(): string
    {
        return <<<'GRAPHQL'
query ComplementaryProductAudit($id: ID!) {
  product: node(id: $id) {
    ... on Product {
    standardComplementary: metafield(namespace: "shopify--discovery--product_recommendation", key: "complementary_products") {
      references(first: 10) {
        nodes {
          ... on Product {
            id
            handle
            title
            status
            variants(first: 50) {
              nodes {
                availableForSale
              }
            }
          }
        }
      }
    }
    appComplementary: metafield(namespace: "$app", key: "complementary_products") {
      references(first: 10) {
        nodes {
          ... on Product {
            id
            handle
            title
            status
            variants(first: 50) {
              nodes {
                availableForSale
              }
            }
          }
        }
      }
    }
    }
  }
}
GRAPHQL;
    }

    private function productsByIdsQuery(): string
    {
        return <<<'GRAPHQL'
query ComplementaryProductStates($ids: [ID!]!) {
  nodes(ids: $ids) {
    ... on Product {
      id
      handle
      title
      status
      variants(first: 50) {
        nodes {
          availableForSale
        }
      }
    }
  }
}
GRAPHQL;
    }
}
