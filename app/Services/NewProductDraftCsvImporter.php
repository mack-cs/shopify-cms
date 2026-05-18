<?php

namespace App\Services;

use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StyleProfile;
use App\Models\Variant;
use App\Services\NewProductDraftProductSync;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

final class NewProductDraftCsvImporter
{
    /** @var array<string, string>|null */
    private ?array $productReferenceLookup = null;

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
     *   unresolved_product_references:int
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
            'shopify id' => 'shopify_id',
            'product shopify id' => 'shopify_id',
            'sku' => 'sku',
            'title' => 'title',
            'description' => 'body_html',
            'description html' => 'body_html',
            'body html' => 'body_html',
            'vendor' => 'vendor',
            'tags' => 'tags',
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
            'variant inventory policy' => 'variant_inventory_policy',
            'variant fulfillment service' => 'variant_fulfillment_service',
            'material cost' => 'material_cost',
            'material cost use 19 00 not 19 00' => 'material_cost',
            'jewelry material' => 'jewelry_material',
            'product materials' => 'product_materials',
            'propduct materials' => 'product_materials',
            'propduct materials new metafield' => 'product_materials',
            'product materials new metafield' => 'product_materials',
            'materials and dimensions' => 'materials_and_dimensions',
            'product design' => 'product_design',
            'product design beaded' => 'product_design',
            'metal' => 'metal',
            'pattern category' => 'colour_style',
            'colour style' => 'colour_style',
            'colour style solid multicolor' => 'colour_style',
            'size' => 'size',
            'siblings' => 'siblings',
            'siblings add product siblings here' => 'siblings',
            'siblings handles' => 'siblings',
            'siblings product handles' => 'siblings',
            'siblings collection name' => 'siblings_collection_name',
            'sibling collection' => 'sibling_collection',
            'uvp short paragraph' => 'uvp_short_paragraph',
            'complementary products' => 'complementary_products',
            'complementary products finish the set and get one free' => 'complementary_products',
            'complementary products handles' => 'complementary_products',
            'complementary product handles' => 'complementary_products',
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
            &$unresolvedProductReferences
        ): void {
            foreach ($csv->getRecords() as $row) {
                $total++;

                $data = [];
                $seoDraftData = [];
                $payload = [];

                foreach ($row as $header => $value) {
                    $normalized = $this->normalizeHeader((string) $header);
                    $value = trim((string) $value);
                    if ($value === '') {
                        continue;
                    }

                    $field = $draftMap[$normalized] ?? null;
                    if ($field) {
                        if ($field === 'material_cost') {
                            $value = $this->normalizeNumeric($value);
                        }
                        $data[$field] = $value;
                    } elseif (isset($seoDraftMap[$normalized])) {
                        $seoDraftData[$seoDraftMap[$normalized]] = $value;
                    } else {
                        $payload[$header] = $value;
                    }
                }

                $draftId = isset($data['draft_id']) ? (int) $data['draft_id'] : null;
                unset($data['draft_id']);

                $handle = $data['handle'] ?? null;
                $shopifyId = $data['shopify_id'] ?? null;

                $draft = $this->findDraftForImport($draftId, $handle, $shopifyId);

                if ($draft && empty($handle)) {
                    $handle = trim((string) ($draft->handle ?? '')) ?: null;
                }

                if (!$handle && !$draft) {
                    $skippedMissingHandle++;
                    continue;
                }

                if ($draft instanceof NewProductDraft && $draft->isPendingApproval()) {
                    $skippedPendingApproval++;
                    $pendingApprovalHandles[] = trim((string) ($draft->handle ?: $draft->title ?: $draft->shopify_id ?: 'Draft #' . $draft->id));
                    continue;
                }

                if (array_key_exists('siblings', $data)) {
                    [$data['siblings'], $resolvedCount, $unresolvedCount] = $this->normalizeProductReferenceField($data['siblings']);
                    $resolvedProductReferences += $resolvedCount;
                    $unresolvedProductReferences += $unresolvedCount;
                }

                if (array_key_exists('complementary_products', $data)) {
                    [$data['complementary_products'], $resolvedCount, $unresolvedCount] = $this->normalizeProductReferenceField($data['complementary_products']);
                    $resolvedProductReferences += $resolvedCount;
                    $unresolvedProductReferences += $unresolvedCount;
                }

                if ($this->failsProductReferenceRules($data)) {
                    $skippedReferenceValidation++;
                    continue;
                }

                $sku = $data['sku'] ?? null;

                if ($sku) {
                    $draftQuery = NewProductDraft::query()->where('sku', $sku);
                    if ($draft) {
                        $draftQuery->whereKeyNot($draft->getKey());
                    } elseif ($handle) {
                        $draftQuery->where('handle', '!=', $handle);
                    }
                    if ($draftQuery->exists() || Variant::where('sku', $sku)->exists()) {
                        $skippedDuplicateSku++;
                        continue;
                    }
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
                        $draft->batch = $draft->batch ?? ('batch' . now()->format('Ymd'));
                    }
                    $draft->payload = $mergedPayload;
                    $draft->save();
                    $draft->touch();
                    $this->syncImportedDraftToProduct($draft, $data, $payload);
                    $updated++;
                } else {
                    $data['payload'] = $payload ?: null;
                    $data['created_by'] = Auth::id();
                    $data['title'] = $data['title'] ?? $handle;
                    $data['variant_inventory_policy'] = $data['variant_inventory_policy'] ?? 'deny';
                    $data['variant_fulfillment_service'] = $data['variant_fulfillment_service'] ?? 'manual';
                    $data['batch'] = $data['batch'] ?? ('batch' . now()->format('Ymd'));
                    $data['origin'] = $data['origin'] ?? NewProductDraft::ORIGIN_DRAFT_TOOL;

                    $draft = NewProductDraft::create($data);
                    $this->syncImportedDraftToProduct($draft, $data, $payload);
                    $created++;
                }

                if (!empty($seoDraftData) && $handle) {
                    $product = Product::query()
                        ->where('handle', $handle)
                        ->with('variants')
                        ->first();

                    $styleProfile = StyleProfile::where('handle', $handle)->first();
                    $styleProfileData = array_merge(
                        $seoDraftData,
                        [
                            'handle' => $handle,
                            'product_id' => $product?->id,
                            'sku' => trim((string) (
                                $styleProfile?->sku
                                ?? $data['sku']
                                ?? $product?->variants->first()?->sku
                                ?? $handle
                            )),
                        ]
                    );

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
        ];
    }

    private function findDraftForImport(?int $draftId, ?string $handle, ?string $shopifyId): ?NewProductDraft
    {
        if ($draftId !== null && $draftId > 0) {
            $draft = NewProductDraft::query()->find($draftId);
            if ($draft) {
                return $draft;
            }
        }

        $trimmedHandle = trim((string) ($handle ?? ''));
        if ($trimmedHandle !== '') {
            $draft = NewProductDraft::query()
                ->where('handle', $trimmedHandle)
                ->first();
            if ($draft) {
                return $draft;
            }
        }

        $trimmedShopifyId = trim((string) ($shopifyId ?? ''));
        if ($trimmedShopifyId === '') {
            return null;
        }

        return NewProductDraft::query()
            ->where('shopify_id', $trimmedShopifyId)
            ->first();
    }

    /**
     * @return array{0:?string,1:int,2:int}
     */
    private function normalizeProductReferenceField(?string $value): array
    {
        $tokens = $this->parseProductReferenceTokens($value);
        if ($tokens === []) {
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
            $normalized = array_shift($parts) . '.' . implode('', $parts);
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
                        if ($normalized !== '' && !isset($lookup[$normalized])) {
                            $lookup[$normalized] = $shopifyId;
                        }
                    }
                }
            });

        $this->productReferenceLookup = $lookup;

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
     * @param array<string, mixed> $data
     */
    private function failsProductReferenceRules(array $data): bool
    {
        foreach (['siblings', 'complementary_products'] as $field) {
            if (!empty($this->invalidProductReferenceStatuses($data[$field] ?? null))) {
                return true;
            }
        }

        if (!$this->complementaryMinimumEnabled()) {
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
            if (!$product instanceof Product) {
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
     * @param array<string, mixed> $data
     * @param array<string, mixed> $payload
     */
    private function syncImportedDraftToProduct(NewProductDraft $draft, array $data, array $payload): void
    {
        $attributes = array_keys($data);

        if (!empty($payload)) {
            $attributes[] = 'payload';
        }

        $attributes = array_values(array_unique(array_filter($attributes, 'is_string')));

        if ($attributes === []) {
            return;
        }

        app(NewProductDraftProductSync::class)->syncToExistingProduct(
            $draft->fresh(),
            ensureApprovalReset: true,
            attributes: $attributes
        );
    }
}
