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
