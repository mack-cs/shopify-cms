<?php

use App\Jobs\ProductShopifyUpdateJob;
use App\Models\Import;
use App\Models\Product;
use App\Models\ProductPartialApprovalRequest;
use App\Models\ShopifyMetafield;
use App\Models\ShopifyRow;
use App\Models\User;
use App\Models\Variant;
use App\Services\HeaderStore;
use App\Services\ProductShopifyUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function guardProduct(array $overrides = []): Product
{
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'guard.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
    ]);

    return Product::withoutEvents(fn (): Product => Product::create(array_merge([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/9001',
        'handle' => 'guard-product',
        'title' => 'Guard Product',
        'vendor' => 'Elevated Basics Bundles',
        'type' => 'Bracelets',
        'tags' => 'bracelets, elevated-basics-bundles, custom-gift',
        'body_html' => '<p>Original description</p>',
        'status' => 'active',
        'approval_version' => 1,
    ], $overrides)));
}

function approveGuardField(Product $product, array $scopes, array $coreFields): void
{
    ProductPartialApprovalRequest::query()->create([
        'product_id' => $product->id,
        'approval_version' => $product->approval_version,
        'requested_by' => User::factory()->create()->id,
        'approved_by' => User::factory()->create()->id,
        'status' => ProductPartialApprovalRequest::STATUS_APPROVED,
        'scopes' => $scopes,
        'core_fields' => $coreFields,
        'approved_at' => now(),
    ]);
}

function fakeGuardShopify(array &$queries, array &$payloads): void
{
    config()->set('services.shopify.shop', 'test-shop.myshopify.com');
    config()->set('services.shopify.admin_access_token', 'test-token');
    config()->set('services.shopify.api_version', '2026-01');

    Http::fake(function ($request) use (&$queries, &$payloads) {
        $body = $request->data();
        $query = (string) ($body['query'] ?? '');
        $variables = (array) ($body['variables'] ?? []);
        $queries[] = $query;
        $payloads[] = $variables;

        if (str_contains($query, 'inventorySetQuantities')
            || str_contains($query, 'inventoryAdjustQuantities')
            || str_contains($query, 'inventoryItemUpdate')
            || str_contains($query, 'collectionAddProducts')
            || str_contains($query, 'collectionRemoveProducts')
            || str_contains($query, 'tagsAdd')
            || str_contains($query, 'tagsRemove')) {
            throw new RuntimeException('Unauthorized Shopify write: '.$query);
        }

        if (str_contains($query, 'mutation ProductUpdate')) {
            return Http::response(['data' => ['productUpdate' => ['product' => ['id' => 'gid://shopify/Product/9001', 'handle' => 'guard-product'], 'userErrors' => []]]]);
        }

        if (str_contains($query, 'query ProductByIdDetails')) {
            return Http::response(['data' => ['product' => [
                'id' => 'gid://shopify/Product/9001',
                'options' => [],
                'category' => ['id' => 'gid://shopify/TaxonomyCategory/aa-6-3', 'name' => 'Jewelry > Bracelets'],
                'productCategory' => null,
                'variants' => ['nodes' => [[
                    'id' => 'gid://shopify/ProductVariant/9001',
                    'sku' => 'GUARD-SKU',
                    'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/9001'],
                    'selectedOptions' => [],
                ]]],
                'media' => ['nodes' => []],
                'images' => ['nodes' => []],
            ]]]);
        }

        if (str_contains($query, 'query ProductByIdMetafields')) {
            return Http::response(['data' => ['product' => ['metafields' => ['nodes' => []]]]]);
        }

        if (str_contains($query, 'mutation MetafieldsSet')) {
            return Http::response(['data' => ['metafieldsSet' => ['metafields' => [['id' => 'gid://shopify/Metafield/1']], 'userErrors' => []]]]);
        }

        if (str_contains($query, 'productVariantsBulkUpdate')) {
            return Http::response(['data' => ['productVariantsBulkUpdate' => ['productVariants' => [['id' => 'gid://shopify/ProductVariant/9001']], 'userErrors' => []]]]);
        }

        throw new RuntimeException('Unexpected Shopify GraphQL call: '.$query);
    });
}

function guardQueriesContain(array $queries, string $needle): bool
{
    return collect($queries)->contains(fn (string $query): bool => str_contains($query, $needle));
}

it('writes only the approved metafield and leaves tags, price, and inventory untouched', function (): void {
    $product = guardProduct();
    Variant::withoutEvents(fn () => Variant::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/9001',
        'sku' => 'GUARD-SKU',
        'price' => '20.00',
        'compare_at_price' => '30.00',
        'inventory_qty' => 9,
        'inventory_tracked' => true,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'position' => 1,
    ]));
    ShopifyRow::create([
        'import_id' => $product->import_id,
        'row_index' => 1,
        'handle' => $product->handle,
        'row_type' => 'product_primary',
        'data' => [
            HeaderStore::TITLE => 'Changed title',
            HeaderStore::TAGS => 'bracelets, necklaces',
            HeaderStore::JEWELRY_MATERIAL => 'Gold',
            HeaderStore::BEAD_COLOUR_FINISH => 'pearlised',
            HeaderStore::VARIANT_INVENTORY_QTY => '9',
            HeaderStore::VARIANT_PRICE => '99.00',
        ],
    ]);
    ShopifyMetafield::create([
        'import_id' => $product->import_id,
        'handle' => $product->handle,
        'namespace' => 'shopify',
        'key' => 'jewelry-material',
        'type' => 'single_line_text_field',
        'value' => '',
    ]);
    ShopifyMetafield::create([
        'import_id' => $product->import_id,
        'handle' => $product->handle,
        'namespace' => 'stiletto',
        'key' => 'bead_colour_finish',
        'type' => 'single_line_text_field',
        'value' => '',
    ]);
    approveGuardField($product, [ProductShopifyUpdater::SYNC_SCOPE_METAFIELDS, ProductShopifyUpdater::SYNC_SCOPE_PRICE], [
        ProductShopifyUpdater::CORE_FIELD_JEWELRY_MATERIAL,
    ]);
    approveGuardField($product, [ProductShopifyUpdater::SYNC_SCOPE_PRICE], []);

    $queries = [];
    $payloads = [];
    fakeGuardShopify($queries, $payloads);

    $result = app(ProductShopifyUpdater::class)->updateApprovedProducts(
        collect([$product]),
        [ProductShopifyUpdater::SYNC_SCOPE_METAFIELDS],
        [ProductShopifyUpdater::CORE_FIELD_JEWELRY_MATERIAL],
        'batch-guard',
        User::factory()->create()->id,
    );

    $metafieldPayload = collect($payloads)->first(fn (array $payload): bool => isset($payload['metafields']));

    expect($result['updated'])->toBe(1)
        ->and($result['failed'])->toBe(0)
        ->and(guardQueriesContain($queries, 'mutation ProductUpdate'))->toBeFalse()
        ->and(guardQueriesContain($queries, 'productVariantsBulkUpdate'))->toBeFalse()
        ->and(guardQueriesContain($queries, 'inventorySetQuantities'))->toBeFalse()
        ->and($metafieldPayload['metafields'])->toHaveCount(1)
        ->and($metafieldPayload['metafields'][0]['key'])->toBe('jewelry-material')
        ->and($metafieldPayload['metafields'][0]['value'])->toBe('Gold');
});

it('syncs nothing for an empty scope list and only the stored price approval when no scope list is supplied', function (): void {
    $product = guardProduct();
    Variant::withoutEvents(fn () => Variant::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/9001',
        'sku' => 'GUARD-SKU',
        'price' => '20.00',
        'inventory_qty' => 4,
        'inventory_tracked' => true,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'position' => 1,
    ]));
    approveGuardField($product, [ProductShopifyUpdater::SYNC_SCOPE_PRICE], []);

    $queries = [];
    $payloads = [];
    fakeGuardShopify($queries, $payloads);

    $empty = app(ProductShopifyUpdater::class)->updateApprovedProducts(collect([$product]), []);
    $missing = app(ProductShopifyUpdater::class)->updateApprovedProducts(collect([$product]), null, null);

    expect($empty['updated'])->toBe(0)
        ->and($missing['updated'])->toBe(1)
        ->and(guardQueriesContain($queries, 'productVariantsBulkUpdate'))->toBeTrue()
        ->and(guardQueriesContain($queries, 'mutation ProductUpdate'))->toBeFalse()
        ->and(guardQueriesContain($queries, 'mutation MetafieldsSet'))->toBeFalse();

    $pricePayload = collect($payloads)->first(fn (array $payload): bool => isset($payload['variants']));
    expect($pricePayload['variants'][0])->toHaveKey('price')
        ->and($pricePayload['variants'][0])->not->toHaveKey('compareAtPrice')
        ->and(json_encode($pricePayload))->not->toContain('inventory');
});

it('keeps a retried explicit approval limited to the original fields', function (): void {
    $product = guardProduct();
    Variant::withoutEvents(fn () => Variant::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/9001',
        'sku' => 'GUARD-SKU',
        'price' => '20.00',
        'inventory_qty' => 4,
        'inventory_tracked' => true,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'position' => 1,
    ]));
    ShopifyRow::create([
        'import_id' => $product->import_id,
        'row_index' => 1,
        'handle' => $product->handle,
        'row_type' => 'product_primary',
        'data' => [
            HeaderStore::TITLE => 'Should stay',
            HeaderStore::TAGS => 'bracelets',
            HeaderStore::VARIANT_PRICE => '44.00',
            HeaderStore::VARIANT_INVENTORY_QTY => '4',
        ],
    ]);
    approveGuardField($product, [
        ProductShopifyUpdater::SYNC_SCOPE_PRODUCT,
        ProductShopifyUpdater::SYNC_SCOPE_PRICE,
        ProductShopifyUpdater::SYNC_SCOPE_VARIANTS,
    ], [ProductShopifyUpdater::CORE_FIELD_TITLE, ProductShopifyUpdater::CORE_FIELD_TAGS]);

    $scopes = [ProductShopifyUpdater::SYNC_SCOPE_PRODUCT];
    $fields = [ProductShopifyUpdater::CORE_FIELD_TITLE];
    $job = new ProductShopifyUpdateJob([$product->id], null, $scopes, $fields, 'retry-batch');

    $queries = [];
    $payloads = [];
    fakeGuardShopify($queries, $payloads);

    $result = app(ProductShopifyUpdater::class)->updateApprovedProducts(
        collect([$product->fresh()]),
        $job->scopes,
        $job->coreFields,
        $job->syncBatchId,
        $job->userId,
    );

    $productUpdate = collect($payloads)->first(fn (array $payload): bool => isset($payload['input']['title']));

    expect($job->scopes)->toBe($scopes)
        ->and($job->coreFields)->toBe($fields)
        ->and($result['updated'])->toBe(1)
        ->and($productUpdate['input'])->toHaveKey('title')
        ->and($productUpdate['input'])->not->toHaveKey('tags')
        ->and($productUpdate['input'])->not->toHaveKey('descriptionHtml')
        ->and(guardQueriesContain($queries, 'productVariantsBulkUpdate'))->toBeFalse()
        ->and(guardQueriesContain($queries, 'inventorySetQuantities'))->toBeFalse();
});

it('sends one approved variant field and never writes inventory from product sync', function (): void {
    $product = guardProduct();
    Variant::withoutEvents(fn () => Variant::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/9001',
        'sku' => 'GUARD-SKU',
        'barcode' => 'GUARD-BARCODE',
        'price' => '20.00',
        'compare_at_price' => '30.00',
        'weight' => 12,
        'weight_unit' => 'g',
        'inventory_qty' => 9,
        'inventory_tracked' => true,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'position' => 1,
    ]));
    ShopifyRow::create([
        'import_id' => $product->import_id,
        'row_index' => 1,
        'handle' => $product->handle,
        'row_type' => 'product_primary',
        'data' => [
            HeaderStore::COST_PER_ITEM => '4.50',
            HeaderStore::VARIANT_INVENTORY_QTY => '9',
        ],
    ]);
    ShopifyRow::create([
        'import_id' => $product->import_id,
        'row_index' => 2,
        'handle' => $product->handle,
        'row_type' => 'variant',
        'data' => [
            HeaderStore::VARIANT_SKU => 'GUARD-SKU',
            HeaderStore::VARIANT_BARCODE => 'GUARD-BARCODE',
            HeaderStore::VARIANT_PRICE => '20.00',
            HeaderStore::VARIANT_COMPARE_AT => '30.00',
            HeaderStore::VARIANT_GRAMS => '12',
            HeaderStore::VARIANT_WEIGHT_UNIT => 'g',
            HeaderStore::COST_PER_ITEM => '4.50',
            HeaderStore::VARIANT_INVENTORY_QTY => '9',
            HeaderStore::OPTION1_NAME => 'Size',
            HeaderStore::OPTION1_VALUE => 'Medium',
        ],
    ]);
    approveGuardField($product, [ProductShopifyUpdater::SYNC_SCOPE_VARIANTS], [
        ProductShopifyUpdater::VARIANT_FIELD_SKU,
        ProductShopifyUpdater::VARIANT_FIELD_BARCODE,
        ProductShopifyUpdater::VARIANT_FIELD_OPTIONS,
        ProductShopifyUpdater::VARIANT_FIELD_WEIGHT,
        ProductShopifyUpdater::VARIANT_FIELD_COST,
    ]);

    $queries = [];
    $payloads = [];
    fakeGuardShopify($queries, $payloads);

    $skuOnly = app(ProductShopifyUpdater::class)->updateApprovedProducts(
        collect([$product]),
        [ProductShopifyUpdater::SYNC_SCOPE_VARIANTS],
        [ProductShopifyUpdater::VARIANT_FIELD_SKU],
    );

    $variantPayload = collect($payloads)->first(fn (array $payload): bool => isset($payload['variants']));
    $sentVariant = $variantPayload['variants'][0];

    expect($skuOnly['updated'])->toBe(1)
        ->and($skuOnly['failed'])->toBe(0)
        ->and($sentVariant)->toBe([
            'id' => 'gid://shopify/ProductVariant/9001',
            'barcode' => 'GUARD-SKU',
            'inventoryItem' => ['sku' => 'GUARD-SKU'],
        ])
        ->and(json_encode($payloads))->not->toContain('inventorySetQuantities')
        ->and(guardQueriesContain($queries, 'inventorySetQuantities'))->toBeFalse()
        ->and(guardQueriesContain($queries, 'inventoryItemUpdate'))->toBeFalse()
        ->and(guardQueriesContain($queries, 'productVariantsBulkCreate'))->toBeFalse();
});
