<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyCollection;
use App\Models\ShopifyMetafield;
use App\Models\ShopifyRow;
use App\Models\Variant;
use App\Services\TagNormalizer;
use Illuminate\Support\Collection;

final class ProductShopifyUpdater
{
    public const SYNC_SCOPE_PRODUCT = 'product';
    public const SYNC_SCOPE_SEO = 'seo';
    public const SYNC_SCOPE_METAFIELDS = 'metafields';
    public const SYNC_SCOPE_VARIANTS = 'variants';
    public const SYNC_SCOPE_IMAGES = 'images';
    public const CORE_FIELD_TITLE = 'title';
    public const CORE_FIELD_VENDOR = 'vendor';
    public const CORE_FIELD_PRODUCT_TYPE = 'product_type';
    public const CORE_FIELD_BODY_HTML = 'body_html';
    public const CORE_FIELD_TAGS = 'tags';
    public const CORE_FIELD_STATUS = 'status';
    public const CORE_FIELD_HANDLE = 'handle';
    public const CORE_FIELD_CATEGORY = 'category';
    public const CORE_FIELD_COLOR = 'color';
    public const CORE_FIELD_MATERIALS_AND_DIMENSIONS = 'materials_and_dimensions';
    public const CORE_FIELD_JEWELRY_MATERIAL = 'jewelry_material';
    public const CORE_FIELD_JEWELRY_TYPE = 'jewelry_type';
    public const CORE_FIELD_TARGET_GENDER = 'target_gender';
    public const CORE_FIELD_AGE_GROUP = 'age_group';
    public const CORE_FIELD_BRACELET_DESIGN = 'bracelet_design';
    public const CORE_FIELD_PATTERN_CATEGORY = 'pattern_category';
    public const CORE_FIELD_PRODUCT_METALS = 'product_metals';
    public const CORE_FIELD_SIBLINGS = 'siblings';
    public const CORE_FIELD_COMPLEMENTARY_PRODUCTS = 'complementary_products';
    public const CORE_FIELD_UVP_SHORT_PARAGRAPH = 'uvp_short_paragraph';
    public const CORE_FIELD_SEO_DEINDEX = 'seo_deindex';

    /** @var array<string, string> */
    private const PATTERN_CATEGORY_METAOBJECT_GIDS = [
        'multicolour' => 'gid://shopify/Metaobject/205977026696',
        'multicolor' => 'gid://shopify/Metaobject/205977026696',
        'solid' => 'gid://shopify/Metaobject/205977059464',
    ];

    /** @var array<string, array<int, string>> */
    private const METAOBJECT_TYPE_OVERRIDES_BY_LOOKUP = [
        'custom.pattern_category' => ['colour_style'],
    ];

    /** @var array<string, array<string, string>> */
    private array $referenceLookupCache = [];

    /** @var array<string, array<int, string>> */
    private array $metaobjectTypesByLookupCache = [];

    /** @var array<string, array<int, array{id:string,displayName:string,handle:string,fields:array<int, array{key:string,value:string}>}>> */
    private array $metaobjectsByTypeCache = [];
    /** @var array<string, string>|null */
    private ?array $productReferenceLookupCache = null;
    /** @var array<string, string>|null */
    private ?array $collectionReferenceLookupCache = null;
    /** @var array<string, string|null> */
    private array $productReferenceRemoteCache = [];

    /** @var array<string, array<string, string>>|null */
    private ?array $acceptedValuesByHeaderCache = null;

    /** @var array<string, string|null> */
    private array $categoryIdByTypeCache = [];
    /** @var array<string, string|null> */
    private array $categoryIdByNameCache = [];
    /** @var array<string, string|null> */
    private array $categoryNameByGidCache = [];

    private ?bool $categoryGraphqlSupported = null;

    public function __construct(
        private readonly ShopifyApiClient $client,
        private readonly ProductHandleService $handleService,
        private readonly ProductPartialApprovalService $partialApprovalService,
    ) {}

    /**
     * @param Collection<int, Product> $products
     * @param array<int, string>|null $scopes
     * @param array<int, string>|null $coreFields
     * @return array{
     *   updated:int,
     *   updated_product_ids:array<int, int>,
     *   skipped_not_approved:int,
     *   skipped_missing_handle:int,
     *   skipped_blocked:int,
     *   failed:int,
     *   warnings:array<int, array{product_id:int, warning:string}>,
     *   failures:array<int, array{product_id:int, reason:string, details:string|null}>
     * }
     */
    public function updateApprovedProducts(Collection $products, ?array $scopes = null, ?array $coreFields = null): array
    {
        $resolvedScopes = $this->normalizeSyncScopes($scopes);
        $resolvedCoreFields = $this->normalizeCoreFields($coreFields);
        if ($scopes !== null && empty($resolvedScopes)) {
            return [
                'updated' => 0,
                'updated_product_ids' => [],
                'skipped_not_approved' => 0,
                'skipped_missing_handle' => 0,
                'skipped_blocked' => 0,
                'failed' => 0,
                'warnings' => [],
                'failures' => [],
            ];
        }

        $updated = 0;
        $updatedProductIds = [];
        $skippedNotApproved = 0;
        $skippedMissingHandle = 0;
        $failed = 0;
        $skippedBlocked = 0;
        $warnings = [];
        $failures = [];

        foreach ($products as $product) {
            if (!$product instanceof Product) {
                continue;
            }

            if (!$product->handle) {
                $skippedMissingHandle++;
                continue;
            }

            if ($this->isBlockedByShopifyMissingDraft($product)) {
                $skippedBlocked++;
                continue;
            }

            $effectiveScopes = $resolvedScopes;
            $effectiveCoreFields = $resolvedCoreFields;

            if (!$product->isApprovedByTwo()) {
                if (!$this->partialApprovalService->isActiveProduct($product)) {
                    $skippedNotApproved++;
                    continue;
                }

                $allowed = $this->partialApprovalService->allowedSelections($product, $resolvedScopes, $resolvedCoreFields);
                $effectiveScopes = $allowed['scopes'];
                $effectiveCoreFields = $allowed['core_fields'];

                if ($effectiveScopes === []) {
                    $skippedNotApproved++;
                    continue;
                }

                if (in_array(self::SYNC_SCOPE_PRODUCT, $effectiveScopes, true) && $effectiveCoreFields === []) {
                    $effectiveScopes = array_values(array_filter(
                        $effectiveScopes,
                        fn (string $scope): bool => $scope !== self::SYNC_SCOPE_PRODUCT
                    ));
                }

                if ($effectiveScopes === []) {
                    $skippedNotApproved++;
                    continue;
                }
            }

            if (in_array(self::SYNC_SCOPE_PRODUCT, $effectiveScopes, true) && empty($effectiveCoreFields)) {
                $skippedNotApproved++;
                continue;
            }

            try {
                $warnings = array_merge($warnings, $this->updateProduct($product, $effectiveScopes, $effectiveCoreFields));
                $updated++;
                $updatedProductIds[] = $product->id;
            } catch (\Throwable $e) {
                $failed++;
                $failures[] = [
                    'product_id' => $product->id,
                    'reason' => 'exception',
                    'details' => $e->getMessage(),
                ];
                logger()->error('Shopify product sync failed.', [
                    'product_id' => $product->id,
                    'handle' => $product->handle,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return [
            'updated' => $updated,
            'updated_product_ids' => $updatedProductIds,
            'skipped_not_approved' => $skippedNotApproved,
            'skipped_missing_handle' => $skippedMissingHandle,
            'skipped_blocked' => $skippedBlocked,
            'failed' => $failed,
            'warnings' => $warnings,
            'failures' => $failures,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function availableSyncScopes(): array
    {
        return [
            self::SYNC_SCOPE_PRODUCT,
            self::SYNC_SCOPE_SEO,
            self::SYNC_SCOPE_METAFIELDS,
            self::SYNC_SCOPE_VARIANTS,
            self::SYNC_SCOPE_IMAGES,
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function syncScopeLabels(): array
    {
        return [
            self::SYNC_SCOPE_PRODUCT => 'Product core fields',
            self::SYNC_SCOPE_SEO => 'SEO title and description',
            self::SYNC_SCOPE_METAFIELDS => 'Metafields',
            self::SYNC_SCOPE_VARIANTS => 'Variants and inventory',
            self::SYNC_SCOPE_IMAGES => 'Images',
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function availableCoreFields(): array
    {
        return [
            self::CORE_FIELD_TITLE,
            self::CORE_FIELD_VENDOR,
            self::CORE_FIELD_PRODUCT_TYPE,
            self::CORE_FIELD_BODY_HTML,
            self::CORE_FIELD_TAGS,
            self::CORE_FIELD_STATUS,
            self::CORE_FIELD_HANDLE,
            self::CORE_FIELD_CATEGORY,
            self::CORE_FIELD_COLOR,
            self::CORE_FIELD_MATERIALS_AND_DIMENSIONS,
            self::CORE_FIELD_JEWELRY_MATERIAL,
            self::CORE_FIELD_JEWELRY_TYPE,
            self::CORE_FIELD_TARGET_GENDER,
            self::CORE_FIELD_AGE_GROUP,
            self::CORE_FIELD_BRACELET_DESIGN,
            self::CORE_FIELD_PATTERN_CATEGORY,
            self::CORE_FIELD_PRODUCT_METALS,
            self::CORE_FIELD_SEO_DEINDEX,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function availableProductCoreFields(): array
    {
        return [
            self::CORE_FIELD_TITLE,
            self::CORE_FIELD_VENDOR,
            self::CORE_FIELD_PRODUCT_TYPE,
            self::CORE_FIELD_BODY_HTML,
            self::CORE_FIELD_TAGS,
            self::CORE_FIELD_STATUS,
            self::CORE_FIELD_HANDLE,
            self::CORE_FIELD_CATEGORY,
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function availableMetafieldFields(): array
    {
        return [
            self::CORE_FIELD_COLOR,
            self::CORE_FIELD_MATERIALS_AND_DIMENSIONS,
            self::CORE_FIELD_JEWELRY_MATERIAL,
            self::CORE_FIELD_JEWELRY_TYPE,
            self::CORE_FIELD_TARGET_GENDER,
            self::CORE_FIELD_AGE_GROUP,
            self::CORE_FIELD_BRACELET_DESIGN,
            self::CORE_FIELD_PATTERN_CATEGORY,
            self::CORE_FIELD_PRODUCT_METALS,
            self::CORE_FIELD_SIBLINGS,
            self::CORE_FIELD_COMPLEMENTARY_PRODUCTS,
            self::CORE_FIELD_UVP_SHORT_PARAGRAPH,
            self::CORE_FIELD_SEO_DEINDEX,
        ];
    }

    /**
     * Default product-field sync excludes handle changes.
     *
     * @return array<int, string>
     */
    public static function defaultCoreFields(): array
    {
        return array_values(array_filter(
            self::availableCoreFields(),
            fn (string $field): bool => $field !== self::CORE_FIELD_HANDLE
        ));
    }

    /**
     * @return array<string, string>
     */
    public static function coreFieldLabels(): array
    {
        return [
            self::CORE_FIELD_TITLE => 'Title',
            self::CORE_FIELD_VENDOR => 'Vendor',
            self::CORE_FIELD_PRODUCT_TYPE => 'Type',
            self::CORE_FIELD_BODY_HTML => 'Body HTML',
            self::CORE_FIELD_TAGS => 'Tags',
            self::CORE_FIELD_STATUS => 'Status',
            self::CORE_FIELD_HANDLE => 'Handle',
            self::CORE_FIELD_CATEGORY => 'Category',
            self::CORE_FIELD_COLOR => 'Colors',
            self::CORE_FIELD_MATERIALS_AND_DIMENSIONS => 'Materials and dimensions',
            self::CORE_FIELD_JEWELRY_MATERIAL => 'Jewelry material',
            self::CORE_FIELD_JEWELRY_TYPE => 'Jewelry type',
            self::CORE_FIELD_TARGET_GENDER => 'Target gender',
            self::CORE_FIELD_AGE_GROUP => 'Age group',
            self::CORE_FIELD_BRACELET_DESIGN => 'Bracelet design',
            self::CORE_FIELD_PATTERN_CATEGORY => 'Color Style',
            self::CORE_FIELD_PRODUCT_METALS => 'Product metals',
            self::CORE_FIELD_SIBLINGS => 'Siblings',
            self::CORE_FIELD_COMPLEMENTARY_PRODUCTS => 'Complementary products',
            self::CORE_FIELD_UVP_SHORT_PARAGRAPH => 'UVP short paragraph',
            self::CORE_FIELD_SEO_DEINDEX => 'SEO: Deindex products',
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function productCoreFieldLabels(): array
    {
        $labels = self::coreFieldLabels();

        return array_intersect_key($labels, array_flip(self::availableProductCoreFields()));
    }

    /**
     * @return array<string, string>
     */
    public static function metafieldFieldLabels(): array
    {
        $labels = self::coreFieldLabels();

        return array_intersect_key($labels, array_flip(self::availableMetafieldFields()));
    }

    /**
     * @param Collection<int, Product> $products
     * @return array{
     *   synced:int,
     *   synced_product_ids:array<int, int>,
     *   skipped_not_approved:int,
     *   skipped_missing_handle:int,
     *   skipped_blocked:int,
     *   failed:int,
     *   warnings:array<int, array{product_id:int, warning:string}>,
     *   failures:array<int, array{product_id:int, reason:string, details:string|null}>
     * }
     */
    public function syncProductImages(Collection $products): array
    {
        $synced = 0;
        $syncedProductIds = [];
        $skippedNotApproved = 0;
        $skippedMissingHandle = 0;
        $failed = 0;
        $skippedBlocked = 0;
        $warnings = [];
        $failures = [];

        foreach ($products as $product) {
            if (!$product instanceof Product) {
                continue;
            }

            if (!$product->handle) {
                $skippedMissingHandle++;
                continue;
            }

            if ($this->isBlockedByShopifyMissingDraft($product)) {
                $skippedBlocked++;
                continue;
            }

            if (!$product->isApprovedByTwo()) {
                $skippedNotApproved++;
                continue;
            }

            try {
                $productId = $this->resolveProductId($product);
                if (!$productId) {
                    throw new \RuntimeException('Unable to resolve Shopify product ID for handle.');
                }

                $details = $this->productDetails($product, null, $productId);
                $warnings = array_merge($warnings, $this->updateImages($product, $productId, [], $details));
                $synced++;
                $syncedProductIds[] = $product->id;
            } catch (\Throwable $e) {
                $failed++;
                $failures[] = [
                    'product_id' => $product->id,
                    'reason' => 'exception',
                    'details' => $e->getMessage(),
                ];
                logger()->error('Shopify product image sync failed.', [
                    'product_id' => $product->id,
                    'handle' => $product->handle,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return [
            'synced' => $synced,
            'synced_product_ids' => $syncedProductIds,
            'skipped_not_approved' => $skippedNotApproved,
            'skipped_missing_handle' => $skippedMissingHandle,
            'skipped_blocked' => $skippedBlocked,
            'failed' => $failed,
            'warnings' => $warnings,
            'failures' => $failures,
        ];
    }

    /**
     * @param array<int, int> $selectedImageIds
     * @return array{
     *   synced:int,
     *   processed_images:int,
     *   skipped_not_approved:int,
     *   skipped_missing_handle:int,
     *   skipped_blocked:int,
     *   failed:int,
     *   warnings:array<int, array{product_id:int, warning:string}>,
     *   failures:array<int, array{product_id:int, reason:string, details:string|null}>
     * }
     */
    public function syncSelectedProductImages(Product $product, array $selectedImageIds): array
    {
        $selectedImageIds = array_values(array_unique(array_map('intval', $selectedImageIds)));

        $result = [
            'synced' => 0,
            'processed_images' => count($selectedImageIds),
            'skipped_not_approved' => 0,
            'skipped_missing_handle' => 0,
            'skipped_blocked' => 0,
            'failed' => 0,
            'warnings' => [],
            'failures' => [],
        ];

        if (empty($selectedImageIds)) {
            $result['failed'] = 1;
            $result['failures'][] = [
                'product_id' => $product->id,
                'reason' => 'no_selection',
                'details' => 'No image IDs were provided for selected image sync.',
            ];

            return $result;
        }

        if (!$product->handle) {
            $result['skipped_missing_handle'] = 1;
            return $result;
        }

        if ($this->isBlockedByShopifyMissingDraft($product)) {
            $result['skipped_blocked'] = 1;
            return $result;
        }

        if (!$product->isApprovedByTwo()) {
            $result['skipped_not_approved'] = 1;
            return $result;
        }

        try {
            $productId = $this->resolveProductId($product);
            if (!$productId) {
                throw new \RuntimeException('Unable to resolve Shopify product ID for handle.');
            }

            $details = $this->productDetails($product, null, $productId);
            $result['warnings'] = $this->updateImages($product, $productId, [], $details, $selectedImageIds);
            $result['synced'] = 1;
        } catch (\Throwable $e) {
            $result['failed'] = 1;
            $result['failures'][] = [
                'product_id' => $product->id,
                'reason' => 'exception',
                'details' => $e->getMessage(),
            ];

            logger()->error('Selected Shopify product image sync failed.', [
                'product_id' => $product->id,
                'handle' => $product->handle,
                'image_ids' => $selectedImageIds,
                'message' => $e->getMessage(),
            ]);
        }

        return $result;
    }

    /**
     * @return array<int, array{product_id:int, warning:string}>
     */
    // private function updateProduct(Product $product): array
    // {
    //     $warnings = [];
    //     $productId = $this->resolveShopifyId($product->handle);
    //     if (!$productId) {
    //         throw new \RuntimeException('Unable to resolve Shopify product ID for handle.');
    //     }

    //     [$primaryRow, $variantRows, $imageRows] = $this->loadRows($product);
    //     $primaryData = $primaryRow?->data ?? [];

    //     $title = $this->valueFromRow($primaryData, HeaderStore::TITLE, $product->title);
    //     $vendor = $this->valueFromRow($primaryData, HeaderStore::VENDOR, $product->vendor);
    //     $productType = $this->valueFromRow($primaryData, HeaderStore::TYPE, $product->type);
    //     $bodyHtml = $this->valueFromRow($primaryData, HeaderStore::BODY_HTML, $product->body_html);
    //     $statusRaw = $this->valueFromRow($primaryData, HeaderStore::STATUS, $product->status ?? 'draft');
    //     $tagsRaw = $this->valueFromRow($primaryData, HeaderStore::TAGS, $product->tags);

    //     // IMPORTANT: Product Category from CSV can be name OR GID.
    //     $productCategory = $this->valueFromRow($primaryData, HeaderStore::PRODUCT_CATEGORY, null);
    //     if ($productCategory !== null) {
    //         $productCategory = $this->normalizeSingleAcceptedValue(HeaderStore::PRODUCT_CATEGORY, $productCategory) ?? $productCategory;
    //     }

    //     $input = [
    //         'id' => $productId,
    //     ];

    //     if ($title !== null) {
    //         $input['title'] = $title;
    //     }
    //     if ($vendor !== null) {
    //         $input['vendor'] = $vendor;
    //     }
    //     if ($productType !== null) {
    //         $input['productType'] = $productType;
    //     }
    //     if ($bodyHtml !== null) {
    //         $input['descriptionHtml'] = $bodyHtml;
    //     }
    //     if ($tagsRaw !== null) {
    //         $input['tags'] = TagNormalizer::parseTokens((string) $tagsRaw);
    //     }
    //     if ($statusRaw !== null) {
    //         $input['status'] = $this->mapStatus((string) $statusRaw);
    //     }

    //     // SEO
    //     if ($primaryRow) {
    //         $seoTitle = $this->nullIfEmpty($primaryRow->get(HeaderStore::SEO_TITLE, ''));
    //         $seoDescription = $this->nullIfEmpty($primaryRow->get(HeaderStore::SEO_DESCRIPTION, ''));
    //         if ($seoTitle !== null || $seoDescription !== null) {
    //             $input['seo'] = [
    //                 'title' => $seoTitle,
    //                 'description' => $seoDescription,
    //             ];
    //         }
    //     }

    //     /**
    //      * ✅ KEY FIX #1:
    //      * Resolve and set taxonomy category in the SAME productUpdate call,
    //      * so that by the time we update metafields we already have category context.
    //      */
    //     $resolvedCategoryGid = $this->resolveProductCategoryGid($product, $productCategory, $productType);
    //     if ($resolvedCategoryGid !== null) {
    //         $candidates = $this->taxonomyIdCandidates($resolvedCategoryGid);
    //         if (!empty($candidates)) {
    //             // Modern Shopify expects ProductInput.category to be an ID STRING
    //             $input['category'] = $candidates[0];
    //         }
    //     }

    //     // Product update (with category included when possible)
    //     $data = $this->client->graphql($this->productUpdateMutation(), [
    //         'input' => $input,
    //     ]);

    //     $errors = data_get($data, 'productUpdate.userErrors', []);
    //     if (is_array($errors) && !empty($errors)) {
    //         $messages = $this->formatUserErrors($errors);

    //         // If category input is not supported / rejected, fallback to attemptCategoryUpdate
    //         if (
    //             $resolvedCategoryGid !== null
    //             && $this->isUnsupportedCategoryInputMessage($messages)
    //         ) {
    //             $this->categoryGraphqlSupported = false;
    //             $warnings = array_merge(
    //                 $warnings,
    //                 $this->attemptCategoryUpdate($product, $productId, $resolvedCategoryGid, $productType)
    //             );

    //             // Retry productUpdate WITHOUT category
    //             unset($input['category']);
    //             $data = $this->client->graphql($this->productUpdateMutation(), [
    //                 'input' => $input,
    //             ]);

    //             $errors = data_get($data, 'productUpdate.userErrors', []);
    //             if (is_array($errors) && !empty($errors)) {
    //                 $messages = $this->formatUserErrors($errors);
    //                 throw new \RuntimeException($messages !== '' ? $messages : 'Shopify rejected the update.');
    //             }
    //         } else {
    //             throw new \RuntimeException($messages !== '' ? $messages : 'Shopify rejected the update.');
    //         }
    //     }

    //     // Fetch latest details after update
    //     $details = $this->productByHandleDetails($product->handle);
    //     $currentCategoryId = trim((string) data_get($details, 'category.id', ''));
    //     if ($currentCategoryId === '') {
    //         $currentCategoryId = trim((string) data_get($details, 'productCategory.productTaxonomyNode.id', ''));
    //     }
    //     $currentCategoryName = trim((string) data_get($details, 'category.name', ''));
    //     if ($currentCategoryName === '') {
    //         $currentCategoryName = trim((string) data_get($details, 'productCategory.productTaxonomyNode.fullName', ''));
    //     }

    //     // Collect existing metafields directly from Shopify to help with reference fallback
    //     $shopifyRawMetafields = $this->productMetafieldRawValuesByHandle($product->handle);

    //     // Update metafields
    //     if ($primaryRow) {
    //         $warnings = array_merge(
    //             $warnings,
    //             $this->updateMetafields(
    //                 $product,
    //                 $productId,
    //                 $primaryData,
    //                 $shopifyRawMetafields,
    //                 $currentCategoryId !== '' ? $currentCategoryId : null,
    //                 $currentCategoryName !== '' ? $currentCategoryName : null
    //             )
    //         );
    //     }

    //     // Variants + inventory
    //     $warnings = array_merge(
    //         $warnings,
    //         $this->updateVariantAndInventory($product, $productId, $variantRows, $details)
    //     );

    //     // Images
    //     $warnings = array_merge(
    //         $warnings,
    //         $this->updateImages($product, $productId, $imageRows, $details)
    //     );

    //     // Coverage warnings (kept as-is)
    //     $warnings = array_merge(
    //         $warnings,
    //         $this->buildSyncCoverageWarnings($product, $primaryData, $variantRows, $imageRows)
    //     );

    //     return $warnings;
    // }


/**
 * @param array<int, string> $coreFields
 * @return array<int, array{product_id:int, warning:string}>
 */
private function updateProduct(Product $product, array $scopes, array $coreFields): array
{
    $warnings = [];
    $syncProduct = $this->scopeEnabled($scopes, self::SYNC_SCOPE_PRODUCT) && !empty($coreFields);
    $syncSeo = $this->scopeEnabled($scopes, self::SYNC_SCOPE_SEO);
    $syncMetafields = $this->scopeEnabled($scopes, self::SYNC_SCOPE_METAFIELDS);
    $syncVariants = $this->scopeEnabled($scopes, self::SYNC_SCOPE_VARIANTS);
    $syncImages = $this->scopeEnabled($scopes, self::SYNC_SCOPE_IMAGES);
    $selectedMetafieldHeaders = $this->selectedCoreMetafieldHeaders($coreFields);

    $currentHandle = trim((string) ($product->handle ?? ''));
    $targetHandle = trim((string) ($product->desiredHandle() ?? ''));
    $productId = $this->resolveProductId($product);
    if (!$productId) {
        throw new \RuntimeException('Unable to resolve Shopify product ID for handle.');
    }

    [$primaryRow, $variantRows, $imageRows] = $this->loadRows($product);
    $primaryData = $primaryRow?->data ?? [];

    // Prefer current Product model values from the tool; row data is fallback only.
    $title = $this->nullIfEmpty($product->title)
        ?? $this->valueFromRow($primaryData, HeaderStore::TITLE, null);
    // Prefer model value for vendor so UI edits are not overridden by stale imported row data.
    $vendor = $this->nullIfEmpty($product->vendor)
        ?? $this->valueFromRow($primaryData, HeaderStore::VENDOR, null);
    $productType = $this->nullIfEmpty($product->type)
        ?? $this->valueFromRow($primaryData, HeaderStore::TYPE, null);
    $bodyHtml = $this->nullIfEmpty($product->body_html)
        ?? $this->valueFromRow($primaryData, HeaderStore::BODY_HTML, null);
    $statusRaw = $this->nullIfEmpty($product->status)
        ?? $this->valueFromRow($primaryData, HeaderStore::STATUS, 'draft');
    $tagsRaw = $this->nullIfEmpty($product->tags)
        ?? $this->valueFromRow($primaryData, HeaderStore::TAGS, null);

    $productCategory = $this->nullIfEmpty($product->product_category)
        ?? $this->valueFromRow($primaryData, HeaderStore::PRODUCT_CATEGORY, null);
    if ($productCategory !== null) {
        $productCategory = $this->normalizeSingleAcceptedValue(HeaderStore::PRODUCT_CATEGORY, $productCategory) ?? $productCategory;
    }

    // ✅ Resolve category to a Shopify taxonomy GID if possible
    $resolvedCategoryGid = $this->resolveProductCategoryGid($productCategory, $productType);

    // ✅ IMPORTANT FIX:
    // If we resolved a taxonomy GID, inject it into row data BEFORE metafields.
    // This guarantees "hasCategoryContext" becomes true for subtype-constrained metafields
    // even if Shopify details query returns "uncategorized" or lags.
    if ($resolvedCategoryGid !== null) {
        $primaryData[HeaderStore::PRODUCT_CATEGORY] = $resolvedCategoryGid;
    }

    $input = [
        'id' => $productId,
    ];

    if ($syncProduct && $this->coreFieldEnabled($coreFields, self::CORE_FIELD_TITLE) && $title !== null) {
        $input['title'] = $title;
    }
    if ($syncProduct && $this->coreFieldEnabled($coreFields, self::CORE_FIELD_VENDOR) && $vendor !== null) {
        $input['vendor'] = $vendor;
    }
    if ($syncProduct && $this->coreFieldEnabled($coreFields, self::CORE_FIELD_PRODUCT_TYPE) && $productType !== null) {
        $input['productType'] = $productType;
    }
    if ($syncProduct && $this->coreFieldEnabled($coreFields, self::CORE_FIELD_BODY_HTML) && $bodyHtml !== null) {
        $input['descriptionHtml'] = $bodyHtml;
    }
    if ($syncProduct && $this->coreFieldEnabled($coreFields, self::CORE_FIELD_TAGS) && $tagsRaw !== null) {
        $input['tags'] = TagNormalizer::parseTokens((string) $tagsRaw);
    }
    if ($syncProduct && $this->coreFieldEnabled($coreFields, self::CORE_FIELD_STATUS) && $statusRaw !== null) {
        $input['status'] = $this->mapStatus((string) $statusRaw);
    }
    if ($syncProduct && $this->coreFieldEnabled($coreFields, self::CORE_FIELD_HANDLE) && $targetHandle !== '' && $targetHandle !== $currentHandle) {
        $input['handle'] = $targetHandle;
    }

    if ($syncSeo) {
        $seoTitle = $this->nullIfEmpty($product->seo_title)
            ?? ($primaryRow ? $this->nullIfEmpty($primaryRow->get(HeaderStore::SEO_TITLE, '')) : null);
        $seoDescription = $this->nullIfEmpty($product->seo_description)
            ?? ($primaryRow ? $this->nullIfEmpty($primaryRow->get(HeaderStore::SEO_DESCRIPTION, '')) : null);
        if ($seoTitle !== null || $seoDescription !== null) {
            $input['seo'] = [
                'title' => $seoTitle,
                'description' => $seoDescription,
            ];
        }
    }

    // ✅ Modern Shopify expects ProductInput.category to be a STRING ID (GID)
    if ($syncProduct && $this->coreFieldEnabled($coreFields, self::CORE_FIELD_CATEGORY) && $resolvedCategoryGid !== null) {
        $candidates = $this->taxonomyIdCandidates($resolvedCategoryGid);
        if (!empty($candidates)) {
            $input['category'] = $candidates[0];
        }
    }

    if (count($input) > 1) {
        // 1) Update product (and category if provided)
        $data = $this->client->graphql($this->productUpdateMutation(), [
            'input' => $input,
        ]);

        $errors = data_get($data, 'productUpdate.userErrors', []);
        if (is_array($errors) && !empty($errors)) {
            $messages = $this->formatUserErrors($errors);

            // If category field is rejected/unsupported, try fallback category update, then retry without category
            if ($syncProduct && $this->coreFieldEnabled($coreFields, self::CORE_FIELD_CATEGORY) && $resolvedCategoryGid !== null && $this->isUnsupportedCategoryInputMessage($messages)) {
                $this->categoryGraphqlSupported = false;

                $warnings = array_merge(
                    $warnings,
                    $this->attemptCategoryUpdate($product, $productId, $resolvedCategoryGid, $productType)
                );

                unset($input['category']);

                $data = $this->client->graphql($this->productUpdateMutation(), [
                    'input' => $input,
                ]);

                $errors = data_get($data, 'productUpdate.userErrors', []);
                if (is_array($errors) && !empty($errors)) {
                    $messages = $this->formatUserErrors($errors);
                    throw new \RuntimeException($messages !== '' ? $messages : 'Shopify rejected the update.');
                }
            } else {
                throw new \RuntimeException($messages !== '' ? $messages : 'Shopify rejected the update.');
            }
        }
    }

    // 2) Fetch latest details
    $detailsHandle = ($syncProduct && $targetHandle !== '') ? $targetHandle : $currentHandle;
    $details = $this->productDetails($product, $detailsHandle, $productId);

    $currentCategoryId = trim((string) data_get($details, 'category.id', ''));
    if ($currentCategoryId === '') {
        $currentCategoryId = trim((string) data_get($details, 'productCategory.productTaxonomyNode.id', ''));
    }

    $currentCategoryName = trim((string) data_get($details, 'category.name', ''));
    if ($currentCategoryName === '') {
        $currentCategoryName = trim((string) data_get($details, 'productCategory.productTaxonomyNode.fullName', ''));
    }

    // 3) Pull current metafields from Shopify for fallback reference support
    $shopifyRawMetafields = $this->productMetafieldRawValues($product, $detailsHandle, $productId);

    if ($syncProduct && $this->coreFieldEnabled($coreFields, self::CORE_FIELD_HANDLE) && $targetHandle !== '' && $targetHandle !== $currentHandle) {
        $this->handleService->promoteApprovedHandle($product);
    }

    // 4) Metafields — pass $primaryData (now has GID if resolved)
    if (($syncMetafields || !empty($selectedMetafieldHeaders)) && $primaryRow) {
        $metafieldRowData = $primaryData;

        if (!empty($selectedMetafieldHeaders)) {
            $metafieldRowData = [];
            foreach ($selectedMetafieldHeaders as $header) {
                $metafieldValue = $this->selectedCoreMetafieldValue($product, $primaryData, $header);
                if ($metafieldValue === null) {
                    continue;
                }

                $metafieldRowData[$header] = $metafieldValue;
            }

            if (array_key_exists(HeaderStore::PRODUCT_CATEGORY, $primaryData)) {
                $metafieldRowData[HeaderStore::PRODUCT_CATEGORY] = $primaryData[HeaderStore::PRODUCT_CATEGORY];
            }
        }

        $warnings = array_merge(
            $warnings,
            $this->updateMetafields(
                $product,
                $productId,
                $metafieldRowData,
                $shopifyRawMetafields,
                $currentCategoryId !== '' ? $currentCategoryId : null,
                $currentCategoryName !== '' ? $currentCategoryName : null
            )
        );
    }

    // 5) Variants/inventory
    if ($syncVariants) {
        $warnings = array_merge(
            $warnings,
            $this->updateVariantAndInventory($product, $productId, $variantRows, $details)
        );
    }

    // 6) Images
    if ($syncImages) {
        $warnings = array_merge(
            $warnings,
            $this->updateImages($product, $productId, $imageRows, $details)
        );
    }

    if ($syncVariants) {
        $warnings = array_merge(
            $warnings,
            $this->syncVariantMediaAssignments($product, $productId, $variantRows)
        );
    }

    // 7) Coverage warnings
    if ($syncProduct) {
        $warnings = array_merge(
            $warnings,
            $this->buildSyncCoverageWarnings($product, $primaryData, $variantRows, $imageRows)
        );
    }

    return $warnings;
}



    /**
     * @return array<int, array{product_id:int, warning:string}>
     */
    private function updateMetafields(
        Product $product,
        string $productId,
        array $rowData,
        array $shopifyRawValues = [],
        ?string $currentCategoryId = null,
        ?string $currentCategoryName = null
    ): array
    {
        $warnings = [];
        $payload = $this->metafieldsFromRow(
            $product,
            $productId,
            $rowData,
            $warnings,
            $shopifyRawValues,
            $currentCategoryId,
            $currentCategoryName
        );
        $metafields = $payload['inputs'];
        $indexMap = $payload['indexMap'];
        if (empty($metafields)) {
            return $warnings;
        }

        $data = $this->client->graphql($this->metafieldsSetMutation(), [
            'metafields' => $metafields,
        ]);

        $errors = data_get($data, 'metafieldsSet.userErrors', []);
        if (is_array($errors) && !empty($errors)) {
            foreach ($errors as $error) {
                $field = $error['field'] ?? null;
                $index = null;
                if (is_array($field) && isset($field[1]) && is_numeric($field[1])) {
                    $index = (int) $field[1];
                }
                $meta = $index !== null ? ($indexMap[$index] ?? null) : null;
                $label = $meta
                    ? "{$meta['header']} ({$meta['namespace']}.{$meta['key']})"
                    : 'metafield';

                $message = $error['message'] ?? 'Unknown error';
                $warnings[] = [
                    'product_id' => $product->id,
                    'warning' => "{$label}: {$message}",
                ];
            }
        }

        return $warnings;
    }

    private function metafieldsFromRow(
        Product $product,
        string $productId,
        array $rowData,
        array &$warnings,
        array $shopifyRawValues = [],
        ?string $currentCategoryId = null,
        ?string $currentCategoryName = null
    ): array
    {
        $definitions = ShopifyMetafield::query()
            ->where('import_id', $product->import_id)
            ->get()
            ->groupBy(fn (ShopifyMetafield $field) => "{$field->namespace}.{$field->key}");

        $existingRawValues = ShopifyMetafield::query()
            ->where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->get()
            ->mapWithKeys(fn (ShopifyMetafield $field): array => [
                "{$field->namespace}.{$field->key}" => (string) ($field->value ?? ''),
            ]);

        $existingRawValues = $existingRawValues->merge($shopifyRawValues);

        $hasShopifyCategoryContext = $this->hasCategoryContext($currentCategoryId, $currentCategoryName);

        $inputs = [];
        $indexMap = [];

        foreach ($rowData as $header => $value) {
            $identifier = $this->metafieldIdentifierFromHeader((string) $header);
            if (!$identifier) {
                continue;
            }

            $lookup = $identifier['namespace'] . '.' . $identifier['key'];

            $stringValue = is_scalar($value) ? trim((string) $value) : '';
            if ($stringValue === '') {
                continue;
            }

            $type = $definitions->get($lookup)?->first()?->type;
            if (!$type) {
                $type = $this->fallbackMetafieldType($header, $stringValue);
            }
            if (!$type) {
                continue;
            }

            if ($this->isSubtypeConstrainedShopifyMetafield($identifier['namespace'], $identifier['key'])) {
                // For constrained Shopify taxonomy metafields, trust the actual current Shopify category.
                if (!$hasShopifyCategoryContext) {
                    $warnings[] = [
                        'product_id' => $product->id,
                        'warning' => "Skipped metafield {$lookup}: missing Shopify taxonomy category on the product.",
                    ];
                    continue;
                }

                if (!$this->categorySupportsSubtypeConstrainedMetafield($identifier['namespace'], $identifier['key'], $currentCategoryId, $currentCategoryName)) {
                    // Gift Cards products should sync without noise even if jewelry-only CSV fields are present.
                    if (
                        $this->isGiftCardsCategoryContext($currentCategoryId, $currentCategoryName)
                        && $this->isJewelryOnlyShopifyMetafield($identifier['namespace'], $identifier['key'])
                    ) {
                        continue;
                    }

                    $categoryLabel = $this->categoryContextLabel($currentCategoryId, $currentCategoryName);
                    $warnings[] = [
                        'product_id' => $product->id,
                        'warning' => "Skipped metafield {$lookup}: product category '{$categoryLabel}' is not compatible with this Shopify taxonomy metafield.",
                    ];
                    continue;
                }
            }

            $stringValue = $this->normalizeMetafieldInputFromAccepted((string) $header, $stringValue, $type);

            $formatted = $this->formatMetafieldValue(
                $type,
                $stringValue,
                is_string($existingRawValues->get($lookup)) ? $existingRawValues->get($lookup) : null,
                $lookup,
                (string) $header
            );

            if ($formatted === null) {
                if ($this->isReferenceType($type)) {
                    $warnings[] = [
                        'product_id' => $product->id,
                        'warning' => "Skipped metafield {$lookup}: unable to resolve reference value to a Shopify GID.",
                    ];
                }
                continue;
            }

            $inputs[] = [
                'ownerId' => $productId,
                'namespace' => $identifier['namespace'],
                'key' => $identifier['key'],
                'type' => $type,
                'value' => $formatted,
            ];

            $indexMap[count($inputs) - 1] = [
                'header' => (string) $header,
                'namespace' => $identifier['namespace'],
                'key' => $identifier['key'],
            ];
        }

        return [
            'inputs' => $inputs,
            'indexMap' => $indexMap,
        ];
    }

    private function resolveShopifyId(string $handle): ?string
    {
        $data = $this->client->graphql($this->productByHandleQuery(), [
            'handle' => $handle,
        ]);

        return data_get($data, 'productByHandle.id');
    }

    private function resolveProductId(Product $product): ?string
    {
        $existingId = trim((string) ($product->shopify_id ?? ''));
        if ($existingId !== '') {
            return $existingId;
        }

        $handle = trim((string) ($product->handle ?? ''));
        if ($handle === '') {
            return null;
        }

        return $this->resolveShopifyId($handle);
    }

    private function productByHandleDetails(string $handle): array
    {
        $data = $this->client->graphql($this->productByHandleDetailsQuery(), [
            'handle' => $handle,
        ]);

        return data_get($data, 'productByHandle', []) ?: [];
    }

    private function productByIdDetails(string $id): array
    {
        $data = $this->client->graphql($this->productByIdDetailsQuery(), [
            'id' => $id,
        ]);

        return data_get($data, 'product', []) ?: [];
    }

    private function productDetails(Product $product, ?string $handleOverride = null, ?string $productIdOverride = null): array
    {
        $productId = trim((string) ($productIdOverride ?? $product->shopify_id ?? ''));
        if ($productId !== '') {
            return $this->productByIdDetails($productId);
        }

        $handle = trim((string) ($handleOverride ?: $product->handle));
        return $handle !== '' ? $this->productByHandleDetails($handle) : [];
    }

    /**
     * @return array<string, string>
     */
    private function productMetafieldRawValuesByHandle(string $handle): array
    {
        $data = $this->client->graphql($this->productByHandleMetafieldsQuery(), [
            'handle' => $handle,
        ]);

        $nodes = data_get($data, 'productByHandle.metafields.nodes', []);
        if (!is_array($nodes) || empty($nodes)) {
            return [];
        }

        $map = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $namespace = trim((string) ($node['namespace'] ?? ''));
            $key = trim((string) ($node['key'] ?? ''));
            $value = (string) ($node['value'] ?? '');
            if ($namespace === '' || $key === '' || $value === '') {
                continue;
            }

            $map["{$namespace}.{$key}"] = $value;
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function productMetafieldRawValuesById(string $id): array
    {
        $data = $this->client->graphql($this->productByIdMetafieldsQuery(), [
            'id' => $id,
        ]);

        $nodes = data_get($data, 'product.metafields.nodes', []);
        if (!is_array($nodes) || empty($nodes)) {
            return [];
        }

        $map = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $namespace = trim((string) ($node['namespace'] ?? ''));
            $key = trim((string) ($node['key'] ?? ''));
            $value = (string) ($node['value'] ?? '');
            if ($namespace === '' || $key === '' || $value === '') {
                continue;
            }

            $map["{$namespace}.{$key}"] = $value;
        }

        return $map;
    }

    /**
     * @return array<string, string>
     */
    private function productMetafieldRawValues(Product $product, ?string $handleOverride = null, ?string $productIdOverride = null): array
    {
        $productId = trim((string) ($productIdOverride ?? $product->shopify_id ?? ''));
        if ($productId !== '') {
            return $this->productMetafieldRawValuesById($productId);
        }

        $handle = trim((string) ($handleOverride ?: $product->handle));
        return $handle !== '' ? $this->productMetafieldRawValuesByHandle($handle) : [];
    }

    /**
     * @return array<int, array{product_id:int, warning:string}>
     */
    private function updateVariantAndInventory(
        Product $product,
        string $productId,
        array $rowDataList,
        array $details
    ): array
    {
        $warnings = [];
        $primaryRow = ShopifyRow::query()
            ->where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->where('row_type', 'product_primary')
            ->first();
        $primaryRowData = is_array($primaryRow?->data) ? $primaryRow->data : [];
        $primaryCostPerItem = $this->valueFromRow($primaryRowData, HeaderStore::COST_PER_ITEM, null);
        $primaryWeightUnit = $this->valueFromRow($primaryRowData, HeaderStore::VARIANT_WEIGHT_UNIT, null);
        $primaryGrams = $this->valueFromRow($primaryRowData, HeaderStore::VARIANT_GRAMS, null);
        logger()->info('Shopify variant/inventory update entry', [
            'product_id' => $product->id,
            'handle' => $product->handle,
            'row_data_count' => count($rowDataList),
            'has_primary_cost_per_item' => $primaryCostPerItem !== null,
            'primary_cost_per_item' => $primaryCostPerItem,
            'primary_weight_unit' => $primaryWeightUnit,
            'primary_grams' => $primaryGrams,
        ]);

        $localVariantsOrdered = $product->variants()->orderBy('id')->get()->values();
        $variantSources = $this->buildVariantSources($rowDataList, $localVariantsOrdered);
        if (empty($variantSources)) {
            return $warnings;
        }

        $details = $this->syncShopifyProductOptionsForVariants($product, $productId, $variantSources, $details, $warnings);
        $details = $this->createMissingShopifyVariants($product, $productId, $variantSources, $details, $primaryCostPerItem, $primaryWeightUnit, $primaryGrams, $warnings);

        $variantNodes = data_get($details, 'variants.nodes', []);
        if (!is_array($variantNodes) || empty($variantNodes)) {
            return $warnings;
        }

        $variantNodesOrdered = array_values(array_filter($variantNodes, fn ($node) => is_array($node) && !empty($node['id'])));
        $shopifyVariantsBySku = [];
        $shopifyVariantsBySignature = [];
        foreach ($variantNodesOrdered as $variantNode) {
            $sku = trim((string) ($variantNode['sku'] ?? ''));
            if ($sku !== '') {
                $shopifyVariantsBySku[$sku] = $variantNode;
            }

            $signature = $this->variantOptionSignatureFromNode($variantNode);
            if ($signature !== '') {
                $shopifyVariantsBySignature[$signature] = $variantNode;
            }
        }

        $locationId = null;
        $variantInputs = [];

        foreach ($variantSources as $rowIndex => $variantSource) {
            $rowData = $variantSource['row'];
            $localVariant = $variantSource['local'];
            logger()->info('Shopify variant row data keys', [
                'product_id' => $product->id,
                'handle' => $product->handle,
                'row_index' => $rowIndex,
                'row_keys' => array_keys($rowData),
                'expected_variant_price_key' => HeaderStore::VARIANT_PRICE,
                'expected_weight_unit_key' => HeaderStore::VARIANT_WEIGHT_UNIT,
                'expected_grams_key' => HeaderStore::VARIANT_GRAMS,
                'expected_cost_key' => HeaderStore::COST_PER_ITEM,
            ]);

            $desiredSku = $this->variantDesiredValue($localVariant, $rowData, 'sku', HeaderStore::VARIANT_SKU);
            $desiredOptionValues = $this->variantOptionValuesFromSource($rowData, $localVariant);
            $desiredSignature = $this->variantOptionSignature($desiredOptionValues);

            $variantNode = $desiredSku !== null
                ? ($shopifyVariantsBySku[$desiredSku] ?? null)
                : null;

            if ($variantNode === null && $desiredSignature !== '') {
                $variantNode = $shopifyVariantsBySignature[$desiredSignature] ?? null;
            }

            if ($variantNode === null) {
                $variantNode = $variantNodesOrdered[$rowIndex] ?? null;
            }

            if (!$variantNode) {
                continue;
            }

            $variantId = $variantNode['id'] ?? null;
            $inventoryItemId = data_get($variantNode, 'inventoryItem.id');

            if ($variantId) {
                $input = ['id' => $variantId];
                $inventoryItemInput = [];

                // Variant Price from row data is the source of truth for Shopify variant price.
                $rowVariantPriceRaw = $this->valueFromRow($rowData, HeaderStore::VARIANT_PRICE, null);
                $localVariantPriceRaw = $localVariant?->price;
                // Prefer local variant value from the editor; row value is fallback.
                $priceRaw = $localVariantPriceRaw ?? $rowVariantPriceRaw;
                $price = $this->normalizeNumeric($priceRaw);
                logger()->info('Shopify variant price resolution', [
                    'product_id' => $product->id,
                    'handle' => $product->handle,
                    'row_index' => $rowIndex,
                    'variant_id' => $variantId,
                    'lookup_sku' => $desiredSku,
                    'desired_sku' => $desiredSku,
                    'local_variant_price' => $localVariantPriceRaw,
                    'row_variant_price' => $rowVariantPriceRaw,
                    'price_source' => $localVariantPriceRaw !== null ? 'local.variant.price' : 'row.variant_price',
                    'resolved_price_raw' => $priceRaw,
                    'resolved_price_numeric' => $price,
                ]);

                $compareAtRaw = $localVariant?->compare_at_price;
                if ($compareAtRaw === null) {
                    $compareAtRaw = $this->valueFromRow($rowData, HeaderStore::VARIANT_COMPARE_AT, null);
                }
                $compareAt = $this->normalizeNumeric($compareAtRaw);
                $weightUnit = $this->valueFromRow(
                    $rowData,
                    HeaderStore::VARIANT_WEIGHT_UNIT,
                    $localVariant?->weight_unit ?? $primaryWeightUnit
                );
                $barcode = $this->valueFromRow($rowData, HeaderStore::VARIANT_BARCODE, $localVariant?->barcode);
                $grams = $this->normalizeNumeric(
                    $this->valueFromRow($rowData, HeaderStore::VARIANT_GRAMS, $localVariant?->weight ?? $primaryGrams)
                );
                logger()->info('Shopify variant weight resolution', [
                    'product_id' => $product->id,
                    'handle' => $product->handle,
                    'row_index' => $rowIndex,
                    'variant_id' => $variantId,
                    'row_weight_unit' => $this->valueFromRow($rowData, HeaderStore::VARIANT_WEIGHT_UNIT, null),
                    'local_weight_unit' => $localVariant?->weight_unit,
                    'primary_weight_unit' => $primaryWeightUnit,
                    'resolved_weight_unit' => $weightUnit,
                    'row_grams' => $this->valueFromRow($rowData, HeaderStore::VARIANT_GRAMS, null),
                    'local_grams' => $localVariant?->weight,
                    'primary_grams' => $primaryGrams,
                    'resolved_grams' => $grams,
                ]);

                $optionValues = $this->variantOptionValuesFromSource($rowData, $localVariant, $variantNode);

                if ($desiredSku !== null) {
                    $inventoryItemInput['sku'] = $desiredSku;
                    // Keep Shopify Variant Barcode aligned with Variant SKU.
                    $barcode = $desiredSku;
                }
                if ($price !== null) {
                    $input['price'] = number_format($price, 2, '.', '');
                }
                if ($compareAt !== null) {
                    $input['compareAtPrice'] = number_format($compareAt, 2, '.', '');
                }
                if ($barcode !== null) {
                    $input['barcode'] = (string) $barcode;
                }
                if (!empty($optionValues)) {
                    $input['optionValues'] = $optionValues;
                }
                if ($grams !== null || $weightUnit !== null) {
                    $unit = $this->mapWeightUnit($weightUnit);
                    $inventoryItemInput['measurement'] = [
                        'weight' => [
                            'unit' => $unit,
                            'value' => $grams !== null ? $this->weightFromGrams($grams, $unit) : 0.0,
                        ],
                    ];
                } else {
                    logger()->warning('Shopify variant weight skipped (no grams and no weight unit)', [
                        'product_id' => $product->id,
                        'handle' => $product->handle,
                        'row_index' => $rowIndex,
                        'variant_id' => $variantId,
                    ]);
                }

                // Cost per item now travels via ProductVariantsBulkInput.inventoryItem.cost.
                $costPerItemRaw = $this->valueFromRow($rowData, HeaderStore::COST_PER_ITEM, $primaryCostPerItem);
                $costPerItem = $this->normalizeNumeric($costPerItemRaw);
                logger()->info('Shopify cost per item resolution', [
                    'product_id' => $product->id,
                    'handle' => $product->handle,
                    'row_index' => $rowIndex,
                    'variant_id' => $variantId,
                    'row_cost_per_item' => $rowData[HeaderStore::COST_PER_ITEM] ?? null,
                    'primary_cost_per_item' => $primaryCostPerItem,
                    'resolved_cost_per_item_raw' => $costPerItemRaw,
                    'resolved_cost_per_item_numeric' => $costPerItem,
                ]);
                if ($costPerItem !== null) {
                    $inventoryItemInput['cost'] = (float) number_format($costPerItem, 2, '.', '');
                }

                if (!empty($inventoryItemInput)) {
                    $input['inventoryItem'] = $inventoryItemInput;
                }

                if (count($input) > 1) {
                    logger()->info('Shopify variant payload queued', [
                        'product_id' => $product->id,
                        'handle' => $product->handle,
                        'variant_id' => $variantId,
                        'payload' => $input,
                    ]);
                    $variantInputs[] = $input;
                } else {
                    logger()->warning('Shopify variant payload skipped (no mutable fields)', [
                        'product_id' => $product->id,
                        'handle' => $product->handle,
                        'variant_id' => $variantId,
                        'desired_sku' => $desiredSku,
                    ]);
                }
            }

            $inventoryQty = $this->normalizeNumeric(
                $this->valueFromRow($rowData, HeaderStore::VARIANT_INVENTORY_QTY, $localVariant?->inventory_qty)
            );
            if ($inventoryItemId && $inventoryQty !== null) {
                $locationId = $locationId ?? $this->firstLocationId();
                if ($locationId) {
                    $data = $this->client->graphql($this->inventorySetMutation(), [
                        'input' => [
                            'name' => 'available',
                            'reason' => 'correction',
                            'ignoreCompareQuantity' => true,
                            'referenceDocumentUri' => sprintf(
                                'logistics://shopify-editor/product/%d/variant/%s',
                                (int) $product->id,
                                rawurlencode((string) $variantId)
                            ),
                            'quantities' => [[
                                'inventoryItemId' => $inventoryItemId,
                                'locationId' => $locationId,
                                'quantity' => (int) $inventoryQty,
                            ]],
                        ],
                    ]);
                    $errors = data_get($data, 'inventorySetQuantities.userErrors', []);
                    if (is_array($errors) && !empty($errors)) {
                        $messages = $this->formatUserErrors($errors);
                        $warnings[] = [
                            'product_id' => $product->id,
                            'warning' => $messages !== '' ? $messages : 'Shopify inventory update failed.',
                        ];
                    }
                } else {
                    $warnings[] = [
                        'product_id' => $product->id,
                        'warning' => 'No Shopify location found for inventory update.',
                    ];
                }
            }
        }

        if (!empty($variantInputs)) {
            logger()->info('Shopify variant bulk update start', [
                'product_id' => $product->id,
                'handle' => $product->handle,
                'variant_input_count' => count($variantInputs),
                'variant_inputs' => $variantInputs,
            ]);
            $data = $this->client->graphql($this->variantsBulkUpdateMutation(), [
                'productId' => $productId,
                'variants' => $variantInputs,
            ]);

            $errors = data_get($data, 'productVariantsBulkUpdate.userErrors', []);
            if (is_array($errors) && !empty($errors)) {
                logger()->error('Shopify variant bulk update errors', [
                    'product_id' => $product->id,
                    'handle' => $product->handle,
                    'errors' => $errors,
                ]);
                $messages = $this->formatUserErrors($errors);
                throw new \RuntimeException($messages !== '' ? $messages : 'Shopify variant update failed.');
            }
            logger()->info('Shopify variant bulk update success', [
                'product_id' => $product->id,
                'handle' => $product->handle,
                'updated_count' => count($variantInputs),
            ]);
        } else {
            logger()->warning('Shopify variant bulk update skipped (no variant inputs)', [
                'product_id' => $product->id,
                'handle' => $product->handle,
            ]);
        }

        return $warnings;
    }

    private function firstLocationId(): ?string
    {
        $data = $this->client->graphql($this->locationsQuery(), []);
        return data_get($data, 'locations.nodes.0.id');
    }

    private function productByHandleQuery(): string
    {
        return <<<'GQL'
query ProductByHandle($handle: String!) {
  productByHandle(handle: $handle) {
    id
  }
}
GQL;
    }

    private function productByHandleDetailsQuery(): string
    {
        return <<<'GQL'
query ProductByHandleDetails($handle: String!) {
  productByHandle(handle: $handle) {
    id
    options {
      id
      name
      position
      values
      optionValues {
        id
        name
        hasVariants
      }
    }
    category {
      id
      name
    }
    productCategory {
      productTaxonomyNode {
        id
        fullName
      }
    }
    variants(first: 250) {
      nodes {
        id
        title
        sku
        selectedOptions {
          name
          value
        }
        media(first: 20) {
          nodes {
            ... on MediaImage {
              id
            }
          }
        }
        inventoryItem {
          id
          unitCost { currencyCode }
        }
      }
    }
    media(first: 50) {
      nodes {
        ... on MediaImage {
          id
          image {
            url
          }
        }
      }
    }
    images(first: 50) {
      nodes { id url }
    }
  }
}
GQL;
    }

    private function productByIdDetailsQuery(): string
    {
        return <<<'GQL'
query ProductByIdDetails($id: ID!) {
  product(id: $id) {
    id
    options {
      id
      name
      position
      values
      optionValues {
        id
        name
        hasVariants
      }
    }
    category {
      id
      name
    }
    productCategory {
      productTaxonomyNode {
        id
        fullName
      }
    }
    variants(first: 250) {
      nodes {
        id
        title
        sku
        selectedOptions {
          name
          value
        }
        media(first: 20) {
          nodes {
            ... on MediaImage {
              id
            }
          }
        }
        inventoryItem {
          id
          unitCost { currencyCode }
        }
      }
    }
    media(first: 50) {
      nodes {
        ... on MediaImage {
          id
          image {
            url
          }
        }
      }
    }
    images(first: 50) {
      nodes { id url }
    }
  }
}
GQL;
    }

    private function productByHandleMetafieldsQuery(): string
    {
        return <<<'GQL'
query ProductByHandleMetafields($handle: String!) {
  productByHandle(handle: $handle) {
    metafields(first: 250) {
      nodes {
        namespace
        key
        value
      }
    }
  }
}
GQL;
    }

    private function productByIdMetafieldsQuery(): string
    {
        return <<<'GQL'
query ProductByIdMetafields($id: ID!) {
  product(id: $id) {
    metafields(first: 250) {
      nodes {
        namespace
        key
        value
      }
    }
  }
}
GQL;
    }

    private function productUpdateMutation(): string
    {
        return <<<'GQL'
mutation ProductUpdate($input: ProductInput!) {
  productUpdate(input: $input) {
    product { id }
    userErrors { field message }
  }
}
GQL;
    }

    private function variantsBulkUpdateMutation(): string
    {
        return <<<'GQL'
mutation ProductVariantsBulkUpdate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
  productVariantsBulkUpdate(productId: $productId, variants: $variants) {
    productVariants { id }
    userErrors { field message }
  }
}
GQL;
    }

    private function variantsBulkCreateMutation(): string
    {
        return <<<'GQL'
mutation ProductVariantsBulkCreate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
  productVariantsBulkCreate(productId: $productId, variants: $variants) {
    productVariants {
      id
      title
      selectedOptions {
        name
        value
      }
    }
    userErrors { field message }
  }
}
GQL;
    }

    private function productOptionsCreateMutation(): string
    {
        return <<<'GQL'
mutation ProductOptionsCreate($productId: ID!, $options: [OptionCreateInput!]!, $variantStrategy: ProductOptionCreateVariantStrategy) {
  productOptionsCreate(productId: $productId, options: $options, variantStrategy: $variantStrategy) {
    product {
      id
      options {
        id
        name
        position
        values
        optionValues {
          id
          name
          hasVariants
        }
      }
    }
    userErrors { field message code }
  }
}
GQL;
    }

    private function productOptionUpdateMutation(): string
    {
        return <<<'GQL'
mutation ProductOptionUpdate(
  $productId: ID!,
  $option: OptionUpdateInput!,
  $optionValuesToAdd: [OptionValueCreateInput!],
  $optionValuesToUpdate: [OptionValueUpdateInput!],
  $variantStrategy: ProductOptionUpdateVariantStrategy
) {
  productOptionUpdate(
    productId: $productId,
    option: $option,
    optionValuesToAdd: $optionValuesToAdd,
    optionValuesToUpdate: $optionValuesToUpdate,
    variantStrategy: $variantStrategy
  ) {
    product {
      id
      options {
        id
        name
        position
        values
        optionValues {
          id
          name
          hasVariants
        }
      }
    }
    userErrors { field message code }
  }
}
GQL;
    }

    private function productCreateMediaMutation(): string
    {
        return <<<'GQL'
mutation ProductCreateMedia($productId: ID!, $media: [CreateMediaInput!]!) {
  productCreateMedia(productId: $productId, media: $media) {
    media {
      ... on MediaImage {
        id
      }
    }
    mediaUserErrors {
      field
      message
    }
  }
}
GQL;
    }

    private function inventoryItemUpdateMutation(): string
    {
        return <<<'GQL'
mutation InventoryItemUpdate($inventoryItemId: ID!, $cost: Decimal!) {
  inventoryItemUpdate(
    id: $inventoryItemId,
    input: {
      cost: $cost
    }
  ) {
    inventoryItem {
      id
      unitCost {
        amount
        currencyCode
      }
    }
    userErrors {
      field
      message
    }
  }
}
GQL;
    }

    private function inventorySetMutation(): string
    {
        return <<<'GQL'
mutation InventorySetQuantities($input: InventorySetQuantitiesInput!) {
  inventorySetQuantities(input: $input) {
    userErrors { field message code }
  }
}
GQL;
    }

    private function locationsQuery(): string
    {
        return <<<'GQL'
query Locations {
  locations(first: 1) {
    nodes { id }
  }
}
GQL;
    }

    private function metafieldsSetMutation(): string
    {
        return <<<'GQL'
mutation MetafieldsSet($metafields: [MetafieldsSetInput!]!) {
  metafieldsSet(metafields: $metafields) {
    metafields { id }
    userErrors { field message }
  }
}
GQL;
    }

    private function metafieldDefinitionQuery(): string
    {
        return <<<'GQL'
query MetafieldDefinition($namespace: String!, $key: String!) {
  metafieldDefinition(ownerType: PRODUCT, namespace: $namespace, key: $key) {
    validations {
      name
      value
    }
  }
}
GQL;
    }

    private function metaobjectDefinitionTypesQuery(): string
    {
        return <<<'GQL'
query MetaobjectDefinitionTypes($ids: [ID!]!) {
  nodes(ids: $ids) {
    ... on MetaobjectDefinition {
      id
      type
    }
  }
}
GQL;
    }

    private function metaobjectsByTypeQuery(): string
    {
        return <<<'GQL'
query MetaobjectsByType($type: String!) {
  metaobjects(type: $type, first: 250) {
    nodes {
      id
      displayName
      handle
      fields {
        key
        value
      }
    }
  }
}
GQL;
    }

    private function productsSearchQuery(): string
    {
        return <<<'GQL'
query ProductsSearch($query: String!) {
  products(first: 1, query: $query) {
    nodes {
      id
    }
  }
}
GQL;
    }

    private function mapStatus(string $status): string
    {
        $normalized = strtolower(trim($status));
        return match ($normalized) {
            'active' => 'ACTIVE',
            'archived' => 'ARCHIVED',
            default => 'DRAFT',
        };
    }

    private function resolveProductCategoryGid(?string $productCategory, ?string $productType): ?string
    {
        $normalizedCategory = CategoryTypeMap::normalizeCategory($productCategory) ?? '';
        if ($normalizedCategory !== '' && str_starts_with($normalizedCategory, 'gid://')) {
            return $normalizedCategory;
        }

        if ($normalizedCategory !== '' && !$this->looksUncategorizedCategory($normalizedCategory)) {
            $seededCategoryGid = $this->seededCategoryGidByName($normalizedCategory);
            if ($seededCategoryGid !== null) {
                return $seededCategoryGid;
            }

            $mappedCategory = CategoryTypeMap::byCategory($normalizedCategory);
            $mappedCategoryGid = $this->normalizeTaxonomyGid($mappedCategory['shopify_taxonomy_gid'] ?? null);
            if ($mappedCategoryGid !== null) {
                return $mappedCategoryGid;
            }

        }

        $typeCategory = CategoryTypeMap::normalizeType($productType) ?? '';
        if ($typeCategory !== '') {
            if (array_key_exists($typeCategory, $this->categoryIdByTypeCache)) {
                return $this->categoryIdByTypeCache[$typeCategory];
            }

            $mappedType = CategoryTypeMap::byType($typeCategory);
            $mappedTypeGid = $this->normalizeTaxonomyGid($mappedType['shopify_taxonomy_gid'] ?? null);
            if ($mappedTypeGid !== null) {
                $this->categoryIdByTypeCache[$typeCategory] = $mappedTypeGid;
                return $mappedTypeGid;
            }

            $mappedTypeCategory = CategoryTypeMap::normalizeCategory($mappedType['category'] ?? null);
            if ($mappedTypeCategory !== null) {
                $seededByTypeCategory = $this->seededCategoryGidByName($mappedTypeCategory);
                if ($seededByTypeCategory !== null) {
                    $this->categoryIdByTypeCache[$typeCategory] = $seededByTypeCategory;
                    return $seededByTypeCategory;
                }
            }

            $this->categoryIdByTypeCache[$typeCategory] = null;
        }

        return null;
    }

    private function seededCategoryGidByName(string $categoryName): ?string
    {
        $normalized = CategoryTypeMap::normalizeCategory($categoryName);
        if ($normalized === null || $normalized === '') {
            return null;
        }

        $key = strtolower($normalized);
        if (array_key_exists($key, $this->categoryIdByNameCache)) {
            return $this->categoryIdByNameCache[$key];
        }

        $gid = Category::query()
            ->whereRaw('LOWER(name) = ?', [$key])
            ->value('shopify_taxonomy_gid');

        $resolved = $this->normalizeTaxonomyGid($gid);
        $this->categoryIdByNameCache[$key] = $resolved;

        return $resolved;
    }

    private function normalizeTaxonomyGid(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $gid = trim($value);
        if ($gid === '' || !str_starts_with($gid, 'gid://shopify/')) {
            return null;
        }

        return $gid;
    }

    /**
     * @return array<int, array{product_id:int, warning:string}>
     */
    private function attemptCategoryUpdate(Product $product, string $productId, string $taxonomyGid, ?string $productType): array
    {
        $warnings = [];
        $lastError = null;

        $candidates = $this->taxonomyIdCandidates($taxonomyGid);

        if ($this->categoryGraphqlSupported !== false) {
            foreach ($candidates as $candidate) {
                $input = [
                    'id' => $productId,
                    'category' => $candidate, // ✅ correct: string ID
                ];

                try {
                    $data = $this->client->graphql($this->productUpdateMutation(), [
                        'input' => $input,
                    ]);
                } catch (\Throwable $e) {
                    $message = $e->getMessage();
                    if ($this->isUnsupportedCategoryInputMessage($message)) {
                        $this->categoryGraphqlSupported = false;
                        break;
                    }
                    $lastError = $message;
                    continue;
                }

                $errors = data_get($data, 'productUpdate.userErrors', []);
                if (!is_array($errors) || empty($errors)) {
                    $this->categoryGraphqlSupported = true;
                    return $warnings; // success
                }

                $messages = $this->formatUserErrors($errors);
                if ($this->isUnsupportedCategoryInputMessage($messages)) {
                    $this->categoryGraphqlSupported = false;
                    break;
                }

                $lastError = $messages;
            }
        }

        // REST fallback (only if GraphQL truly not supported)
        if ($this->categoryGraphqlSupported === false) {
            $restSet = $this->attemptCategoryUpdateViaRest($productId, $taxonomyGid);
            if ($restSet) {
                return $warnings;
            }
        }

        if ($lastError !== null) {
            $warnings[] = [
                'product_id' => $product->id,
                'warning' => "Unable to set Shopify taxonomy category automatically: {$lastError}",
            ];
        }

        return $warnings;
    }

    private function attemptCategoryUpdateViaRest(string $productId, string $taxonomyGid): bool
    {
        $numericProductId = $this->extractNumericId($productId, 'Product');
        $taxonomyNodeId = $this->extractTaxonomyNodeTail($taxonomyGid);
        if ($numericProductId === null || $taxonomyNodeId === null) {
            return false;
        }

        try {
            $this->client->rest('PUT', "products/{$numericProductId}.json", [
                'product' => [
                    'id' => $numericProductId,
                    'product_taxonomy_node_id' => $taxonomyNodeId,
                ],
            ]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function extractNumericId(string $gid, string $resource): ?string
    {
        if (!preg_match('#gid://shopify/' . preg_quote($resource, '#') . '/([0-9]+)$#', trim($gid), $m)) {
            return null;
        }

        return $m[1] ?? null;
    }

    // ✅ KEY FIX: don’t force numeric tail; Shopify can change formats
    private function extractTaxonomyNodeTail(string $gid): ?string
    {
        if (!preg_match('#gid://shopify/(?:TaxonomyCategory|ProductTaxonomyNode)/(.+)$#', trim($gid), $m)) {
            return null;
        }

        $tail = trim((string) ($m[1] ?? ''));
        return $tail === '' ? null : $tail;
    }

    /**
     * @return array<int, string>
     */
    private function taxonomyIdCandidates(string $taxonomyGid): array
    {
        $value = trim($taxonomyGid);
        if ($value === '') {
            return [];
        }

        $candidates = [];

        // Prefer TaxonomyCategory for modern ProductInput.category usage
        if (str_contains($value, 'gid://shopify/TaxonomyCategory/')) {
            $candidates[] = $value;
            $candidates[] = str_replace('gid://shopify/TaxonomyCategory/', 'gid://shopify/ProductTaxonomyNode/', $value);
        } elseif (str_contains($value, 'gid://shopify/ProductTaxonomyNode/')) {
            $candidates[] = str_replace('gid://shopify/ProductTaxonomyNode/', 'gid://shopify/TaxonomyCategory/', $value);
            $candidates[] = $value;
        } else {
            $candidates[] = $value;
        }

        $candidates = array_values(array_unique(array_filter($candidates, fn ($v) => is_string($v) && trim($v) !== '')));
        return $candidates;
    }

    private function isUnsupportedCategoryInputMessage(string $message): bool
    {
        $normalized = strtolower($message);
        return str_contains($normalized, 'field is not defined on productinput')
            || str_contains($normalized, 'unknown argument')
            || str_contains($normalized, 'cannot query field')
            || str_contains($normalized, 'expected type')
            || str_contains($normalized, 'provided invalid value for category')
            || str_contains($normalized, 'provided invalid value for productcategory')
            || str_contains($normalized, 'provided invalid value for producttaxonomynodeid');
    }
    private function looksUncategorizedCategory(string $value): bool
    {
        return str_contains(strtolower(trim($value)), 'uncategorized');
    }

    private function hasCategoryContext(?string $currentCategoryId, ?string $currentCategoryName): bool
    {
        $id = trim((string) ($currentCategoryId ?? ''));
        if ($id === '') {
            return false;
        }

        $name = trim((string) ($currentCategoryName ?? ''));
        return $name === '' || !$this->looksUncategorizedCategory($name);
    }

    private function metafieldIdentifierFromHeader(string $header): ?array
    {
        if ($header === HeaderStore::UVP_SHORT_PARAGRAPH) {
            return [
                'namespace' => 'custom',
                'key' => 'uvp_short_paragraph',
            ];
        }

        if (!preg_match('/\\(product\\.metafields\\.([^.]+)\\.([^)]+)\\)/', $header, $matches)) {
            return null;
        }

        return [
            'namespace' => $matches[1],
            'key' => $matches[2],
        ];
    }

    private function nullIfEmpty(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function fallbackMetafieldType(string $header, string $value): ?string
    {
        if ($header === HeaderStore::SEO_DEINDEX) {
            return 'boolean';
        }
        if ($header === HeaderStore::UVP_SHORT_PARAGRAPH) {
            return 'rich_text_field';
        }

        return 'single_line_text_field';
    }

    private function formatMetafieldValue(
        string $type,
        string $value,
        ?string $existingRawValue = null,
        ?string $lookup = null,
        ?string $header = null
    ): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if ($type === 'boolean') {
            $normalized = strtolower($trimmed);
            return in_array($normalized, ['1', 'true', 'yes', 'y'], true) ? 'true' : 'false';
        }

        if ($type === 'number_integer') {
            $number = $this->normalizeNumeric($trimmed);
            return $number === null ? null : (string) (int) $number;
        }

        if ($type === 'number_decimal') {
            $number = $this->normalizeNumeric($trimmed);
            return $number === null ? null : rtrim(rtrim(number_format($number, 6, '.', ''), '0'), '.');
        }

        if ($type === 'rich_text_field') {
            return $this->richTextFieldValueFromInput($trimmed);
        }

        if (str_starts_with($type, 'list.') && str_ends_with($type, 'reference')) {
            if ($header !== null) {
                $trimmed = $this->normalizeListAcceptedValue($header, $trimmed) ?? $trimmed;
            }

            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $items = array_values(array_filter($decoded, fn ($item) => is_string($item) && str_starts_with($item, 'gid://')));
                if (empty($items) && $lookup !== null) {
                    $resolved = [];
                    foreach ($decoded as $item) {
                        if (!is_string($item)) {
                            continue;
                        }
                        $gid = $this->resolveReferenceTokenFromShopify($lookup, trim($item));
                        if ($gid !== null) {
                            $resolved[] = $gid;
                        }
                    }
                    $items = array_values(array_unique($resolved));
                }
                return empty($items) ? null : json_encode($items);
            }

            $parts = $this->referenceTokensFromRaw($trimmed);

            $items = array_values(array_filter($parts, fn ($item) => str_starts_with($item, 'gid://')));
            if (!empty($items)) {
                return json_encode($items);
            }

            if ($lookup !== null) {
                $resolved = $this->resolveReferenceValueFromShopify($lookup, $type, $trimmed);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
            return $this->referenceFallbackFromExistingRaw($type, $existingRawValue);
        }

        if (str_ends_with($type, 'reference')) {
            if ($header !== null) {
                $trimmed = $this->normalizeSingleAcceptedValue($header, $trimmed) ?? $trimmed;
            }

            if (str_starts_with($trimmed, 'gid://')) {
                return $trimmed;
            }

            if ($lookup !== null) {
                $resolved = $this->resolveReferenceValueFromShopify($lookup, $type, $trimmed);
                if ($resolved !== null) {
                    return $resolved;
                }
            }
            return $this->referenceFallbackFromExistingRaw($type, $existingRawValue);
        }

        if (str_starts_with($type, 'list.')) {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $items = array_values(array_filter(array_map('trim', $decoded)));
                return empty($items) ? null : json_encode($items);
            }

            $parts = str_contains($trimmed, ';')
                ? array_map('trim', explode(';', $trimmed))
                : array_map('trim', explode(',', $trimmed));
            $items = array_values(array_filter($parts, fn ($item) => $item !== ''));
            return empty($items) ? null : json_encode($items);
        }

        return $trimmed;
    }

    private function richTextFieldValueFromInput(string $input): ?string
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            return null;
        }

        $decoded = json_decode($trimmed, true);
        if (is_array($decoded) && (($decoded['type'] ?? null) === 'root')) {
            return json_encode($decoded, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (preg_match('/<[^>]+>/', $trimmed)) {
            $root = $this->richTextRootFromHtml($trimmed);
            if ($root !== null) {
                return json_encode($root, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
        }

        $paragraphs = preg_split('/\R+/', $trimmed) ?: [$trimmed];
        $children = [];
        foreach ($paragraphs as $paragraph) {
            $text = trim((string) $paragraph);
            if ($text === '') {
                continue;
            }

            $children[] = [
                'type' => 'paragraph',
                'children' => [[
                    'type' => 'text',
                    'value' => $text,
                ]],
            ];
        }

        if (empty($children)) {
            $children[] = [
                'type' => 'paragraph',
                'children' => [[
                    'type' => 'text',
                    'value' => $trimmed,
                ]],
            ];
        }

        return json_encode([
            'type' => 'root',
            'children' => $children,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function richTextRootFromHtml(string $html): ?array
    {
        if (!class_exists(\DOMDocument::class)) {
            return null;
        }

        $dom = new \DOMDocument('1.0', 'UTF-8');
        $prev = libxml_use_internal_errors(true);
        $loaded = $dom->loadHTML('<?xml encoding="utf-8" ?><body>' . $html . '</body>');
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$loaded) {
            return null;
        }

        $bodyNodes = $dom->getElementsByTagName('body');
        $body = $bodyNodes->length > 0 ? $bodyNodes->item(0) : null;
        if (!$body instanceof \DOMNode) {
            return null;
        }

        $paragraphs = [];
        foreach ($body->childNodes as $node) {
            if ($node instanceof \DOMText && trim((string) $node->textContent) !== '') {
                $inline = $this->richTextInlineNodesFromDom([$node]);
                if (!empty($inline)) {
                    $paragraphs[] = [
                        'type' => 'paragraph',
                        'children' => $inline,
                    ];
                }
                continue;
            }

            if (!$node instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);
            if (in_array($tag, ['p', 'div'], true)) {
                $inline = $this->richTextInlineNodesFromDom(iterator_to_array($node->childNodes));
                if (!empty($inline)) {
                    $paragraphs[] = [
                        'type' => 'paragraph',
                        'children' => $inline,
                    ];
                }
                continue;
            }

            $inline = $this->richTextInlineNodesFromDom([$node]);
            if (!empty($inline)) {
                $paragraphs[] = [
                    'type' => 'paragraph',
                    'children' => $inline,
                ];
            }
        }

        if (empty($paragraphs)) {
            return null;
        }

        return [
            'type' => 'root',
            'children' => $paragraphs,
        ];
    }

    /**
     * @param array<int, \DOMNode> $nodes
     * @return array<int, array<string, mixed>>
     */
    private function richTextInlineNodesFromDom(array $nodes): array
    {
        $result = [];
        foreach ($nodes as $node) {
            if ($node instanceof \DOMText) {
                $text = preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? '';
                if (trim($text) === '') {
                    continue;
                }
                $result[] = [
                    'type' => 'text',
                    'value' => $text,
                ];
                continue;
            }

            if (!$node instanceof \DOMElement) {
                continue;
            }

            $tag = strtolower($node->tagName);
            if ($tag === 'br') {
                $result[] = [
                    'type' => 'text',
                    'value' => "\n",
                ];
                continue;
            }

            $children = $this->richTextInlineNodesFromDom(iterator_to_array($node->childNodes));
            if (empty($children)) {
                continue;
            }

            if (in_array($tag, ['strong', 'b'], true)) {
                foreach ($children as $child) {
                    if (($child['type'] ?? null) === 'text') {
                        $child['bold'] = true;
                    }
                    $result[] = $child;
                }
                continue;
            }

            foreach ($children as $child) {
                $result[] = $child;
            }
        }

        return $result;
    }

    private function referenceFallbackFromExistingRaw(string $type, ?string $existingRawValue): ?string
    {
        if ($existingRawValue === null) {
            return null;
        }

        $trimmed = trim($existingRawValue);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($type, 'list.') && str_ends_with($type, 'reference')) {
            $decoded = json_decode($trimmed, true);
            if (!is_array($decoded)) {
                return null;
            }

            $items = array_values(array_filter($decoded, fn ($item) => is_string($item) && str_starts_with($item, 'gid://')));
            return empty($items) ? null : json_encode($items);
        }

        if (str_ends_with($type, 'reference') && str_starts_with($trimmed, 'gid://')) {
            return $trimmed;
        }

        return null;
    }

    private function resolveReferenceValueFromShopify(string $lookup, string $type, string $raw): ?string
    {
        $tokens = $this->referenceTokensFromRaw($raw);

        if (empty($tokens)) {
            return null;
        }

        if (str_starts_with($type, 'list.') && str_ends_with($type, 'reference')) {
            $resolved = [];
            foreach ($tokens as $token) {
                $gid = $this->resolveReferenceTokenFromShopify($lookup, $token);
                if ($gid !== null) {
                    $resolved[] = $gid;
                }
            }
            $resolved = array_values(array_unique($resolved));
            return empty($resolved) ? null : json_encode($resolved);
        }

        return $this->resolveReferenceTokenFromShopify($lookup, $tokens[0]);
    }

    private function resolveReferenceTokenFromShopify(string $lookup, string $token): ?string
    {
        if (str_starts_with($token, 'gid://')) {
            return $token;
        }

        $productGid = $this->resolveProductReferenceTokenFromShopify($lookup, $token);
        if ($productGid !== null) {
            return $productGid;
        }

        $map = $this->referenceLookupMap($lookup);
        if (empty($map)) {
            return $this->resolveHardcodedReferenceToken($lookup, $token);
        }

        $normalized = $this->normalizeReferenceLabel($token);
        $normalized = $this->applyLookupReferenceAlias($lookup, $normalized);
        $hardcoded = $this->resolveHardcodedReferenceByNormalized($lookup, $normalized);
        if ($hardcoded !== null) {
            return $hardcoded;
        }
        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        return $this->fuzzyReferenceMatch($map, $normalized);
    }

    private function resolveProductReferenceTokenFromShopify(string $lookup, string $token): ?string
    {
        if (!$this->isProductReferenceLookup($lookup)) {
            return null;
        }

        $trimmed = trim($token);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('#^gid://shopify/Product/[0-9]+$#', $trimmed)) {
            return $trimmed;
        }

        // Accept malformed flattened GIDs like "gidshopifyproduct8835945595016".
        if (preg_match('#^gidshopifyproduct([0-9]+)$#i', $trimmed, $m)) {
            return "gid://shopify/Product/{$m[1]}";
        }

        // Also accept mixed separators, e.g. "gid-shopify-product-8835945595016".
        if (preg_match('#^gid[^0-9]*shopify[^0-9]*product[^0-9]*([0-9]+)$#i', $trimmed, $m)) {
            return "gid://shopify/Product/{$m[1]}";
        }

        if (preg_match('#^/?products/([0-9]+)$#i', $trimmed, $m)) {
            return "gid://shopify/Product/{$m[1]}";
        }

        if (preg_match('#/products/([0-9]+)(?:[/?\\#].*)?$#i', $trimmed, $m)) {
            return "gid://shopify/Product/{$m[1]}";
        }

        if (preg_match('#^[0-9]+$#', $trimmed)) {
            return "gid://shopify/Product/{$trimmed}";
        }

        if (preg_match('#(?:^|/)products/([a-z0-9][a-z0-9\\-]*)(?:[/?\\#].*)?$#i', $trimmed, $m)) {
            $trimmed = $m[1];
        }

        $map = $this->productReferenceLookupMap();

        $normalized = $this->normalizeReferenceLabel($trimmed);
        if ($normalized === '') {
            return null;
        }

        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        $fuzzy = $this->fuzzyReferenceMatch($map, $normalized);
        if ($fuzzy !== null) {
            return $fuzzy;
        }

        return $this->resolveProductReferenceTokenFromShopifyRemote($trimmed);
    }

    private function isProductReferenceLookup(string $lookup): bool
    {
        return in_array($lookup, [
            'shopify--discovery--product_recommendation.complementary_products',
            'shopify--discovery--product_recommendation.related_products',
        ], true);
    }

    /**
     * @return array<string, string>
     */
    private function productReferenceLookupMap(): array
    {
        if ($this->productReferenceLookupCache !== null) {
            return $this->productReferenceLookupCache;
        }

        $map = [];

        Product::query()
            ->select(['id', 'shopify_id', 'handle', 'title'])
            ->whereNotNull('shopify_id')
            ->where('shopify_id', '!=', '')
            ->chunkById(500, function ($products) use (&$map): void {
                foreach ($products as $product) {
                    $gid = trim((string) ($product->shopify_id ?? ''));
                    if ($gid === '') {
                        continue;
                    }

                    foreach ([
                        trim((string) ($product->handle ?? '')),
                        trim((string) ($product->title ?? '')),
                    ] as $label) {
                        $normalized = $this->normalizeReferenceLabel($label);
                        if ($normalized !== '' && !isset($map[$normalized])) {
                            $map[$normalized] = $gid;
                        }
                    }
                }
            });

        Variant::query()
            ->join('products', 'products.id', '=', 'variants.product_id')
            ->select(['variants.sku as sku', 'products.shopify_id as shopify_id'])
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->whereNotNull('products.shopify_id')
            ->where('products.shopify_id', '!=', '')
            ->orderBy('variants.id')
            ->chunk(500, function ($variants) use (&$map): void {
                foreach ($variants as $variant) {
                    $sku = trim((string) ($variant->sku ?? ''));
                    $gid = trim((string) ($variant->shopify_id ?? ''));
                    if ($sku === '' || $gid === '') {
                        continue;
                    }

                    $normalized = $this->normalizeReferenceLabel($sku);
                    if ($normalized !== '' && !isset($map[$normalized])) {
                        $map[$normalized] = $gid;
                    }
                }
            });

        $this->productReferenceLookupCache = $map;
        return $map;
    }

    private function resolveProductReferenceTokenFromShopifyRemote(string $token): ?string
    {
        $normalized = $this->normalizeReferenceLabel($token);
        if ($normalized === '') {
            return null;
        }

        if (array_key_exists($normalized, $this->productReferenceRemoteCache)) {
            return $this->productReferenceRemoteCache[$normalized];
        }

        $gid = $this->resolveShopifyProductGidByHandle($normalized);

        if ($gid === null && str_contains($token, '/')) {
            $segments = array_values(array_filter(explode('/', trim($token, '/'))));
            $last = end($segments);
            if (is_string($last) && $last !== '') {
                $gid = $this->resolveShopifyProductGidByHandle($this->normalizeReferenceLabel($last));
            }
        }

        if ($gid === null && str_contains($token, ' ')) {
            $gid = $this->resolveShopifyProductGidByQuery($token);
        }

        $this->productReferenceRemoteCache[$normalized] = $gid;
        if ($gid !== null) {
            $this->productReferenceLookupCache = null;
        }

        return $gid;
    }

    private function resolveShopifyProductGidByHandle(string $handle): ?string
    {
        $trimmed = trim($handle);
        if ($trimmed === '') {
            return null;
        }

        try {
            $data = $this->client->graphql($this->productByHandleQuery(), [
                'handle' => $trimmed,
            ]);
        } catch (\Throwable) {
            return null;
        }

        $gid = trim((string) data_get($data, 'productByHandle.id', ''));
        return $gid !== '' ? $gid : null;
    }

    private function resolveShopifyProductGidByQuery(string $token): ?string
    {
        $query = trim($token);
        if ($query === '') {
            return null;
        }

        try {
            $data = $this->client->graphql($this->productsSearchQuery(), [
                'query' => $query,
            ]);
        } catch (\Throwable) {
            return null;
        }

        $nodes = data_get($data, 'products.nodes', []);
        if (!is_array($nodes) || empty($nodes)) {
            return null;
        }

        $gid = trim((string) data_get($nodes, '0.id', ''));
        return $gid !== '' ? $gid : null;
    }

    /**
     * @return array<string, string>
     */
    private function referenceLookupMap(string $lookup): array
    {
        if (array_key_exists($lookup, $this->referenceLookupCache)) {
            return $this->referenceLookupCache[$lookup];
        }

        [$namespace, $key] = array_pad(explode('.', $lookup, 2), 2, null);
        if ($namespace === null || $key === null) {
            $this->referenceLookupCache[$lookup] = [];
            return [];
        }

        $types = $this->metaobjectTypesForMetafieldLookup($namespace, $key);
        $map = [];

        foreach ($types as $type) {
            foreach ($this->metaobjectsForType($type) as $metaobject) {
                $id = $metaobject['id'];
                $displayName = $metaobject['displayName'];
                $handle = $metaobject['handle'];

                if ($displayName !== '') {
                    $map[$this->normalizeReferenceLabel($displayName)] = $id;
                }
                if ($handle !== '') {
                    $map[$this->normalizeReferenceLabel($handle)] = $id;
                }

                foreach ($metaobject['fields'] as $field) {
                    if ($field['value'] !== '') {
                        $map[$this->normalizeReferenceLabel($field['value'])] = $id;
                    }
                }
            }
        }

        $this->referenceLookupCache[$lookup] = $map;
        return $map;
    }

    /**
     * @return array<int, string>
     */
    private function metaobjectTypesForMetafieldLookup(string $namespace, string $key): array
    {
        $lookup = "{$namespace}.{$key}";
        if (array_key_exists($lookup, $this->metaobjectTypesByLookupCache)) {
            return $this->metaobjectTypesByLookupCache[$lookup];
        }

        $types = [];
        try {
            $data = $this->client->graphql($this->metafieldDefinitionQuery(), [
                'namespace' => $namespace,
                'key' => $key,
            ]);
        } catch (\Throwable) {
            $data = [];
        }

        $validations = data_get($data, 'metafieldDefinition.validations', []);
        $definitionIds = [];

        if (is_array($validations)) {
            foreach ($validations as $validation) {
                if (!is_array($validation)) {
                    continue;
                }
                $name = strtolower(trim((string) ($validation['name'] ?? '')));
                if (!str_contains($name, 'metaobject')) {
                    continue;
                }
                $value = trim((string) ($validation['value'] ?? ''));
                if ($value === '') {
                    continue;
                }

                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $item) {
                        if (is_string($item) && str_starts_with($item, 'gid://shopify/MetaobjectDefinition/')) {
                            $definitionIds[] = $item;
                        }
                        if (is_array($item)) {
                            foreach ($item as $nested) {
                                if (is_string($nested) && str_starts_with($nested, 'gid://shopify/MetaobjectDefinition/')) {
                                    $definitionIds[] = $nested;
                                }
                            }
                        }
                    }
                }

                if (str_starts_with($value, 'gid://shopify/MetaobjectDefinition/')) {
                    $definitionIds[] = $value;
                }

                if (preg_match_all('#gid://shopify/MetaobjectDefinition/[0-9]+#', $value, $matches)) {
                    foreach (($matches[0] ?? []) as $matched) {
                        $definitionIds[] = $matched;
                    }
                }
            }
        }

        $definitionIds = array_values(array_unique($definitionIds));
        if (!empty($definitionIds)) {
            try {
                $nodeData = $this->client->graphql($this->metaobjectDefinitionTypesQuery(), [
                    'ids' => $definitionIds,
                ]);
            } catch (\Throwable) {
                $nodeData = [];
            }
            $nodes = data_get($nodeData, 'nodes', []);
            if (is_array($nodes)) {
                foreach ($nodes as $node) {
                    $type = trim((string) data_get($node, 'type', ''));
                    if ($type !== '') {
                        $types[] = $type;
                    }
                }
            }
        }

        if (empty($types)) {
            $types[] = str_replace('-', '_', $key);
            $types[] = str_replace('_', '-', $key);
            $types[] = "shopify--{$key}";
            $types[] = "shopify--" . str_replace('_', '-', $key);
            $types[] = "{$namespace}--{$key}";
            $types[] = "{$namespace}--" . str_replace('_', '-', $key);
        }

        $overrideTypes = self::METAOBJECT_TYPE_OVERRIDES_BY_LOOKUP[$lookup] ?? [];
        if (!empty($overrideTypes)) {
            $types = array_merge($overrideTypes, $types);
        }

        $types = array_values(array_unique(array_filter($types)));
        $this->metaobjectTypesByLookupCache[$lookup] = $types;
        return $types;
    }

    /**
     * @return array<int, array{id:string,displayName:string,handle:string,fields:array<int, array{key:string,value:string}>}>
     */
    private function metaobjectsForType(string $type): array
    {
        if (array_key_exists($type, $this->metaobjectsByTypeCache)) {
            return $this->metaobjectsByTypeCache[$type];
        }

        try {
            $data = $this->client->graphql($this->metaobjectsByTypeQuery(), [
                'type' => $type,
            ]);
        } catch (\Throwable) {
            $data = [];
        }

        $nodes = data_get($data, 'metaobjects.nodes', []);
        if (!is_array($nodes)) {
            $this->metaobjectsByTypeCache[$type] = [];
            return [];
        }

        $result = [];
        foreach ($nodes as $node) {
            if (!is_array($node)) {
                continue;
            }

            $id = trim((string) ($node['id'] ?? ''));
            if ($id === '') {
                continue;
            }

            $fields = [];
            $fieldNodes = $node['fields'] ?? [];
            if (is_array($fieldNodes)) {
                foreach ($fieldNodes as $fieldNode) {
                    if (!is_array($fieldNode)) {
                        continue;
                    }
                    $fieldValue = trim((string) ($fieldNode['value'] ?? ''));
                    if ($fieldValue === '') {
                        continue;
                    }
                    $fields[] = [
                        'key' => trim((string) ($fieldNode['key'] ?? '')),
                        'value' => $fieldValue,
                    ];
                }
            }

            $result[] = [
                'id' => $id,
                'displayName' => trim((string) ($node['displayName'] ?? '')),
                'handle' => trim((string) ($node['handle'] ?? '')),
                'fields' => $fields,
            ];
        }

        $this->metaobjectsByTypeCache[$type] = $result;
        return $result;
    }

    private function normalizeReferenceLabel(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace(['_', ' '], '-', $normalized);
        $normalized = preg_replace('/[^a-z0-9-]+/', '', $normalized) ?? '';
        $normalized = preg_replace('/-+/', '-', $normalized) ?? '';
        return trim($normalized, '-');
    }

    private function applyLookupReferenceAlias(string $lookup, string $normalized): string
    {
        if ($lookup !== 'custom.pattern_category' || $normalized === '') {
            return $normalized;
        }

        return match ($normalized) {
            'multicolor', 'multi-color', 'multi-colour', 'multicolot' => 'multicolour',
            'sold' => 'solid',
            default => $normalized,
        };
    }

    private function resolveHardcodedReferenceToken(string $lookup, string $token): ?string
    {
        $normalized = $this->normalizeReferenceLabel($token);
        $normalized = $this->applyLookupReferenceAlias($lookup, $normalized);
        return $this->resolveHardcodedReferenceByNormalized($lookup, $normalized);
    }

    private function resolveHardcodedReferenceByNormalized(string $lookup, string $normalized): ?string
    {
        if ($lookup === 'stiletto.sibling_collection') {
            $map = $this->collectionReferenceLookupMap();

            if (isset($map[$normalized])) {
                return $map[$normalized];
            }

            return $this->fuzzyReferenceMatch($map, $normalized);
        }

        if ($lookup === 'custom.pattern_category') {
            return self::PATTERN_CATEGORY_METAOBJECT_GIDS[$normalized] ?? null;
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    private function collectionReferenceLookupMap(): array
    {
        if ($this->collectionReferenceLookupCache !== null) {
            return $this->collectionReferenceLookupCache;
        }

        $map = [];

        ShopifyCollection::query()
            ->select(['shopify_id', 'title', 'handle'])
            ->whereNotNull('shopify_id')
            ->where('shopify_id', '!=', '')
            ->chunkById(500, function ($collections) use (&$map): void {
                foreach ($collections as $collection) {
                    $gid = trim((string) ($collection->shopify_id ?? ''));
                    if ($gid === '') {
                        continue;
                    }

                    foreach ([
                        trim((string) ($collection->title ?? '')),
                        trim((string) ($collection->handle ?? '')),
                    ] as $label) {
                        $normalized = $this->normalizeReferenceLabel($label);
                        if ($normalized !== '' && !isset($map[$normalized])) {
                            $map[$normalized] = $gid;
                        }
                    }
                }
            });

        $this->collectionReferenceLookupCache = $map;

        return $map;
    }

    /**
     * @return array<int, string>
     */
    private function referenceTokensFromRaw(string $raw): array
    {
        $parts = preg_split('/[,\n\r;]+/', $raw) ?: [];
        $parts = array_map(static fn ($item) => trim((string) $item), $parts);
        return array_values(array_filter($parts, static fn ($item) => $item !== ''));
    }

    /**
     * @param array<string, string> $map
     */
    private function fuzzyReferenceMatch(array $map, string $normalized): ?string
    {
        if ($normalized === '') {
            return null;
        }

        $bestKey = null;
        foreach ($map as $key => $gid) {
            if (!str_contains($normalized, $key) && !str_contains($key, $normalized)) {
                continue;
            }

            if ($bestKey === null || strlen($key) > strlen($bestKey)) {
                $bestKey = $key;
            }
        }

        return $bestKey !== null ? ($map[$bestKey] ?? null) : null;
    }

    private function isReferenceType(string $type): bool
    {
        return str_ends_with($type, 'reference');
    }

    private function isSubtypeConstrainedShopifyMetafield(string $namespace, string $key): bool
    {
        if ($namespace !== 'shopify') {
            return false;
        }

        return in_array($key, [
            'age-group',
            'jewelry-type',
            'target-gender',
            'bracelet-design',
            'earring-design',
            'necklace-design',
            'jewelry-material',
            'material',
            'color-pattern',
        ], true);
    }

    private function categorySupportsSubtypeConstrainedMetafield(
        string $namespace,
        string $key,
        ?string $currentCategoryId,
        ?string $currentCategoryName
    ): bool {
        if ($namespace !== 'shopify') {
            return true;
        }

        if (!$this->isJewelryOnlyShopifyMetafield($namespace, $key)) {
            return true;
        }

        $categoryText = $this->categoryContextLabel($currentCategoryId, $currentCategoryName);
        $normalized = strtolower(trim($categoryText));
        if ($normalized === '') {
            return false;
        }

        return str_contains($normalized, 'jewelry')
            || str_contains($normalized, 'bracelet')
            || str_contains($normalized, 'earring')
            || str_contains($normalized, 'necklace')
            || str_contains($normalized, 'charm');
    }

    private function isJewelryOnlyShopifyMetafield(string $namespace, string $key): bool
    {
        if ($namespace !== 'shopify') {
            return false;
        }

        return in_array($key, [
            'age-group',
            'jewelry-type',
            'target-gender',
            'bracelet-design',
            'earring-design',
            'necklace-design',
            'jewelry-material',
        ], true);
    }

    private function isGiftCardsCategoryContext(?string $currentCategoryId, ?string $currentCategoryName): bool
    {
        $id = trim((string) ($currentCategoryId ?? ''));
        if (strcasecmp($id, 'gid://shopify/TaxonomyCategory/gc') === 0) {
            return true;
        }

        $label = strtolower(trim($this->categoryContextLabel($currentCategoryId, $currentCategoryName)));
        return $label === 'gift cards' || str_contains($label, 'gift card');
    }

    private function categoryContextLabel(?string $currentCategoryId, ?string $currentCategoryName): string
    {
        $name = trim((string) ($currentCategoryName ?? ''));
        if ($name !== '') {
            return $name;
        }

        $fromGid = $this->categoryNameFromGid($currentCategoryId);
        if ($fromGid !== null) {
            return $fromGid;
        }

        return trim((string) ($currentCategoryId ?? ''));
    }

    private function categoryNameFromGid(?string $gid): ?string
    {
        $normalizedGid = $this->normalizeTaxonomyGid($gid);
        if ($normalizedGid === null) {
            return null;
        }

        if (array_key_exists($normalizedGid, $this->categoryNameByGidCache)) {
            return $this->categoryNameByGidCache[$normalizedGid];
        }

        $name = Category::query()
            ->where('shopify_taxonomy_gid', $normalizedGid)
            ->value('name');

        $resolved = is_string($name) ? trim($name) : null;
        $this->categoryNameByGidCache[$normalizedGid] = $resolved !== '' ? $resolved : null;

        return $this->categoryNameByGidCache[$normalizedGid];
    }

    private function normalizeMetafieldInputFromAccepted(string $header, string $value, string $type): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        if (str_starts_with($type, 'list.')) {
            return $this->normalizeListAcceptedValue($header, $trimmed) ?? $trimmed;
        }

        if ($this->isReferenceType($type)) {
            return $this->normalizeSingleAcceptedValue($header, $trimmed) ?? $trimmed;
        }

        return $trimmed;
    }

    private function normalizeSingleAcceptedValue(string $header, string $value): ?string
    {
        $map = $this->acceptedValuesByHeader()[$header] ?? [];
        if (empty($map)) {
            return null;
        }

        $normalized = $this->normalizeReferenceLabel($value);
        if ($normalized === '') {
            return null;
        }

        if (isset($map[$normalized])) {
            return $map[$normalized];
        }

        return $this->fuzzyAcceptedToken($map, $normalized);
    }

    private function normalizeListAcceptedValue(string $header, string $value): ?string
    {
        $map = $this->acceptedValuesByHeader()[$header] ?? [];
        if (empty($map)) {
            return null;
        }

        $parts = str_contains($value, ';')
            ? array_map('trim', explode(';', $value))
            : array_map('trim', explode(',', $value));
        $parts = array_values(array_filter($parts, fn ($item) => $item !== ''));
        if (empty($parts)) {
            return null;
        }

        $tokens = [];
        $seen = [];
        foreach ($parts as $part) {
            $normalized = $this->normalizeReferenceLabel($part);
            if ($normalized === '') {
                continue;
            }

            $canonical = $map[$normalized] ?? $this->fuzzyAcceptedToken($map, $normalized);
            if ($canonical === null) {
                $canonical = $normalized;
            }

            $canonicalNorm = $this->normalizeReferenceLabel($canonical);
            if ($canonicalNorm === '' || isset($seen[$canonicalNorm])) {
                continue;
            }

            $seen[$canonicalNorm] = true;
            $tokens[] = $canonical;
        }

        return empty($tokens) ? null : implode('; ', $tokens);
    }

    /**
     * @param array<string, string> $map
     */
    private function fuzzyAcceptedToken(array $map, string $normalized): ?string
    {
        if ($normalized === '') {
            return null;
        }

        $best = null;
        foreach ($map as $key => $canonical) {
            if (!str_contains($normalized, $key) && !str_contains($key, $normalized)) {
                continue;
            }

            if ($best === null || strlen($key) > strlen($best)) {
                $best = $key;
            }
        }

        return $best !== null ? ($map[$best] ?? null) : null;
    }

    /**
     * @return array<string, array<string, string>>
     */
    private function acceptedValuesByHeader(): array
    {
        if ($this->acceptedValuesByHeaderCache !== null) {
            return $this->acceptedValuesByHeaderCache;
        }

        $path = $this->acceptedValuesCsvPath();
        if ($path === null || !is_file($path)) {
            $this->acceptedValuesByHeaderCache = [];
            return [];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            $this->acceptedValuesByHeaderCache = [];
            return [];
        }

        $headers = fgetcsv($handle);
        if (!is_array($headers) || empty($headers)) {
            fclose($handle);
            $this->acceptedValuesByHeaderCache = [];
            return [];
        }

        $headers = array_map(function ($header): string {
            $value = trim((string) $header);
            return ltrim($value, "\xEF\xBB\xBF");
        }, $headers);

        $map = [];
        while (($row = fgetcsv($handle)) !== false) {
            foreach ($headers as $index => $header) {
                if ($header === '') {
                    continue;
                }

                $raw = trim((string) ($row[$index] ?? ''));
                if ($raw === '') {
                    continue;
                }

                $tokens = $this->tokenizeAcceptedCell($header, $raw);
                foreach ($tokens as $token) {
                    $normalized = $this->normalizeReferenceLabel($token);
                    if ($normalized === '' || isset($map[$header][$normalized])) {
                        continue;
                    }
                    $map[$header][$normalized] = $token;
                }
            }
        }

        fclose($handle);
        $this->acceptedValuesByHeaderCache = $map;
        return $map;
    }

    /**
     * @return array<int, string>
     */
    private function tokenizeAcceptedCell(string $header, string $value): array
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }

        if ($header === HeaderStore::TAGS || str_contains($header, '(product.metafields.shopify.')) {
            $parts = str_contains($trimmed, ';')
                ? array_map('trim', explode(';', $trimmed))
                : array_map('trim', explode(',', $trimmed));
            return array_values(array_filter($parts, fn ($item) => $item !== ''));
        }

        return [$trimmed];
    }

    private function acceptedValuesCsvPath(): ?string
    {
        $candidates = [
            storage_path('app/private/templates/shopify-accepted-values.csv'),
            storage_path('app/private/shoppify-acceeped-values.csv'),
            storage_path('app/private/shopify-accepted-values.csv'),
            storage_path('app/private/shopify-acceppted-values.csv'),
            storage_path('app/private/tempates/shopify-acceppted-values.csv'),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function updateImages(
        Product $product,
        string $productId,
        array $_rowDataList,
        array $details,
        ?array $selectedImageIds = null,
    ): array
    {
        $warnings = [];
        $selectedImageIds = $selectedImageIds !== null
            ? array_values(array_unique(array_map('intval', $selectedImageIds)))
            : null;
        $selectedImageIdMap = $selectedImageIds !== null
            ? array_fill_keys($selectedImageIds, true)
            : null;
        $selectedSync = $selectedImageIdMap !== null;
        $existingEntriesById = [];
        $existingEntriesByUrl = [];

        $existingMediaNodes = collect(data_get($details, 'media.nodes', []))
            ->filter(fn ($node) => is_array($node))
            ->map(function (array $node): array {
                return [
                    'id' => trim((string) ($node['id'] ?? '')),
                    'url' => trim((string) data_get($node, 'image.url', '')),
                    'mode' => 'file_reference',
                ];
            })
            ->filter(fn (array $row) => $row['url'] !== '')
            ->values()
            ->all();

        foreach ($existingMediaNodes as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            $url = trim((string) ($row['url'] ?? ''));
            if ($id === '' || $url === '') {
                continue;
            }

            $existingEntriesById[$id] = $row;
            $existingEntriesByUrl[$url] = $row;
        }

        $legacyImageNodes = collect(data_get($details, 'images.nodes', []))
            ->filter(fn ($node) => is_array($node))
            ->map(function (array $node): array {
                return [
                    'id' => trim((string) ($node['id'] ?? '')),
                    'url' => trim((string) ($node['url'] ?? '')),
                    'mode' => 'legacy_image',
                ];
            })
            ->filter(fn (array $row) => $row['url'] !== '')
            ->values()
            ->all();

        foreach ($legacyImageNodes as $row) {
            $id = trim((string) ($row['id'] ?? ''));
            $url = trim((string) ($row['url'] ?? ''));
            if ($url === '' || isset($existingEntriesByUrl[$url])) {
                continue;
            }

            if ($id !== '') {
                $existingEntriesById[$id] = $row;
            }
            $existingEntriesByUrl[$url] = $row;
        }

        $matchedExistingIds = [];

        $imageQuery = $product->allImages()
            ->where('sync_state', '!=', Image::SYNC_STATE_LOCAL_DELETED);

        if ($selectedImageIdMap !== null) {
            $imageQuery->whereIn('id', array_keys($selectedImageIdMap));
        }

        $desiredImages = $imageQuery
            ->with('imageAsset')
            ->orderByRaw('CASE WHEN position IS NULL THEN 1 ELSE 0 END')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->values()
            ->map(function (Image $image, int $index) use ($existingEntriesById, $existingEntriesByUrl): array {
                $previousMatch = $this->findExistingImageMatch(
                    $this->nullIfEmpty($image->shopify_id),
                    $this->normalizeMediaUrl($image->src),
                    $this->nullIfEmpty($image->desiredSyncSourceUrl()),
                    $existingEntriesById,
                    $existingEntriesByUrl,
                );

                return [
                    'image' => $image,
                    'shopify_id' => $this->nullIfEmpty($image->shopify_id),
                    'current_url' => $this->normalizeMediaUrl($image->src),
                    'sync_url' => $this->nullIfEmpty($image->desiredSyncSourceUrl()),
                    'preferred_filename' => $image->preferredFilename(),
                    'alt' => $this->nullIfEmpty($image->alt_text),
                    'position' => $this->normalizeImagePosition($image->position),
                    'index' => $index,
                    'matched_media_id' => null,
                    'requires_republish' => $image->needsShopifyRepublish(),
                    'previous_media_id' => trim((string) ($previousMatch['id'] ?? '')) ?: null,
                    'previous_media_mode' => trim((string) ($previousMatch['mode'] ?? '')) ?: null,
                ];
            })
            ->filter(function (array $row) use (&$warnings, $product): bool {
                if ($row['sync_url'] !== null) {
                    return true;
                }

                /** @var Image $image */
                $image = $row['image'];

                $warnings[] = [
                    'product_id' => $product->id,
                    'warning' => "Skipped image {$image->id} because it has no usable sync source URL.",
                ];

                return false;
            })
            ->sortBy(fn (array $row): string => sprintf(
                '%010d-%010d',
                $row['position'] ?? 2147483647,
                $row['index']
            ))
            ->unique(function (array $row): string {
                return (string) ($row['shopify_id'] ?: $row['sync_url'] ?: $row['current_url'] ?: $row['index']);
            })
            ->values();

        $desiredImages = $desiredImages->map(function (array $desiredImage) use (&$matchedExistingIds, $existingEntriesById, $existingEntriesByUrl): array {
            $shopifyId = $desiredImage['shopify_id'];
            $currentUrl = $desiredImage['current_url'];
            $syncUrl = $desiredImage['sync_url'];
            $match = null;

            if ($desiredImage['requires_republish']) {
                return $desiredImage;
            }

            if ($shopifyId !== null && isset($existingEntriesById[$shopifyId])) {
                $match = $existingEntriesById[$shopifyId];
            } elseif ($currentUrl !== null && isset($existingEntriesByUrl[$currentUrl])) {
                $match = $existingEntriesByUrl[$currentUrl];
            } elseif ($syncUrl !== null && isset($existingEntriesByUrl[$syncUrl])) {
                $match = $existingEntriesByUrl[$syncUrl];
            }

            if ($match !== null) {
                $matchedId = trim((string) ($match['id'] ?? ''));
                if ($matchedId !== '') {
                    $matchedExistingIds[] = $matchedId;
                    $desiredImage['matched_media_id'] = $matchedId;
                    $this->markImageSynced($desiredImage['image'], $matchedId, $desiredImage['preferred_filename']);
                }
            }

            return $desiredImage;
        });

        if (!$selectedSync) {
            // Non-destructive cleanup: remove product references for shared media files,
            // and never delete the underlying Shopify CDN asset from this sync path.
            $staleEntries = collect($existingEntriesByUrl)
                ->reject(function (array $row) use ($matchedExistingIds): bool {
                    $id = trim((string) ($row['id'] ?? ''));
                    return $id !== '' && in_array($id, $matchedExistingIds, true);
                })
                ->values()
                ->all();

            $fileDetachInputs = collect($staleEntries)
                ->filter(fn (array $row) => ($row['mode'] ?? null) === 'file_reference')
                ->map(function (array $row) use ($productId): ?array {
                    $id = trim((string) ($row['id'] ?? ''));
                    if ($id === '') {
                        return null;
                    }

                    return [
                        'id' => $id,
                        'referencesToRemove' => [$productId],
                    ];
                })
                ->filter()
                ->unique('id')
                ->values()
                ->all();

            if (!empty($fileDetachInputs)) {
                try {
                    $detachData = $this->client->graphql($this->fileUpdateMutation(), [
                        'files' => $fileDetachInputs,
                    ]);

                    $detachErrors = data_get($detachData, 'fileUpdate.userErrors', []);
                    if (is_array($detachErrors) && !empty($detachErrors)) {
                        $messages = $this->formatUserErrors($detachErrors, 'files');
                        $warnings[] = [
                            'product_id' => $product->id,
                            'warning' => $messages !== '' ? $messages : 'Shopify image detach failed.',
                        ];
                    }
                } catch (\Throwable $e) {
                    $warnings[] = [
                        'product_id' => $product->id,
                        'warning' => 'Shopify image detach failed: ' . $e->getMessage(),
                    ];
                }
            }

            $legacyStaleCount = collect($staleEntries)
                ->filter(fn (array $row) => ($row['mode'] ?? null) === 'legacy_image')
                ->count();

            if ($legacyStaleCount > 0) {
                $warnings[] = [
                    'product_id' => $product->id,
                    'warning' => "Skipped {$legacyStaleCount} stale legacy Shopify product image(s) to avoid destructive deletion.",
                ];
            }
        }

        $desiredImages = $desiredImages->map(function (array $desiredImage) use ($product, $productId, &$warnings): array {
            if ($desiredImage['matched_media_id'] !== null) {
                return $desiredImage;
            }

            /** @var Image $image */
            $image = $desiredImage['image'];
            $imageUrl = $desiredImage['sync_url'];
            if ($imageUrl === null) {
                return $desiredImage;
            }

            $mediaInput = [
                'originalSource' => $imageUrl,
                'mediaContentType' => 'IMAGE',
            ];
            if ($desiredImage['alt'] !== null) {
                $mediaInput['alt'] = $desiredImage['alt'];
            }

            $data = $this->client->graphql($this->productCreateMediaMutation(), [
                'productId' => $productId,
                'media' => [
                    $mediaInput,
                ],
            ]);

            $payload = $data['productCreateMedia'] ?? null;
            if (!$payload) {
                $this->markImageSyncFailed($image, "Missing productCreateMedia payload for image {$image->id}.");
                $warnings[] = [
                    'product_id' => $product->id,
                    'warning' => "Missing productCreateMedia payload for image {$image->id}.",
                ];
                return $desiredImage;
            }

            $errors = $payload['mediaUserErrors'] ?? [];
            if (!empty($errors)) {
                $messages = $this->formatUserErrors($errors, 'media');
                $this->markImageSyncFailed(
                    $image,
                    $messages !== '' ? $messages : "Unknown media error for image {$image->id}."
                );
                $warnings[] = [
                    'product_id' => $product->id,
                    'warning' => $messages !== '' ? $messages : "Unknown media error for image {$image->id}.",
                ];
                return $desiredImage;
            }

            $createdMediaId = trim((string) data_get($payload, 'media.0.id', ''));
            if ($createdMediaId !== '') {
                $desiredImage['matched_media_id'] = $createdMediaId;
                $this->markImageSynced($image, $createdMediaId, $desiredImage['preferred_filename']);
            }

            return $desiredImage;
        });

        if ($selectedSync) {
            $warnings = array_merge(
                $warnings,
                $this->cleanupSelectedStaleImages($product, $productId, $desiredImages)
            );
        }

        $warnings = array_merge(
            $warnings,
            $selectedSync
                ? $this->reorderSelectedProductImages($product, $productId, $desiredImages)
                : $this->reorderProductImages($product, $productId, $desiredImages)
        );

        return $warnings;
    }

    private function findExistingImageMatch(
        ?string $shopifyId,
        ?string $currentUrl,
        ?string $syncUrl,
        array $existingEntriesById,
        array $existingEntriesByUrl,
    ): ?array {
        if ($shopifyId !== null && isset($existingEntriesById[$shopifyId])) {
            return $existingEntriesById[$shopifyId];
        }

        if ($currentUrl !== null && isset($existingEntriesByUrl[$currentUrl])) {
            return $existingEntriesByUrl[$currentUrl];
        }

        if ($syncUrl !== null && isset($existingEntriesByUrl[$syncUrl])) {
            return $existingEntriesByUrl[$syncUrl];
        }

        return null;
    }

    /**
     * @param \Illuminate\Support\Collection<int, array{image:Image,shopify_id:?string,current_url:?string,sync_url:?string,preferred_filename:string,alt:?string,position:?int,index:int,matched_media_id:?string,requires_republish:bool,previous_media_id:?string,previous_media_mode:?string}> $desiredImages
     * @return array<int, array{product_id:int, warning:string}>
     */
    private function cleanupSelectedStaleImages(Product $product, string $productId, Collection $desiredImages): array
    {
        $warnings = [];

        $fileDetachInputs = [];
        $legacyMediaIds = [];

        foreach ($desiredImages as $desiredImage) {
            $previousMediaId = trim((string) ($desiredImage['previous_media_id'] ?? ''));
            $matchedMediaId = trim((string) ($desiredImage['matched_media_id'] ?? ''));

            if ($previousMediaId === '' || $matchedMediaId === '' || $previousMediaId === $matchedMediaId) {
                continue;
            }

            $mode = trim((string) ($desiredImage['previous_media_mode'] ?? ''));

            if ($mode === 'file_reference') {
                $fileDetachInputs[] = [
                    'id' => $previousMediaId,
                    'referencesToRemove' => [$productId],
                ];
                continue;
            }

            if ($mode === 'legacy_image') {
                $legacyMediaIds[] = $previousMediaId;
            }
        }

        $fileDetachInputs = collect($fileDetachInputs)
            ->unique('id')
            ->values()
            ->all();

        if (!empty($fileDetachInputs)) {
            try {
                $detachData = $this->client->graphql($this->fileUpdateMutation(), [
                    'files' => $fileDetachInputs,
                ]);

                $detachErrors = data_get($detachData, 'fileUpdate.userErrors', []);
                if (is_array($detachErrors) && !empty($detachErrors)) {
                    $messages = $this->formatUserErrors($detachErrors, 'files');
                    $warnings[] = [
                        'product_id' => $product->id,
                        'warning' => $messages !== '' ? $messages : 'Shopify selected image detach failed.',
                    ];
                }
            } catch (\Throwable $e) {
                $warnings[] = [
                    'product_id' => $product->id,
                    'warning' => 'Shopify selected image detach failed: ' . $e->getMessage(),
                ];
            }
        }

        $legacyMediaIds = array_values(array_unique(array_filter($legacyMediaIds)));
        if (!empty($legacyMediaIds)) {
            try {
                $deleteData = $this->client->graphql($this->productDeleteMediaMutation(), [
                    'productId' => $productId,
                    'mediaIds' => $legacyMediaIds,
                ]);

                $deleteErrors = data_get($deleteData, 'productDeleteMedia.mediaUserErrors', []);
                if (is_array($deleteErrors) && !empty($deleteErrors)) {
                    $messages = $this->formatUserErrors($deleteErrors, 'media');
                    $warnings[] = [
                        'product_id' => $product->id,
                        'warning' => $messages !== '' ? $messages : 'Shopify selected legacy image removal failed.',
                    ];
                }
            } catch (\Throwable $e) {
                $warnings[] = [
                    'product_id' => $product->id,
                    'warning' => 'Shopify selected legacy image removal failed: ' . $e->getMessage(),
                ];
            }
        }

        return $warnings;
    }

    /**
     * @param \Illuminate\Support\Collection<int, array{image:Image,shopify_id:?string,current_url:?string,sync_url:?string,preferred_filename:string,alt:?string,position:?int,index:int,matched_media_id:?string,requires_republish:bool,previous_media_id:?string,previous_media_mode:?string}> $desiredImages
     * @return array<int, array{product_id:int, warning:string}>
     */
    private function reorderSelectedProductImages(Product $product, string $productId, Collection $desiredImages): array
    {
        $warnings = [];

        if ($desiredImages->isEmpty()) {
            return $warnings;
        }

        $details = $this->productByHandleDetails($product->handle);
        $mediaNodes = collect(data_get($details, 'media.nodes', []))
            ->filter(fn ($node) => is_array($node))
            ->map(function (array $node): array {
                return [
                    'id' => trim((string) ($node['id'] ?? '')),
                    'url' => trim((string) data_get($node, 'image.url', '')),
                ];
            })
            ->filter(fn (array $row) => $row['id'] !== '')
            ->values();

        if ($mediaNodes->count() <= 1) {
            return $warnings;
        }

        $mediaIdByUrl = $mediaNodes
            ->filter(fn (array $row) => $row['url'] !== '')
            ->mapWithKeys(fn (array $row): array => [$row['url'] => $row['id']])
            ->all();
        $currentMediaIds = $mediaNodes
            ->pluck('id')
            ->all();

        $selectedPlacements = [];
        foreach ($desiredImages
            ->sortBy(fn (array $row): string => sprintf('%010d-%010d', $row['position'] ?? 2147483647, $row['index']))
            ->values() as $desiredImage) {
            $mediaId = $desiredImage['matched_media_id']
                ?? ($desiredImage['current_url'] ? ($mediaIdByUrl[$desiredImage['current_url']] ?? null) : null)
                ?? ($desiredImage['sync_url'] ? ($mediaIdByUrl[$desiredImage['sync_url']] ?? null) : null);

            if ($mediaId === null) {
                /** @var Image $image */
                $image = $desiredImage['image'];
                $warnings[] = [
                    'product_id' => $product->id,
                    'warning' => "Selected image reorder skipped for image {$image->id} because the product media ID is not available yet.",
                ];
                continue;
            }

            $selectedPlacements[] = [
                'media_id' => $mediaId,
                'target_position' => $desiredImage['position'] ?? (count($currentMediaIds) + count($selectedPlacements) + 1),
            ];
        }

        if (empty($selectedPlacements)) {
            return $warnings;
        }

        $selectedMediaIds = array_values(array_unique(array_map(
            fn (array $placement): string => $placement['media_id'],
            $selectedPlacements
        )));

        $desiredOrder = array_values(array_filter(
            $currentMediaIds,
            fn (string $id): bool => !in_array($id, $selectedMediaIds, true)
        ));

        foreach ($selectedPlacements as $placement) {
            $desiredOrder = array_values(array_filter(
                $desiredOrder,
                fn (string $id): bool => $id !== $placement['media_id']
            ));

            $insertAt = max(0, min(count($desiredOrder), ((int) $placement['target_position']) - 1));
            array_splice($desiredOrder, $insertAt, 0, [$placement['media_id']]);
        }

        if ($desiredOrder === $currentMediaIds) {
            return $warnings;
        }

        $moves = [];
        foreach ($desiredOrder as $position => $mediaId) {
            $moves[] = [
                'id' => $mediaId,
                'newPosition' => (string) $position,
            ];
        }

        try {
            $reorderData = $this->client->graphql($this->productReorderMediaMutation(), [
                'id' => $productId,
                'moves' => $moves,
            ]);

            $reorderErrors = data_get($reorderData, 'productReorderMedia.mediaUserErrors', []);
            if (is_array($reorderErrors) && !empty($reorderErrors)) {
                $messages = $this->formatUserErrors($reorderErrors, 'media');
                $warnings[] = [
                    'product_id' => $product->id,
                    'warning' => $messages !== '' ? $messages : 'Shopify selected image reorder failed.',
                ];
            }
        } catch (\Throwable $e) {
            $warnings[] = [
                'product_id' => $product->id,
                'warning' => 'Shopify selected image reorder failed: ' . $e->getMessage(),
            ];
        }

        return $warnings;
    }

    /**
     * @param \Illuminate\Support\Collection<int, array{image:Image,shopify_id:?string,current_url:?string,sync_url:?string,preferred_filename:string,alt:?string,position:?int,index:int,matched_media_id:?string,requires_republish:bool,previous_media_id:?string,previous_media_mode:?string}> $desiredImages
     * @return array<int, array{product_id:int, warning:string}>
     */
    private function reorderProductImages(Product $product, string $productId, Collection $desiredImages): array
    {
        $warnings = [];

        if ($desiredImages->count() <= 1) {
            return $warnings;
        }

        $details = $this->productByHandleDetails($product->handle);
        $mediaNodes = collect(data_get($details, 'media.nodes', []))
            ->filter(fn ($node) => is_array($node))
            ->map(function (array $node): array {
                return [
                    'id' => trim((string) ($node['id'] ?? '')),
                    'url' => trim((string) data_get($node, 'image.url', '')),
                ];
            })
            ->filter(fn (array $row) => $row['id'] !== '')
            ->values();

        if ($mediaNodes->count() <= 1) {
            return $warnings;
        }

        $mediaIdByUrl = $mediaNodes
            ->filter(fn (array $row) => $row['url'] !== '')
            ->mapWithKeys(fn (array $row): array => [$row['url'] => $row['id']])
            ->all();
        $currentMediaIds = $mediaNodes
            ->pluck('id')
            ->all();

        $desiredMediaIds = [];
        foreach ($desiredImages as $desiredImage) {
            $mediaId = $desiredImage['matched_media_id']
                ?? ($desiredImage['current_url'] ? ($mediaIdByUrl[$desiredImage['current_url']] ?? null) : null)
                ?? ($desiredImage['sync_url'] ? ($mediaIdByUrl[$desiredImage['sync_url']] ?? null) : null);

            if ($mediaId === null) {
                /** @var Image $image */
                $image = $desiredImage['image'];
                $warnings[] = [
                    'product_id' => $product->id,
                    'warning' => "Shopify image reorder skipped for image {$image->id} because the product media ID is not available yet.",
                ];
                continue;
            }

            $desiredMediaIds[] = $mediaId;
        }

        if (count($desiredMediaIds) <= 1) {
            return $warnings;
        }

        $currentDesiredOrder = array_values(array_filter(
            $currentMediaIds,
            fn (string $id): bool => in_array($id, $desiredMediaIds, true)
        ));

        if ($currentDesiredOrder === $desiredMediaIds) {
            return $warnings;
        }

        $moves = [];
        foreach ($desiredMediaIds as $position => $mediaId) {
            $moves[] = [
                'id' => $mediaId,
                'newPosition' => (string) $position,
            ];
        }

        try {
            $reorderData = $this->client->graphql($this->productReorderMediaMutation(), [
                'id' => $productId,
                'moves' => $moves,
            ]);

            $reorderErrors = data_get($reorderData, 'productReorderMedia.mediaUserErrors', []);
            if (is_array($reorderErrors) && !empty($reorderErrors)) {
                $messages = $this->formatUserErrors($reorderErrors, 'media');
                $warnings[] = [
                    'product_id' => $product->id,
                    'warning' => $messages !== '' ? $messages : 'Shopify image reorder failed.',
                ];
            }
        } catch (\Throwable $e) {
            $warnings[] = [
                'product_id' => $product->id,
                'warning' => 'Shopify image reorder failed: ' . $e->getMessage(),
            ];
        }

        return $warnings;
    }

    /**
     * @param array<int, array> $rowDataList
     * @return array<int, array{product_id:int, warning:string}>
     */
    private function syncVariantMediaAssignments(Product $product, string $productId, array $rowDataList): array
    {
        $warnings = [];

        $localVariantsOrdered = $product->variants()
            ->with('image')
            ->orderBy('id')
            ->get()
            ->values();

        $variantSources = $this->buildVariantSources($rowDataList, $localVariantsOrdered);
        if (empty($variantSources)) {
            return $warnings;
        }

        $details = $this->productByHandleDetails($product->handle);
        $variantNodes = data_get($details, 'variants.nodes', []);
        if (!is_array($variantNodes) || empty($variantNodes)) {
            return $warnings;
        }

        $variantNodesOrdered = array_values(array_filter($variantNodes, fn ($node) => is_array($node) && !empty($node['id'])));
        $shopifyVariantsBySku = [];
        $shopifyVariantsBySignature = [];

        foreach ($variantNodesOrdered as $variantNode) {
            $sku = trim((string) ($variantNode['sku'] ?? ''));
            if ($sku !== '') {
                $shopifyVariantsBySku[$sku] = $variantNode;
            }

            $signature = $this->variantOptionSignatureFromNode($variantNode);
            if ($signature !== '') {
                $shopifyVariantsBySignature[$signature] = $variantNode;
            }
        }

        $mediaNodes = collect(data_get($details, 'media.nodes', []))
            ->filter(fn ($node) => is_array($node))
            ->map(function (array $node): array {
                return [
                    'id' => trim((string) ($node['id'] ?? '')),
                    'url' => trim((string) data_get($node, 'image.url', '')),
                ];
            })
            ->filter(fn (array $row) => $row['id'] !== '')
            ->values();

        $mediaIdByShopifyId = $product->images()
            ->whereNotNull('shopify_id')
            ->pluck('shopify_id', 'id')
            ->mapWithKeys(fn (string $shopifyId, int $imageId): array => [$imageId => trim($shopifyId)])
            ->all();

        $mediaIdByUrl = $mediaNodes
            ->filter(fn (array $row) => $row['url'] !== '')
            ->mapWithKeys(fn (array $row): array => [$row['url'] => $row['id']])
            ->all();

        $detachInputs = [];
        $appendInputs = [];

        foreach ($variantSources as $rowIndex => $variantSource) {
            /** @var Variant|null $localVariant */
            $localVariant = $variantSource['local'];
            if ($localVariant === null) {
                continue;
            }

            $variantNode = $this->resolveShopifyVariantNode(
                $variantNodesOrdered,
                $shopifyVariantsBySku,
                $shopifyVariantsBySignature,
                $localVariant,
                $variantSource['row'],
                $rowIndex,
            );

            if ($variantNode === null) {
                continue;
            }

            $variantId = trim((string) ($variantNode['id'] ?? ''));
            if ($variantId === '') {
                continue;
            }

            $currentMediaIds = collect(data_get($variantNode, 'media.nodes', []))
                ->filter(fn ($node) => is_array($node))
                ->map(fn (array $node): string => trim((string) ($node['id'] ?? '')))
                ->filter()
                ->values()
                ->all();

            $desiredMediaId = $this->desiredVariantMediaId($localVariant, $mediaIdByShopifyId, $mediaIdByUrl);

            if ($localVariant->image_id !== null && $desiredMediaId === null) {
                $warnings[] = [
                    'product_id' => $product->id,
                    'warning' => "Variant {$localVariant->sku} image assignment was skipped because the linked product image has not been synced to Shopify yet.",
                ];
                continue;
            }

            $mediaIdsToDetach = $desiredMediaId === null
                ? $currentMediaIds
                : array_values(array_filter(
                    $currentMediaIds,
                    fn (string $mediaId): bool => $mediaId !== $desiredMediaId
                ));

            if (!empty($mediaIdsToDetach)) {
                $detachInputs[] = [
                    'variantId' => $variantId,
                    'mediaIds' => $mediaIdsToDetach,
                ];
            }

            if ($desiredMediaId !== null && !in_array($desiredMediaId, $currentMediaIds, true)) {
                $appendInputs[] = [
                    'variantId' => $variantId,
                    'mediaIds' => [$desiredMediaId],
                ];
            }
        }

        if (!empty($detachInputs)) {
            try {
                $detachData = $this->client->graphql($this->productVariantDetachMediaMutation(), [
                    'productId' => $productId,
                    'variantMedia' => $detachInputs,
                ]);

                $detachErrors = data_get($detachData, 'productVariantDetachMedia.userErrors', []);
                if (is_array($detachErrors) && !empty($detachErrors)) {
                    $messages = $this->formatUserErrors($detachErrors, 'variantMedia');
                    $warnings[] = [
                        'product_id' => $product->id,
                        'warning' => $messages !== '' ? $messages : 'Shopify variant image detach failed.',
                    ];
                }
            } catch (\Throwable $e) {
                $warnings[] = [
                    'product_id' => $product->id,
                    'warning' => 'Shopify variant image detach failed: ' . $e->getMessage(),
                ];
            }
        }

        if (!empty($appendInputs)) {
            try {
                $appendData = $this->client->graphql($this->productVariantAppendMediaMutation(), [
                    'productId' => $productId,
                    'variantMedia' => $appendInputs,
                ]);

                $appendErrors = data_get($appendData, 'productVariantAppendMedia.userErrors', []);
                if (is_array($appendErrors) && !empty($appendErrors)) {
                    $messages = $this->formatUserErrors($appendErrors, 'variantMedia');
                    $warnings[] = [
                        'product_id' => $product->id,
                        'warning' => $messages !== '' ? $messages : 'Shopify variant image attach failed.',
                    ];
                }
            } catch (\Throwable $e) {
                $warnings[] = [
                    'product_id' => $product->id,
                    'warning' => 'Shopify variant image attach failed: ' . $e->getMessage(),
                ];
            }
        }

        return $warnings;
    }

    /**
     * @param array<int, array> $variantNodesOrdered
     * @param array<string, array> $shopifyVariantsBySku
     * @param array<string, array> $shopifyVariantsBySignature
     * @param array<string, mixed> $rowData
     */
    private function resolveShopifyVariantNode(
        array $variantNodesOrdered,
        array $shopifyVariantsBySku,
        array $shopifyVariantsBySignature,
        ?Variant $localVariant,
        array $rowData,
        int $rowIndex
    ): ?array {
        $desiredSku = $this->variantDesiredValue($localVariant, $rowData, 'sku', HeaderStore::VARIANT_SKU);
        $desiredSignature = $this->variantOptionSignature(
            $this->variantOptionValuesFromSource($rowData, $localVariant)
        );

        $variantNode = $desiredSku !== null
            ? ($shopifyVariantsBySku[$desiredSku] ?? null)
            : null;

        if ($variantNode === null && $desiredSignature !== '') {
            $variantNode = $shopifyVariantsBySignature[$desiredSignature] ?? null;
        }

        if ($variantNode === null) {
            $variantNode = $variantNodesOrdered[$rowIndex] ?? null;
        }

        return is_array($variantNode) ? $variantNode : null;
    }

    /**
     * @param array<int, string> $mediaIdByShopifyId
     * @param array<string, string> $mediaIdByUrl
     */
    private function desiredVariantMediaId(Variant $variant, array $mediaIdByShopifyId, array $mediaIdByUrl): ?string
    {
        $image = $variant->image;
        if ($image === null) {
            return null;
        }

        $linkedShopifyId = trim((string) ($mediaIdByShopifyId[$image->id] ?? ''));
        if ($linkedShopifyId !== '') {
            return $linkedShopifyId;
        }

        $currentUrl = $this->normalizeMediaUrl($image->src);
        if ($currentUrl !== null && isset($mediaIdByUrl[$currentUrl])) {
            return $mediaIdByUrl[$currentUrl];
        }

        $syncUrl = $this->nullIfEmpty($image->desiredSyncSourceUrl());
        if ($syncUrl !== null && isset($mediaIdByUrl[$syncUrl])) {
            return $mediaIdByUrl[$syncUrl];
        }

        return null;
    }

    private function markImageSynced(Image $image, ?string $shopifyId = null, ?string $filename = null): void
    {
        Image::withoutEvents(function () use ($image, $shopifyId, $filename): void {
            $updates = [
                'sync_state' => Image::SYNC_STATE_SYNCED,
                'local_dirty' => false,
                'last_shopify_seen_at' => now(),
                'last_synced_at' => now(),
                'last_shopify_synced_image_asset_id' => $image->image_asset_id,
                'last_shopify_synced_filename' => $filename ?: $image->preferredFilename(),
                'last_shopify_image_synced_at' => now(),
                'needs_shopify_image_sync' => false,
                'shopify_image_sync_error' => null,
            ];

            if ($shopifyId !== null && trim($shopifyId) !== '') {
                $updates['shopify_id'] = $shopifyId;
            }

            $image->forceFill($updates)->save();
        });
    }

    private function markImageSyncFailed(Image $image, string $message): void
    {
        Image::withoutEvents(function () use ($image, $message): void {
            $image->forceFill([
                'needs_shopify_image_sync' => true,
                'shopify_image_sync_error' => $message,
            ])->save();
        });
    }

    private function normalizeMediaUrl(?string $value): ?string
    {
        $trimmed = trim((string) $value);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '//')) {
            return 'https:' . $trimmed;
        }

        return $trimmed;
    }

    private function normalizeImagePosition(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);
        if ($trimmed === '' || !is_numeric($trimmed)) {
            return null;
        }

        $position = (int) $trimmed;
        return $position > 0 ? $position : null;
    }

    private function fileUpdateMutation(): string
    {
        return <<<'GQL'
mutation FileUpdate($files: [FileUpdateInput!]!) {
  fileUpdate(files: $files) {
    files {
      ... on MediaImage {
        id
      }
    }
    userErrors {
      field
      message
    }
  }
}
GQL;
    }

    private function productVariantAppendMediaMutation(): string
    {
        return <<<'GQL'
mutation ProductVariantAppendMedia($productId: ID!, $variantMedia: [ProductVariantAppendMediaInput!]!) {
  productVariantAppendMedia(productId: $productId, variantMedia: $variantMedia) {
    product {
      id
    }
    productVariants {
      id
    }
    userErrors {
      field
      message
    }
  }
}
GQL;
    }

    private function productVariantDetachMediaMutation(): string
    {
        return <<<'GQL'
mutation ProductVariantDetachMedia($productId: ID!, $variantMedia: [ProductVariantDetachMediaInput!]!) {
  productVariantDetachMedia(productId: $productId, variantMedia: $variantMedia) {
    product {
      id
    }
    productVariants {
      id
    }
    userErrors {
      field
      message
    }
  }
}
GQL;
    }

    private function productDeleteMediaMutation(): string
    {
        return <<<'GQL'
mutation ProductDeleteMedia($productId: ID!, $mediaIds: [ID!]!) {
  productDeleteMedia(productId: $productId, mediaIds: $mediaIds) {
    deletedMediaIds
    deletedProductImageIds
    mediaUserErrors {
      field
      message
    }
  }
}
GQL;
    }

    private function productReorderMediaMutation(): string
    {
        return <<<'GQL'
mutation ProductReorderMedia($id: ID!, $moves: [MoveInput!]!) {
  productReorderMedia(id: $id, moves: $moves) {
    job {
      id
    }
    mediaUserErrors {
      field
      message
    }
  }
}
GQL;
    }


    /**
     * @return array{0:?ShopifyRow,1:array<int, array>,2:array<int, array>}
     */
    private function loadRows(Product $product): array
    {
        $rows = ShopifyRow::query()
            ->where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->whereIn('row_type', ['product_primary', 'variant', 'image'])
            ->orderByDesc('id')
            ->get();

        $primary = $rows->firstWhere('row_type', 'product_primary');
        $variantRows = $rows->where('row_type', 'variant')->values();
        $imageRows = $rows->where('row_type', 'image')->values();

        $variantData = $variantRows->isNotEmpty()
            ? $variantRows->map(fn (ShopifyRow $row) => $row->data ?? [])->all()
            : ($primary ? [$primary->data ?? []] : []);

        $imageData = $imageRows->isNotEmpty()
            ? $imageRows->map(fn (ShopifyRow $row) => $row->data ?? [])->all()
            : ($primary ? [$primary->data ?? []] : []);

        return [$primary, $variantData, $imageData];
    }

    private function valueFromRow(array $rowData, string $header, mixed $fallback = null): ?string
    {
        $hasHeader = array_key_exists($header, $rowData);
        $value = $hasHeader ? $rowData[$header] : $fallback;

        if ($value !== null) {
            $trimmed = trim((string) $value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        if ($fallback === null) {
            return null;
        }

        $fallbackTrimmed = trim((string) $fallback);
        return $fallbackTrimmed === '' ? null : $fallbackTrimmed;
    }

    private function normalizeNumeric(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }
        $raw = is_scalar($value) ? (string) $value : null;
        if ($raw === null) {
            return null;
        }
        $normalized = str_replace([' ', ','], ['', '.'], $raw);
        $normalized = preg_replace('/[^0-9.]/', '', $normalized ?? '');
        if ($normalized === null || $normalized === '') {
            return null;
        }
        $parts = explode('.', $normalized);
        if (count($parts) > 2) {
            $normalized = array_shift($parts) . '.' . implode('', $parts);
        }
        return (float) $normalized;
    }

    /**
     * @return array<int, array{optionName:string,name:string}>
     */
    private function variantOptionValuesFromSource(array $rowData, ?Variant $localVariant = null, ?array $variantNode = null): array
    {
        $optionValues = [];
        $pairs = [
            [0, 'option1_name', HeaderStore::OPTION1_NAME, 'option1_value', HeaderStore::OPTION1_VALUE],
            [1, 'option2_name', HeaderStore::OPTION2_NAME, 'option2_value', HeaderStore::OPTION2_VALUE],
            [2, 'option3_name', HeaderStore::OPTION3_NAME, 'option3_value', HeaderStore::OPTION3_VALUE],
        ];

        foreach ($pairs as [$index, $nameField, $nameHeader, $valueField, $valueHeader]) {
            $value = $this->variantDesiredValue($localVariant, $rowData, $valueField, $valueHeader);
            if ($value === null) {
                continue;
            }

            $name = $this->variantDesiredValue($localVariant, $rowData, $nameField, $nameHeader)
                ?? $this->nullIfEmpty((string) data_get($variantNode, "selectedOptions.{$index}.name"));
            if ($name === null) {
                continue;
            }

            $optionValues[] = [
                'optionName' => $name,
                'name' => $value,
            ];
        }

        return $optionValues;
    }

    /**
     * @param Collection<int, Variant> $localVariantsOrdered
     * @return array<int, array{row:array, local:?Variant}>
     */
    private function buildVariantSources(array $rowDataList, Collection $localVariantsOrdered): array
    {
        $variantSources = [];
        $max = max(count($rowDataList), $localVariantsOrdered->count());

        for ($index = 0; $index < $max; $index++) {
            $rowData = $rowDataList[$index] ?? [];
            if (!is_array($rowData)) {
                $rowData = [];
            }

            $localVariant = $localVariantsOrdered->get($index);
            if ($localVariant === null && $rowData === []) {
                continue;
            }

            $variantSources[] = [
                'row' => $rowData,
                'local' => $localVariant,
            ];
        }

        return $variantSources;
    }

    private function variantDesiredValue(
        ?Variant $localVariant,
        array $rowData,
        string $localField,
        string $header,
        mixed $fallback = null
    ): ?string {
        $localValue = $localVariant?->{$localField};
        if ($localValue !== null) {
            $trimmed = trim((string) $localValue);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return $this->valueFromRow($rowData, $header, $fallback);
    }

    /**
     * @param array<int, array{row:array, local:?Variant}> $variantSources
     * @param array<int, array{product_id:int, warning:string}> $warnings
     */
    private function syncShopifyProductOptionsForVariants(
        Product $product,
        string $productId,
        array $variantSources,
        array $details,
        array &$warnings
    ): array {
        $desiredOptions = $this->desiredProductOptionsFromVariantSources($variantSources);
        if (empty($desiredOptions)) {
            return $details;
        }

        $currentOptions = data_get($details, 'options', []);
        if (!is_array($currentOptions)) {
            $currentOptions = [];
        }

        $optionsChanged = false;

        foreach ($desiredOptions as $position => $desiredOption) {
            $currentOption = $currentOptions[$position] ?? null;
            if (!is_array($currentOption) || empty($currentOption['id'])) {
                $data = $this->client->graphql($this->productOptionsCreateMutation(), [
                    'productId' => $productId,
                    'options' => [[
                        'name' => $desiredOption['name'],
                        'position' => $position + 1,
                        'values' => array_map(
                            static fn (string $value): array => ['name' => $value],
                            $desiredOption['values']
                        ),
                    ]],
                    'variantStrategy' => 'LEAVE_AS_IS',
                ]);

                $errors = data_get($data, 'productOptionsCreate.userErrors', []);
                if (is_array($errors) && !empty($errors)) {
                    $messages = $this->formatUserErrors($errors, 'productOptionsCreate');
                    throw new \RuntimeException($messages !== '' ? $messages : 'Shopify product option creation failed.');
                }

                $optionsChanged = true;
                $details = $this->productByHandleDetails($product->handle);
                $currentOptions = data_get($details, 'options', []);
                if (!is_array($currentOptions)) {
                    $currentOptions = [];
                }
                continue;
            }

            $optionInput = [
                'id' => $currentOption['id'],
                'position' => $position + 1,
            ];

            $currentOptionName = trim((string) ($currentOption['name'] ?? ''));
            if ($currentOptionName !== $desiredOption['name']) {
                $optionInput['name'] = $desiredOption['name'];
            }

            $optionValuesToUpdate = [];
            $optionValuesToAdd = [];
            $currentOptionValues = is_array($currentOption['optionValues'] ?? null) ? $currentOption['optionValues'] : [];
            $currentValueNames = [];

            foreach ($currentOptionValues as $currentValue) {
                if (!is_array($currentValue)) {
                    continue;
                }

                $currentName = trim((string) ($currentValue['name'] ?? ''));
                if ($currentName !== '') {
                    $currentValueNames[] = $currentName;
                }
            }

            if (
                strcasecmp($currentOptionName, 'Title') === 0
                && count($currentOptionValues) === 1
                && !empty($desiredOption['values'])
            ) {
                $currentValueId = trim((string) ($currentOptionValues[0]['id'] ?? ''));
                $firstDesiredValue = $desiredOption['values'][0];
                if ($currentValueId !== '') {
                    $currentValueName = trim((string) ($currentOptionValues[0]['name'] ?? ''));
                    if ($currentValueName !== $firstDesiredValue) {
                        $optionValuesToUpdate[] = [
                            'id' => $currentValueId,
                            'name' => $firstDesiredValue,
                        ];
                    }
                }

                $currentValueNames = [$firstDesiredValue];
            }

            foreach ($desiredOption['values'] as $desiredValue) {
                if (in_array($desiredValue, $currentValueNames, true)) {
                    continue;
                }

                $optionValuesToAdd[] = ['name' => $desiredValue];
            }

            if (count($optionInput) === 2 && empty($optionValuesToAdd) && empty($optionValuesToUpdate)) {
                continue;
            }

            $variables = [
                'productId' => $productId,
                'option' => $optionInput,
                'variantStrategy' => 'LEAVE_AS_IS',
            ];
            if (!empty($optionValuesToAdd)) {
                $variables['optionValuesToAdd'] = $optionValuesToAdd;
            }
            if (!empty($optionValuesToUpdate)) {
                $variables['optionValuesToUpdate'] = $optionValuesToUpdate;
            }

            $data = $this->client->graphql($this->productOptionUpdateMutation(), $variables);
            $errors = data_get($data, 'productOptionUpdate.userErrors', []);
            if (is_array($errors) && !empty($errors)) {
                $messages = $this->formatUserErrors($errors, 'productOptionUpdate');
                throw new \RuntimeException($messages !== '' ? $messages : 'Shopify product option update failed.');
            }

            $optionsChanged = true;
            $details = $this->productByHandleDetails($product->handle);
            $currentOptions = data_get($details, 'options', []);
            if (!is_array($currentOptions)) {
                $currentOptions = [];
            }
        }

        if (!$optionsChanged) {
            return $details;
        }

        return $this->productByHandleDetails($product->handle);
    }

    /**
     * @param array<int, array{row:array, local:?Variant}> $variantSources
     * @param array<int, array{product_id:int, warning:string}> $warnings
     */
    private function createMissingShopifyVariants(
        Product $product,
        string $productId,
        array $variantSources,
        array $details,
        ?string $primaryCostPerItem,
        ?string $primaryWeightUnit,
        ?string $primaryGrams,
        array &$warnings
    ): array {
        $variantNodes = data_get($details, 'variants.nodes', []);
        if (!is_array($variantNodes)) {
            $variantNodes = [];
        }

        $shopifyVariantsBySku = [];
        $shopifyVariantsBySignature = [];
        foreach ($variantNodes as $variantNode) {
            if (!is_array($variantNode)) {
                continue;
            }

            $sku = trim((string) ($variantNode['sku'] ?? ''));
            if ($sku !== '') {
                $shopifyVariantsBySku[$sku] = $variantNode;
            }

            $signature = $this->variantOptionSignatureFromNode($variantNode);
            if ($signature !== '') {
                $shopifyVariantsBySignature[$signature] = $variantNode;
            }
        }

        $createInputs = [];

        foreach ($variantSources as $variantSource) {
            $rowData = $variantSource['row'];
            $localVariant = $variantSource['local'];

            $desiredSku = $this->variantDesiredValue($localVariant, $rowData, 'sku', HeaderStore::VARIANT_SKU);
            if ($desiredSku !== null && isset($shopifyVariantsBySku[$desiredSku])) {
                continue;
            }

            $optionValues = $this->variantOptionValuesFromSource($rowData, $localVariant);
            $signature = $this->variantOptionSignature($optionValues);
            if ($signature !== '' && isset($shopifyVariantsBySignature[$signature])) {
                continue;
            }

            if (empty($optionValues)) {
                continue;
            }

            $input = [
                'optionValues' => $optionValues,
            ];

            $priceRaw = $this->variantDesiredValue($localVariant, $rowData, 'price', HeaderStore::VARIANT_PRICE);
            $price = $this->normalizeNumeric($priceRaw);
            if ($price !== null) {
                $input['price'] = (float) number_format($price, 2, '.', '');
            }

            $compareAtRaw = $this->variantDesiredValue($localVariant, $rowData, 'compare_at_price', HeaderStore::VARIANT_COMPARE_AT);
            $compareAt = $this->normalizeNumeric($compareAtRaw);
            if ($compareAt !== null) {
                $input['compareAtPrice'] = (float) number_format($compareAt, 2, '.', '');
            }

            $barcode = $this->variantDesiredValue($localVariant, $rowData, 'barcode', HeaderStore::VARIANT_BARCODE);
            $inventoryItemInput = [];

            if ($desiredSku !== null) {
                $inventoryItemInput['sku'] = $desiredSku;
                $barcode = $desiredSku;
            }

            if ($barcode !== null) {
                $input['barcode'] = $barcode;
            }

            $weightUnit = $this->variantDesiredValue($localVariant, $rowData, 'weight_unit', HeaderStore::VARIANT_WEIGHT_UNIT, $primaryWeightUnit);
            $grams = $this->normalizeNumeric(
                $this->variantDesiredValue($localVariant, $rowData, 'weight', HeaderStore::VARIANT_GRAMS, $primaryGrams)
            );
            if ($grams !== null || $weightUnit !== null) {
                $unit = $this->mapWeightUnit($weightUnit);
                $inventoryItemInput['measurement'] = [
                    'weight' => [
                        'unit' => $unit,
                        'value' => $grams !== null ? $this->weightFromGrams($grams, $unit) : 0.0,
                    ],
                ];
            }

            $costPerItemRaw = $this->valueFromRow($rowData, HeaderStore::COST_PER_ITEM, $primaryCostPerItem);
            $costPerItem = $this->normalizeNumeric($costPerItemRaw);
            if ($costPerItem !== null) {
                $inventoryItemInput['cost'] = (float) number_format($costPerItem, 2, '.', '');
            }

            if (!empty($inventoryItemInput)) {
                $input['inventoryItem'] = $inventoryItemInput;
            }

            $createInputs[] = $input;
        }

        if (empty($createInputs)) {
            return $details;
        }

        logger()->info('Shopify variant bulk create start', [
            'product_id' => $product->id,
            'handle' => $product->handle,
            'variant_input_count' => count($createInputs),
            'variant_inputs' => $createInputs,
        ]);

        $data = $this->client->graphql($this->variantsBulkCreateMutation(), [
            'productId' => $productId,
            'variants' => $createInputs,
        ]);

        $errors = data_get($data, 'productVariantsBulkCreate.userErrors', []);
        if (is_array($errors) && !empty($errors)) {
            logger()->error('Shopify variant bulk create errors', [
                'product_id' => $product->id,
                'handle' => $product->handle,
                'errors' => $errors,
            ]);
            $messages = $this->formatUserErrors($errors, 'productVariantsBulkCreate');
            throw new \RuntimeException($messages !== '' ? $messages : 'Shopify variant creation failed.');
        }

        return $this->productByHandleDetails($product->handle);
    }

    /**
     * @param array<int, array{row:array, local:?Variant}> $variantSources
     * @return array<int, array{name:string, values:array<int, string>}>
     */
    private function desiredProductOptionsFromVariantSources(array $variantSources): array
    {
        $slots = [
            ['name' => null, 'values' => []],
            ['name' => null, 'values' => []],
            ['name' => null, 'values' => []],
        ];

        foreach ($variantSources as $variantSource) {
            $rowData = $variantSource['row'];
            $localVariant = $variantSource['local'];

            foreach ([1, 2, 3] as $slotNumber) {
                $slotIndex = $slotNumber - 1;
                $name = $this->variantDesiredValue(
                    $localVariant,
                    $rowData,
                    "option{$slotNumber}_name",
                    constant(HeaderStore::class . "::OPTION{$slotNumber}_NAME")
                );
                $value = $this->variantDesiredValue(
                    $localVariant,
                    $rowData,
                    "option{$slotNumber}_value",
                    constant(HeaderStore::class . "::OPTION{$slotNumber}_VALUE")
                );

                if ($name === null || $value === null) {
                    continue;
                }

                if ($slots[$slotIndex]['name'] === null) {
                    $slots[$slotIndex]['name'] = $name;
                }

                if (!in_array($value, $slots[$slotIndex]['values'], true)) {
                    $slots[$slotIndex]['values'][] = $value;
                }
            }
        }

        $desiredOptions = [];
        foreach ($slots as $slot) {
            if (!is_string($slot['name']) || $slot['name'] === '' || empty($slot['values'])) {
                continue;
            }

            $desiredOptions[] = [
                'name' => $slot['name'],
                'values' => $slot['values'],
            ];
        }

        return $desiredOptions;
    }

    /**
     * @param array<int, array{optionName:string,name:string}> $optionValues
     */
    private function variantOptionSignature(array $optionValues): string
    {
        if (empty($optionValues)) {
            return '';
        }

        $parts = [];
        foreach ($optionValues as $optionValue) {
            $optionName = strtolower(trim((string) ($optionValue['optionName'] ?? '')));
            $name = strtolower(trim((string) ($optionValue['name'] ?? '')));
            if ($optionName === '' || $name === '') {
                continue;
            }

            $parts[] = "{$optionName}:{$name}";
        }

        return implode('|', $parts);
    }

    private function variantOptionSignatureFromNode(array $variantNode): string
    {
        $optionValues = [];
        $selectedOptions = is_array($variantNode['selectedOptions'] ?? null) ? $variantNode['selectedOptions'] : [];
        foreach ($selectedOptions as $selectedOption) {
            if (!is_array($selectedOption)) {
                continue;
            }

            $optionValues[] = [
                'optionName' => (string) ($selectedOption['name'] ?? ''),
                'name' => (string) ($selectedOption['value'] ?? ''),
            ];
        }

        return $this->variantOptionSignature($optionValues);
    }

    private function mapWeightUnit(?string $value): string
    {
        $normalized = strtolower(trim((string) $value));
        return match ($normalized) {
            'kg', 'kilogram', 'kilograms' => 'KILOGRAMS',
            'oz', 'ounce', 'ounces' => 'OUNCES',
            'lb', 'lbs', 'pound', 'pounds' => 'POUNDS',
            default => 'GRAMS',
        };
    }

    private function weightFromGrams(float $grams, string $unit): float
    {
        return match ($unit) {
            'KILOGRAMS' => $grams / 1000,
            'OUNCES' => $grams / 28.3495,
            'POUNDS' => $grams / 453.592,
            default => $grams,
        };
    }

    /**
     * @param array<int, array{field?:array|string|null,message?:string|null}> $errors
     */
    private function formatUserErrors(array $errors, string $fallbackField = 'input'): string
    {
        return collect($errors)
            ->map(function (array $error) use ($fallbackField): string {
                $field = $error['field'] ?? null;
                $fieldPath = $fallbackField;
                if (is_array($field)) {
                    $fieldPath = implode('.', $field);
                } elseif (is_string($field) && $field !== '') {
                    $fieldPath = $field;
                }
                $message = $error['message'] ?? 'Unknown error';
                return "{$fieldPath}: {$message}";
            })
            ->filter()
            ->implode('; ');
    }

    /**
     * @param array<int, array> $variantRows
     * @param array<int, array> $imageRows
     * @return array<int, array{product_id:int, warning:string}>
     */
    private function buildSyncCoverageWarnings(
        Product $product,
        array $primaryData,
        array $variantRows,
        array $imageRows
    ): array {
        // Your existing logic returns [] anyway; kept untouched.
        return [];
    }

    /**
     * @param array<int, string>|null $scopes
     * @return array<int, string>
     */
    private function normalizeSyncScopes(?array $scopes): array
    {
        if ($scopes === null) {
            return self::availableSyncScopes();
        }

        $allowed = array_fill_keys(self::availableSyncScopes(), true);
        $normalized = [];

        foreach ($scopes as $scope) {
            $scope = is_string($scope) ? trim($scope) : '';
            if ($scope === '' || !isset($allowed[$scope])) {
                continue;
            }

            $normalized[$scope] = $scope;
        }

        return array_values($normalized);
    }

    /**
     * @param array<int, string>|null $coreFields
     * @return array<int, string>
     */
    private function normalizeCoreFields(?array $coreFields): array
    {
        if ($coreFields === null) {
            return self::defaultCoreFields();
        }

        $allowed = array_fill_keys(self::availableCoreFields(), true);
        $normalized = [];

        foreach ($coreFields as $field) {
            $field = is_string($field) ? trim($field) : '';
            if ($field === '' || !isset($allowed[$field])) {
                continue;
            }

            $normalized[$field] = $field;
        }

        return array_values($normalized);
    }

    /**
     * @param array<int, string> $scopes
     */
    private function scopeEnabled(array $scopes, string $scope): bool
    {
        return in_array($scope, $scopes, true);
    }

    /**
     * @param array<int, string> $coreFields
     */
    private function coreFieldEnabled(array $coreFields, string $field): bool
    {
        return in_array($field, $coreFields, true);
    }

    /**
     * @return array<string, string>
     */
    private function coreFieldHeaderMap(): array
    {
        return [
            self::CORE_FIELD_COLOR => HeaderStore::COLOR_METAFIELD,
            self::CORE_FIELD_MATERIALS_AND_DIMENSIONS => HeaderStore::MATERIALS_AND_DIMENSIONS,
            self::CORE_FIELD_JEWELRY_MATERIAL => HeaderStore::JEWELRY_MATERIAL,
            self::CORE_FIELD_JEWELRY_TYPE => HeaderStore::JEWELRY_TYPE,
            self::CORE_FIELD_TARGET_GENDER => HeaderStore::TARGET_GENDER,
            self::CORE_FIELD_AGE_GROUP => HeaderStore::AGE_GROUP,
            self::CORE_FIELD_BRACELET_DESIGN => HeaderStore::BRACELET_DESIGN,
            self::CORE_FIELD_PATTERN_CATEGORY => HeaderStore::PATTERN_CATEGORY,
            self::CORE_FIELD_PRODUCT_METALS => HeaderStore::PRODUCT_METALS,
            self::CORE_FIELD_SIBLINGS => HeaderStore::SIBLINGS,
            self::CORE_FIELD_COMPLEMENTARY_PRODUCTS => HeaderStore::COMPLEMENTARY_PRODUCTS,
            self::CORE_FIELD_UVP_SHORT_PARAGRAPH => HeaderStore::UVP_SHORT_PARAGRAPH,
            self::CORE_FIELD_SEO_DEINDEX => HeaderStore::SEO_DEINDEX,
        ];
    }

    /**
     * @param array<int, string> $coreFields
     * @return array<int, string>
     */
    private function selectedCoreMetafieldHeaders(array $coreFields): array
    {
        $map = $this->coreFieldHeaderMap();
        $headers = [];

        foreach ($coreFields as $field) {
            if (!isset($map[$field])) {
                continue;
            }

            $headers[$map[$field]] = $map[$field];
        }

        return array_values($headers);
    }

    private function selectedCoreMetafieldValue(Product $product, array $primaryData, string $header): ?string
    {
        if ($header === HeaderStore::COLOR_METAFIELD) {
            $productValue = $this->nullIfEmpty($product->color_string);
            if ($productValue !== null) {
                return str_replace(',', ';', $productValue);
            }
        }

        if ($header === HeaderStore::UVP_SHORT_PARAGRAPH) {
            $productValue = $this->nullIfEmpty($product->uvp_short_paragraph);
            if ($productValue !== null) {
                return $productValue;
            }
        }

        $value = $primaryData[$header] ?? null;
        if (!is_scalar($value)) {
            return null;
        }

        $stringValue = trim((string) $value);
        return $stringValue === '' ? null : $stringValue;
    }

    private function isMetafieldHeader(string $header): bool
    {
        return (bool) preg_match('/\\(product\\.metafields\\.([^.]+)\\.([^)]+)\\)/', $header);
    }

    private function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '';
    }

    private function isBlockedByShopifyMissingDraft(Product $product): bool
    {
        return NewProductDraft::query()
            ->where('shopify_missing_sync_blocked', true)
            ->where(function ($query) use ($product): void {
                $shopifyId = trim((string) ($product->shopify_id ?? ''));
                $handle = trim((string) ($product->handle ?? ''));

                if ($shopifyId !== '') {
                    $query->orWhere('shopify_id', $shopifyId);
                }

                if ($handle !== '') {
                    $query->orWhere('handle', $handle);
                }
            })
            ->exists();
    }
}
