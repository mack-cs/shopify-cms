<?php

namespace App\Services;

use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyCollection;
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
                    $data = $this->draftDataFromProduct($product, $userId);

                    $draft = NewProductDraft::query()
                        ->where('handle', $product->handle)
                        ->first();

                    if (!$draft) {
                        NewProductDraft::create($data);
                        $created++;
                        continue;
                    }

                    $changes = $this->reconcileDraftWithImportedData($draft, $data);

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

    public function upsertFromProduct(Product $product, ?int $userId = null): NewProductDraft
    {
        $data = $this->draftDataFromProduct($product, $userId);

        $draft = NewProductDraft::query()
            ->where('handle', $product->handle)
            ->first();

        if (!$draft && filled(trim((string) ($product->shopify_id ?? '')))) {
            $draft = NewProductDraft::query()
                ->where('shopify_id', $product->shopify_id)
                ->first();
        }

        if (!$draft) {
            return NewProductDraft::create($data);
        }

        $changes = $this->reconcileDraftWithImportedData($draft, $data);

        if (!empty($changes)) {
            $draft->fill($changes)->save();
        }

        return $draft->fresh() ?? $draft;
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
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function reconcileDraftWithImportedData(NewProductDraft $draft, array $data): array
    {
        $changes = [];
        $warnings = [];
        $supportsWarnings = NewProductDraft::supportsShopifySyncWarningsColumn();

        $identityFields = ['handle', 'shopify_id'];
        $skipFields = ['created_by'];
        $nonWarningFields = ['origin', 'payload', 'image_url', 'image_path', 'batch'];

        foreach ($data as $key => $incomingValue) {
            if (in_array($key, $skipFields, true)) {
                continue;
            }

            $currentValue = $draft->getAttribute($key);

            if (in_array($key, $identityFields, true)) {
                if (!$this->valuesMatch($key, $currentValue, $incomingValue)) {
                    $changes[$key] = $incomingValue;
                }
                continue;
            }

            if ($this->isEmptyValue($incomingValue)) {
                continue;
            }

            if ($this->isEmptyValue($currentValue)) {
                $changes[$key] = $incomingValue;
                continue;
            }

            if (in_array($key, $nonWarningFields, true)) {
                continue;
            }

            if ($supportsWarnings && !$this->valuesMatch($key, $currentValue, $incomingValue)) {
                $warnings[] = $this->warningPayload($key, $currentValue, $incomingValue);
            }
        }

        $existingWarnings = $supportsWarnings ? $draft->shopifySyncWarnings() : [];
        if ($supportsWarnings && $existingWarnings !== $warnings) {
            $changes['shopify_sync_warnings'] = empty($warnings) ? null : $warnings;
        }

        return $changes;
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

    /**
     * @return array<string, mixed>
     */
    private function draftDataFromProduct(Product $product, ?int $userId = null): array
    {
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
            'shopify_id' => $product->shopify_id,
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
            'origin' => NewProductDraft::ORIGIN_SHOPIFY_SEED,
            'created_by' => $userId,
            'payload' => $this->extraPayloadFromRow($product, $row),
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
        $data['product_design'] = $this->designValueFromRowOrMetafield($product, $row);
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
        $data['siblings_collection_name'] = trim((string) ($product->title ?? '')) !== ''
            ? trim((string) $product->title)
            : $this->valueFromRowOrMetafield($product, $row, HeaderStore::SIBLINGS_COLLECTION_NAME, [
                ['stiletto', 'sibling_option_name'],
            ]);
        $data['sibling_collection'] = $this->resolvedSiblingCollectionValue($product, $row);
        if ($data['uvp_short_paragraph'] === null || trim((string) $data['uvp_short_paragraph']) === '') {
            $data['uvp_short_paragraph'] = $this->valueFromRowOrMetafield($product, $row, HeaderStore::UVP_SHORT_PARAGRAPH, [
                ['custom', 'uvp_short_paragraph'],
            ]);
        }
        $data['complementary_products'] = $this->valueFromRowOrMetafield($product, $row, HeaderStore::COMPLEMENTARY_PRODUCTS, [
            ['shopify--discovery--product_recommendation', 'complementary_products'],
        ]);

        return $data;
    }

    private function resolvedSiblingCollectionValue(Product $product, ?ShopifyRow $row): ?string
    {
        $value = $this->valueFromRowOrMetafield($product, $row, HeaderStore::SIBLING_COLLECTION, [
            ['stiletto', 'sibling_collection'],
        ]);

        $trimmed = is_string($value) ? trim($value) : '';
        if ($trimmed === '') {
            return null;
        }

        if (!str_starts_with($trimmed, 'gid://shopify/Collection/')) {
            return $trimmed;
        }

        $collection = ShopifyCollection::query()
            ->where('shopify_id', $trimmed)
            ->first(['title', 'handle']);

        $title = trim((string) ($collection?->title ?? ''));
        if ($title !== '') {
            return $title;
        }

        $handle = trim((string) ($collection?->handle ?? ''));
        return $handle !== '' ? $handle : $trimmed;
    }

    private function designValueFromRowOrMetafield(Product $product, ?ShopifyRow $row): ?string
    {
        $resolvedHeader = HeaderStore::designHeaderForTypeAndTags($product->type, $product->tags);
        $headers = $resolvedHeader !== null
            ? array_values(array_unique(array_merge([$resolvedHeader], HeaderStore::designHeaders())))
            : HeaderStore::designHeaders();

        foreach ($headers as $header) {
            $value = $this->valueFromRowOrMetafield(
                $product,
                $row,
                $header,
                $this->designMetafieldLookups($header)
            );

            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{0:string,1:string}>
     */
    private function designMetafieldLookups(string $header): array
    {
        return match ($header) {
            HeaderStore::BRACELET_DESIGN => [['shopify', 'bracelet-design']],
            HeaderStore::NECKLACE_DESIGN => [['shopify', 'necklace-design']],
            HeaderStore::EARRING_DESIGN => [['shopify', 'earring-design']],
            default => [],
        };
    }

    /**
     * @return array<string, string>|null
     */
    private function extraPayloadFromRow(Product $product, ?ShopifyRow $row): ?array
    {
        if (!$row) {
            return null;
        }

        $payload = [];
        foreach (HeaderStore::extraProductHeadersForDraftWorkflow($product->import?->headers ?? []) as $header) {
            $value = $row->get($header, null);
            if (!is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed === '') {
                continue;
            }

            $payload[$header] = $trimmed;
        }

        return $payload === [] ? null : $payload;
    }

    /**
     * @return array{field:string,label:string,draft_value:string,shopify_value:string}
     */
    private function warningPayload(string $field, mixed $draftValue, mixed $shopifyValue): array
    {
        return [
            'field' => $field,
            'label' => $this->warningLabel($field),
            'draft_value' => $this->stringifyValue($draftValue),
            'shopify_value' => $this->stringifyValue($shopifyValue),
        ];
    }

    private function warningLabel(string $field): string
    {
        return match ($field) {
            'body_html' => 'Description',
            'product_category' => 'Product category',
            'google_product_category' => 'Google product category',
            'color_string' => 'Colors',
            'variant_price' => 'Price',
            'variant_compare_at_price' => 'Compare-at price',
            'variant_inventory_qty' => 'Inventory',
            'material_cost' => 'Material cost',
            'jewelry_material' => 'Jewelry material',
            'product_materials' => 'Product materials',
            'materials_and_dimensions' => 'Materials and dimensions',
            'product_design' => 'Product design',
            'metal' => 'Metal',
            'colour_style' => 'Color Style',
            'siblings_collection_name' => 'Siblings collection name',
            'uvp_short_paragraph' => 'UVP short paragraph',
            'complementary_products' => 'Complementary products',
            default => ucwords(str_replace('_', ' ', $field)),
        };
    }

    private function valuesMatch(string $field, mixed $left, mixed $right): bool
    {
        if (is_array($left) || is_array($right)) {
            return $this->normalizeArrayValue($left) === $this->normalizeArrayValue($right);
        }

        return $this->normalizeComparableValue($field, $left) === $this->normalizeComparableValue($field, $right);
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function normalizeArrayValue(mixed $value): ?array
    {
        if (!is_array($value)) {
            return null;
        }

        ksort($value);

        return array_map(function (mixed $item): mixed {
            if (is_array($item)) {
                return $this->normalizeArrayValue($item);
            }

            return is_string($item) ? trim($item) : $item;
        }, $value);
    }

    private function stringifyValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_array($value)) {
            $normalized = $this->normalizeArrayValue($value);
            return $normalized === null ? '' : (json_encode($normalized) ?: '');
        }

        return trim((string) $value);
    }

    private function normalizeComparableValue(string $field, mixed $value): string
    {
        $string = $this->stringifyValue($value);

        return match ($field) {
            'uvp_short_paragraph' => $this->normalizeRichTextForComparison($string),
            default => $string,
        };
    }

    private function normalizeRichTextForComparison(string $value): string
    {
        $text = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace(['/', '\\'], ' ', $text);
        $text = preg_replace('/[[:punct:]]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', strtolower(trim($text))) ?? strtolower(trim($text));

        return $text;
    }
}
