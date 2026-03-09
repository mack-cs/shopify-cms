<?php

use App\Services\ShopifyApiClient;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command(
    'shopify:set-product-category {productId : Shopify Product GID} {categoryId : Shopify TaxonomyCategory/ProductTaxonomyNode GID}',
    function (string $productId, string $categoryId): int {
        try {
            /** @var ShopifyApiClient $client */
            $client = app(ShopifyApiClient::class);

            $result = $client->graphql(<<<'GQL'
                mutation SetProductCategory($productId: ID!, $categoryId: ID!) {
                productUpdate(input: {
                    id: $productId
                    category: $categoryId
                }) {
                    product {
                    id
                    category {
                        id
                        name
                    }
                    }
                    userErrors {
                    field
                    message
                    }
                }
                }
            GQL, [
                            'productId' => $productId,
                            'categoryId' => $categoryId,
                        ]);

            $userErrors = data_get($result, 'productUpdate.userErrors', []);
            if (!empty($userErrors)) {
                $this->error('Shopify returned userErrors:');
                foreach ($userErrors as $error) {
                    $field = is_array($error['field'] ?? null)
                        ? implode('.', $error['field'])
                        : ($error['field'] ?? 'unknown');
                    $this->line("- [{$field}] " . ($error['message'] ?? 'Unknown error'));
                }

                return self::FAILURE;
            }

            $product = data_get($result, 'productUpdate.product');
            if (!$product) {
                $this->error('No product returned from Shopify.');
                $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                return self::FAILURE;
            }

            $this->info('Product category updated successfully.');
            $this->line('Product: ' . data_get($product, 'id', 'n/a'));
            $this->line('Category: ' . data_get($product, 'category.id', 'n/a'));
            $this->line('Category Name: ' . data_get($product, 'category.name', 'n/a'));

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Request failed: ' . $e->getMessage());

            return self::FAILURE;
        }
    }
)->purpose('Set a Shopify product category using credentials from .env');

Artisan::command(
    'shopify:find-cat {search : Search text like bracelet, ring, necklace}',
    function (string $search): int {
        $query = <<<'GQL'
            query FindTaxonomyCategories($search: String!) {
            taxonomy {
                categories(first: 20, search: $search) {
                nodes {
                    id
                    name
                    fullName
                    isLeaf
                }
                }
            }
            }
            GQL;

        try {
            /** @var ShopifyApiClient $client */
            $client = app(ShopifyApiClient::class);
            $result = $client->graphql($query, ['search' => $search]);
            $nodes = data_get($result, 'taxonomy.categories.nodes', []) ?: [];

            if (empty($nodes)) {
                $this->warn("No categories found for: {$search}");
                return self::SUCCESS;
            }

            foreach ($nodes as $node) {
                $this->line(implode(' | ', [
                    (string) data_get($node, 'id', ''),
                    (string) data_get($node, 'name', ''),
                    (string) data_get($node, 'fullName', ''),
                    data_get($node, 'isLeaf', false) ? 'leaf' : 'branch',
                ]));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Request failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
)->purpose('Find Shopify taxonomy categories by search text');

Artisan::command(
    'shopify:test-uvp
    {ownerId : Shopify Product GID (e.g. gid://shopify/Product/1234567890)}
    {--text= : UVP text for the rich text paragraph}
    {--namespace=custom : Metafield namespace}
    {--key=uvp_short_paragraph : Metafield key}
    {--verify : Query this exact metafield after write}',
    function (string $ownerId): int {
        $text = trim((string) ($this->option('text') ?? ''));
        $namespace = trim((string) ($this->option('namespace') ?? 'custom'));
        $key = trim((string) ($this->option('key') ?? 'uvp_short_paragraph'));
        $verify = (bool) $this->option('verify');

        if ($text === '') {
            $text = 'A compact, compelling UVP explaining why this product is unique and why customers should choose it.';
        }

        if ($namespace === '' || $key === '') {
            $this->error('Namespace and key are required.');
            return self::FAILURE;
        }
        if (!preg_match('/^[a-z0-9_]+$/', $key)) {
            $this->error("Invalid key '{$key}'. Use only lowercase letters, numbers, and underscores.");
            return self::FAILURE;
        }

        $richTextValue = json_encode([
            'type' => 'root',
            'children' => [[
                'type' => 'paragraph',
                'children' => [[
                    'type' => 'text',
                    'value' => $text,
                ]],
            ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        try {
            /** @var ShopifyApiClient $client */
            $client = app(ShopifyApiClient::class);

            $result = $client->graphql(<<<'GQL'
                mutation UpdateProductUVP($metafields: [MetafieldsSetInput!]!) {
                  metafieldsSet(metafields: $metafields) {
                    metafields {
                      namespace
                      key
                      type
                      value
                    }
                    userErrors {
                      field
                      message
                    }
                  }
                }
            GQL, [
                'metafields' => [[
                    'ownerId' => $ownerId,
                    'namespace' => $namespace,
                    'key' => $key,
                    'type' => 'rich_text_field',
                    'value' => $richTextValue,
                ]],
            ]);

            $userErrors = data_get($result, 'metafieldsSet.userErrors', []);
            if (is_array($userErrors) && !empty($userErrors)) {
                $this->error('Shopify returned userErrors:');
                foreach ($userErrors as $error) {
                    $field = is_array($error['field'] ?? null)
                        ? implode('.', $error['field'])
                        : ($error['field'] ?? 'unknown');
                    $this->line("- [{$field}] " . ($error['message'] ?? 'Unknown error'));
                }

                return self::FAILURE;
            }

            $metafields = data_get($result, 'metafieldsSet.metafields', []);
            $this->info('UVP metafield set successfully.');
            $this->line('Owner: ' . $ownerId);
            $this->line('Namespace/Key: ' . $namespace . '.' . $key);
            $this->line('Text: ' . $text);
            $this->line('Returned metafields:');
            $this->line(json_encode($metafields, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

            if ($verify) {
                $verifyResult = $client->graphql(<<<'GQL'
                    query CheckUVPMetafield($id: ID!, $namespace: String!, $key: String!) {
                      product(id: $id) {
                        id
                        uvpField: metafield(namespace: $namespace, key: $key) {
                          namespace
                          key
                          type
                          value
                          jsonValue
                        }
                      }
                    }
                GQL, [
                    'id' => $ownerId,
                    'namespace' => $namespace,
                    'key' => $key,
                ]);

                $this->line('Verified uvpField:');
                $this->line(json_encode(
                    data_get($verifyResult, 'product.uvpField'),
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                ));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Request failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
)->purpose('Test setting a product UVP rich text metafield via Shopify GraphQL');

Artisan::command(
    'shopify:update-variant-price
    {product : Product handle or Product GID (gid://shopify/Product/...)}
    {price? : New variant price, e.g. 24.99}
    {--sku= : Target SKU (recommended when product has multiple variants)}
    {--variant-id= : Target variant GID (gid://shopify/ProductVariant/...)}
    {--all : Update all variants on the product}
    {--sku-barcode= : Set Variant SKU and Variant Barcode to this exact same value}
    {--compare-at= : Set Variant Compare At Price}
    {--weight-unit= : Set Variant Weight Unit (g, kg, oz, lb)}
    {--weight= : Optional weight value used with --weight-unit}
    {--cost-per-item= : Set inventory item cost}',
    function (string $product, ?string $price = null): int {
        $priceInput = trim((string) ($price ?? ''));
        $hasPrice = $priceInput !== '';
        if ($hasPrice && !preg_match('/^\d+(?:\.\d{1,2})?$/', $priceInput)) {
            $this->error("Invalid price '{$priceInput}'. Use a numeric value with up to 2 decimals.");
            return self::FAILURE;
        }

        $targetSku = trim((string) ($this->option('sku') ?? ''));
        $targetVariantId = trim((string) ($this->option('variant-id') ?? ''));
        $updateAll = (bool) $this->option('all');
        $skuBarcode = trim((string) ($this->option('sku-barcode') ?? ''));
        $compareAtInput = trim((string) ($this->option('compare-at') ?? ''));
        $weightUnitInput = strtolower(trim((string) ($this->option('weight-unit') ?? '')));
        $weightInput = trim((string) ($this->option('weight') ?? ''));
        $costPerItemInput = trim((string) ($this->option('cost-per-item') ?? ''));

        if ($compareAtInput !== '' && !preg_match('/^\d+(?:\.\d{1,2})?$/', $compareAtInput)) {
            $this->error("Invalid compare-at '{$compareAtInput}'. Use a numeric value with up to 2 decimals.");
            return self::FAILURE;
        }
        if ($costPerItemInput !== '' && !preg_match('/^\d+(?:\.\d{1,2})?$/', $costPerItemInput)) {
            $this->error("Invalid cost-per-item '{$costPerItemInput}'. Use a numeric value with up to 2 decimals.");
            return self::FAILURE;
        }
        if ($weightInput !== '' && !preg_match('/^\d+(?:\.\d{1,6})?$/', $weightInput)) {
            $this->error("Invalid weight '{$weightInput}'. Use a numeric value.");
            return self::FAILURE;
        }

        $weightEnum = null;
        if ($weightUnitInput !== '') {
            $weightEnum = match ($weightUnitInput) {
                'g', 'gram', 'grams' => 'GRAMS',
                'kg', 'kilogram', 'kilograms' => 'KILOGRAMS',
                'oz', 'ounce', 'ounces' => 'OUNCES',
                'lb', 'lbs', 'pound', 'pounds' => 'POUNDS',
                default => null,
            };
            if ($weightEnum === null) {
                $this->error("Invalid --weight-unit '{$weightUnitInput}'. Allowed: g, kg, oz, lb.");
                return self::FAILURE;
            }
        }

        $hasAnyFieldUpdate = $hasPrice
            || $skuBarcode !== ''
            || $compareAtInput !== ''
            || $weightEnum !== null
            || $weightInput !== ''
            || $costPerItemInput !== '';
        if (!$hasAnyFieldUpdate) {
            $this->error('Nothing to update. Provide price and/or any of --sku-barcode, --compare-at, --weight-unit, --weight, --cost-per-item.');
            return self::FAILURE;
        }
        if ($weightInput !== '' && $weightEnum === null) {
            $this->error('When using --weight, also provide --weight-unit.');
            return self::FAILURE;
        }

        if ($targetSku !== '' && $targetVariantId !== '') {
            $this->error('Use either --sku or --variant-id, not both.');
            return self::FAILURE;
        }

        try {
            /** @var ShopifyApiClient $client */
            $client = app(ShopifyApiClient::class);

            if (str_starts_with($product, 'gid://shopify/Product/')) {
                $query = <<<'GQL'
query ProductByIdForPriceUpdate($id: ID!) {
  product(id: $id) {
    ... on Product {
      id
      handle
      title
      variants(first: 250) {
        nodes {
          id
          sku
          price
          compareAtPrice
          barcode
          inventoryItem {
            id
          }
        }
      }
    }
  }
}
GQL;
                $result = $client->graphql($query, ['id' => $product]);
                $productNode = data_get($result, 'product');
            } else {
                $query = <<<'GQL'
query ProductByHandleForPriceUpdate($handle: String!) {
  productByHandle(handle: $handle) {
    id
    handle
    title
    variants(first: 250) {
      nodes {
        id
        sku
        price
        compareAtPrice
        barcode
        inventoryItem {
          id
        }
      }
    }
  }
}
GQL;
                $result = $client->graphql($query, ['handle' => $product]);
                $productNode = data_get($result, 'productByHandle');
            }

            if (!is_array($productNode) || empty($productNode['id'])) {
                $this->error("Product not found for '{$product}'.");
                return self::FAILURE;
            }

            $productId = (string) ($productNode['id'] ?? '');
            $productHandle = (string) ($productNode['handle'] ?? '');
            $variantNodes = data_get($productNode, 'variants.nodes', []);
            if (!is_array($variantNodes) || empty($variantNodes)) {
                $this->error('No variants found for this product.');
                return self::FAILURE;
            }

            $variants = collect($variantNodes)
                ->filter(fn ($node) => is_array($node) && !empty($node['id']))
                ->values();

            if ($variants->isEmpty()) {
                $this->error('No valid variants found for this product.');
                return self::FAILURE;
            }

            $selected = collect();
            if ($updateAll) {
                $selected = $variants;
            } elseif ($targetVariantId !== '') {
                $selected = $variants->filter(
                    fn ($v) => (string) ($v['id'] ?? '') === $targetVariantId
                )->values();
            } elseif ($targetSku !== '') {
                $selected = $variants->filter(
                    fn ($v) => trim((string) ($v['sku'] ?? '')) === $targetSku
                )->values();
            } elseif ($variants->count() === 1) {
                $selected = $variants->take(1);
            } else {
                $this->error('Product has multiple variants. Pass --sku=<sku>, --variant-id=<gid>, or --all.');
                $this->line('Available variants:');
                foreach ($variants as $v) {
                    $this->line('- ' . (string) ($v['id'] ?? '') . ' | sku=' . (string) ($v['sku'] ?? '') . ' | current=' . (string) ($v['price'] ?? ''));
                }
                return self::FAILURE;
            }

            if ($selected->isEmpty()) {
                $this->error('No matching variant found for provided selector.');
                return self::FAILURE;
            }

            $inputs = $selected
                ->map(function ($v) use ($hasPrice, $priceInput, $skuBarcode, $compareAtInput, $weightEnum, $weightInput, $costPerItemInput): array {
                    $input = [
                        'id' => (string) ($v['id'] ?? ''),
                    ];

                    if ($hasPrice) {
                        $input['price'] = number_format((float) $priceInput, 2, '.', '');
                    }
                    if ($skuBarcode !== '') {
                        $input['barcode'] = $skuBarcode;
                    }
                    if ($compareAtInput !== '') {
                        $input['compareAtPrice'] = number_format((float) $compareAtInput, 2, '.', '');
                    }
                    if ($skuBarcode !== '' || $weightEnum !== null || $costPerItemInput !== '') {
                        $inventoryItem = [];
                        if ($skuBarcode !== '') {
                            $inventoryItem['sku'] = $skuBarcode;
                        }
                        if ($costPerItemInput !== '') {
                            $inventoryItem['cost'] = (float) number_format((float) $costPerItemInput, 2, '.', '');
                        }
                        if ($weightEnum !== null) {
                            $inventoryItem['measurement'] = [
                                'weight' => [
                                    'unit' => $weightEnum,
                                    'value' => (float) ($weightInput !== '' ? $weightInput : '0'),
                                ],
                            ];
                        }
                        if (!empty($inventoryItem)) {
                            $input['inventoryItem'] = $inventoryItem;
                        }
                    }

                    return $input;
                })
                ->values()
                ->all();

            $mutation = <<<'GQL'
mutation ProductVariantsBulkPriceUpdate($productId: ID!, $variants: [ProductVariantsBulkInput!]!) {
  productVariantsBulkUpdate(productId: $productId, variants: $variants) {
    productVariants {
      id
      sku
      barcode
      price
      compareAtPrice
      inventoryItem {
        id
        sku
        unitCost {
          amount
          currencyCode
        }
        measurement {
          weight {
            value
            unit
          }
        }
      }
    }
    userErrors {
      field
      message
    }
  }
}
GQL;

            $mutationResult = null;
            if (!empty($inputs)) {
                $mutationResult = $client->graphql($mutation, [
                    'productId' => $productId,
                    'variants' => $inputs,
                ]);
            }

            $userErrors = data_get($mutationResult, 'productVariantsBulkUpdate.userErrors', []);
            if (is_array($userErrors) && !empty($userErrors)) {
                $this->error('Shopify returned userErrors:');
                foreach ($userErrors as $error) {
                    $field = is_array($error['field'] ?? null)
                        ? implode('.', $error['field'])
                        : ($error['field'] ?? 'unknown');
                    $this->line("- [{$field}] " . ($error['message'] ?? 'Unknown error'));
                }
                return self::FAILURE;
            }

            $updated = data_get($mutationResult, 'productVariantsBulkUpdate.productVariants', []);

            $this->info('Variant update succeeded.');
            $this->line('Product: ' . $productId . ($productHandle !== '' ? " ({$productHandle})" : ''));
            if ($hasPrice) {
                $this->line('Price: ' . number_format((float) $priceInput, 2, '.', ''));
            }
            if ($skuBarcode !== '') {
                $this->line('SKU/Barcode: ' . $skuBarcode);
            }
            if ($compareAtInput !== '') {
                $this->line('Compare-at: ' . number_format((float) $compareAtInput, 2, '.', ''));
            }
            if ($weightEnum !== null) {
                $this->line('Weight unit: ' . $weightEnum . ($weightInput !== '' ? (' | weight=' . $weightInput) : ''));
            }
            if ($costPerItemInput !== '') {
                $this->line('Cost per item: ' . number_format((float) $costPerItemInput, 2, '.', ''));
            }
            $this->line('Updated variants:');
            foreach ((array) $updated as $row) {
                $this->line('- ' . (string) data_get($row, 'id', '') . ' | sku=' . (string) data_get($row, 'sku', '') . ' | price=' . (string) data_get($row, 'price', ''));
            }

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Request failed: ' . $e->getMessage());
            return self::FAILURE;
        }
    }
)->purpose('Update Shopify variant fields (price, compare-at, sku/barcode, weight unit/weight, cost per item) for a specific product.');
