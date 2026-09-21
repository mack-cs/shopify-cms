<?php

use App\Models\Product;
use App\Models\Variant;
use App\Services\ProductInventorySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('sets on hand and refreshes Shopify available committed and on hand quantities', function (): void {
    config()->set('services.shopify.shop', 'test-shop.myshopify.com');
    config()->set('services.shopify.admin_access_token', 'test-token');
    config()->set('services.shopify.api_version', '2026-01');

    $product = Product::withoutEvents(fn (): Product => Product::query()->create([
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
