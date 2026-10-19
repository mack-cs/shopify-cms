<?php

use App\Models\Import;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\ProductInventorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('sets on hand and refreshes Shopify available committed and on hand quantities', function (): void {
    config()->set('services.shopify.shop', 'test-shop.myshopify.com');
    config()->set('services.shopify.admin_access_token', 'test-token');
    config()->set('services.shopify.api_version', '2026-01');

    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'inventory.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
    ]);
    $product = Product::withoutEvents(fn (): Product => Product::query()->create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/9001', 'handle' => 'inventory-state-test',
        'title' => 'Inventory State Test', 'status' => 'active',
    ]));
    $variant = Variant::withoutEvents(fn (): Variant => Variant::query()->create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/9001',
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/9001',
        'sku' => 'STATE-9001', 'inventory_tracked' => true,
        'inventory_qty' => 4, 'current_available_quantity' => 4,
        'current_committed_quantity' => 2, 'current_on_hand_quantity' => 10,
        'inventory_local_dirty' => true,
    ]));

    $setInput = null;
    $productReads = 0;
    Http::fake(function ($request) use (&$setInput, &$productReads) {
        $query = (string) ($request->data()['query'] ?? '');
        $variables = (array) ($request->data()['variables'] ?? []);

        if (str_contains($query, 'query ProductInventoryById')) {
            $productReads++;
            expect($query)->toContain('quantities(names: ["available", "committed", "on_hand"])');

            return Http::response(['data' => ['product' => [
                'id' => 'gid://shopify/Product/9001', 'status' => 'ACTIVE',
                'createdAt' => '2026-01-01T00:00:00Z',
                'variants' => ['nodes' => [[
                    'id' => 'gid://shopify/ProductVariant/9001', 'sku' => 'STATE-9001',
                    'availableForSale' => true, 'price' => '100.00', 'compareAtPrice' => null,
                    'inventoryQuantity' => 8,
                    'inventoryItem' => [
                        'id' => 'gid://shopify/InventoryItem/9001', 'tracked' => true,
                        'inventoryLevels' => ['nodes' => [[
                            'location' => ['id' => 'gid://shopify/Location/1'],
                            'quantities' => [
                                ['name' => 'available', 'quantity' => 8],
                                ['name' => 'committed', 'quantity' => 2],
                                ['name' => 'on_hand', 'quantity' => 10],
                            ],
                        ]]],
                    ],
                ]]],
            ]]]);
        }
        if (str_contains($query, 'mutation InventoryItemTrackingUpdate')) {
            return Http::response(['data' => ['inventoryItemUpdate' => ['userErrors' => []]]]);
        }
        if (str_contains($query, 'query Locations')) {
            return Http::response(['data' => ['locations' => ['nodes' => [[
                'id' => 'gid://shopify/Location/1',
            ]]]]]);
        }
        if (str_contains($query, 'mutation InventorySetQuantities')) {
            $setInput = $variables['input'] ?? null;

            return Http::response(['data' => ['inventorySetQuantities' => ['userErrors' => []]]]);
        }
        if (str_contains($query, 'mutation ProductStatusUpdate')) {
            return Http::response(['data' => ['productUpdate' => ['userErrors' => []]]]);
        }

        throw new RuntimeException('Unexpected Shopify GraphQL request.');
    });

    $result = app(ProductInventorySyncService::class)->syncVariants(collect([$variant]));

    expect($result['failed'])->toBe(0)
        ->and($productReads)->toBe(2)
        ->and($setInput)->toMatchArray([
            'name' => 'on_hand',
            'reason' => 'correction',
            'quantities' => [[
                'inventoryItemId' => 'gid://shopify/InventoryItem/9001',
                'locationId' => 'gid://shopify/Location/1',
                'quantity' => 10,
            ]],
        ]);

    $variant->refresh();
    expect($variant->inventory_qty)->toBe(8)
        ->and($variant->current_available_quantity)->toBe(8)
        ->and($variant->current_committed_quantity)->toBe(2)
        ->and($variant->current_on_hand_quantity)->toBe(10)
        ->and($variant->inventory_location_count)->toBe(1);
});

it('updates a draft product on hand without changing its draft status', function (): void {
    config()->set('services.shopify.shop', 'test-shop.myshopify.com');
    config()->set('services.shopify.admin_access_token', 'test-token');
    config()->set('services.shopify.api_version', '2026-01');
    config()->set('services.shopify.inventory_location_id', 'gid://shopify/Location/4');

    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'draft-inventory.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
    ]);
    $product = Product::withoutEvents(fn (): Product => Product::query()->create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/9101',
        'handle' => 'draft-inventory',
        'title' => 'Draft Inventory',
        'status' => 'draft',
    ]));
    $variant = Variant::withoutEvents(fn (): Variant => Variant::query()->create([
        'product_id' => $product->id,
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/9101',
        'sku' => 'SHARED-SKU',
        'inventory_tracked' => true,
        'current_on_hand_quantity' => 3,
        'inventory_local_dirty' => true,
    ]));

    $setInput = null;
    $statusMutations = 0;
    Http::fake(function ($request) use (&$setInput, &$statusMutations) {
        $query = (string) ($request->data()['query'] ?? '');
        $variables = (array) ($request->data()['variables'] ?? []);

        if (str_contains($query, 'mutation ProductStatusUpdate') || str_contains($query, 'productUpdate')) {
            $statusMutations++;

            return Http::response(['data' => ['productUpdate' => ['userErrors' => []]]]);
        }

        if (str_contains($query, 'query ProductInventoryById')) {
            return Http::response(['data' => ['product' => [
                'id' => 'gid://shopify/Product/9101',
                'status' => 'DRAFT',
                'createdAt' => '2026-01-01T00:00:00Z',
                'variants' => ['nodes' => [
                    [
                        'id' => 'gid://shopify/ProductVariant/other',
                        'sku' => 'SHARED-SKU',
                        'availableForSale' => false,
                        'price' => '10.00',
                        'compareAtPrice' => null,
                        'inventoryQuantity' => 1,
                        'inventoryPolicy' => 'DENY',
                        'inventoryItem' => [
                            'id' => 'gid://shopify/InventoryItem/other',
                            'tracked' => true,
                            'inventoryLevels' => ['nodes' => [[
                                'location' => ['id' => 'gid://shopify/Location/4'],
                                'quantities' => [
                                    ['name' => 'available', 'quantity' => 1],
                                    ['name' => 'committed', 'quantity' => 0],
                                    ['name' => 'on_hand', 'quantity' => 1],
                                ],
                            ]]],
                        ],
                    ],
                    [
                        'id' => 'gid://shopify/ProductVariant/9101',
                        'sku' => 'SHARED-SKU',
                        'availableForSale' => false,
                        'price' => '10.00',
                        'compareAtPrice' => null,
                        'inventoryQuantity' => 3,
                        'inventoryPolicy' => 'DENY',
                        'inventoryItem' => [
                            'id' => 'gid://shopify/InventoryItem/9101',
                            'tracked' => true,
                            'inventoryLevels' => ['nodes' => [[
                                'location' => ['id' => 'gid://shopify/Location/4'],
                                'quantities' => [
                                    ['name' => 'available', 'quantity' => 3],
                                    ['name' => 'committed', 'quantity' => 0],
                                    ['name' => 'on_hand', 'quantity' => 3],
                                ],
                            ]]],
                        ],
                    ],
                ]],
            ]]]);
        }

        if (str_contains($query, 'mutation InventoryItemTrackingUpdate')) {
            return Http::response(['data' => ['inventoryItemUpdate' => ['userErrors' => []]]]);
        }

        if (str_contains($query, 'mutation InventorySetQuantities')) {
            $setInput = $variables['input'] ?? null;

            return Http::response(['data' => ['inventorySetQuantities' => ['userErrors' => []]]]);
        }

        throw new RuntimeException('Unexpected Shopify GraphQL request.');
    });

    $result = app(ProductInventorySyncService::class)->syncVariants(collect([$variant]));

    expect($result['failed'])->toBe(0)
        ->and($statusMutations)->toBe(0)
        ->and($product->fresh()->status)->toBe('draft')
        ->and($setInput['quantities'][0])->toMatchArray([
            'inventoryItemId' => 'gid://shopify/InventoryItem/9101',
            'locationId' => 'gid://shopify/Location/4',
            'quantity' => 3,
        ]);
});

it('does not update inventory when the sku matches more than one shopify variant', function (): void {
    config()->set('services.shopify.shop', 'test-shop.myshopify.com');
    config()->set('services.shopify.admin_access_token', 'test-token');
    config()->set('services.shopify.api_version', '2026-01');

    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'ambiguous-inventory.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
    ]);
    $product = Product::withoutEvents(fn (): Product => Product::query()->create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/9201',
        'handle' => 'ambiguous-inventory',
        'title' => 'Ambiguous Inventory',
        'status' => 'active',
    ]));
    $variant = Variant::withoutEvents(fn (): Variant => Variant::query()->create([
        'product_id' => $product->id,
        'sku' => 'SHARED-SKU',
        'inventory_tracked' => true,
        'current_on_hand_quantity' => 2,
        'inventory_local_dirty' => true,
    ]));

    Http::fake(function ($request) {
        $query = (string) ($request->data()['query'] ?? '');
        if (! str_contains($query, 'query ProductInventoryById')) {
            throw new RuntimeException('Inventory write ran before a unique variant match.');
        }

        return Http::response(['data' => ['product' => [
            'id' => 'gid://shopify/Product/9201',
            'status' => 'ACTIVE',
            'variants' => ['nodes' => [
                ['id' => 'gid://shopify/ProductVariant/1', 'sku' => 'SHARED-SKU', 'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/1', 'tracked' => true, 'inventoryLevels' => ['nodes' => []]]],
                ['id' => 'gid://shopify/ProductVariant/2', 'sku' => 'SHARED-SKU', 'inventoryItem' => ['id' => 'gid://shopify/InventoryItem/2', 'tracked' => true, 'inventoryLevels' => ['nodes' => []]]],
            ]],
        ]]]);
    });

    $result = app(ProductInventorySyncService::class)->syncVariants(collect([$variant]));

    expect($result['failed'])->toBe(1)
        ->and($result['failures'][0])->toContain('Unable to resolve Shopify variant');
});
