<?php

namespace App\Services;

use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyMetafield;
use App\Models\ShopifyRow;
use App\Services\HeaderStore;

final class NewProductDraftSeeder
{
    /**
     * @return array{created:int, updated:int, skipped:int}
     */
    public function seedMissingFromProducts(int $importId, ?int $userId = null): array
    {
        $created = 0;
        $updated = 0;
        $skipped = 0;

        Product::query()
            ->where('import_id', $importId)
            ->whereNotNull('handle')
            ->where('handle', '!=', '')
            ->orderBy('id')
            ->chunkById(200, function ($products) use (&$created, &$updated, &$skipped, $userId): void {
                foreach ($products as $product) {
                    $imageUrl = $product->images()
                        ->orderBy('position')
                        ->value('src');

                    $row = ShopifyRow::query()
                        ->where('import_id', $product->import_id)
                        ->where('handle', $product->handle)
                        ->where('row_type', 'product_primary')
                        ->first();

                    $data = [
                        'handle' => $product->handle,
                        'title' => $product->title,
                        'body_html' => $product->body_html,
                        'vendor' => $product->vendor,
                        'tags' => $product->tags,
                        'type' => $product->type,
                        'published' => $product->published,
                        'product_category' => $product->product_category,
                        'google_product_category' => $product->google_product_category,
                        'status' => $product->status,
                        'color_string' => $product->color_string,
                        'uvp_short_paragraph' => $product->uvp_short_paragraph,
                        'batch' => $product->batch,
                        'image_url' => $imageUrl,
                        'created_by' => $userId,
                    ];

                    if ($row) {
                        $data['material_cost'] = $row->get(HeaderStore::MATERIAL_COST, null);
                    }
                    $data['jewelry_material'] = $this->valueFromRowOrMetafield($product, $row, HeaderStore::JEWELRY_MATERIAL, [
                            ['shopify', 'jewelry-material'],
                        ]);
                    $data['product_materials'] = $this->valueFromRowOrMetafield($product, $row, HeaderStore::PRODUCT_MATERIALS, [
                            ['custom', 'product_materials'],
                        ]);
                    $data['materials_and_dimensions'] = $this->valueFromRowOrMetafield($product, $row, HeaderStore::MATERIALS_AND_DIMENSIONS, [
                            ['custom', 'materials_and_dimensions'],
                        ]);
                    $data['product_design'] = $this->valueFromRowOrMetafield($product, $row, HeaderStore::BRACELET_DESIGN, [
                            ['shopify', 'bracelet-design'],
                        ]);
                    $data['metal'] = $this->valueFromRowOrMetafield($product, $row, HeaderStore::PRODUCT_METALS, [
                            ['custom', 'product_metals'],
                        ]);
                    $data['colour_style'] = $this->valueFromRowOrMetafield($product, $row, HeaderStore::PATTERN_CATEGORY, [
                            ['custom', 'pattern_category'],
                        ]);
                    $data['size'] = $this->valueFromRowOrMetafield($product, $row, HeaderStore::SIZE, [
                            ['custom', 'size'],
                        ]);
                    $data['siblings'] = $this->valueFromRowOrMetafield($product, $row, HeaderStore::SIBLINGS, [
                            ['shopify--discovery--product_recommendation', 'related_products'],
                        ]);
                    $data['siblings_collection_name'] = $this->valueFromRowOrMetafield($product, $row, HeaderStore::SIBLINGS_COLLECTION_NAME, [
                            ['stiletto', 'sibling_option_name'],
                        ]);
                    if ($data['uvp_short_paragraph'] === null || trim((string) $data['uvp_short_paragraph']) === '') {
                        $data['uvp_short_paragraph'] = $this->valueFromRowOrMetafield($product, $row, HeaderStore::UVP_SHORT_PARAGRAPH, [
                            ['custom', 'uvp_short_paragraph'],
                        ]);
                    }
                    $data['complementary_products'] = $this->valueFromRowOrMetafield($product, $row, HeaderStore::COMPLEMENTARY_PRODUCTS, [
                        ['shopify--discovery--product_recommendation', 'complementary_products'],
                    ]);

                    $draft = NewProductDraft::query()
                        ->where('handle', $product->handle)
                        ->first();

                    if (!$draft) {
                        NewProductDraft::create($data);
                        $created++;
                        continue;
                    }

                    $changes = [];
                    foreach ($data as $key => $incomingValue) {
                        if ($key === 'handle' || $key === 'created_by') {
                            continue;
                        }
                        if ($this->isEmptyValue($incomingValue)) {
                            continue;
                        }

                        $currentValue = $draft->getAttribute($key);

                        // SKU should always mirror current product variant SKU.
                        if ($key === 'sku') {
                            if ((string) $currentValue !== (string) $incomingValue) {
                                $changes[$key] = $incomingValue;
                            }
                            continue;
                        }

                        // Backfill only when draft field is still empty.
                        if ($this->isEmptyValue($currentValue)) {
                            $changes[$key] = $incomingValue;
                        }
                    }

                    if (!empty($changes)) {
                        $draft->fill($changes)->save();
                        $updated++;
                    } else {
                        $skipped++;
                    }
                }
            });

        return [
            'created' => $created,
            'updated' => $updated,
            'skipped' => $skipped,
        ];
    }

    private function isEmptyValue(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        if (is_string($value)) {
            return trim($value) === '';
        }

        if (is_array($value)) {
            return empty($value);
        }

        return false;
    }

    /**
     * @param array<int, array{0:string,1:string}> $metafieldLookups
     */
    private function valueFromRowOrMetafield(
        Product $product,
        ?ShopifyRow $row,
        string $header,
        array $metafieldLookups = []
    ): ?string {
        $rowValue = $row?->get($header, null);
        if (is_string($rowValue) && trim($rowValue) !== '') {
            return trim($rowValue);
        }

        foreach ($metafieldLookups as [$namespace, $key]) {
            $value = ShopifyMetafield::query()
                ->where('import_id', $product->import_id)
                ->where('handle', $product->handle)
                ->where('namespace', $namespace)
                ->where('key', $key)
                ->value('value');

            $trimmed = is_string($value) ? trim($value) : '';
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }
}
