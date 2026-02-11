<?php

namespace App\Services;

use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyRow;
use App\Models\ShopifyCollection;
use App\Models\ShopifyMetafield;
use League\Csv\Reader;

final class ShopifyApiImporter
{
    private array $metaobjectCache = [];

    public function __construct(
        private readonly ShopifyApiClient $client,
        private readonly Normalizer $normalizer,
    ) {}

    public function createOrReuseCurrentImport(int $userId, string $mode = 'overwrite'): Import
    {
        $current = Import::where('is_current', true)->first();
        if ($current) {
            $current->update([
                'filename' => 'shopify-api',
                'mode' => $mode,
                'status' => 'processing',
                'created_by' => $userId,
                'is_valid' => true,
            ]);
            return $current;
        }

        Import::query()->update(['is_current' => false]);

        return Import::create([
            'filename' => 'shopify-api',
            'mode' => $mode,
            'status' => 'processing',
            'created_by' => $userId,
            'is_current' => true,
            'is_valid' => true,
        ]);
    }

    public function importIntoExistingImport(Import $import): void
    {
        ShopifyRow::where('import_id', $import->id)->delete();
        Product::where('import_id', $import->id)->delete();
        ShopifyCollection::where('import_id', $import->id)->delete();
        ShopifyMetafield::where('import_id', $import->id)->delete();

        $headers = $this->loadHeaders();
        $import->forceFill(['headers' => $headers])->save();

        $metafieldHeaders = $this->metafieldHeaders($headers);

        $rowIndex = 0;
        foreach ($this->fetchProducts() as $product) {
            $metaobjectMap = $this->resolveMetaobjectValues($product);
            $rows = $this->rowsForProduct($product, $headers, $metafieldHeaders, $metaobjectMap);
            foreach ($rows as $row) {
                ShopifyRow::create([
                    'import_id' => $import->id,
                    'row_index' => $rowIndex++,
                    'handle' => $row['handle'],
                    'row_type' => $row['row_type'],
                    'variant_key' => $row['variant_key'],
                    'image_key' => $row['image_key'],
                    'data' => $row['data'],
                ]);
            }
            $this->storeMetafields($import, $product, $metaobjectMap);
        }

        $this->storeCollections($import);

        $this->normalizer->buildNormalizedTables($import);
        $import->update(['status' => 'ready', 'is_valid' => true]);
    }

    private function loadHeaders(): array
    {
        $templatePath = HeaderStore::latestTemplatePath();
        if ($templatePath === null || !is_file($templatePath)) {
            return HeaderStore::knownHeaders();
        }

        $csv = Reader::createFromPath($templatePath);
        $csv->setHeaderOffset(0);
        $headers = $csv->getHeader();
        return empty($headers) ? HeaderStore::knownHeaders() : $headers;
    }

    private function metafieldHeaders(array $headers): array
    {
        $map = [];
        foreach ($headers as $header) {
            $identifier = $this->metafieldIdentifierFromHeader($header);
            if ($identifier) {
                $map[$header] = $identifier;
            }
        }
        return $map;
    }

    private function metafieldIdentifierFromHeader(string $header): ?array
    {
        if (!preg_match('/\\(product\\.metafields\\.([^.]+)\\.([^)]+)\\)/', $header, $matches)) {
            return null;
        }

        return [
            'namespace' => $matches[1],
            'key' => $matches[2],
        ];
    }

    private function fetchProducts(): \Generator
    {
        $after = null;
        do {
            $data = $this->client->graphql(
                $this->productsQuery(),
                [
                    'first' => 100,
                    'after' => $after,
                ]
            );

            $products = data_get($data, 'products.nodes', []);
            foreach ($products as $product) {
                yield $product;
            }

            $pageInfo = data_get($data, 'products.pageInfo', []);
            $after = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;
        } while ($after);
    }

    private function productsQuery(): string
    {
        return <<<'GQL'
query Products($first: Int!, $after: String) {
  products(first: $first, after: $after) {
    pageInfo { hasNextPage endCursor }
    nodes {
      handle
      title
      descriptionHtml
      vendor
      productType
      status
      tags
      publishedAt
      seo { title description }
      productCategory { productTaxonomyNode { fullName } }
      metafields(first: 250) {
        nodes {
          namespace
          key
          value
          type
        }
      }
      variants(first: 250) {
        nodes {
          sku
          price
          compareAtPrice
          barcode
          selectedOptions { name value }
          inventoryItem { unitCost { amount currencyCode } }
        }
      }
      images(first: 250) {
        nodes {
          url
          altText
        }
      }
    }
  }
}
GQL;
    }

    private function fetchCollections(): \Generator
    {
        $after = null;
        do {
            $data = $this->client->graphql(
                $this->collectionsQuery(),
                [
                    'first' => 100,
                    'after' => $after,
                ]
            );

            $collections = data_get($data, 'collections.nodes', []);
            foreach ($collections as $collection) {
                yield $collection;
            }

            $pageInfo = data_get($data, 'collections.pageInfo', []);
            $after = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;
        } while ($after);
    }

    private function collectionsQuery(): string
    {
        return <<<'GQL'
query Collections($first: Int!, $after: String) {
  collections(first: $first, after: $after) {
    pageInfo { hasNextPage endCursor }
    nodes {
      id
      handle
      title
      descriptionHtml
      seo { title description }
    }
  }
}
GQL;
    }

    private function rowsForProduct(
        array $product,
        array $headers,
        array $metafieldHeaders,
        array $metaobjectMap
    ): array
    {
        $handle = trim((string) data_get($product, 'handle', ''));
        if ($handle === '') {
            return [];
        }

        $rows = [];
        $blank = $this->blankRow($headers);
        $base = $blank;
        $base[HeaderStore::HANDLE] = $handle;

        $this->applyProductFields($base, $product, $headers);
        $this->applyMetafields($base, $product, $headers, $metafieldHeaders, $metaobjectMap);
        $this->applyCostPerItem($base, $product, $headers);

        $variants = data_get($product, 'variants.nodes', []);
        $images = data_get($product, 'images.nodes', []);

        $primaryVariant = $variants[0] ?? null;
        $primaryImage = $images[0] ?? null;

        $primary = $base;
        if ($primaryVariant) {
            $this->applyVariantFields($primary, $primaryVariant, $headers);
        }
        if ($primaryImage) {
            $this->applyImageFields($primary, $primaryImage, 1, $headers);
        }

        $rows[] = [
            'handle' => $handle,
            'row_type' => 'product_primary',
            'variant_key' => RowKey::variantKey($primary),
            'image_key' => RowKey::imageKey($primary),
            'data' => $primary,
        ];

        if (count($variants) > 1) {
            foreach (array_slice($variants, 1) as $variant) {
                $row = $blank;
                $row[HeaderStore::HANDLE] = $handle;
                $this->applyVariantFields($row, $variant, $headers);
                $rows[] = [
                    'handle' => $handle,
                    'row_type' => 'variant',
                    'variant_key' => RowKey::variantKey($row),
                    'image_key' => null,
                    'data' => $row,
                ];
            }
        }

        $imageOffset = $primaryImage ? 1 : 0;
        if (count($images) > $imageOffset) {
            $position = $imageOffset + 1;
            foreach (array_slice($images, $imageOffset) as $image) {
                $row = $blank;
                $row[HeaderStore::HANDLE] = $handle;
                $this->applyImageFields($row, $image, $position++, $headers);
                $rows[] = [
                    'handle' => $handle,
                    'row_type' => 'image',
                    'variant_key' => null,
                    'image_key' => RowKey::imageKey($row),
                    'data' => $row,
                ];
            }
        }

        return $rows;
    }

    private function blankRow(array $headers): array
    {
        $row = [];
        foreach ($headers as $header) {
            $row[$header] = '';
        }
        return $row;
    }

    private function applyProductFields(array &$row, array $product, array $headers): void
    {
        $this->setIfHeaderExists($row, $headers, HeaderStore::TITLE, data_get($product, 'title'));
        $this->setIfHeaderExists($row, $headers, HeaderStore::BODY_HTML, data_get($product, 'descriptionHtml'));
        $this->setIfHeaderExists($row, $headers, HeaderStore::VENDOR, data_get($product, 'vendor'));
        $this->setIfHeaderExists($row, $headers, HeaderStore::TYPE, data_get($product, 'productType'));

        $tags = data_get($product, 'tags', []);
        if (is_array($tags)) {
            $tags = implode(', ', array_values(array_filter($tags)));
        }
        $this->setIfHeaderExists($row, $headers, HeaderStore::TAGS, $tags);

        $category = data_get($product, 'productCategory.productTaxonomyNode.fullName');
        $this->setIfHeaderExists($row, $headers, HeaderStore::PRODUCT_CATEGORY, $category);

        $status = strtolower((string) data_get($product, 'status', ''));
        if ($status !== '') {
            $this->setIfHeaderExists($row, $headers, HeaderStore::STATUS, $status);
            $this->setIfHeaderExists($row, $headers, HeaderStore::PUBLISHED, $status === 'active' ? 'true' : 'false');
        }

        $this->setIfHeaderExists($row, $headers, HeaderStore::SEO_TITLE, data_get($product, 'seo.title'));
        $this->setIfHeaderExists($row, $headers, HeaderStore::SEO_DESCRIPTION, data_get($product, 'seo.description'));
    }

    private function applyMetafields(
        array &$row,
        array $product,
        array $headers,
        array $metafieldHeaders,
        array $metaobjectMap
    ): void
    {
        if (empty($metafieldHeaders)) {
            return;
        }

        $metafields = data_get($product, 'metafields.nodes', []);
        $metafieldMap = [];
        foreach ($metafields as $metafield) {
            $namespace = $metafield['namespace'] ?? null;
            $key = $metafield['key'] ?? null;
            if (!$namespace || !$key) {
                continue;
            }
            $metafieldMap["{$namespace}.{$key}"] = $this->normalizeMetafieldValue($metafield, $metaobjectMap);
        }

        foreach ($metafieldHeaders as $header => $identifier) {
            $lookup = $identifier['namespace'] . '.' . $identifier['key'];
            $value = $metafieldMap[$lookup] ?? '';
            $this->setIfHeaderExists($row, $headers, $header, $value);
        }
    }

    private function storeMetafields(Import $import, array $product, array $metaobjectMap): void
    {
        $handle = trim((string) data_get($product, 'handle', ''));
        if ($handle === '') {
            return;
        }

        $metafields = data_get($product, 'metafields.nodes', []);
        if (empty($metafields)) {
            return;
        }

        $now = now();
        $rows = [];
        foreach ($metafields as $metafield) {
            $namespace = $metafield['namespace'] ?? null;
            $key = $metafield['key'] ?? null;
            if (!$namespace || !$key) {
                continue;
            }

            $rows[] = [
                'import_id' => $import->id,
                'handle' => $handle,
                'namespace' => $namespace,
                'key' => $key,
                'type' => $metafield['type'] ?? null,
                'value' => $this->normalizeMetafieldValue($metafield, $metaobjectMap),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        if (empty($rows)) {
            return;
        }

        ShopifyMetafield::upsert(
            $rows,
            ['import_id', 'handle', 'namespace', 'key'],
            ['type', 'value', 'updated_at']
        );
    }

    private function storeCollections(Import $import): void
    {
        foreach ($this->fetchCollections() as $collection) {
            $shopifyId = trim((string) data_get($collection, 'id', ''));
            $handle = trim((string) data_get($collection, 'handle', ''));
            if ($shopifyId === '' || $handle === '') {
                continue;
            }

            ShopifyCollection::updateOrCreate(
                [
                    'import_id' => $import->id,
                    'shopify_id' => $shopifyId,
                ],
                [
                    'handle' => $handle,
                    'title' => data_get($collection, 'title'),
                    'description_html' => data_get($collection, 'descriptionHtml'),
                    'seo_title' => data_get($collection, 'seo.title'),
                    'seo_description' => data_get($collection, 'seo.description'),
                ]
            );
        }
    }

    private function normalizeMetafieldValue(array $metafield, array $metaobjectMap = []): string
    {
        $value = (string) ($metafield['value'] ?? '');
        $type = (string) ($metafield['type'] ?? '');

        if ($value === '') {
            return '';
        }

        $resolved = $this->resolveMetaobjectLabelsFromValue($value, $metaobjectMap);
        if ($resolved !== null) {
            return $resolved;
        }

        if ($type === 'metaobject_reference') {
            return $metaobjectMap[$value] ?? '';
        }

        if ($type === 'list.metaobject_reference') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $items = [];
                foreach ($decoded as $id) {
                    $label = $metaobjectMap[$id] ?? null;
                    if ($label !== null && $label !== '') {
                        $items[] = $label;
                    }
                }
                return implode('; ', $items);
            }
            return '';
        }

        if (str_starts_with($type, 'list.')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $items = array_values(array_filter(array_map('trim', $decoded)));
                return implode('; ', $items);
            }
        }

        if ($type === 'boolean') {
            return strtolower($value) === 'true' ? 'true' : 'false';
        }

        return $value;
    }

    private function applyCostPerItem(array &$row, array $product, array $headers): void
    {
        if (!in_array(HeaderStore::COST_PER_ITEM, $headers, true)) {
            return;
        }

        $amount = data_get($product, 'variants.nodes.0.inventoryItem.unitCost.amount');
        if ($amount !== null) {
            $row[HeaderStore::COST_PER_ITEM] = (string) $amount;
        }
    }

    private function applyVariantFields(array &$row, array $variant, array $headers): void
    {
        $this->setIfHeaderExists($row, $headers, HeaderStore::VARIANT_SKU, data_get($variant, 'sku'));
        $this->setIfHeaderExists($row, $headers, HeaderStore::VARIANT_PRICE, data_get($variant, 'price'));
        $this->setIfHeaderExists($row, $headers, HeaderStore::VARIANT_COMPARE_AT, data_get($variant, 'compareAtPrice'));
        $this->setIfHeaderExists($row, $headers, HeaderStore::VARIANT_BARCODE, data_get($variant, 'barcode'));

        $selected = data_get($variant, 'selectedOptions', []);
        if (is_array($selected)) {
            $opt1 = $selected[0] ?? null;
            $opt2 = $selected[1] ?? null;
            $opt3 = $selected[2] ?? null;

            $this->setIfHeaderExists($row, $headers, HeaderStore::OPTION1_NAME, $opt1['name'] ?? null);
            $this->setIfHeaderExists($row, $headers, HeaderStore::OPTION1_VALUE, $opt1['value'] ?? null);
            $this->setIfHeaderExists($row, $headers, HeaderStore::OPTION2_NAME, $opt2['name'] ?? null);
            $this->setIfHeaderExists($row, $headers, HeaderStore::OPTION2_VALUE, $opt2['value'] ?? null);
            $this->setIfHeaderExists($row, $headers, HeaderStore::OPTION3_NAME, $opt3['name'] ?? null);
            $this->setIfHeaderExists($row, $headers, HeaderStore::OPTION3_VALUE, $opt3['value'] ?? null);
        }

        $weight = data_get($variant, 'weight');
        $unit = strtoupper((string) data_get($variant, 'weightUnit', ''));
        $grams = $this->toGrams($weight, $unit);
        if ($grams !== null) {
            $this->setIfHeaderExists($row, $headers, HeaderStore::VARIANT_GRAMS, $grams);
            $this->setIfHeaderExists($row, $headers, HeaderStore::VARIANT_WEIGHT_UNIT, 'g');
        }
    }

    private function applyImageFields(array &$row, array $image, int $position, array $headers): void
    {
        $src = data_get($image, 'url') ?? data_get($image, 'src');
        $this->setIfHeaderExists($row, $headers, HeaderStore::IMAGE_SRC, $src);
        $this->setIfHeaderExists($row, $headers, HeaderStore::IMAGE_POSITION, (string) $position);
        $this->setIfHeaderExists($row, $headers, HeaderStore::IMAGE_ALT_TEXT, data_get($image, 'altText'));
    }

    private function toGrams(mixed $weight, string $unit): ?string
    {
        if ($weight === null || $weight === '') {
            return null;
        }

        $value = (float) $weight;
        if ($value <= 0) {
            return null;
        }

        $grams = match ($unit) {
            'KILOGRAMS' => $value * 1000,
            'OUNCES' => $value * 28.3495,
            'POUNDS' => $value * 453.592,
            default => $value,
        };

        return rtrim(rtrim(number_format($grams, 3, '.', ''), '0'), '.');
    }

    private function setIfHeaderExists(array &$row, array $headers, string $header, mixed $value): void
    {
        if (!in_array($header, $headers, true)) {
            return;
        }

        if ($value === null) {
            return;
        }

        $row[$header] = (string) $value;
    }

    private function resolveMetaobjectValues(array $product): array
    {
        $metafields = data_get($product, 'metafields.nodes', []);
        if (empty($metafields)) {
            return [];
        }

        $ids = [];
        foreach ($metafields as $metafield) {
            $type = (string) ($metafield['type'] ?? '');
            $value = (string) ($metafield['value'] ?? '');
            if ($value === '') {
                continue;
            }

            if ($type === 'metaobject_reference') {
                $ids[] = $this->normalizeMetaobjectId($value);
                continue;
            }

            if ($type === 'list.metaobject_reference') {
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    foreach ($decoded as $id) {
                        if (is_string($id) && $id !== '') {
                            $ids[] = $this->normalizeMetaobjectId($id);
                        }
                    }
                }
            }

            $ids = array_merge($ids, $this->extractMetaobjectIdsFromValue($value));
        }

        $ids = array_values(array_unique(array_filter($ids)));
        if (empty($ids)) {
            return [];
        }

        $missing = array_values(array_diff($ids, array_keys($this->metaobjectCache)));
        if (!empty($missing)) {
            $this->hydrateMetaobjectCache($missing);
        }

        $resolved = [];
        foreach ($ids as $id) {
            if (isset($this->metaobjectCache[$id])) {
                $resolved[$id] = $this->metaobjectCache[$id];
            }
        }

        return $resolved;
    }

    private function hydrateMetaobjectCache(array $ids): void
    {
        $chunks = array_chunk($ids, 50);
        foreach ($chunks as $chunk) {
            $data = $this->client->graphql(
                $this->metaobjectsQuery(),
                ['ids' => $chunk]
            );

            $nodes = data_get($data, 'nodes', []);
            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }
                if (($node['__typename'] ?? '') !== 'Metaobject') {
                    continue;
                }
                $id = $node['id'] ?? null;
                if (!$id) {
                    continue;
                }
                $label = $this->metaobjectDisplayValue($node);
                if ($label !== '') {
                    $this->metaobjectCache[$id] = $label;
                }
            }
        }
    }

    private function metaobjectsQuery(): string
    {
        return <<<'GQL'
query Metaobjects($ids: [ID!]!) {
  nodes(ids: $ids) {
    __typename
    ... on Metaobject {
      id
      handle
      displayName
      fields { key value }
    }
  }
}
GQL;
    }

    private function metaobjectDisplayValue(array $metaobject): string
    {
        $fields = $metaobject['fields'] ?? [];
        if (!is_array($fields)) {
            return '';
        }

        if (!empty($metaobject['displayName'])) {
            return trim((string) $metaobject['displayName']);
        }

        if (!empty($metaobject['handle'])) {
            return trim((string) $metaobject['handle']);
        }

        $preferred = ['name', 'title', 'label', 'value', 'display_name', 'displayName'];
        $map = [];
        foreach ($fields as $field) {
            $key = $field['key'] ?? null;
            $value = $field['value'] ?? null;
            if (!$key || $value === null) {
                continue;
            }
            $map[$key] = $value;
        }

        foreach ($preferred as $key) {
            if (isset($map[$key]) && trim((string) $map[$key]) !== '') {
                return trim((string) $map[$key]);
            }
        }

        foreach ($map as $value) {
            $value = trim((string) $value);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    private function resolveMetaobjectLabelsFromValue(string $value, array $metaobjectMap): ?string
    {
        $ids = $this->extractMetaobjectIdsFromValue($value);
        if (empty($ids)) {
            return null;
        }

        $labels = [];
        foreach ($ids as $id) {
            if (isset($metaobjectMap[$id])) {
                $labels[] = $metaobjectMap[$id];
            }
        }

        if (empty($labels)) {
            return '';
        }

        return implode('; ', $labels);
    }

    private function extractMetaobjectIdsFromValue(string $value): array
    {
        $value = trim($value);
        if ($value === '') {
            return [];
        }

        if (str_starts_with($value, '[')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $ids = [];
                foreach ($decoded as $id) {
                    if (is_string($id)) {
                        $normalized = $this->normalizeMetaobjectId($id);
                        if ($normalized !== '') {
                            $ids[] = $normalized;
                        }
                    }
                }
                return $ids;
            }
        }

        $parts = str_contains($value, ';')
            ? array_map('trim', explode(';', $value))
            : [$value];

        $ids = [];
        foreach ($parts as $part) {
            $normalized = $this->normalizeMetaobjectId($part);
            if ($normalized !== '') {
                $ids[] = $normalized;
            }
        }

        return $ids;
    }

    private function normalizeMetaobjectId(string $value): string
    {
        $trimmed = trim($value);
        $trimmed = trim($trimmed, "\"'");
        $trimmed = trim($trimmed);

        return str_starts_with($trimmed, 'gid://shopify/Metaobject/')
            ? $trimmed
            : '';
    }
}
