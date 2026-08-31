<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Jobs\ProcessShopifyStackFulfillmentJob;
use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyFulfillment;
use App\Models\ShopifyStackComponentDeduction;
use App\Models\User;
use App\Models\Variant;
use App\Services\Shopify\ShopifyInventoryAdjustmentService;
use App\Services\Shopify\StackFulfillmentInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('accepts a verified fulfillment webhook and queues its persisted fulfillment', function (): void {
    Queue::fake();
    config([
        'services.shopify.verify_webhooks' => true,
        'services.shopify.webhook_secret' => 'fulfillment-secret',
    ]);

    $payload = stackFulfillmentPayload(7001, 8001, 9001, 2);
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $hmac = base64_encode(hash_hmac('sha256', $json, 'fulfillment-secret', true));

    $response = $this->call('POST', '/webhooks/shopify/fulfillments', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
        'HTTP_X_SHOPIFY_TOPIC' => 'fulfillments/create',
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'fulfillment-webhook-1',
    ], $json);

    $response->assertStatus(202);
    $fulfillment = ShopifyFulfillment::query()->firstOrFail();
    expect($fulfillment->shopify_order_id)->toBe('8001')
        ->and($fulfillment->payload['line_items'][0]['quantity'])->toBe(2);
    Queue::assertPushed(
        ProcessShopifyStackFulfillmentJob::class,
        fn (ProcessShopifyStackFulfillmentJob $job): bool => $job->fulfillmentId === $fulfillment->id,
    );
});

it('deducts component quantities for partial fulfillment exactly once', function (): void {
    $records = createFulfillmentStackRecords();
    $fulfillment = ShopifyFulfillment::create([
        'shopify_fulfillment_id' => 'gid://shopify/Fulfillment/7001',
        'shopify_order_id' => '8001',
        'shopify_location_id' => '6001',
        'shopify_status' => 'success',
        'payload' => stackFulfillmentPayload(7001, 8001, 9001, 2),
    ]);

    $inventory = Mockery::mock(ShopifyInventoryAdjustmentService::class);
    $inventory->shouldReceive('decreaseAvailable')
        ->once()
        ->withArgs(fn (Variant $variant, int $quantity): bool => $variant->is($records['component_x_variant']) && $quantity === 2)
        ->andReturn(['createdAt' => now()->toIso8601String()]);
    $inventory->shouldReceive('decreaseAvailable')
        ->once()
        ->withArgs(fn (Variant $variant, int $quantity): bool => $variant->is($records['component_y_variant']) && $quantity === 4)
        ->andReturn(['createdAt' => now()->toIso8601String()]);

    $service = new StackFulfillmentInventoryService($inventory);
    $summary = $service->process($fulfillment);

    expect($summary['deductions'])->toBe(2)
        ->and(ShopifyStackComponentDeduction::query()->count())->toBe(2)
        ->and(ShopifyStackComponentDeduction::query()->sum('quantity_deducted'))->toBe(6)
        ->and($fulfillment->fresh()->processing_status)->toBe(ShopifyFulfillment::STATUS_COMPLETED);

    $retrySummary = $service->process($fulfillment->fresh());
    expect($retrySummary['deductions'])->toBe(0)
        ->and($retrySummary['already_completed'])->toBe(2)
        ->and(ShopifyStackComponentDeduction::query()->count())->toBe(2);
});

it('leaves ordinary products and unconfigured Stacks to their existing inventory flow', function (): void {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'non-stack-fulfillment-test.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
    ]);

    foreach ([
        ['variant_id' => 9201, 'shopify_product_id' => 5201, 'sku' => 'NORMAL-A', 'tags' => 'bracelets', 'is_bundle' => false],
        ['variant_id' => 9202, 'shopify_product_id' => 5202, 'sku' => 'STACK-UNKNOWN', 'tags' => 'stacks', 'is_bundle' => false],
        ['variant_id' => 9203, 'shopify_product_id' => 5203, 'sku' => 'BUNDLE-UNKNOWN', 'tags' => null, 'is_bundle' => true],
        ['variant_id' => 9204, 'shopify_product_id' => 5204, 'sku' => 'STACK-ORPHAN-QUANTITY', 'tags' => 'stack', 'is_bundle' => true],
    ] as $index => $record) {
        $product = Product::withoutEvents(fn (): Product => Product::create([
            'import_id' => $import->id,
            'shopify_id' => "gid://shopify/Product/{$record['shopify_product_id']}",
            'handle' => strtolower($record['sku']),
            'title' => $record['sku'],
            'tags' => $record['tags'],
            'status' => 'active',
            'is_bundle' => $record['is_bundle'],
            'approval_version' => 1,
        ]));
        Variant::withoutEvents(fn (): Variant => Variant::create([
            'product_id' => $product->id,
            'shopify_id' => "gid://shopify/ProductVariant/{$record['variant_id']}",
            'sku' => $record['sku'],
            'sync_state' => Variant::SYNC_STATE_SYNCED,
        ]));

        if ($record['sku'] === 'STACK-ORPHAN-QUANTITY') {
            $orphan = fulfillmentComponent($import, 5301, 9301, 'ORPHAN-COMPONENT');
            NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
                'shopify_id' => $product->shopify_id,
                'handle' => $product->handle,
                'sku' => $record['sku'],
                'title' => $record['sku'],
                'tags' => 'stack',
                'status' => 'active',
                'bundle_product_ids' => [],
                'bundle_component_quantities' => [
                    ['product_id' => $orphan['product']->id, 'quantity' => 2],
                ],
                'approval_version' => 1,
                'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
            ]));
        }

        $payload = stackFulfillmentPayload(7100 + $index, 8100 + $index, $record['variant_id'], 1);
        $payload['line_items'][0]['product_id'] = $record['shopify_product_id'];
        $payload['line_items'][0]['sku'] = $record['sku'];

        $fulfillment = ShopifyFulfillment::create([
            'shopify_fulfillment_id' => 'gid://shopify/Fulfillment/'.(7100 + $index),
            'shopify_order_id' => (string) (8100 + $index),
            'shopify_location_id' => '6001',
            'shopify_status' => 'success',
            'payload' => $payload,
        ]);

        $inventory = Mockery::mock(ShopifyInventoryAdjustmentService::class);
        $inventory->shouldNotReceive('decreaseAvailable');
        $summary = (new StackFulfillmentInventoryService($inventory))->process($fulfillment);

        expect($summary['deductions'])->toBe(0)
            ->and($fulfillment->fresh()->processing_status)->toBe(ShopifyFulfillment::STATUS_COMPLETED);
    }

    expect(ShopifyStackComponentDeduction::query()->count())->toBe(0);
});

it('deducts only the quantity in each partial fulfillment', function (): void {
    createFulfillmentStackRecords();
    $inventory = Mockery::mock(ShopifyInventoryAdjustmentService::class);
    $inventory->shouldReceive('decreaseAvailable')->times(4)->andReturn(['createdAt' => now()->toIso8601String()]);
    $service = new StackFulfillmentInventoryService($inventory);

    foreach ([[7001, 1], [7002, 2]] as [$fulfillmentId, $quantity]) {
        $service->process(ShopifyFulfillment::create([
            'shopify_fulfillment_id' => "gid://shopify/Fulfillment/{$fulfillmentId}",
            'shopify_order_id' => '8001',
            'shopify_location_id' => '6001',
            'shopify_status' => 'success',
            'payload' => stackFulfillmentPayload($fulfillmentId, 8001, 9001, $quantity),
        ]));
    }

    $deductions = ShopifyStackComponentDeduction::query()->orderBy('shopify_fulfillment_id')->get();
    expect($deductions)->toHaveCount(4)
        ->and($deductions->pluck('stack_quantity_fulfilled')->all())->toBe([1, 1, 2, 2])
        ->and($deductions->pluck('quantity_deducted')->all())->toBe([1, 2, 2, 4]);
});

it('retries only failed component deductions and reuses their idempotency key', function (): void {
    $records = createFulfillmentStackRecords();
    $fulfillment = ShopifyFulfillment::create([
        'shopify_fulfillment_id' => 'gid://shopify/Fulfillment/7010',
        'shopify_order_id' => '8010',
        'shopify_location_id' => '6001',
        'shopify_status' => 'success',
        'payload' => stackFulfillmentPayload(7010, 8010, 9001, 1),
    ]);

    $failedKey = null;
    $componentYAttempts = 0;
    $inventory = Mockery::mock(ShopifyInventoryAdjustmentService::class);
    $inventory->shouldReceive('decreaseAvailable')
        ->times(3)
        ->andReturnUsing(function (Variant $variant, int $quantity, string $reference, string $key) use (
            $records,
            &$failedKey,
            &$componentYAttempts,
        ): array {
            if ($variant->is($records['component_y_variant'])) {
                $componentYAttempts++;
                $failedKey ??= $key;
                expect($key)->toBe($failedKey);
                if ($componentYAttempts === 1) {
                    throw new RuntimeException('Temporary Shopify failure');
                }
            }

            return ['createdAt' => now()->toIso8601String(), 'quantity' => $quantity, 'reference' => $reference];
        });

    $service = new StackFulfillmentInventoryService($inventory);
    expect(fn () => $service->process($fulfillment))->toThrow(RuntimeException::class, 'Temporary Shopify failure');

    $rows = ShopifyStackComponentDeduction::query()->orderBy('id')->get();
    expect($rows[0]->status)->toBe(ShopifyStackComponentDeduction::STATUS_COMPLETED)
        ->and($rows[1]->status)->toBe(ShopifyStackComponentDeduction::STATUS_FAILED);

    $summary = $service->process($fulfillment->fresh());
    expect($summary['already_completed'])->toBe(1)
        ->and($summary['deductions'])->toBe(1)
        ->and($componentYAttempts)->toBe(2)
        ->and($fulfillment->fresh()->processing_status)->toBe(ShopifyFulfillment::STATUS_COMPLETED);
});

it('sends a negative idempotent Shopify inventory adjustment', function (): void {
    $records = createFulfillmentStackRecords();
    $gateway = Mockery::mock(ShopifyGraphqlGateway::class);
    $gateway->shouldReceive('graphql')
        ->once()
        ->withArgs(function (string $query, array $variables): bool {
            expect($query)->toContain('@idempotent(key: $idempotencyKey)')
                ->and($variables['idempotencyKey'])->toBe('11111111-1111-4111-8111-111111111111')
                ->and(data_get($variables, 'input.reason'))->toBe('correction')
                ->and(data_get($variables, 'input.changes.0.delta'))->toBe(-4)
                ->and(data_get($variables, 'input.changes.0.locationId'))->toBe('gid://shopify/Location/6001');

            return true;
        })
        ->andReturn([
            'inventoryAdjustQuantities' => [
                'userErrors' => [],
                'inventoryAdjustmentGroup' => ['createdAt' => now()->toIso8601String()],
            ],
        ]);

    $service = new ShopifyInventoryAdjustmentService($gateway);
    $service->decreaseAvailable(
        $records['component_x_variant'],
        4,
        'gid://shopify/Fulfillment/7001#stack-component-1',
        '11111111-1111-4111-8111-111111111111',
        'gid://shopify/Location/6001',
    );
});

/** @return array<string, mixed> */
function createFulfillmentStackRecords(): array
{
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'stack-fulfillment-test.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
    ]);

    $stack = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/5001',
        'handle' => 'stack-a',
        'title' => 'Stack A',
        'status' => 'active',
        'is_bundle' => true,
        'approval_version' => 1,
    ]));
    $stackVariant = Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $stack->id,
        'shopify_id' => 'gid://shopify/ProductVariant/9001',
        'sku' => 'STACK-A',
        'sync_state' => Variant::SYNC_STATE_SYNCED,
    ]));

    $componentX = fulfillmentComponent($import, 5101, 9101, 'COMP-X');
    $componentY = fulfillmentComponent($import, 5102, 9102, 'COMP-Y');

    NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'shopify_id' => $stack->shopify_id,
        'handle' => $stack->handle,
        'sku' => 'STACK-A',
        'title' => 'Stack A',
        'tags' => 'bundles',
        'status' => 'active',
        'bundle_product_ids' => [$componentX['product']->id, $componentY['product']->id],
        'bundle_component_quantities' => [
            ['product_id' => $componentX['product']->id, 'quantity' => 1],
            ['product_id' => $componentY['product']->id, 'quantity' => 2],
        ],
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    return [
        'stack' => $stack,
        'stack_variant' => $stackVariant,
        'component_x_variant' => $componentX['variant'],
        'component_y_variant' => $componentY['variant'],
    ];
}

/** @return array{product:Product,variant:Variant} */
function fulfillmentComponent(Import $import, int $productId, int $variantId, string $sku): array
{
    $product = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id,
        'shopify_id' => "gid://shopify/Product/{$productId}",
        'handle' => strtolower($sku),
        'title' => $sku,
        'status' => 'active',
        'is_bundle' => false,
        'approval_version' => 1,
    ]));
    $variant = Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $product->id,
        'shopify_id' => "gid://shopify/ProductVariant/{$variantId}",
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/'.($variantId + 1000),
        'sku' => $sku,
        'inventory_tracked' => true,
        'inventory_qty' => 10,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
    ]));

    return compact('product', 'variant');
}

/** @return array<string, mixed> */
function stackFulfillmentPayload(int $fulfillmentId, int $orderId, int $variantId, int $quantity): array
{
    return [
        'id' => $fulfillmentId,
        'admin_graphql_api_id' => "gid://shopify/Fulfillment/{$fulfillmentId}",
        'order_id' => $orderId,
        'status' => 'success',
        'location_id' => 6001,
        'created_at' => now()->toIso8601String(),
        'line_items' => [[
            'id' => 10001,
            'fulfillment_line_item_id' => $fulfillmentId + 10000,
            'variant_id' => $variantId,
            'product_id' => 5001,
            'sku' => 'STACK-A',
            'quantity' => $quantity,
        ]],
    ];
}
