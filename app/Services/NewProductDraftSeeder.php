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
    /** @var array<string, int>|null */
    private ?array $productReferenceMap = null;

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

                    $draft = $this->resolveExistingDraftForProduct($product);

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

        $draft = $this->resolveExistingDraftForProduct($product);

        if (!$draft) {
            return NewProductDraft::create($data);
        }

        $changes = $this->reconcileDraftWithImportedData($draft, $data);

        if (!empty($changes)) {
            $draft->fill($changes)->save();
        }

        return $draft->fresh() ?? $draft;
    }

    private function resolveExistingDraftForProduct(Product $product): ?NewProductDraft
    {
        $shopifyId = trim((string) ($product->shopify_id ?? ''));
        if ($shopifyId !== '') {
            $draft = NewProductDraft::query()
                ->where('shopify_id', $shopifyId)
                ->first();

            if ($draft instanceof NewProductDraft) {
                return $draft;
            }
        }

        $handle = trim((string) ($product->handle ?? ''));
        if ($handle === '') {
            return null;
        }

        $draft = NewProductDraft::query()
            ->where('handle', $handle)
            ->first();

        return $draft instanceof NewProductDraft ? $draft : null;
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

            if ($key === 'status') {
                $normalizedIncomingStatus = strtolower(trim((string) $incomingValue));

                if ($normalizedIncomingStatus !== '' && $normalizedIncomingStatus !== 'draft') {
                    if (!$this->valuesMatch($key, $currentValue, $incomingValue)) {
                        $changes[$key] = $incomingValue;
                    }

                    continue;
                }
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
        $data['complementary_products'] = $this->complementaryValueFromMetafieldOrRow($product, $row);

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

    private function complementaryValueFromMetafieldOrRow(Product $product, ?ShopifyRow $row): ?string
    {
        $metafieldValue = ShopifyMetafield::query()
            ->where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->where('namespace', 'shopify--discovery--product_recommendation')
            ->where('key', 'complementary_products')
            ->value('value');

        $metafieldTrimmed = is_string($metafieldValue) ? trim($metafieldValue) : '';
        if ($metafieldTrimmed !== '') {
            return $metafieldTrimmed;
        }

        $rowValue = $row?->get(HeaderStore::COMPLEMENTARY_PRODUCTS, null);
        $rowTrimmed = is_string($rowValue) ? trim($rowValue) : '';

        return $rowTrimmed !== '' ? $rowTrimmed : null;
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
            'draft_value' => $this->stringifyDisplayValue($field, $draftValue),
            'shopify_value' => $this->stringifyDisplayValue($field, $shopifyValue),
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
        if ($field === 'complementary_products') {
            return $this->complementaryProductsMatch($left, $right);
        }

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
            'product_category' => strtolower($this->normalizeCategoryDisplayValue($string)),
            'sibling_collection' => strtolower($this->normalizeSiblingCollectionDisplayValue($string)),
            default => $string,
        };
    }

    private function stringifyDisplayValue(string $field, mixed $value): string
    {
        $string = $this->stringifyValue($value);

        return match ($field) {
            'product_category' => $this->normalizeCategoryDisplayValue($string),
            'sibling_collection' => $this->normalizeSiblingCollectionDisplayValue($string),
            'complementary_products' => $this->normalizeComplementaryProductsDisplayValue($value),
            default => $string,
        };
    }

    private function normalizeComplementaryProductsDisplayValue(mixed $value): string
    {
        $tokens = $this->parseComplementaryReferenceTokens($value);
        if ($tokens === []) {
            return '';
        }

        $productIds = [];
        foreach ($tokens as $token) {
            $productId = $this->resolveProductIdFromReferenceToken($token);
            if ($productId !== null) {
                $productIds[] = $productId;
            }
        }

        $titlesById = Product::query()
            ->whereKey(array_values(array_unique($productIds)))
            ->pluck('title', 'id')
            ->all();

        $display = [];
        foreach ($tokens as $token) {
            $productId = $this->resolveProductIdFromReferenceToken($token);
            if ($productId !== null) {
                $title = trim((string) ($titlesById[$productId] ?? ''));
                if ($title !== '') {
                    $display[] = $title;
                    continue;
                }
            }

            $normalized = trim((string) $token);
            if ($normalized !== '') {
                $display[] = $normalized;
            }
        }

        return implode('; ', array_values(array_unique($display)));
    }

    private function normalizeCategoryDisplayValue(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }

        return trim((string) (CategoryTypeMap::categoryLabelForValue($trimmed) ?? $trimmed));
    }

    private function normalizeSiblingCollectionDisplayValue(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
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

    private function normalizeRichTextForComparison(string $value): string
    {
        $html = str_replace(["\xc2\xa0", '&nbsp;'], ' ', $value);
        $html = preg_replace('/<\s*br\s*\/?>/iu', ' ', $html) ?? $html;
        $html = preg_replace('/<\/p>\s*<p[^>]*>/iu', ' ', $html) ?? $html;
        $html = preg_replace('/<[^>]+>/u', ' ', $html) ?? $html;

        $text = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}]/u', ' ', $text) ?? $text;
        $text = str_replace(['/', '\\'], ' ', $text);
        $text = preg_replace('/[[:punct:]]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/u', ' ', strtolower(trim($text))) ?? strtolower(trim($text));

        return $text;
    }

    private function complementaryProductsMatch(mixed $draftValue, mixed $shopifyValue): bool
    {
        $draftRefs = $this->normalizedComplementaryReferenceSet($draftValue);
        $shopifyRefs = array_slice(
            $this->normalizedComplementaryReferenceSet($shopifyValue),
            0,
            3
        );

        if ($this->hasInactiveComplementaryProducts(array_merge($draftRefs, $shopifyRefs))) {
            return false;
        }

        if ($shopifyRefs === []) {
            return $draftRefs === [];
        }

        foreach ($shopifyRefs as $reference) {
            if (!in_array($reference, $draftRefs, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<int, string> $references
     */
    private function hasInactiveComplementaryProducts(array $references): bool
    {
        $ids = [];

        foreach ($references as $reference) {
            if (!str_starts_with($reference, 'id:')) {
                continue;
            }

            $id = (int) substr($reference, 3);
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        $ids = array_values(array_unique($ids));
        if ($ids === []) {
            return false;
        }

        $statuses = Product::query()
            ->whereKey($ids)
            ->pluck('status', 'id')
            ->all();

        foreach ($ids as $id) {
            $status = strtolower(trim((string) ($statuses[$id] ?? '')));
            if ($status !== 'active') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<int, string>
     */
    private function normalizedComplementaryReferenceSet(mixed $value): array
    {
        $tokens = $this->parseComplementaryReferenceTokens($value);
        $resolved = [];

        foreach ($tokens as $token) {
            $productId = $this->resolveProductIdFromReferenceToken($token);
            if ($productId !== null) {
                $resolved[] = 'id:' . $productId;
                continue;
            }

            $normalized = $this->normalizeProductReferenceToken($token);
            if ($normalized !== '') {
                $resolved[] = 'raw:' . $normalized;
            }
        }

        return array_values(array_unique($resolved));
    }

    /**
     * @return array<int, string>
     */
    private function parseComplementaryReferenceTokens(mixed $value): array
    {
        $raw = trim($this->stringifyValue($value));
        if ($raw === '') {
            return [];
        }

        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            return $this->parseComplementaryReferenceTokens(implode('; ', array_map('strval', $decoded)));
        }

        $parts = preg_split('/[,\n\r;]+/', $raw) ?: [];

        return array_values(array_unique(array_filter(array_map(
            static fn (mixed $item): string => trim((string) $item),
            $parts
        ), static fn (string $item): bool => $item !== '')));
    }

    private function resolveProductIdFromReferenceToken(string $token): ?int
    {
        $normalized = $this->normalizeProductReferenceToken($token);
        if ($normalized === '') {
            return null;
        }

        return $this->productReferenceMap()[$normalized] ?? null;
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
            ->chunkById(500, function ($products) use (&$map): void {
                foreach ($products as $product) {
                    foreach ([
                        trim((string) ($product->shopify_id ?? '')),
                        trim((string) ($product->handle ?? '')),
                        trim((string) ($product->title ?? '')),
                    ] as $value) {
                        $normalized = $this->normalizeProductReferenceToken($value);
                        if ($normalized !== '' && !isset($map[$normalized])) {
                            $map[$normalized] = (int) $product->id;
                        }
                    }
                }
            });

        return $this->productReferenceMap = $map;
    }

    private function normalizeProductReferenceToken(?string $value): string
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
}
