<?php

use App\Filament\Exports\ShopifyCollectionProductReportRowExporter;
use App\Models\ShopifyCollectionProductReportRow;
use App\Services\ShopifyCollectionProductReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('exports only the requested collection mapping columns', function (): void {
    expect(collect(ShopifyCollectionProductReportRowExporter::getColumns())
        ->map(fn ($column): string => $column->getName())
        ->all())->toBe([
            'collection_title',
            'collection_handle',
            'collection_url',
            'collection_sort_order',
            'product_title',
            'product_url',
            'product_status',
            'main_collection',
            'product_type',
            'total_inventory',
            'product_created_at',
        ]);
});

it('stores a normalized unique set of selected collection handles on a report run', function (): void {
    $run = app(ShopifyCollectionProductReportService::class)
        ->createRun(null, [' Bracelets ', 'bracelets', 'sale']);

    expect($run->selected_collection_handles)->toBe(['bracelets', 'sale']);
});

it('maps fully paginated collections and products while auditing online store visibility', function () {
    config()->set([
        'services.shopify.shop' => 'leigh-avenue.myshopify.com',
        'services.shopify.admin_access_token' => 'test-token',
        'services.shopify.api_version' => '2026-01',
        'services.shopify.storefront_url' => 'https://leighavenue.co.za',
    ]);

    $publication = [
        'id' => 'gid://shopify/Publication/online',
        'name' => 'Online Store',
        'app' => ['title' => 'Online Store'],
    ];
    $published = [[
        'isPublished' => true,
        'publishDate' => '2026-08-01T08:00:00Z',
        'publication' => ['id' => $publication['id'], 'name' => 'Online Store'],
    ]];
    $scheduled = [[
        'isPublished' => false,
        'publishDate' => '2099-08-01T08:00:00Z',
        'publication' => ['id' => $publication['id'], 'name' => 'Online Store'],
    ]];

    $product = fn (string $id, string $title, string $handle, ?string $url, bool $moreVariants = false): array => [
        'id' => $id,
        'title' => $title,
        'handle' => $handle,
        'status' => 'ACTIVE',
        'vendor' => 'Leigh Avenue',
        'productType' => 'Jewellery',
        'createdAt' => '2026-01-01T00:00:00Z',
        'updatedAt' => '2026-08-01T00:00:00Z',
        'publishedAt' => '2026-01-02T00:00:00Z',
        'tags' => ['gold'],
        'totalInventory' => 5,
        'onlineStoreUrl' => $url,
        'featuredImage' => ['url' => 'https://cdn.test/image.jpg', 'altText' => 'Image'],
        'category' => ['id' => 'gid://shopify/TaxonomyCategory/1', 'fullName' => 'Apparel > Jewellery'],
        'seo' => ['title' => $title, 'description' => 'SEO'],
        'variants' => [
            'nodes' => [[
                'id' => "{$id}/Variant/1", 'title' => 'Default', 'sku' => 'SKU-1',
                'barcode' => null, 'price' => '10.00', 'compareAtPrice' => null,
                'inventoryQuantity' => 5, 'availableForSale' => true,
            ]],
            'pageInfo' => ['hasNextPage' => $moreVariants, 'endCursor' => $moreVariants ? 'variant-1' : null],
        ],
    ];

    $braceletProduct = $product(
        'gid://shopify/Product/1',
        'African Queen Gold Bracelet',
        'african-queen-gold-bracelet',
        'https://leighavenue.co.za/products/african-queen-gold-bracelet',
        true,
    );
    $fallbackProduct = $product('gid://shopify/Product/2', 'Fallback URL Product', 'fallback-url-product', null);
    $archivedProduct = $product('gid://shopify/Product/3', 'Archived Product', 'archived-product', null);
    $archivedProduct['status'] = 'ARCHIVED';

    Http::fake(function (Request $request) use ($publication, $published, $scheduled, $braceletProduct, $fallbackProduct, $archivedProduct) {
        $payload = $request->data();
        $query = (string) ($payload['query'] ?? '');
        $variables = (array) ($payload['variables'] ?? []);

        if (str_contains($query, 'query OnlineStorePublication')) {
            return Http::response(['data' => ['publications' => [
                'nodes' => [$publication],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            ]]]);
        }

        if (str_contains($query, 'query CollectionProductMapping') && ($variables['after'] ?? null) === null) {
            return Http::response(['data' => ['collections' => [
                'nodes' => [[
                    'id' => 'gid://shopify/Collection/1', 'title' => 'Bracelets', 'handle' => 'bracelets',
                    'description' => 'Bracelets', 'descriptionHtml' => '<p>Bracelets</p>',
                    'updatedAt' => '2026-08-01T00:00:00Z', 'sortOrder' => 'MANUAL', 'templateSuffix' => null,
                    'productsCount' => ['count' => 2], 'image' => null,
                    'resourcePublicationsV2' => ['nodes' => $published, 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
                    'products' => ['nodes' => [$braceletProduct], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'product-1']],
                ], [
                    'id' => 'gid://shopify/Collection/3', 'title' => 'Hidden Empty', 'handle' => 'hidden-empty',
                    'productsCount' => ['count' => 0], 'resourcePublicationsV2' => ['nodes' => $scheduled, 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
                    'products' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
                ]],
                'pageInfo' => ['hasNextPage' => true, 'endCursor' => 'collection-1'],
            ]]]);
        }

        if (str_contains($query, 'query CollectionProductMapping')) {
            return Http::response(['data' => ['collections' => [
                'nodes' => [[
                    'id' => 'gid://shopify/Collection/2', 'title' => 'Sale', 'handle' => 'sale',
                    'productsCount' => ['count' => 2], 'resourcePublicationsV2' => ['nodes' => $published, 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
                    'products' => ['nodes' => [$braceletProduct, $archivedProduct], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
                ]],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            ]]]);
        }

        if (str_contains($query, 'query CollectionProducts')) {
            return Http::response(['data' => ['node' => ['products' => [
                'nodes' => [$fallbackProduct],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            ]]]]);
        }

        if (str_contains($query, 'query ProductVariants')) {
            return Http::response(['data' => ['node' => ['variants' => [
                'nodes' => [[
                    'id' => 'gid://shopify/ProductVariant/2', 'title' => 'Second', 'sku' => 'SKU-2',
                    'barcode' => null, 'price' => '12.00', 'compareAtPrice' => '15.00',
                    'inventoryQuantity' => 2, 'availableForSale' => true,
                ]],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            ]]]]);
        }

        throw new RuntimeException('Unexpected Shopify GraphQL request.');
    });

    $service = app(ShopifyCollectionProductReportService::class);
    $run = $service->generate($service->createRun());

    expect($run->collection_count)->toBe(3)
        ->and($run->relationship_count)->toBe(4)
        ->and($run->failed_collection_count)->toBe(0)
        ->and(ShopifyCollectionProductReportRow::query()->count())->toBe(4)
        ->and(ShopifyCollectionProductReportRow::query()->where('collection_published_online', true)->count())->toBe(3)
        ->and(ShopifyCollectionProductReportRow::query()->where('collection_title', 'Hidden Empty')->value('product_id'))->toBeNull()
        ->and(ShopifyCollectionProductReportRow::query()->where('product_title', 'Fallback URL Product')->value('product_url'))
        ->toBe('https://leighavenue.co.za/products/fallback-url-product')
        ->and(ShopifyCollectionProductReportRow::query()->where('product_id', 'gid://shopify/Product/1')->count())->toBe(2)
        ->and(ShopifyCollectionProductReportRow::query()->where('product_status', '!=', 'ACTIVE')->count())->toBe(0)
        ->and(ShopifyCollectionProductReportRow::query()->where('product_id', 'gid://shopify/Product/3')->exists())->toBeFalse()
        ->and(ShopifyCollectionProductReportRow::query()->where('collection_title', 'Bracelets')->where('product_id', 'gid://shopify/Product/1')->value('variant_count'))->toBe(2)
        ->and(ShopifyCollectionProductReportRow::query()->where('collection_title', 'Bracelets')->first()?->collection_online_publish_date?->format('Y-m-d H:i:s'))
        ->toBe('2026-08-01 08:00:00');
});

it('surfaces Shopify GraphQL errors instead of producing an unsafe visibility report', function () {
    config()->set([
        'services.shopify.shop' => 'leigh-avenue.myshopify.com',
        'services.shopify.admin_access_token' => 'test-token',
    ]);
    Http::fake(fn () => Http::response(['errors' => [['message' => 'Access denied for publications field.']]]));

    $service = app(ShopifyCollectionProductReportService::class);

    expect(fn () => $service->generate($service->createRun()))
        ->toThrow(RuntimeException::class, 'Access denied for publications field.');
});
