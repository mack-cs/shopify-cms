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
