<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Models\Approval;
use App\Models\Import;
use App\Models\Product;
use App\Models\ProductLaunchItem;
use App\Models\ProductLaunchRun;
use App\Models\User;
use App\Models\Variant;
use App\Services\Shopify\ProductLaunchService;
use App\Services\Shopify\ShopifyInventoryAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'product_launch.enabled' => true,
        'product_launch.publications' => ['gid://shopify/Publication/1'],
    ]);
});

it('does not launch a product when it is approved', function (): void {
    $product = launchProduct('draft', tracked: true);
    approveLaunchProduct($product);

    expect($product->fresh()->status)->toBe('draft')
        ->and(ProductLaunchRun::query()->count())->toBe(0)
        ->and($product->fresh()->isApprovedByTwo())->toBeTrue();
});

it('launches an approved tracked product and does not write inventory again on a repeat', function (): void {
    $product = launchProduct('draft', tracked: true, onHand: 0, quantity: 5);
    approveLaunchProduct($product);
    $writes = 0;
    $activated = false;
    bindLaunchClients($writes, $activated);

    $service = app(ProductLaunchService::class);
    $first = $service->queue(collect([$product]), ['gid://shopify/Publication/1'], User::factory()->create()->id);
    $second = $service->queue(collect([$product->fresh()]), ['gid://shopify/Publication/1'], User::factory()->create()->id);

    expect($first->fresh()->status)->toBe(ProductLaunchRun::STATUS_COMPLETED)
        ->and($first->items()->first()->outcome)->toBe(ProductLaunchItem::OUTCOME_LAUNCHED)
        ->and($first->items()->first()->status_confirmed_at)->not->toBeNull()
        ->and($second->items()->first()->outcome)->toBe(ProductLaunchItem::OUTCOME_SKIPPED)
        ->and($writes)->toBe(1)
        ->and($product->fresh()->status)->toBe('active')
        ->and($product->variants()->first()->current_on_hand_quantity)->toBe(5);
});

it('blocks products that fail validation and still launches the valid ones', function (): void {
    $ready = launchProduct('draft', tracked: true, onHand: 0, quantity: 4);
    $blocked = launchProduct('draft', tracked: true, onHand: 0, quantity: 4, handle: 'blocked-launch');
    approveLaunchProduct($ready);
    $writes = 0;
    $activated = false;
    bindLaunchClients($writes, $activated);

    $run = app(ProductLaunchService::class)->queue(
        collect([$ready, $blocked]),
        ['gid://shopify/Publication/1'],
        User::factory()->create()->id,
    );

    expect($run->fresh()->status)->toBe(ProductLaunchRun::STATUS_COMPLETED_WITH_FAILURES)
        ->and($run->items()->where('product_id', $ready->id)->value('outcome'))->toBe(ProductLaunchItem::OUTCOME_LAUNCHED)
        ->and($run->items()->where('product_id', $blocked->id)->value('outcome'))->toBe(ProductLaunchItem::OUTCOME_BLOCKED)
        ->and($blocked->fresh()->status)->toBe('draft')
        ->and($writes)->toBe(1);
});

it('does not launch when CMS inventory is stale compared with Shopify', function (): void {
    $product = launchProduct('draft', tracked: true, onHand: 10, quantity: 10);
    approveLaunchProduct($product);
    $writes = 0;
    $activated = false;
    bindLaunchClients($writes, $activated, liveOnHand: 7);

    $run = app(ProductLaunchService::class)->queue(
        collect([$product]),
        ['gid://shopify/Publication/1'],
        User::factory()->create()->id,
    );

    expect($run->items()->first()->outcome)->toBe(ProductLaunchItem::OUTCOME_BLOCKED)
        ->and($run->items()->first()->detail)->toContain('does not match Shopify')
        ->and($writes)->toBe(0)
        ->and($product->fresh()->status)->toBe('draft');
});

it('launches a stack without enabling inventory tracking or assigning a quantity', function (): void {
    $product = launchProduct('draft', tracked: false, stack: true);
    approveLaunchProduct($product);
    $writes = 0;
    $activated = false;
    bindLaunchClients($writes, $activated);

    $run = app(ProductLaunchService::class)->queue(
        collect([$product]),
        ['gid://shopify/Publication/1'],
        User::factory()->create()->id,
    );

    expect($run->items()->first()->outcome)->toBe(ProductLaunchItem::OUTCOME_LAUNCHED)
        ->and($run->items()->first()->inventory_tracking)->toBe('untracked')
        ->and($run->items()->first()->proposed_inventory)->toBeNull()
        ->and($writes)->toBe(0)
        ->and($product->fresh()->status)->toBe('active')
        ->and($product->fresh()->is_bundle)->toBeTrue();
});

it('reports a failed launch when Shopify does not confirm publication', function (): void {
    $product = launchProduct('draft', tracked: false);
    approveLaunchProduct($product);
    $writes = 0;
    $activated = false;
    bindLaunchClients($writes, $activated, publicationConfirmed: false);

    $run = app(ProductLaunchService::class)->queue(
        collect([$product]),
        ['gid://shopify/Publication/1'],
        User::factory()->create()->id,
    );

    expect($run->items()->first()->outcome)->toBe(ProductLaunchItem::OUTCOME_FAILED)
        ->and($run->items()->first()->detail)->toContain('did not confirm')
        ->and($product->fresh()->status)->toBe('draft');
});

function launchProduct(
    string $status,
    bool $tracked,
    int $onHand = 0,
    ?int $quantity = 0,
    string $handle = 'launch-product',
    bool $stack = false,
): Product {
    $user = User::factory()->create();
    $import = Import::query()->create([
        'filename' => $handle.'.csv', 'mode' => 'append', 'status' => 'ready', 'created_by' => $user->id,
    ]);
    $product = Product::query()->create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/'.$handle,
        'handle' => $handle,
        'title' => 'Launch '.$handle,
        'status' => $status,
        'is_bundle' => $stack,
        'tags' => $stack ? 'bundles' : null,
    ]);
    Variant::query()->create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/'.$handle,
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/'.$handle,
        'sku' => strtoupper($handle),
        'inventory_tracked' => $tracked,
        'inventory_qty' => $quantity,
        'current_on_hand_quantity' => $onHand,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
    ]);
    $product->forceFill(['has_errors' => false, 'error_fields' => []])->saveQuietly();

    return $product->fresh() ?? $product;
}

function approveLaunchProduct(Product $product): void
{
    foreach ([User::factory()->create(), User::factory()->create()] as $user) {
        Approval::query()->create([
            'product_id' => $product->id,
            'user_id' => $user->id,
            'approval_version' => $product->approval_version,
        ]);
    }
}

function bindLaunchClients(int &$writes, bool &$activated, int $liveOnHand = 0, bool $publicationConfirmed = true): void
{
    $inventory = Mockery::mock(ShopifyInventoryAdjustmentService::class);
    $inventory->shouldReceive('currentQuantities')->zeroOrMoreTimes()->andReturnUsing(
        function () use (&$writes, $liveOnHand): array {
            $onHand = $writes > 0 ? 5 : $liveOnHand;

            return ['location_id' => 'gid://shopify/Location/1', 'available' => $onHand, 'on_hand' => $onHand, 'committed' => 0];
        }
    );
    $inventory->shouldReceive('setOnHand')->zeroOrMoreTimes()->andReturnUsing(function () use (&$writes): array {
        $writes++;

        return ['createdAt' => now()->toIso8601String()];
    });
    $gateway = Mockery::mock(ShopifyGraphqlGateway::class);
    $gateway->shouldReceive('graphql')->zeroOrMoreTimes()->andReturnUsing(
        function (string $query) use (&$activated, $publicationConfirmed): array {
            if (str_contains($query, 'LaunchProductState')) {
                return ['product' => [
                    'status' => $activated ? 'ACTIVE' : 'DRAFT',
                    'resourcePublications' => ['nodes' => $activated ? [[
                        'isPublished' => true,
                        'publication' => ['id' => 'gid://shopify/Publication/1'],
                    ]] : []],
                ]];
            }
            if (str_contains($query, 'LaunchActivateProduct')) {
                $activated = true;

                return ['productUpdate' => ['product' => ['status' => 'ACTIVE'], 'userErrors' => []]];
            }
            if (str_contains($query, 'LaunchPublishProduct')) {
                return ['publishablePublish' => ['userErrors' => []]];
            }
            if (str_contains($query, 'LaunchConfirmPublication')) {
                return ['product' => ['status' => 'ACTIVE', 'publishedOnPublication' => $publicationConfirmed]];
            }

            throw new RuntimeException('Unexpected Shopify launch query.');
        }
    );
    app()->instance(ShopifyInventoryAdjustmentService::class, $inventory);
    app()->instance(ShopifyGraphqlGateway::class, $gateway);
}
