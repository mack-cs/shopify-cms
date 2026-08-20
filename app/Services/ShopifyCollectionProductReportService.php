<?php

namespace App\Services;

use App\Models\ShopifyCollectionProductReportRow;
use App\Models\ShopifyCollectionProductReportRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class ShopifyCollectionProductReportService
{
    private const COLLECTION_PAGE_SIZE = 10;

    private const INITIAL_PRODUCT_PAGE_SIZE = 20;

    private const PRODUCT_PAGE_SIZE = 50;

    private const INITIAL_VARIANT_PAGE_SIZE = 3;

    private const VARIANT_PAGE_SIZE = 100;

    public function __construct(private readonly ShopifyApiClient $client) {}

    /** @param array<int, string> $collectionHandles */
    public function createRun(?int $userId = null, array $collectionHandles = []): ShopifyCollectionProductReportRun
    {
        $collectionHandles = collect($collectionHandles)
            ->map(fn ($handle): string => strtolower(trim((string) $handle)))
            ->filter()
            ->unique()
            ->values()
            ->all();

        return ShopifyCollectionProductReportRun::query()->create([
            'requested_by' => $userId,
            'status' => ShopifyCollectionProductReportRun::STATUS_QUEUED,
            'api_version' => (string) config('services.shopify.api_version', '2026-01'),
            'selected_collection_handles' => $collectionHandles === [] ? null : $collectionHandles,
        ]);
    }

    public function generate(ShopifyCollectionProductReportRun $run): ShopifyCollectionProductReportRun
    {
        $run->forceFill([
            'status' => ShopifyCollectionProductReportRun::STATUS_RUNNING,
            'started_at' => now(),
            'completed_at' => null,
            'failure_message' => null,
            'errors' => null,
        ])->save();
        $run->rows()->delete();

        $onlineStore = $this->onlineStorePublication();
        $run->forceFill([
            'online_store_publication_id' => $onlineStore['id'],
            'online_store_publication_name' => $onlineStore['name'],
        ])->save();

        $collectionAfter = null;
        $collectionCount = 0;
        $relationshipCount = 0;
        $errors = [];
        $selectedHandles = collect($run->selected_collection_handles ?? [])
            ->map(fn ($handle): string => strtolower(trim((string) $handle)))
            ->filter()
            ->flip();

        do {
            $data = $this->client->graphql($this->collectionsQuery(), [
                'first' => self::COLLECTION_PAGE_SIZE,
                'after' => $collectionAfter,
                'productFirst' => self::INITIAL_PRODUCT_PAGE_SIZE,
                'variantFirst' => self::INITIAL_VARIANT_PAGE_SIZE,
            ]);

            foreach ((array) data_get($data, 'collections.nodes', []) as $collection) {
                $handle = strtolower(trim((string) data_get($collection, 'handle')));
                if ($selectedHandles->isNotEmpty() && ! $selectedHandles->has($handle)) {
                    continue;
                }

                $collectionCount++;
                try {
                    [$collection, $publicationErrors] = $this->completeCollectionPublications($collection);
                    [$products, $collectionErrors] = $this->completeCollectionProducts($collection);
                    $errors = [...$errors, ...$publicationErrors, ...$collectionErrors];
                    $this->storeCollection($run, $collection, $products, $onlineStore['id']);
                    $relationshipCount += max(1, count($products));
                } catch (\Throwable $exception) {
                    $errors[] = $this->errorEntry($collection, $exception);
                    Log::error('Shopify collection mapping collection failed', end($errors));
                }
            }

            $pageInfo = (array) data_get($data, 'collections.pageInfo', []);
            $collectionAfter = ($pageInfo['hasNextPage'] ?? false)
                ? ($pageInfo['endCursor'] ?? null)
                : null;
        } while ($collectionAfter !== null);

        $run->forceFill([
            'status' => ShopifyCollectionProductReportRun::STATUS_COMPLETED,
            'collection_count' => $collectionCount,
            'relationship_count' => $relationshipCount,
            'failed_collection_count' => collect($errors)->pluck('collection_id')->filter()->unique()->count(),
            'errors' => $errors === [] ? null : $errors,
            'completed_at' => now(),
        ])->save();

        return $run->fresh() ?? $run;
    }

    /** @return array{id:string,name:string} */
    private function onlineStorePublication(): array
    {
        $after = null;
        do {
            $data = $this->client->graphql($this->publicationsQuery(), [
                'first' => 100,
                'after' => $after,
            ]);
            foreach ((array) data_get($data, 'publications.nodes', []) as $publication) {
                $labels = [
                    data_get($publication, 'name'),
                    data_get($publication, 'app.title'),
                    data_get($publication, 'catalog.title'),
                ];
                if (collect($labels)->contains(fn ($label): bool => $this->isOnlineStoreLabel($label))) {
                    return [
                        'id' => (string) data_get($publication, 'id'),
                        'name' => (string) (data_get($publication, 'name') ?: data_get($publication, 'app.title')),
                    ];
                }
            }

            $pageInfo = (array) data_get($data, 'publications.pageInfo', []);
            $after = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;
        } while ($after !== null);

        throw new \RuntimeException(
            'The Shopify Online Store publication could not be identified. Ensure the app has read_publications access.'
        );
    }

    private function isOnlineStoreLabel(mixed $label): bool
    {
        return in_array(strtolower(trim((string) $label)), ['online store', 'online_store'], true);
    }

    /** @return array{0:array<string,mixed>,1:array<int,array<string,mixed>>} */
    private function completeCollectionPublications(array $collection): array
    {
        $connection = (array) data_get($collection, 'resourcePublicationsV2', []);
        $nodes = (array) ($connection['nodes'] ?? []);
        $pageInfo = (array) ($connection['pageInfo'] ?? []);
        $after = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;
        $errors = [];

        while ($after !== null) {
            try {
                $data = $this->client->graphql($this->collectionPublicationsQuery(), [
                    'id' => (string) data_get($collection, 'id'),
                    'first' => 100,
                    'after' => $after,
                ]);
                $page = (array) data_get($data, 'node.resourcePublicationsV2', []);
                $nodes = [...$nodes, ...(array) ($page['nodes'] ?? [])];
                $pageInfo = (array) ($page['pageInfo'] ?? []);
                $after = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;
            } catch (\Throwable $exception) {
                $errors[] = $this->errorEntry($collection, $exception, 'publication pagination');
                Log::error('Shopify collection mapping publication pagination failed', end($errors));
                break;
            }
        }

        data_set($collection, 'resourcePublicationsV2', [
            'nodes' => $nodes,
            'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
        ]);

        return [$collection, $errors];
    }

    /**
     * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>}
     */
    private function completeCollectionProducts(array $collection): array
    {
        $products = (array) data_get($collection, 'products.nodes', []);
        $errors = [];
        $pageInfo = (array) data_get($collection, 'products.pageInfo', []);
        $after = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;

        while ($after !== null) {
            try {
                $data = $this->client->graphql($this->collectionProductsQuery(), [
                    'id' => (string) data_get($collection, 'id'),
                    'first' => self::PRODUCT_PAGE_SIZE,
                    'after' => $after,
                    'variantFirst' => self::INITIAL_VARIANT_PAGE_SIZE,
                ]);
                $connection = (array) data_get($data, 'node.products', []);
                $products = [...$products, ...(array) ($connection['nodes'] ?? [])];
                $pageInfo = (array) ($connection['pageInfo'] ?? []);
                $after = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;
            } catch (\Throwable $exception) {
                $errors[] = $this->errorEntry($collection, $exception, 'product pagination');
                Log::error('Shopify collection mapping product pagination failed', end($errors));
                break;
            }
        }

        $products = array_values(array_filter(
            $products,
            fn (array $product): bool => strtoupper(trim((string) data_get($product, 'status'))) === 'ACTIVE'
        ));

        foreach ($products as &$product) {
            try {
                $product['variants'] = $this->completeVariants($product);
            } catch (\Throwable $exception) {
                $errors[] = $this->errorEntry($collection, $exception, 'variant pagination', $product);
                Log::error('Shopify collection mapping variant pagination failed', end($errors));
            }
        }
        unset($product);

        return [$products, $errors];
    }

    /** @return array<string,mixed> */
    private function completeVariants(array $product): array
    {
        $connection = (array) data_get($product, 'variants', []);
        $nodes = (array) ($connection['nodes'] ?? []);
        $pageInfo = (array) ($connection['pageInfo'] ?? []);
        $after = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;

        while ($after !== null) {
            $data = $this->client->graphql($this->productVariantsQuery(), [
                'id' => (string) data_get($product, 'id'),
                'first' => self::VARIANT_PAGE_SIZE,
                'after' => $after,
            ]);
            $page = (array) data_get($data, 'node.variants', []);
            $nodes = [...$nodes, ...(array) ($page['nodes'] ?? [])];
            $pageInfo = (array) ($page['pageInfo'] ?? []);
            $after = ($pageInfo['hasNextPage'] ?? false) ? ($pageInfo['endCursor'] ?? null) : null;
        }

        return ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]];
    }

    private function storeCollection(
        ShopifyCollectionProductReportRun $run,
        array $collection,
        array $products,
        string $onlineStorePublicationId,
    ): void {
        $publications = (array) data_get($collection, 'resourcePublicationsV2.nodes', []);
        $onlinePublication = collect($publications)->first(function ($publication) use ($onlineStorePublicationId): bool {
            if ((string) data_get($publication, 'publication.id') !== $onlineStorePublicationId) {
                return false;
            }
            if (! (bool) data_get($publication, 'isPublished', false)) {
                return false;
            }
            $publishDate = data_get($publication, 'publishDate');

            return blank($publishDate) || Carbon::parse((string) $publishDate)->lte(now());
        });
        $collectionData = $this->collectionData($collection, $publications, $onlinePublication);
        $products = $products === [] ? [null] : $products;

        $rows = [];
        foreach ($products as $product) {
            $rows[] = [
                'shopify_collection_product_report_run_id' => $run->id,
                ...$collectionData,
                ...$this->productData(is_array($product) ? $product : null),
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 25) as $chunk) {
            ShopifyCollectionProductReportRow::query()->insert($chunk);
        }
    }

    /** @return array<string,mixed> */
    private function collectionData(array $collection, array $publications, mixed $onlinePublication): array
    {
        $handle = trim((string) data_get($collection, 'handle'));

        return [
            'collection_id' => (string) data_get($collection, 'id'),
            'collection_title' => data_get($collection, 'title'),
            'collection_handle' => $handle ?: null,
            'collection_url' => $handle ? $this->storefrontUrl("collections/{$handle}") : null,
            'collection_description' => data_get($collection, 'description'),
            'collection_description_html' => data_get($collection, 'descriptionHtml'),
            'collection_sort_order' => data_get($collection, 'sortOrder'),
            'collection_template_suffix' => data_get($collection, 'templateSuffix'),
            'collection_product_count' => (int) data_get($collection, 'productsCount.count', 0),
            'collection_updated_at' => $this->databaseDateTime(data_get($collection, 'updatedAt')),
            'collection_image_url' => data_get($collection, 'image.url'),
            'collection_image_alt' => data_get($collection, 'image.altText'),
            'collection_image_width' => data_get($collection, 'image.width'),
            'collection_image_height' => data_get($collection, 'image.height'),
            'collection_published_online' => $onlinePublication !== null,
            'collection_online_publish_date' => $this->databaseDateTime(data_get($onlinePublication, 'publishDate')),
            'collection_publications' => json_encode($publications, JSON_THROW_ON_ERROR),
        ];
    }

    /** @return array<string,mixed> */
    private function productData(?array $product): array
    {
        if ($product === null) {
            return [
                'product_id' => null, 'product_title' => null, 'product_handle' => null,
                'product_url' => null, 'product_online_store_url' => null, 'product_status' => null,
                'vendor' => null, 'product_type' => null, 'product_created_at' => null,
                'product_updated_at' => null, 'product_published_at' => null, 'tags' => null,
                'total_inventory' => null, 'featured_image_url' => null, 'featured_image_alt' => null,
                'product_category_id' => null, 'product_category_name' => null, 'seo_title' => null,
                'seo_description' => null, 'variant_count' => 0, 'sku_summary' => null, 'variants' => null,
            ];
        }

        $handle = trim((string) data_get($product, 'handle'));
        $onlineUrl = trim((string) data_get($product, 'onlineStoreUrl'));
        $variants = (array) data_get($product, 'variants.nodes', []);
        $skus = collect($variants)->pluck('sku')->map(fn ($sku) => trim((string) $sku))->filter()->unique()->values();

        return [
            'product_id' => data_get($product, 'id'),
            'product_title' => data_get($product, 'title'),
            'product_handle' => $handle ?: null,
            'product_url' => $onlineUrl ?: ($handle ? $this->storefrontUrl("products/{$handle}") : null),
            'product_online_store_url' => $onlineUrl ?: null,
            'product_status' => data_get($product, 'status'),
            'vendor' => data_get($product, 'vendor'),
            'product_type' => data_get($product, 'productType'),
            'product_created_at' => $this->databaseDateTime(data_get($product, 'createdAt')),
            'product_updated_at' => $this->databaseDateTime(data_get($product, 'updatedAt')),
            'product_published_at' => $this->databaseDateTime(data_get($product, 'publishedAt')),
            'tags' => json_encode((array) data_get($product, 'tags', []), JSON_THROW_ON_ERROR),
            'total_inventory' => data_get($product, 'totalInventory'),
            'featured_image_url' => data_get($product, 'featuredImage.url'),
            'featured_image_alt' => data_get($product, 'featuredImage.altText'),
            'product_category_id' => data_get($product, 'category.id'),
            'product_category_name' => data_get($product, 'category.fullName'),
            'seo_title' => data_get($product, 'seo.title'),
            'seo_description' => data_get($product, 'seo.description'),
            'variant_count' => count($variants),
            'sku_summary' => $skus->isEmpty() ? null : $skus->implode(', '),
            'variants' => json_encode($variants, JSON_THROW_ON_ERROR),
        ];
    }

    private function storefrontUrl(string $path): string
    {
        return rtrim((string) config('services.shopify.storefront_url', 'https://leighavenue.co.za'), '/')
            .'/'.ltrim($path, '/');
    }

    private function databaseDateTime(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        return Carbon::parse((string) $value)->utc()->format('Y-m-d H:i:s');
    }

    /** @return array<string,mixed> */
    private function errorEntry(array $collection, \Throwable $exception, string $stage = 'collection', ?array $product = null): array
    {
        return [
            'stage' => $stage,
            'collection_id' => data_get($collection, 'id'),
            'collection_title' => data_get($collection, 'title'),
            'product_id' => data_get($product, 'id'),
            'message' => Str::limit(Str::before($exception->getMessage(), ' (Connection:'), 1000),
        ];
    }

    private function publicationsQuery(): string
    {
        return <<<'GQL'
query OnlineStorePublication($first: Int!, $after: String) {
  publications(first: $first, after: $after, catalogType: APP) {
    nodes { id name app { title } catalog { title status } }
    pageInfo { hasNextPage endCursor }
  }
}
GQL;
    }

    private function collectionsQuery(): string
    {
        return <<<'GQL'
query CollectionProductMapping($first: Int!, $after: String, $productFirst: Int!, $variantFirst: Int!) {
  collections(first: $first, after: $after) {
    nodes {
      id title handle description descriptionHtml updatedAt sortOrder templateSuffix
      productsCount { count }
      image { url altText width height }
      resourcePublicationsV2(first: 100) {
        nodes { isPublished publishDate publication { id name catalog { title status } } }
        pageInfo { hasNextPage endCursor }
      }
      products(first: $productFirst) {
        nodes { ...MappingProduct }
        pageInfo { hasNextPage endCursor }
      }
    }
    pageInfo { hasNextPage endCursor }
  }
}

fragment MappingProduct on Product {
  id title handle status vendor productType createdAt updatedAt publishedAt tags totalInventory onlineStoreUrl
  featuredImage { url altText }
  category { id fullName }
  seo { title description }
  variants(first: $variantFirst) {
    nodes { id title sku barcode price compareAtPrice inventoryQuantity availableForSale }
    pageInfo { hasNextPage endCursor }
  }
}
GQL;
    }

    private function collectionPublicationsQuery(): string
    {
        return <<<'GQL'
query CollectionPublications($id: ID!, $first: Int!, $after: String) {
  node(id: $id) {
    ... on Collection {
      resourcePublicationsV2(first: $first, after: $after) {
        nodes { isPublished publishDate publication { id name catalog { title status } } }
        pageInfo { hasNextPage endCursor }
      }
    }
  }
}
GQL;
    }

    private function collectionProductsQuery(): string
    {
        return <<<'GQL'
query CollectionProducts($id: ID!, $first: Int!, $after: String, $variantFirst: Int!) {
  node(id: $id) {
    ... on Collection {
      products(first: $first, after: $after) {
        nodes {
          id title handle status vendor productType createdAt updatedAt publishedAt tags totalInventory onlineStoreUrl
          featuredImage { url altText }
          category { id fullName }
          seo { title description }
          variants(first: $variantFirst) {
            nodes { id title sku barcode price compareAtPrice inventoryQuantity availableForSale }
            pageInfo { hasNextPage endCursor }
          }
        }
        pageInfo { hasNextPage endCursor }
      }
    }
  }
}
GQL;
    }

    private function productVariantsQuery(): string
    {
        return <<<'GQL'
query ProductVariants($id: ID!, $first: Int!, $after: String) {
  node(id: $id) {
    ... on Product {
      variants(first: $first, after: $after) {
        nodes { id title sku barcode price compareAtPrice inventoryQuantity availableForSale }
        pageInfo { hasNextPage endCursor }
      }
    }
  }
}
GQL;
    }
}
