<?php

namespace App\Services;

use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\StyleProfile;
use App\Models\Variant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use League\Csv\Reader;

final class NewProductDraftCsvImporter
{
    /**
     * @return array{
     *   total:int,
     *   created:int,
     *   updated:int,
     *   seo_drafts_upserted:int,
     *   skipped_missing_handle:int,
     *   skipped_duplicate_sku:int
     * }
     */
    public function importFromPath(string $absolutePath): array
    {
        $csv = Reader::createFromPath($absolutePath);
        $csv->setHeaderOffset(0);

        $headerMap = [];
        foreach ($csv->getHeader() as $header) {
            $headerMap[$this->normalizeHeader($header)] = $header;
        }

        $draftMap = [
            'handle' => 'handle',
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
            'colour style' => 'colour_style',
            'colour style solid multicolor' => 'colour_style',
            'size' => 'size',
            'siblings' => 'siblings',
            'siblings add product siblings here' => 'siblings',
            'siblings collection name' => 'siblings_collection_name',
            'uvp short paragraph' => 'uvp_short_paragraph',
            'complementary products' => 'complementary_products',
            'complementary products finish the set and get one free' => 'complementary_products',
        ];

        $seoDraftMap = [
            'style' => 'style_type',
            'materials' => 'materials',
            'components' => 'components',
            'colour prompt' => 'colour_prompt',
            'color prompt' => 'colour_prompt',
            'seo title' => 'draft_seo_title',
            'seo title 60 chars' => 'draft_seo_title',
            'seo title 70 chars' => 'draft_seo_title',
            'seo description 160 chars' => 'draft_seo_description',
            'seo description' => 'draft_seo_description',
            'image alt text 125 chars' => 'draft_image_alt_text',
            'image alt text' => 'draft_image_alt_text',
        ];

        $total = 0;
        $created = 0;
        $updated = 0;
        $seoDraftsUpserted = 0;
        $skippedMissingHandle = 0;
        $skippedDuplicateSku = 0;

        DB::transaction(function () use (
            $csv,
            $headerMap,
            $draftMap,
            $seoDraftMap,
            &$total,
            &$created,
            &$updated,
            &$seoDraftsUpserted,
            &$skippedMissingHandle,
            &$skippedDuplicateSku
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

                $handle = $data['handle'] ?? null;
                if (!$handle) {
                    $skippedMissingHandle++;
                    continue;
                }
                $sku = $data['sku'] ?? null;

                $draft = NewProductDraft::where('handle', $handle)->first();

                if ($sku) {
                    $draftQuery = NewProductDraft::query()->where('sku', $sku);
                    $draftQuery->where('handle', '!=', $handle);
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
                    $updated++;
                } else {
                    $data['payload'] = $payload ?: null;
                    $data['created_by'] = Auth::id();
                    $data['title'] = $data['title'] ?? $handle;
                    $data['variant_inventory_policy'] = $data['variant_inventory_policy'] ?? 'deny';
                    $data['variant_fulfillment_service'] = $data['variant_fulfillment_service'] ?? 'manual';
                    $data['batch'] = $data['batch'] ?? ('batch' . now()->format('Ymd'));

                    NewProductDraft::create($data);
                    $created++;
                }

                if (!empty($seoDraftData)) {
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

                    $seoDraftsUpserted++;
                }
            }
        });

        return [
            'total' => $total,
            'created' => $created,
            'updated' => $updated,
            'seo_drafts_upserted' => $seoDraftsUpserted,
            'skipped_missing_handle' => $skippedMissingHandle,
            'skipped_duplicate_sku' => $skippedDuplicateSku,
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
}
