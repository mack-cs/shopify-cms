<?php

use App\Jobs\HandleShopifyInventoryLevelUpdatedJob;
use App\Jobs\HandleShopifyProductUpdatedJob;
use App\Jobs\InventorySyncJob;
use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Notifications\StackSellabilityChangedSlackNotification;
use App\Services\StackBundleSellabilityService;
use App\Services\StackSellabilityShopifyPushService;
use App\Services\StackSellabilitySlackNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('accepts a verified Shopify inventory webhook and queues the handler', function (): void {
    Queue::fake();
    config([
        'services.shopify.verify_webhooks' => true,
        'services.shopify.webhook_secret' => 'test-webhook-secret',
    ]);

    $payload = json_encode(['inventory_item_id' => 123456789], JSON_THROW_ON_ERROR);
    $hmac = base64_encode(hash_hmac('sha256', $payload, 'test-webhook-secret', true));

    $response = $this->call('POST', '/webhooks/shopify/inventory-levels-update', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
        'HTTP_X_SHOPIFY_TOPIC' => 'inventory_levels/update',
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'webhook-1',
    ], $payload);

    $response->assertStatus(202);

    Queue::assertPushed(
        HandleShopifyInventoryLevelUpdatedJob::class,
        fn (HandleShopifyInventoryLevelUpdatedJob $job): bool => $job->inventoryItemId === '123456789'
            && $job->webhookId === 'webhook-1'
    );
});

it('rejects a Shopify inventory webhook with an invalid signature', function (): void {
    Queue::fake();
    config([
        'services.shopify.verify_webhooks' => true,
        'services.shopify.webhook_secret' => 'test-webhook-secret',
    ]);

    $payload = json_encode(['inventory_item_id' => 123456789], JSON_THROW_ON_ERROR);

    $response = $this->call('POST', '/webhooks/shopify/inventory-levels-update', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SHOPIFY_HMAC_SHA256' => 'bad-signature',
        'HTTP_X_SHOPIFY_TOPIC' => 'inventory_levels/update',
    ], $payload);

    $response->assertStatus(401);
    Queue::assertNothingPushed();
});

it('can skip Shopify webhook signature verification in local testing only', function (): void {
    Queue::fake();
    app()->detectEnvironment(fn (): string => 'local');
    config([
        'services.shopify.verify_webhooks' => false,
        'services.shopify.webhook_secret' => '',
    ]);

    $payload = json_encode(['inventory_item_id' => 123456789], JSON_THROW_ON_ERROR);

    $response = $this->call('POST', '/webhooks/shopify/inventory-levels-update', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SHOPIFY_TOPIC' => 'inventory_levels/update',
    ], $payload);

    $response->assertStatus(202);
    Queue::assertPushed(HandleShopifyInventoryLevelUpdatedJob::class);
});

it('accepts a verified Shopify product update webhook and queues the handler', function (): void {
    Queue::fake();
    config([
        'services.shopify.verify_webhooks' => true,
        'services.shopify.webhook_secret' => 'test-webhook-secret',
    ]);

    $payload = json_encode([
        'id' => 900000002,
        'admin_graphql_api_id' => 'gid://shopify/Product/900000002',
        'handle' => 'test-webhook-component',
        'status' => 'draft',
    ], JSON_THROW_ON_ERROR);
    $hmac = base64_encode(hash_hmac('sha256', $payload, 'test-webhook-secret', true));

    $response = $this->call('POST', '/webhooks/shopify/products-update', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SHOPIFY_HMAC_SHA256' => $hmac,
        'HTTP_X_SHOPIFY_TOPIC' => 'products/update',
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'webhook-product-1',
    ], $payload);

    $response->assertStatus(202);

    Queue::assertPushed(
        HandleShopifyProductUpdatedJob::class,
        fn (HandleShopifyProductUpdatedJob $job): bool => $job->shopifyProductId === 'gid://shopify/Product/900000002'
            && $job->handle === 'test-webhook-component'
            && $job->webhookId === 'webhook-product-1'
    );
});

it('uses an inventory item webhook to refresh Shopify truth and force affected stacks unsellable', function (): void {
    config([
        'services.shopify.shop' => 'leigh-avenue-test.myshopify.com',
        'services.shopify.admin_access_token' => 'test-token',
    ]);

    $records = createInventoryWebhookStackRecords(componentQty: 5, stackTracked: false, stackQty: null);

    fakeShopifyInventoryProduct(
        $records['component'],
        $records['component_variant'],
        tracked: true,
        quantity: 0,
    );

    $summary = app(StackBundleSellabilityService::class)
        ->enforceForInventoryItem('123456789');

    $records['draft']->refresh();
    $records['stack_variant']->refresh();

    expect($summary['affected_stacks'])->toBe(1);
    expect($summary['forced_unsellable'])->toBe(1);
    expect($summary['changes'][0]['action'])->toBe('disabled');
    expect($records['draft']->variant_inventory_qty)->toBe(0);
    expect($records['stack_variant']->inventory_tracked)->toBeTrue();
    expect($records['stack_variant']->inventory_qty)->toBe(0);
    expect($records['stack_variant']->inventory_local_dirty)->toBeTrue();
});

it('uses a product update webhook to refresh component status and force affected stacks unsellable', function (): void {
    Queue::fake();
    Notification::fake();
    config([
        'services.shopify.shop' => 'leigh-avenue-test.myshopify.com',
        'services.shopify.admin_access_token' => 'test-token',
        'services.slack.channels.inventory' => '#inventory-alerts',
    ]);

    $records = createInventoryWebhookStackRecords(componentQty: 5, stackTracked: false, stackQty: null);

    fakeShopifyInventoryProduct(
        $records['component'],
        $records['component_variant'],
        tracked: true,
        quantity: 5,
        status: 'DRAFT',
    );

    $job = new HandleShopifyProductUpdatedJob($records['component']->shopify_id, $records['component']->handle);
    $job->handle(
        app(StackBundleSellabilityService::class),
        app(StackSellabilityShopifyPushService::class),
        app(StackSellabilitySlackNotifier::class),
    );

    $records['component']->refresh();
    $records['draft']->refresh();
    $records['stack_variant']->refresh();

    expect($records['component']->status)->toBe('draft');
    expect($records['draft']->variant_inventory_qty)->toBe(0);
    expect($records['stack_variant']->inventory_tracked)->toBeTrue();
    expect($records['stack_variant']->inventory_qty)->toBe(0);

    Queue::assertPushed(
        InventorySyncJob::class,
        fn (InventorySyncJob $job): bool => $job->mode === 'push'
            && $job->variantIds === [$records['stack_variant']->id]
    );
    Notification::assertSentOnDemand(StackSellabilityChangedSlackNotification::class);
});

it('restores an affected stack to untracked inventory when all associated products recover', function (): void {
    config([
        'services.shopify.shop' => 'leigh-avenue-test.myshopify.com',
        'services.shopify.admin_access_token' => 'test-token',
    ]);

    $records = createInventoryWebhookStackRecords(componentQty: 0, stackTracked: true, stackQty: 0);

    fakeShopifyInventoryProduct(
        $records['component'],
        $records['component_variant'],
        tracked: true,
        quantity: 8,
    );

    $summary = app(StackBundleSellabilityService::class)
        ->enforceForInventoryItem('gid://shopify/InventoryItem/123456789');

    $records['draft']->refresh();
    $records['stack_variant']->refresh();

    expect($summary['affected_stacks'])->toBe(1);
    expect($summary['restored_sellable'])->toBe(1);
    expect($summary['changes'][0]['action'])->toBe('restored');
    expect($records['draft']->variant_inventory_qty)->toBeNull();
    expect($records['stack_variant']->inventory_tracked)->toBeFalse();
    expect($records['stack_variant']->inventory_qty)->toBeNull();
    expect($records['stack_variant']->inventory_local_dirty)->toBeTrue();
});

it('ignores inventory webhooks for stack products themselves', function (): void {
    $records = createInventoryWebhookStackRecords(componentQty: 0, stackTracked: true, stackQty: 0);

    $summary = app(StackBundleSellabilityService::class)
        ->enforceForInventoryItem('gid://shopify/InventoryItem/987654321');

    expect($summary['skipped_stack_product_webhook'])->toBe(1);
    expect($summary['changes'])->toBe([]);

    $records['stack_variant']->refresh();
    expect($records['stack_variant']->inventory_qty)->toBe(0);
});

it('sends the inventory Slack notification only when the webhook changes a stack', function (): void {
    Queue::fake();
    Notification::fake();
    config([
        'services.shopify.shop' => 'leigh-avenue-test.myshopify.com',
        'services.shopify.admin_access_token' => 'test-token',
        'services.slack.channels.inventory' => '#inventory-alerts',
    ]);

    $records = createInventoryWebhookStackRecords(componentQty: 5, stackTracked: false, stackQty: null);

    fakeShopifyInventoryProduct(
        $records['component'],
        $records['component_variant'],
        tracked: true,
        quantity: 0,
    );

    $job = new HandleShopifyInventoryLevelUpdatedJob('123456789');
    $job->handle(
        app(StackBundleSellabilityService::class),
        app(StackSellabilityShopifyPushService::class),
        app(StackSellabilitySlackNotifier::class),
    );

    Queue::assertPushed(
        InventorySyncJob::class,
        fn (InventorySyncJob $job): bool => $job->mode === 'push'
            && $job->variantIds === [$records['stack_variant']->id]
    );
    Notification::assertSentOnDemand(StackSellabilityChangedSlackNotification::class);
});

it('routes stack sellability Slack notifications to the inventory updates channel', function (): void {
    Notification::fake();
    config(['services.slack.channels.inventory' => '#inventory-updates']);

    $sent = app(StackSellabilitySlackNotifier::class)->notifyIfChanged([
        'changes' => [[
            'action' => 'disabled',
            'stack' => [
                'title' => 'Test Stack',
                'sku' => 'TEST-STACK',
            ],
            'component' => [
                'title' => 'Test Component',
                'sku' => 'TEST-COMPONENT',
                'reason' => 'Local inventory is 0',
                'current_stock' => 0,
            ],
        ]],
    ]);

    expect($sent)->toBeTrue();

    Notification::assertSentOnDemand(
        StackSellabilityChangedSlackNotification::class,
        fn (StackSellabilityChangedSlackNotification $notification, array $channels, object $notifiable): bool => in_array('slack', $channels, true)
            && method_exists($notifiable, 'routeNotificationFor')
            && $notifiable->routeNotificationFor('slack') === '#inventory-updates'
    );
});

it('does not send inventory Slack notifications without a stack sellability change', function (): void {
    Notification::fake();
    config(['services.slack.channels.inventory' => '#inventory-updates']);

    $sent = app(StackSellabilitySlackNotifier::class)->notifyIfChanged([
        'changes' => [[
            'action' => 'refreshed',
            'stack' => ['title' => 'Test Stack'],
        ]],
    ]);

    expect($sent)->toBeFalse();
    Notification::assertNothingSent();
});

it('queues a Shopify push when manual stack enforcement changes a stack', function (): void {
    Queue::fake();
    Notification::fake();

    $records = createInventoryWebhookStackRecords(componentQty: 0, stackTracked: false, stackQty: null);

    $this->artisan('inventory:enforce-stack-sellability')
        ->assertSuccessful()
        ->expectsOutput('Forced unsellable: 1')
        ->expectsOutput('Shopify push queued variants: 1');

    Queue::assertPushed(
        InventorySyncJob::class,
        fn (InventorySyncJob $job): bool => $job->mode === 'push'
            && $job->variantIds === [$records['stack_variant']->id]
    );
});

/**
 * @return array{
 *   import: Import,
 *   draft: NewProductDraft,
 *   stack: Product,
 *   stack_variant: Variant,
 *   component: Product,
 *   component_variant: Variant
 * }
 */
function createInventoryWebhookStackRecords(int $componentQty, bool $stackTracked, ?int $stackQty): array
{
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'inventory-webhook-stack.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
    ]);

    $stack = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/900000001',
        'handle' => 'test-webhook-stack',
        'title' => 'Test Webhook Stack',
        'type' => 'Bracelets',
        'tags' => 'bundles',
        'status' => 'active',
        'is_bundle' => true,
        'approval_version' => 1,
    ]));

    $stackVariant = Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $stack->id,
        'shopify_id' => 'gid://shopify/ProductVariant/900000001',
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/987654321',
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'sku' => 'TEST-WEBHOOK-STACK',
        'inventory_tracked' => $stackTracked,
        'inventory_qty' => $stackQty,
        'inventory_local_dirty' => false,
    ]));

    $component = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/900000002',
        'handle' => 'test-webhook-component',
        'title' => 'Test Webhook Component',
        'type' => 'Bracelets',
        'status' => 'active',
        'is_bundle' => false,
        'approval_version' => 1,
    ]));

    $componentVariant = Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $component->id,
        'shopify_id' => 'gid://shopify/ProductVariant/900000002',
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/123456789',
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'sku' => 'TEST-WEBHOOK-COMPONENT',
        'inventory_tracked' => true,
        'inventory_qty' => $componentQty,
        'inventory_local_dirty' => false,
    ]));

    $draft = NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'sku' => 'TEST-WEBHOOK-STACK',
        'shopify_id' => $stack->shopify_id,
        'handle' => $stack->handle,
        'title' => $stack->title,
        'type' => 'Bracelets',
        'tags' => 'bundles',
        'status' => 'active',
        'variant_inventory_qty' => $stackTracked === false ? null : $stackQty,
        'bundle_product_ids' => [$component->id],
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    return [
        'import' => $import,
        'draft' => $draft,
        'stack' => $stack,
        'stack_variant' => $stackVariant,
        'component' => $component,
        'component_variant' => $componentVariant,
    ];
}

function fakeShopifyInventoryProduct(Product $product, Variant $variant, bool $tracked, int $quantity, string $status = 'ACTIVE'): void
{
    Http::fake(fn () => Http::response([
        'data' => [
            'product' => [
                'id' => $product->shopify_id,
                'status' => $status,
                'variants' => [
                    'nodes' => [[
                        'id' => $variant->shopify_id,
                        'sku' => $variant->sku,
                        'inventoryQuantity' => $quantity,
                        'inventoryItem' => [
                            'id' => $variant->shopify_inventory_item_id,
                            'tracked' => $tracked,
                        ],
                    ]],
                ],
            ],
        ],
    ]));
}
