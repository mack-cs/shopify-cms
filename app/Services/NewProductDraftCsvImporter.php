<?php

namespace App\Services;

use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StyleProfile;
use App\Models\Variant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use League\Csv\Reader;

final class NewProductDraftCsvImporter
{
    /** @var array<string, string>|null */
    private ?array $productReferenceLookup = null;

    /** @var array<string, string>|null */
    private ?array $csvProductReferenceLookup = null;

    /**
     * @return array{
     *   total:int,
     *   created:int,
     *   updated:int,
     *   seo_drafts_upserted:int,
     *   skipped_pending_approval:int,
     *   pending_approval_handles:array<int,string>,
     *   skipped_missing_handle:int,
     *   skipped_duplicate_sku:int,
     *   skipped_reference_validation:int,
     *   resolved_product_references:int,
     *   unresolved_product_references:int,
     *   protected_conflict_count:int,
     *   protected_conflicts:array<int,string>,
     *   invalid_seo_count:int,
     *   invalid_seo_rows:int,
     *   seo_corrections:array<int,string>,
     *   prepopulation_applied:int,
     *   prepopulation_unmatched:int,
     *   pricing_batch:?string
     * }
     */
    public function importFromPath(string $absolutePath): array
    {
        $csv = Reader::createFromPath($absolutePath);
        $csv->setHeaderOffset(0);

        $draftMap = [
            'draft id' => 'draft_id',
            'new product draft id' => 'draft_id',
            'handle' => 'handle',
            'batch' => 'batch',
            'shopify id' => 'shopify_id',
            'product shopify id' => 'shopify_id',
            'sku' => 'sku',
            'title' => 'title',
            'product name' => 'title',
            'description' => 'body_html',
            'description html' => 'body_html',
            'body html' => 'body_html',
            'vendor' => 'vendor',
            'tags' => 'tags',
            'collection' => 'collection_prepopulation',
            'collection tag' => 'collection_prepopulation',
            'collection tags' => 'collection_prepopulation',
            'collection handle' => 'collection_prepopulation',
            'prepopulation collection' => 'collection_prepopulation',
            'product type' => 'type',
            'type' => 'type',
            'product category' => 'product_category',
            'google product category' => 'google_product_category',
            'status' => 'status',
            'published' => 'published',
            'colors' => 'color_string',
            'color' => 'color_string',
            'price' => 'variant_price',
            'compare at price' => 'variant_compare_at_price',
            'compare-at price' => 'variant_compare_at_price',
            'compare at price stricked out price' => 'variant_compare_at_price',
            'inventory' => 'variant_inventory_qty',
            'inventory available in stock' => 'variant_inventory_qty',
            'variant inventory qty' => 'variant_inventory_qty',
            'weight' => 'variant_weight',
            'variant weight' => 'variant_weight',
            'variant grams' => 'variant_weight',
            'weight unit' => 'variant_weight_unit',
            'variant weight unit' => 'variant_weight_unit',
            'variant inventory policy' => 'variant_inventory_policy',
            'variant fulfillment service' => 'variant_fulfillment_service',
            'material cost' => 'material_cost',
            'material cost use 19 00 not 19 00' => 'material_cost',
            'jewelry material' => 'jewelry_material',
            'jewelry materials' => 'jewelry_material',
            'jewellery material' => 'jewelry_material',
            'jewellery materials' => 'jewelry_material',
            'product materials' => 'product_materials',
            'propduct materials' => 'product_materials',
            'propduct materials new metafield' => 'product_materials',
            'product materials new metafield' => 'product_materials',
            'materials and dimensions' => 'materials_and_dimensions',
            'product design' => 'product_design',
            'product design beaded' => 'product_design',
            'metal' => 'metal',
            'pattern category' => 'colour_style',
            'color style' => 'colour_style',
            'colour style' => 'colour_style',
            'colour style solid multicolor' => 'colour_style',
            'size' => 'size',
            'siblings collection name' => 'siblings_collection_name',
            'sibling collection' => 'sibling_collection',
            'shop your vibe' => 'shop_your_vibe_tags',
            'shop your vibe tags' => 'shop_your_vibe_tags',
            'uvp short paragraph' => 'uvp_short_paragraph',
            'bead colour finish' => 'bead_colour_finish',
            'bead color finish' => 'bead_colour_finish',
            'complementary products' => 'complementary_products',
            'complementary products finish the set and get one free' => 'complementary_products',
            'complementary products handles' => 'complementary_products',
            'complementary product handles' => 'complementary_products',
            'complementary product skus' => 'complementary_product_skus',
            'complementary products skus' => 'complementary_product_skus',
            'complementary skus' => 'complementary_product_skus',
            'associated products' => 'associated_products',
            'associated product handles' => 'associated_products',
            'associated products handles' => 'associated_products',
            'associated product skus' => 'associated_product_skus',
            'associated products skus' => 'associated_product_skus',
            'associated skus' => 'associated_product_skus',
            'bundle products' => 'associated_products',
            'bundle product handles' => 'associated_products',
            'bundle product skus' => 'associated_product_skus',
            'component products' => 'associated_products',
            'component product handles' => 'associated_products',
            'component product skus' => 'associated_product_skus',
            'stack products' => 'associated_products',
            'stack product handles' => 'associated_products',
            'stack product skus' => 'associated_product_skus',
        ];

        $seoDraftMap = [
            'style' => 'style_type',
            'style materials' => 'materials',
            'style components' => 'components',
            'materials' => 'materials',
            'components' => 'components',
            'colour prompt' => 'colour_prompt',
            'color prompt' => 'colour_prompt',
            'draft title' => 'draft_title',
            'draft description' => 'draft_description',
            'seo title' => 'draft_seo_title',
            'seo title 60 chars' => 'draft_seo_title',
            'seo title 70 chars' => 'draft_seo_title',
            'seo description 160 chars' => 'draft_seo_description',
            'seo description' => 'draft_seo_description',
            'draft image alt text' => 'draft_image_alt_text',
            'image alt text 125 chars' => 'draft_image_alt_text',
            'image alt text' => 'draft_image_alt_text',
        ];

        $total = 0;
        $created = 0;
        $updated = 0;
        $seoDraftsUpserted = 0;
        $skippedPendingApproval = 0;
        $pendingApprovalHandles = [];
        $skippedMissingHandle = 0;
        $skippedDuplicateSku = 0;
        $skippedReferenceValidation = 0;
        $resolvedProductReferences = 0;
        $unresolvedProductReferences = 0;
        $protectedConflicts = [];
        $invalidSeoCount = 0;
        $invalidSeoRows = [];
        $seoCorrections = [];
        $prepopulationApplied = 0;
        $prepopulationUnmatched = 0;
        $pricingFields = ['variant_price', 'variant_compare_at_price', 'material_cost'];
        $pricingImport = false;

        foreach ($csv->getHeader() as $header) {
            $field = $draftMap[$this->normalizeHeader((string) $header)] ?? null;
            if (in_array($field, $pricingFields, true)) {
                $pricingImport = true;
                break;
            }
        }

        $pricingBatch = $pricingImport ? 'pricing_'.now()->format('Y_m_d_His') : null;
        $this->csvProductReferenceLookup = $this->csvProductReferenceLookup($csv, $draftMap);

        DB::transaction(function () use (
            $csv,
            $draftMap,
            $seoDraftMap,
            &$total,
            &$created,
            &$updated,
            &$seoDraftsUpserted,
            &$skippedPendingApproval,
            &$pendingApprovalHandles,
            &$skippedMissingHandle,
            &$skippedDuplicateSku,
            &$skippedReferenceValidation,
            &$resolvedProductReferences,
            &$unresolvedProductReferences,
            &$protectedConflicts,
            &$invalidSeoCount,
            &$invalidSeoRows,
            &$seoCorrections,
            &$prepopulationApplied,
            &$prepopulationUnmatched,
            $pricingBatch
        ): void {
            foreach ($csv->getRecords() as $row) {
                $total++;

                $data = [];
                $seoDraftData = [];
                $payload = [];

                foreach ($row as $header => $value) {
                    $normalized = $this->normalizeHeader((string) $header);
                    $value = trim((string) $value);
                    $field = $draftMap[$normalized] ?? null;
                    if ($field) {
                        $authoritativeNullable = in_array($field, [
                            'variant_price',
                            'variant_compare_at_price',
                        ], true);

                        if ($value === '' && ! $authoritativeNullable) {
                            continue;
                        }

                        if ($field === 'material_cost' && $value !== '') {
                            $value = $this->normalizeNumeric($value);
                        }
                        $data[$field] = $value === '' ? null : $value;
                    } elseif (isset($seoDraftMap[$normalized])) {
                        if ($value === '') {
                            continue;
                        }
                        $seoDraftData[$seoDraftMap[$normalized]] = $value;
                    } else {
                        if ($value === '') {
                            continue;
                        }
                        $payload[$header] = $value;
                    }
                }

                $data = $this->applyImportedTypeCategoryMapping($data);
                [$data, $appliedCollectionRule] = $this->applyCollectionPrepopulation($data);
                $data = $this->mergeImportedTagSources($data);
                $payload = $this->applyDefaultExtraShopifyPayload($payload);
                if ($appliedCollectionRule === true) {
                    $prepopulationApplied++;
                } elseif ($appliedCollectionRule === false) {
                    $prepopulationUnmatched++;
                }

                unset($data['draft_id']);
                unset($data['collection_prepopulation']);

                $handle = $data['handle'] ?? null;
                $shopifyId = $data['shopify_id'] ?? null;
                $sku = $data['sku'] ?? null;

                $draft = $this->findDraftForImport($sku, $shopifyId, $handle);
                $skuOwnerProductIds = $this->productIdsForSku(is_string($sku) ? $sku : null);

                if (! $draft && count($skuOwnerProductIds) === 1) {
                    $owner = Product::query()->find($skuOwnerProductIds[0]);
                    if ($owner instanceof Product) {
                        $draft = $this->findDraftForLinkedProduct($owner);
                        if (! $draft) {
                            if (empty($data['handle']) && trim((string) $owner->handle) !== '') {
                                $data['handle'] = $owner->handle;
                                $handle = $owner->handle;
                            }
                            if (empty($data['shopify_id']) && trim((string) $owner->shopify_id) !== '') {
                                $data['shopify_id'] = $owner->shopify_id;
                                $shopifyId = $owner->shopify_id;
                            }
                            if (! array_key_exists('title', $data) && trim((string) $owner->title) !== '') {
                                $data['title'] = $owner->title;
                            }
                        }
                    }
                }

                if ($draft) {
                    foreach ($this->protectedFieldConflicts($draft, $data) as $conflict) {
                        $protectedConflicts[] = $conflict;
                    }

                    unset($data['handle']);
                    $handle = trim((string) ($draft->handle ?? '')) ?: null;

                    if (count($skuOwnerProductIds) === 1) {
                        $owner = Product::query()->find($skuOwnerProductIds[0]);
                        if ($owner instanceof Product) {
                            if ($handle === null && trim((string) $owner->handle) !== '') {
                                $draft->handle = $owner->handle;
                                $handle = $owner->handle;
                            }
                            if (trim((string) ($draft->shopify_id ?? '')) === '' && trim((string) $owner->shopify_id) !== '') {
                                $draft->shopify_id = $owner->shopify_id;
                            }
                        }
                    }

                    if ($pricingBatch !== null) {
                        $data['batch'] = $pricingBatch;
                    }
                }

                if ($draft && empty($handle)) {
                    $handle = trim((string) ($draft->handle ?? '')) ?: null;
                }

                if (! $sku && ! $draft) {
                    $skippedMissingHandle++;

                    continue;
                }

                if ($draft instanceof NewProductDraft && $draft->isPendingApproval()) {
                    $skippedPendingApproval++;
                    $pendingApprovalHandles[] = trim((string) ($draft->handle ?: $draft->title ?: $draft->shopify_id ?: 'Draft #'.$draft->id));

                    continue;
                }

                if (array_key_exists('complementary_products', $data) || array_key_exists('complementary_product_skus', $data)) {
                    [$data['complementary_products'], $resolvedCount, $unresolvedCount] = $this->normalizeProductReferenceField(
                        $data['complementary_products'] ?? null,
                        $data['complementary_product_skus'] ?? null
                    );
                    $resolvedProductReferences += $resolvedCount;
                    $unresolvedProductReferences += $unresolvedCount;
                }
                unset($data['complementary_product_skus']);

                if (array_key_exists('associated_products', $data) || array_key_exists('associated_product_skus', $data)) {
                    [$data['bundle_product_ids'], $resolvedCount, $unresolvedCount] = $this->normalizeAssociatedProductField(
                        $data['associated_products'] ?? null,
                        $data['associated_product_skus'] ?? null
                    );
                    $resolvedProductReferences += $resolvedCount;
                    $unresolvedProductReferences += $unresolvedCount;
                }
                unset($data['associated_products'], $data['associated_product_skus']);

                if ($this->failsProductReferenceRules($data)) {
                    $skippedReferenceValidation++;

                    continue;
                }

                if ($sku) {
                    $normalizedSku = strtolower(trim((string) $sku));
                    $draftQuery = NewProductDraft::query()
                        ->whereRaw('LOWER(TRIM(sku)) = ?', [$normalizedSku]);
                    if ($draft) {
                        $draftQuery->whereKeyNot($draft->getKey());
                    } elseif ($handle) {
                        $draftQuery->where('handle', '!=', $handle);
                    }

                    $variantQuery = Variant::query()
                        ->whereRaw('LOWER(TRIM(sku)) = ?', [$normalizedSku]);
                    $linkedProductId = $draft ? $this->linkedProductId($draft) : null;
                    if ($linkedProductId === null && count($skuOwnerProductIds) === 1) {
                        $linkedProductId = $skuOwnerProductIds[0];
                    }
                    if ($linkedProductId !== null) {
                        $variantQuery->where('product_id', '!=', $linkedProductId);
                    }

                    if ($draftQuery->exists() || $variantQuery->exists()) {
                        $skippedDuplicateSku++;

                        continue;
                    }
                }

                $seoReference = trim((string) ($sku ?: $handle ?: 'unknown product'));
                $seoRules = [
                    'draft_seo_title' => [
                        'label' => 'SEO title',
                        'min' => StyleProfile::SEO_TITLE_RECOMMENDED_MIN,
                        'max' => StyleProfile::SEO_TITLE_RECOMMENDED_MAX,
                    ],
                    'draft_seo_description' => [
                        'label' => 'SEO description',
                        'min' => StyleProfile::SEO_DESCRIPTION_RECOMMENDED_MIN,
                        'max' => StyleProfile::SEO_DESCRIPTION_RECOMMENDED_MAX,
                    ],
                ];

                foreach ($seoRules as $field => $rule) {
                    if (! array_key_exists($field, $seoDraftData)) {
                        continue;
                    }

                    $length = StyleProfile::trimmedLength($seoDraftData[$field]);
                    if ($length >= $rule['min'] && $length <= $rule['max']) {
                        continue;
                    }

                    unset($seoDraftData[$field]);
                    $invalidSeoCount++;
                    $invalidSeoRows[$total] = true;
                    $seoCorrections[] = 'Row '.($total + 1)." ({$seoReference}): {$rule['label']} is {$length} characters; required {$rule['min']}-{$rule['max']}.";
                }

                if ($draft) {
                    $mergedPayload = array_merge($draft->payload ?? [], $payload);
                    $draft->fill($data);
                    if (empty($data['variant_inventory_policy'])) {
                        $draft->variant_inventory_policy = 'deny';
                    }
                    if (empty($data['variant_fulfillment_service'])) {
                        $draft->variant_fulfillment_service = 'manual';
                    }
                    if (empty($data['batch'])) {
                        $draft->batch = $draft->batch ?? ('batch'.now()->format('Ymd'));
                    }
                    $draft->payload = $mergedPayload;
                    $draft->save();
                    $draft->touch();
                    $this->syncImportedDraftToProduct($draft, $data, $payload);
                    $updated++;
                } else {
                    $data['payload'] = $payload ?: null;
                    $data['created_by'] = Auth::id();
                    $data['variant_inventory_policy'] = $data['variant_inventory_policy'] ?? 'deny';
                    $data['variant_fulfillment_service'] = $data['variant_fulfillment_service'] ?? 'manual';
                    $data['batch'] = $data['batch'] ?? ('batch'.now()->format('Ymd'));
                    $data['origin'] = $data['origin'] ?? NewProductDraft::ORIGIN_DRAFT_TOOL;

                    $draft = NewProductDraft::create($data);
                    $this->syncImportedDraftToProduct($draft, $data, $payload);
                    $created++;
                }

                if (! empty($seoDraftData)) {
                    $resolvedSku = trim((string) ($data['sku'] ?? $draft?->sku ?? $sku ?? ''));
                    $resolvedHandle = trim((string) ($handle ?: $draft?->handle ?: '')) ?: null;
                    $inferredHandle = $resolvedHandle
                        ?: (trim((string) ($data['title'] ?? $draft?->title ?? '')) !== ''
                            ? Str::slug((string) ($data['title'] ?? $draft?->title))
                            : null);

                    $product = $this->productForSeoDraft($resolvedSku, $resolvedHandle);
                    $styleProfile = $this->styleProfileForSeoDraft($resolvedSku, $resolvedHandle);
                    $styleProfileHandle = $resolvedHandle
                        ?: trim((string) ($product?->handle ?? ''))
                        ?: $inferredHandle;
                    $styleProfileSku = trim((string) (
                        $styleProfile?->sku
                        ?? $resolvedSku
                        ?? $product?->variants()->orderBy('id')->value('sku')
                        ?? $styleProfileHandle
                    ));

                    if ($styleProfileSku === '' || trim((string) $styleProfileHandle) === '') {
                        continue;
                    }

                    $styleProfileData = array_merge($seoDraftData, [
                        'handle' => $styleProfileHandle,
                        'product_id' => $product?->id,
                        'sku' => $styleProfileSku,
                    ]);

                    if ($styleProfile) {
                        $styleProfile->update($styleProfileData);
                    } else {
                        StyleProfile::create($styleProfileData);
                    }

                    if ($draft) {
                        $draft->touch();
                    }

                    $seoDraftsUpserted++;
                }
            }
        });

        return [
            'total' => $total,
            'created' => $created,
            'updated' => $updated,
            'seo_drafts_upserted' => $seoDraftsUpserted,
            'skipped_pending_approval' => $skippedPendingApproval,
            'pending_approval_handles' => array_values(array_unique(array_filter($pendingApprovalHandles))),
            'skipped_missing_handle' => $skippedMissingHandle,
            'skipped_duplicate_sku' => $skippedDuplicateSku,
            'skipped_reference_validation' => $skippedReferenceValidation,
            'resolved_product_references' => $resolvedProductReferences,
            'unresolved_product_references' => $unresolvedProductReferences,
            'protected_conflict_count' => count($protectedConflicts),
            'protected_conflicts' => array_values(array_unique($protectedConflicts)),
            'invalid_seo_count' => $invalidSeoCount,
            'invalid_seo_rows' => count($invalidSeoRows),
            'seo_corrections' => $seoCorrections,
            'prepopulation_applied' => $prepopulationApplied,
            'prepopulation_unmatched' => $prepopulationUnmatched,
            'pricing_batch' => $pricingBatch,
        ];
    }

    /**
     * Shop Your Vibe and sibling collection are supplied as their own columns.
     * Their values are also membership tags, so add them to the product tags.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mergeImportedTagSources(array $data): array
    {
        $supplemental = [];

        if (array_key_exists('shop_your_vibe_tags', $data)) {
            $supplemental = array_merge(
                $supplemental,
                TagNormalizer::parseTokens(is_string($data['shop_your_vibe_tags']) ? $data['shop_your_vibe_tags'] : null)
            );
            unset($data['shop_your_vibe_tags']);
        }

        $sibling = trim((string) ($data['sibling_collection'] ?? ''));
        if (
            $sibling !== ''
            && strcasecmp($sibling, 'no sibling collection') !== 0
            && $sibling !== NewProductDraft::NO_SIBLING_COLLECTION
        ) {
            $supplemental = array_merge($supplemental, TagNormalizer::parseTokens($sibling));
        }

        if ($supplemental === []) {
            return $data;
        }

        $data['tags'] = TagNormalizer::normalizeFromArray(array_merge(
            TagNormalizer::parseTokens(is_string($data['tags'] ?? null) ? $data['tags'] : null),
            $supplemental
        ));

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{0: array<string, mixed>, 1: ?bool}
     */
    private function applyCollectionPrepopulation(array $data): array
    {
        $collection = trim((string) ($data['collection_prepopulation'] ?? ''));
        if ($collection === '') {
            return [$data, null];
        }

        $service = app(PrepopulationRuleService::class);
        $rule = $service->ruleForCollection($collection);
        if ($rule === null) {
            return [$data, false];
        }

        $explicit = array_flip(array_keys($data));
        $updates = $service->applyCollectionRuleReplacingManagedTags(
            $rule,
            $data['tags'] ?? null,
            is_string($data['type'] ?? null) ? $data['type'] : null
        );

        foreach ($updates as $field => $value) {
            if ($field === 'tags') {
                $data['tags'] = TagNormalizer::normalizeFromArray(
                    is_array($value) ? $value : TagNormalizer::parseTokens((string) $value)
                );
                continue;
            }

            if (isset($explicit[$field])) {
                continue;
            }

            $data[$field] = $value;
        }

        return [$data, true];
    }

    private function findDraftForImport(?string $sku, ?string $shopifyId, ?string $handle): ?NewProductDraft
    {
        $trimmedSku = trim((string) ($sku ?? ''));
        if ($trimmedSku !== '') {
            $draft = NewProductDraft::query()
                ->whereRaw('LOWER(TRIM(sku)) = ?', [strtolower($trimmedSku)])
                ->orderBy('id')
                ->first();
            if ($draft) {
                return $draft;
            }
        }

        $trimmedShopifyId = trim((string) ($shopifyId ?? ''));
        if ($trimmedShopifyId !== '') {
            $draft = NewProductDraft::query()
                ->where('shopify_id', $trimmedShopifyId)
                ->first();
            if ($draft) {
                return $draft;
            }
        }

        $trimmedHandle = trim((string) ($handle ?? ''));
        if ($trimmedHandle === '') {
            return null;
        }

        return NewProductDraft::query()
            ->whereRaw('LOWER(TRIM(handle)) = ?', [strtolower($trimmedHandle)])
            ->first();
    }

    private function productForSeoDraft(?string $sku, ?string $handle): ?Product
    {
        $trimmedHandle = trim((string) ($handle ?? ''));
        if ($trimmedHandle !== '') {
            $product = Product::query()
                ->where('handle', $trimmedHandle)
                ->first();
            if ($product instanceof Product) {
                return $product;
            }
        }

        $trimmedSku = trim((string) ($sku ?? ''));
        if ($trimmedSku === '') {
            return null;
        }

        $variant = Variant::query()
            ->whereRaw('LOWER(TRIM(sku)) = ?', [strtolower($trimmedSku)])
            ->with('product')
            ->orderBy('id')
            ->first();

        return $variant?->product instanceof Product ? $variant->product : null;
    }

    private function styleProfileForSeoDraft(?string $sku, ?string $handle): ?StyleProfile
    {
        $trimmedHandle = trim((string) ($handle ?? ''));
        if ($trimmedHandle !== '') {
            $profile = StyleProfile::query()
                ->where('handle', $trimmedHandle)
                ->first();
            if ($profile instanceof StyleProfile) {
                return $profile;
            }
        }

        $trimmedSku = trim((string) ($sku ?? ''));
        if ($trimmedSku === '') {
            return null;
        }

        return StyleProfile::query()
            ->whereRaw('LOWER(TRIM(sku)) = ?', [strtolower($trimmedSku)])
            ->orderBy('id')
            ->first();
    }

    /**
     * Apply the same dependent selections as the New Product Draft form. An
     * imported type is treated as the user's selection and therefore drives
     * the canonical Shopify and Google categories.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function applyImportedTypeCategoryMapping(array $data): array
    {
        $type = trim((string) ($data['type'] ?? ''));
        if ($type === '') {
            return $data;
        }

        $mapping = CategoryTypeMap::byType($type);
        if ($mapping === null) {
            return $data;
        }

        $data['type'] = $mapping['type'];
        $data['product_category'] = $mapping['shopify_taxonomy_gid'] ?? $mapping['category'];
        $data['google_product_category'] = $mapping['google_product_category'];

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<int, string>
     */
    private function protectedFieldConflicts(NewProductDraft $draft, array $data): array
    {
        $conflicts = [];
        $identity = trim((string) ($draft->sku ?: $draft->shopify_id ?: $draft->handle ?: "Draft #{$draft->id}"));

        $incomingHandle = trim((string) ($data['handle'] ?? ''));
        $existingHandle = trim((string) ($draft->handle ?? ''));
        if ($incomingHandle !== '' && strcasecmp($incomingHandle, $existingHandle) !== 0) {
            $conflicts[] = "{$identity}: Handle protected (file: {$incomingHandle}; existing: {$existingHandle})";
        }

        return $conflicts;
    }

    /**
     * @return array<int, int>
     */
    private function productIdsForSku(?string $sku): array
    {
        $normalizedSku = strtolower(trim((string) $sku));
        if ($normalizedSku === '') {
            return [];
        }

        return Variant::query()
            ->whereRaw('LOWER(TRIM(sku)) = ?', [$normalizedSku])
            ->pluck('product_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->unique()
            ->values()
            ->all();
    }

    private function findDraftForLinkedProduct(Product $product): ?NewProductDraft
    {
        $handle = trim((string) ($product->handle ?? ''));
        if ($handle !== '') {
            $draft = NewProductDraft::query()
                ->whereRaw('LOWER(TRIM(handle)) = ?', [strtolower($handle)])
                ->first();
            if ($draft instanceof NewProductDraft) {
                return $draft;
            }
        }

        $shopifyId = trim((string) ($product->shopify_id ?? ''));
        if ($shopifyId === '') {
            return null;
        }

        $draft = NewProductDraft::query()
            ->where('shopify_id', $shopifyId)
            ->first();

        return $draft instanceof NewProductDraft ? $draft : null;
    }

    private function linkedProductId(NewProductDraft $draft): ?int
    {
        $shopifyId = trim((string) ($draft->shopify_id ?? ''));
        if ($shopifyId !== '') {
            $productId = Product::query()
                ->where('shopify_id', $shopifyId)
                ->value('id');

            if ($productId !== null) {
                return (int) $productId;
            }
        }

        $handle = trim((string) ($draft->handle ?? ''));
        if ($handle === '') {
            return null;
        }

        $productId = Product::query()
            ->where('handle', $handle)
            ->value('id');

        return $productId === null ? null : (int) $productId;
    }

    /**
     * @return array{0:?string,1:int,2:int}
     */
    private function normalizeProductReferenceField(?string $value, ?string $skuValue = null): array
    {
        $tokens = $this->parseProductReferenceTokens($value);
        $skuTokens = $this->parseProductReferenceTokens($skuValue);
        if ($tokens === [] && $skuTokens === []) {
            return [null, 0, 0];
        }

        $normalizedTokens = [];
        $resolvedCount = 0;
        $unresolvedCount = 0;

        foreach ($tokens as $token) {
            $resolved = $this->resolveProductReferenceToken($token);
            if ($resolved !== null) {
                if ($resolved !== trim($token)) {
                    $resolvedCount++;
                }
                $normalizedTokens[] = $resolved;

                continue;
            }

            $normalizedTokens[] = trim($token);
            $unresolvedCount++;
        }

        foreach ($skuTokens as $token) {
            $resolved = $this->resolveProductReferenceSku($token);
            if ($resolved !== null) {
                if ($resolved !== trim($token)) {
                    $resolvedCount++;
                }
                $normalizedTokens[] = $resolved;

                continue;
            }

            $normalizedTokens[] = trim($token);
            $unresolvedCount++;
        }

        $normalizedTokens = array_values(array_unique(array_filter($normalizedTokens)));

        return [
            $normalizedTokens === [] ? null : implode('; ', $normalizedTokens),
            $resolvedCount,
            $unresolvedCount,
        ];
    }

    private function normalizeHeader(string $header): string
    {
        $lower = strtolower(trim($header));
        $lower = preg_replace('/[^\\x20-\\x7E]/', '', $lower);
        $lower = preg_replace('/[^a-z0-9]+/', ' ', $lower);

        return trim($lower);
    }

    private function normalizeNumeric(string $value): string
    {
        $normalized = str_replace([' ', ','], ['', '.'], $value);
        $normalized = preg_replace('/[^0-9.]/', '', $normalized ?? '');
        if ($normalized === null) {
            return $value;
        }
        $parts = explode('.', $normalized);
        if (count($parts) > 2) {
            $normalized = array_shift($parts).'.'.implode('', $parts);
        }

        return $normalized;
    }

    /**
     * @return array<int, string>
     */
    private function parseProductReferenceTokens(?string $value): array
    {
        $raw = trim((string) ($value ?? ''));
        if ($raw === '') {
            return [];
        }

        if (str_starts_with($raw, '[')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $this->parseProductReferenceTokens(implode('; ', array_map('strval', $decoded)));
            }
        }

        $parts = str_contains($raw, ';')
            ? explode(';', $raw)
            : explode(',', $raw);

        return array_values(array_unique(array_filter(array_map(
            static fn (string $item): string => trim($item),
            $parts
        ), static fn (string $item): bool => $item !== '')));
    }

    private function resolveProductReferenceToken(string $token): ?string
    {
        $trimmed = trim($token);
        if ($trimmed === '') {
            return null;
        }

        if (preg_match('#(?:^|/)products/([a-z0-9][a-z0-9\\-]*)(?:[/?\\#].*)?$#i', $trimmed, $matches)) {
            $trimmed = $matches[1];
        }

        $lookup = $this->productReferenceLookup();
        $normalized = $this->normalizeReferenceToken($trimmed);

        if ($normalized === '') {
            return null;
        }

        return $lookup[$normalized] ?? null;
    }

    private function resolveProductReferenceSku(string $sku): ?string
    {
        $normalized = $this->normalizeReferenceToken($sku);
        if ($normalized === '') {
            return null;
        }

        $csvLookup = $this->csvProductReferenceLookup ?? [];
        if (isset($csvLookup[$normalized])) {
            return $csvLookup[$normalized];
        }

        $lookup = $this->productReferenceLookup();

        return $lookup[$normalized] ?? null;
    }

    /**
     * @return array{0:?array<int, int>,1:int,2:int}
     */
    private function normalizeAssociatedProductField(?string $value, ?string $skuValue = null): array
    {
        $tokens = $this->parseProductReferenceTokens($value);
        $skuTokens = $this->parseProductReferenceTokens($skuValue);
        if ($tokens === [] && $skuTokens === []) {
            return [null, 0, 0];
        }

        $productIds = [];
        $resolvedCount = 0;
        $unresolvedCount = 0;

        foreach ($tokens as $token) {
            $productId = $this->resolveAssociatedProductToken($token);
            if ($productId !== null) {
                $productIds[] = $productId;
                $resolvedCount++;

                continue;
            }

            $unresolvedCount++;
        }

        foreach ($skuTokens as $token) {
            $productId = $this->resolveAssociatedProductSku($token);
            if ($productId !== null) {
                $productIds[] = $productId;
                $resolvedCount++;

                continue;
            }

            $unresolvedCount++;
        }

        $productIds = array_values(array_unique(array_filter(
            array_map('intval', $productIds),
            static fn (int $id): bool => $id > 0
        )));

        return [$productIds === [] ? null : $productIds, $resolvedCount, $unresolvedCount];
    }

    private function resolveAssociatedProductSku(string $sku): ?int
    {
        $reference = $this->resolveProductReferenceSku($sku);

        return $reference === null ? null : $this->resolveAssociatedProductToken($reference);
    }

    private function resolveAssociatedProductToken(string $token): ?int
    {
        $reference = $this->resolveProductReferenceToken($token) ?? trim($token);
        if ($reference === '') {
            return null;
        }

        if (preg_match('#(?:^|/)products/([a-z0-9][a-z0-9\\-]*)(?:[/?\\#].*)?$#i', $reference, $matches)) {
            $reference = $matches[1];
        }

        $normalized = $this->normalizeReferenceToken($reference);
        if ($normalized === '') {
            return null;
        }

        $product = Product::query()
            ->whereRaw('LOWER(TRIM(shopify_id)) = ?', [$normalized])
            ->orWhereRaw('LOWER(TRIM(handle)) = ?', [$normalized])
            ->first(['id']);

        return $product instanceof Product ? (int) $product->id : null;
    }

    /**
     * @return array<string, string>
     */
    private function productReferenceLookup(): array
    {
        if ($this->productReferenceLookup !== null) {
            return $this->productReferenceLookup;
        }

        $lookup = [];

        Product::query()
            ->select(['id', 'shopify_id', 'handle'])
            ->whereNotNull('shopify_id')
            ->where('shopify_id', '!=', '')
            ->chunkById(500, function ($products) use (&$lookup): void {
                foreach ($products as $product) {
                    $shopifyId = trim((string) ($product->shopify_id ?? ''));
                    if ($shopifyId === '') {
                        continue;
                    }

                    foreach ([
                        $shopifyId,
                        trim((string) ($product->handle ?? '')),
                    ] as $token) {
                        $normalized = $this->normalizeReferenceToken($token);
                        if ($normalized !== '' && ! isset($lookup[$normalized])) {
                            $lookup[$normalized] = $shopifyId;
                        }
                    }
                }
            });

        Variant::query()
            ->with(['product:id,shopify_id,handle'])
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->chunkById(500, function ($variants) use (&$lookup): void {
                $resolved = [];
                $duplicates = [];

                foreach ($variants as $variant) {
                    $sku = $this->normalizeReferenceToken((string) ($variant->sku ?? ''));
                    if ($sku === '') {
                        continue;
                    }

                    $reference = trim((string) ($variant->product?->shopify_id ?? ''))
                        ?: trim((string) ($variant->product?->handle ?? ''));
                    if ($reference === '') {
                        continue;
                    }

                    if (isset($resolved[$sku]) && $resolved[$sku] !== $reference) {
                        $duplicates[$sku] = true;
                        continue;
                    }

                    $resolved[$sku] = $reference;
                }

                foreach ($resolved as $sku => $reference) {
                    if (isset($duplicates[$sku]) || isset($lookup[$sku])) {
                        continue;
                    }

                    $lookup[$sku] = $reference;
                }
            });

        NewProductDraft::query()
            ->select(['id', 'sku', 'shopify_id', 'handle'])
            ->whereNotNull('sku')
            ->where('sku', '!=', '')
            ->chunkById(500, function ($drafts) use (&$lookup): void {
                $resolved = [];
                $duplicates = [];

                foreach ($drafts as $draft) {
                    $sku = $this->normalizeReferenceToken((string) ($draft->sku ?? ''));
                    if ($sku === '') {
                        continue;
                    }

                    $reference = trim((string) ($draft->shopify_id ?? ''))
                        ?: trim((string) ($draft->handle ?? ''));
                    if ($reference === '') {
                        continue;
                    }

                    if (isset($resolved[$sku]) && $resolved[$sku] !== $reference) {
                        $duplicates[$sku] = true;
                        continue;
                    }

                    $resolved[$sku] = $reference;
                }

                foreach ($resolved as $sku => $reference) {
                    if (isset($duplicates[$sku]) || isset($lookup[$sku])) {
                        continue;
                    }

                    $lookup[$sku] = $reference;
                }
            });

        $this->productReferenceLookup = $lookup;

        return $lookup;
    }

    /**
     * @param  array<string, string>  $draftMap
     * @return array<string, string>
     */
    private function csvProductReferenceLookup(Reader $csv, array $draftMap): array
    {
        $lookup = [];
        $duplicates = [];

        foreach ($csv->getRecords() as $row) {
            $data = [];
            foreach ($row as $header => $value) {
                $field = $draftMap[$this->normalizeHeader((string) $header)] ?? null;
                if (! in_array($field, ['sku', 'shopify_id', 'handle'], true)) {
                    continue;
                }

                $value = trim((string) $value);
                if ($value === '') {
                    continue;
                }

                $data[$field] = $value;
            }

            $sku = $this->normalizeReferenceToken((string) ($data['sku'] ?? ''));
            if ($sku === '') {
                continue;
            }

            $reference = trim((string) ($data['shopify_id'] ?? ''))
                ?: trim((string) ($data['handle'] ?? ''));
            if ($reference === '') {
                continue;
            }

            if (isset($lookup[$sku]) && $lookup[$sku] !== $reference) {
                $duplicates[$sku] = true;
                continue;
            }

            $lookup[$sku] = $reference;
        }

        foreach (array_keys($duplicates) as $sku) {
            unset($lookup[$sku]);
        }

        return $lookup;
    }

    private function normalizeReferenceToken(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = str_replace(['_', ' '], '-', $normalized);
        $normalized = preg_replace('/[^a-z0-9-:\/]+/', '', $normalized) ?? '';
        $normalized = preg_replace('/-+/', '-', $normalized) ?? '';

        return trim($normalized, '-');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function failsProductReferenceRules(array $data): bool
    {
        foreach (['complementary_products'] as $field) {
            if (! empty($this->invalidProductReferenceStatuses($data[$field] ?? null))) {
                return true;
            }
        }

        if (! $this->complementaryMinimumEnabled()) {
            return false;
        }

        return count($this->parseProductReferenceTokens($data['complementary_products'] ?? null)) < $this->complementaryMinimumCount();
    }

    /**
     * @return array<int, string>
     */
    private function invalidProductReferenceStatuses(?string $value): array
    {
        $selected = $this->parseProductReferenceTokens($value);
        if ($selected === []) {
            return [];
        }

        $products = Product::query()
            ->whereIn('shopify_id', $selected)
            ->get(['shopify_id', 'title', 'handle', 'status'])
            ->keyBy(fn (Product $product): string => trim((string) ($product->shopify_id ?? '')));

        $invalid = [];
        foreach ($selected as $shopifyId) {
            $product = $products->get($shopifyId);
            if (! $product instanceof Product) {
                continue;
            }

            $status = strtolower(trim((string) ($product->status ?? '')));
            if (in_array($status, ['active', 'draft'], true)) {
                continue;
            }

            $invalid[] = trim((string) ($product->handle ?: $product->title ?: $shopifyId));
        }

        return array_values(array_unique($invalid));
    }

    private function complementaryMinimumEnabled(): bool
    {
        return Setting::getBool('new_product_drafts.complementary_minimum.enabled', false);
    }

    private function complementaryMinimumCount(): int
    {
        return max(1, (int) Setting::getValue('new_product_drafts.complementary_minimum.count', 3));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $payload
     */
    private function syncImportedDraftToProduct(NewProductDraft $draft, array $data, array $payload): void
    {
        $attributes = array_keys($data);

        if (! empty($payload)) {
            $attributes[] = 'payload';
        }

        $attributes = array_values(array_unique(array_filter($attributes, 'is_string')));

        if ($attributes === []) {
            return;
        }

        app(NewProductDraftProductSync::class)->syncToExistingProduct(
            $draft,
            ensureApprovalReset: true,
            attributes: $attributes
        );

        $attributeLookup = array_flip($attributes);
        $authoritativeAttributes = array_intersect_key($draft->getAttributes(), $attributeLookup);
        unset($authoritativeAttributes['payload']);

        if ($authoritativeAttributes !== []) {
            NewProductDraft::query()
                ->whereKey($draft->getKey())
                ->update($authoritativeAttributes);
            $draft->refresh();
        }
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function applyDefaultExtraShopifyPayload(array $payload): array
    {
        return $payload + [
            HeaderStore::JEWELRY_TYPE => 'handcrafted-jewellery',
            HeaderStore::TARGET_GENDER => 'Unisex',
            HeaderStore::AGE_GROUP => 'Universal',
            HeaderStore::GOOGLE_SHOPPING_AGE_GROUP => 'adult',
        ];
    }
}
