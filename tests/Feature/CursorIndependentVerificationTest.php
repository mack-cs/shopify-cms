<?php

use App\Jobs\DailyShopifyInventoryRefreshJob;
use App\Jobs\InventorySyncJob;
use App\Jobs\ProcessSupplierReceiptJob;
use App\Models\Import;
use App\Models\InventoryAdjustmentRequest;
use App\Models\InventoryWriteIntent;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\EngravedBeads\EngravedBeadMutationGuard;
use App\Services\InventoryAdjustmentApprovalService;
use App\Services\InventoryLevelReconciliation;
use App\Services\Procurement\SupplierOrderService;
use App\Services\Procurement\SupplierReceiptService;
use App\Services\ProductInventorySyncService;
use App\Services\ShopifyApiClient;
use App\Services\StackBundleSellabilityService;
use App\Services\StackSellabilityShopifyPushService;
use App\Services\StackSellabilitySlackNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/InventoryAuthorizationFixture.php';

function cursorImport(User $user): Import
{
    return Import::query()->create([
        'filename' => 'cursor-independent.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
    ]);
}

function cursorVariant(string $sku, bool $tracked, ?int $qty, bool $bundle = false): Variant
{
    $user = inventoryAuthorizedUser();
    $import = cursorImport($user);
    $product = Product::withoutEvents(fn (): Product => Product::query()->create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/'.$sku,
        'handle' => strtolower($sku),
        'title' => 'Product '.$sku,
        'vendor' => 'Leigh Avenue',
        'type' => 'Jewellery',
        'status' => 'active',
        'tags' => $bundle ? 'bundles' : 'livi-road',
        'is_bundle' => $bundle,
        'approval_version' => 1,
    ]));

    return Variant::withoutEvents(fn (): Variant => Variant::query()->create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/'.$sku,
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/'.$sku,
        'sku' => $sku,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'inventory_tracked' => $tracked,
        'inventory_qty' => $qty,
        'current_inventory_quantity' => $qty,
        'current_available_quantity' => $qty,
        'current_on_hand_quantity' => $tracked ? $qty : null,
        'price' => 100,
    ]));
}

function cursorStack(int $componentQty, ?bool $componentTracked): array
{
    $user = User::factory()->create();
    $import = cursorImport($user);
    $stack = Product::withoutEvents(fn (): Product => Product::query()->create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/cursor-stack-'.$componentQty.'-'.md5((string) $componentTracked),
        'handle' => 'cursor-stack-'.$componentQty,
        'title' => 'Cursor Stack',
        'type' => 'Bracelets',
        'tags' => 'bundles',
        'status' => 'active',
        'is_bundle' => true,
        'approval_version' => 1,
    ]));
    $stackVariant = Variant::withoutEvents(fn (): Variant => Variant::query()->create([
        'product_id' => $stack->id,
        'shopify_id' => 'gid://shopify/ProductVariant/cursor-stack-'.$stack->id,
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/cursor-stack-'.$stack->id,
        'sku' => 'CURSOR-STACK-'.$stack->id,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'inventory_tracked' => true,
        'inventory_qty' => 0,
        'current_on_hand_quantity' => 0,
    ]));
    $component = Product::withoutEvents(fn (): Product => Product::query()->create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/cursor-component-'.$stack->id,
        'handle' => 'cursor-component-'.$stack->id,
        'title' => 'Cursor Component',
        'type' => 'Bracelets',
        'status' => 'active',
        'is_bundle' => false,
        'approval_version' => 1,
    ]));
    $componentVariant = Variant::withoutEvents(fn (): Variant => Variant::query()->create([
        'product_id' => $component->id,
        'shopify_id' => 'gid://shopify/ProductVariant/cursor-component-'.$stack->id,
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/cursor-component-'.$stack->id,
        'sku' => 'CURSOR-COMPONENT-'.$stack->id,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'inventory_tracked' => $componentTracked,
        'inventory_qty' => $componentQty,
        'current_on_hand_quantity' => $componentTracked ? $componentQty : null,
    ]));
    $draft = NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::query()->create([
        'sku' => $stackVariant->sku,
        'shopify_id' => $stack->shopify_id,
        'handle' => $stack->handle,
        'title' => $stack->title,
        'type' => 'Bracelets',
        'tags' => 'bundles',
        'status' => 'active',
        'variant_inventory_qty' => 0,
        'bundle_product_ids' => [$component->id],
        'approval_version' => 1,
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    return compact('stack', 'stackVariant', 'component', 'componentVariant', 'draft');
}

function cursorShopifyConfig(): void
{
    config([
        'services.shopify.shop' => 'cursor-test.myshopify.com',
        'services.shopify.admin_access_token' => 'cursor-token',
        'services.shopify.inventory_location_id' => 'gid://shopify/Location/1',
    ]);
}

function cursorReplaceHttp(callable $callback): void
{
    $factory = new \Illuminate\Http\Client\Factory;
    Http::swap($factory);
    $factory->fake($callback);
}

function cursorInventoryResponse(Variant $variant, bool $tracked, ?int $onHand, array $locations): array
{
    $levels = [];
    foreach ($locations as $locationId => $quantity) {
        $levels[] = [
            'location' => ['id' => $locationId],
            'quantities' => [
                ['name' => 'available', 'quantity' => $quantity],
                ['name' => 'on_hand', 'quantity' => $quantity],
                ['name' => 'committed', 'quantity' => 0],
            ],
        ];
    }

    return ['data' => ['product' => [
        'id' => $variant->product->shopify_id,
        'status' => 'ACTIVE',
        'variants' => ['nodes' => [[
            'id' => $variant->shopify_id,
            'sku' => $variant->sku,
            'inventoryQuantity' => $onHand,
            'inventoryPolicy' => 'DENY',
            'inventoryItem' => [
                'id' => $variant->shopify_inventory_item_id,
                'tracked' => $tracked,
                'inventoryLevels' => ['nodes' => $levels],
            ],
        ]]],
    ]]];
}

it('does not restore a stack when component stock is unknown', function (?bool $tracked, ?int $qty): void {
    $records = cursorStack($qty ?? 0, $tracked);
    $records['componentVariant']->forceFill([
        'inventory_tracked' => $tracked,
        'inventory_qty' => $qty,
    ])->saveQuietly();

    app(StackBundleSellabilityService::class)->enforceForAffectedStacks([$records['draft']->id]);

    $stack = $records['stackVariant']->fresh();
    expect($stack->inventory_tracked)->toBeTrue()
        ->and($stack->inventory_qty)->toBe(0);
})->with([
    'null tracking' => [null, null],
    'tracked null quantity' => [true, null],
]);

it('does not restore a zero stack from a non-throwing incomplete component read', function (): void {
    Bus::fake();
    Notification::fake();
    cursorShopifyConfig();
    $records = cursorStack(5, true);
    Http::fake(function ($request) use ($records) {
        $id = $request['variables']['id'] ?? null;
        if ($id === $records['component']->shopify_id) {
            return Http::response(['data' => ['product' => [
                'id' => $records['component']->shopify_id,
                'status' => 'ACTIVE',
                'variants' => ['nodes' => []],
            ]]]);
        }

        return Http::response(['data' => ['product' => [
            'id' => $records['stack']->shopify_id,
            'status' => 'ACTIVE',
            'variants' => ['nodes' => [[
                'id' => $records['stackVariant']->shopify_id,
                'sku' => $records['stackVariant']->sku,
                'inventoryQuantity' => 0,
                'inventoryItem' => [
                    'id' => $records['stackVariant']->shopify_inventory_item_id,
                    'tracked' => true,
                    'inventoryLevels' => ['nodes' => [[
                        'location' => ['id' => 'gid://shopify/Location/1'],
                        'quantities' => [
                            ['name' => 'on_hand', 'quantity' => 0],
                            ['name' => 'available', 'quantity' => 0],
                        ],
                    ]]],
                ],
            ]]],
        ]]]);
    });

    (new DailyShopifyInventoryRefreshJob)->handle(
        app(ProductInventorySyncService::class),
        app(StackBundleSellabilityService::class),
        app(StackSellabilityShopifyPushService::class),
        app(StackSellabilitySlackNotifier::class),
    );

    $stack = $records['stackVariant']->fresh();
    expect($records['componentVariant']->fresh()->inventory_qty)->toBe(5)
        ->and($stack->inventory_tracked)->toBeTrue()
        ->and($stack->inventory_qty)->toBe(0)
        ->and(\Illuminate\Support\Facades\DB::table('inventory_reconciliations')
            ->where('inventory_item_id', $records['componentVariant']->shopify_inventory_item_id)
            ->exists())->toBeTrue();
});

it('publishes an approved quantity after a refresh clears the dirty flag on an untracked item', function (): void {
    cursorShopifyConfig();
    $requester = inventoryAuthorizedUser();
    $reviewer = inventoryAuthorizedUser();
    $variant = cursorVariant('CURSOR-UNTRACKED', false, null, true);
    $service = app(InventoryAdjustmentApprovalService::class);
    $request = $service->submit([[
        'variant' => $variant,
        'inventory_tracked' => true,
        'on_hand_quantity' => 0,
        'reason' => 'Stop selling this stack',
    ]], $requester->id, $reviewer->id);
    $approved = $service->approve($request, $reviewer->id, false);
    expect($variant->fresh()->inventory_local_dirty)->toBeTrue()
        ->and($variant->fresh()->inventory_tracked)->toBeFalse();

    Variant::withoutEvents(function () use ($variant): void {
        $variant->fresh()->forceFill([
            'inventory_local_dirty' => false,
            'inventory_tracked' => false,
            'inventory_qty' => null,
            'current_on_hand_quantity' => null,
        ])->save();
    });

    $remoteTracked = false;
    $remoteOnHand = null;
    $mutations = 0;
    Http::fake(function ($request) use ($variant, &$mutations, &$remoteTracked, &$remoteOnHand) {
        $query = (string) ($request['query'] ?? '');
        if (str_contains($query, 'InventoryItemTrackingUpdate')) {
            $mutations++;
            $remoteTracked = (bool) $request['variables']['tracked'];

            return Http::response(['data' => ['inventoryItemUpdate' => [
                'inventoryItem' => ['id' => $variant->shopify_inventory_item_id, 'tracked' => $remoteTracked],
                'userErrors' => [],
            ]]]);
        }
        if (str_contains($query, 'mutation InventorySetQuantities')) {
            $mutations++;
            $remoteOnHand = (int) $request['variables']['input']['quantities'][0]['quantity'];
            expect($request['variables']['input']['quantities'][0]['quantity'])->toBe(0)
                ->and(array_key_exists('changeFromQuantity', $request['variables']['input']['quantities'][0]))->toBeFalse();

            return Http::response(['data' => ['inventorySetQuantities' => [
                'inventoryAdjustmentGroup' => ['createdAt' => now()->toIso8601String(), 'reason' => 'correction'],
                'userErrors' => [],
            ]]]);
        }

        return Http::response(cursorInventoryResponse($variant->fresh(), $remoteTracked, $remoteOnHand, $remoteTracked ? [
            'gid://shopify/Location/1' => $remoteOnHand,
        ] : []));
    });

    (new InventorySyncJob([$variant->id], 'push', $reviewer->id, $approved->sync_batch_id))
        ->handle(app(ProductInventorySyncService::class), $service);

    expect([
        'mutations' => $mutations,
        'status' => $approved->fresh()->status,
        'tracked' => $variant->fresh()->inventory_tracked,
        'on_hand' => $variant->fresh()->current_on_hand_quantity,
    ])->toBe([
        'mutations' => 2,
        'status' => InventoryAdjustmentRequest::STATUS_APPLIED,
        'tracked' => true,
        'on_hand' => 0,
    ]);
});

it('sends an approved tracked quantity with compare-and-set and blocks a lost response from replaying', function (): void {
    cursorShopifyConfig();
    $requester = inventoryAuthorizedUser();
    $reviewer = inventoryAuthorizedUser();
    $variant = cursorVariant('CURSOR-TRACKED', true, 4);
    $service = app(InventoryAdjustmentApprovalService::class);
    $request = $service->submit([[
        'variant' => $variant,
        'inventory_tracked' => true,
        'on_hand_quantity' => 9,
        'reason' => 'Cycle count',
    ]], $requester->id, $reviewer->id);
    $approved = $service->approve($request, $reviewer->id, false);
    $sent = null;
    $attempts = 0;
    Http::fake(function ($request) use ($variant, &$sent, &$attempts) {
        $query = (string) ($request['query'] ?? '');
        if (str_contains($query, 'mutation InventorySetQuantities')) {
            $attempts++;
            $sent = $request['variables']['input']['quantities'][0];

            return Http::response(['data' => ['inventorySetQuantities' => [
                'inventoryAdjustmentGroup' => ['createdAt' => now()->toIso8601String(), 'reason' => 'correction'],
                'userErrors' => [],
            ]]]);
        }

        $quantity = $attempts > 0 ? 9 : 4;

        return Http::response(cursorInventoryResponse($variant->fresh(), true, $quantity, [
            'gid://shopify/Location/1' => $quantity,
        ]));
    });

    (new InventorySyncJob([$variant->id], 'push', $reviewer->id, $approved->sync_batch_id))
        ->handle(app(ProductInventorySyncService::class), $service);

    expect($attempts)->toBe(1)
        ->and($sent['quantity'])->toBe(9)
        ->and($sent['changeFromQuantity'])->toBe(4)
        ->and($approved->fresh()->status)->toBe(InventoryAdjustmentRequest::STATUS_APPLIED)
        ->and($variant->fresh()->current_on_hand_quantity)->toBe(9);

    $lost = cursorVariant('CURSOR-LOST', true, 4);
    $lostRequest = $service->approve($service->submit([[
        'variant' => $lost,
        'inventory_tracked' => true,
        'on_hand_quantity' => 9,
        'reason' => 'Lost response',
    ]], $requester->id, $reviewer->id), $reviewer->id, false);
    $lostAttempts = 0;
    cursorReplaceHttp(function ($request) use ($lost, &$lostAttempts) {
        $query = (string) ($request['query'] ?? '');
        if (str_contains($query, 'mutation InventorySetQuantities')) {
            $lostAttempts++;

            return Http::response('lost', 500);
        }

        return Http::response(cursorInventoryResponse($lost->fresh(), true, 4, [
            'gid://shopify/Location/1' => 4,
        ]));
    });
    $messages = [];
    $run = function () use ($lost, $reviewer, $lostRequest, $service, &$messages): void {
        try {
            (new InventorySyncJob([$lost->id], 'push', $reviewer->id, $lostRequest->sync_batch_id))
                ->handle(app(ProductInventorySyncService::class), $service);
        } catch (\Throwable $exception) {
            $messages[] = $exception->getMessage();
        }
    };
    $run();
    $run();
    expect([
        'attempts' => $lostAttempts,
        'intent' => InventoryWriteIntent::query()->where('variant_id', $lost->id)->value('status'),
        'request' => $lostRequest->fresh()->status,
        'messages' => $messages,
    ])->toBe([
        'attempts' => 1,
        'intent' => 'started',
        'request' => InventoryAdjustmentRequest::STATUS_APPROVED,
        'messages' => $messages,
    ]);
});

it('refuses a stale quantity and a multi-location aggregate', function (): void {
    cursorShopifyConfig();
    $requester = inventoryAuthorizedUser();
    $reviewer = inventoryAuthorizedUser();
    $service = app(InventoryAdjustmentApprovalService::class);
    $stale = cursorVariant('CURSOR-STALE', true, 4);
    $staleRequest = $service->approve($service->submit([[
        'variant' => $stale,
        'inventory_tracked' => true,
        'on_hand_quantity' => 9,
        'reason' => 'Stale',
    ]], $requester->id, $reviewer->id), $reviewer->id, false);
    $writes = 0;
    Http::fake(function ($request) use ($stale, &$writes) {
        if (str_contains((string) ($request['query'] ?? ''), 'mutation InventorySetQuantities')) {
            $writes++;
        }

        return Http::response(cursorInventoryResponse($stale->fresh(), true, 7, [
            'gid://shopify/Location/1' => 7,
        ]));
    });
    $staleMessage = null;
    try {
        (new InventorySyncJob([$stale->id], 'push', $reviewer->id, $staleRequest->sync_batch_id))
            ->handle(app(ProductInventorySyncService::class), $service);
    } catch (\Throwable $exception) {
        $staleMessage = $exception->getMessage();
    }
    expect($writes)->toBe(0)
        ->and($staleMessage)->toContain('Shopify stock changed')
        ->and($staleRequest->fresh()->status)->not->toBe(InventoryAdjustmentRequest::STATUS_APPLIED);

    $multi = cursorVariant('CURSOR-MULTI', true, 4);
    $multiRequest = $service->approve($service->submit([[
        'variant' => $multi,
        'inventory_tracked' => true,
        'on_hand_quantity' => 9,
        'reason' => 'Locations',
    ]], $requester->id, $reviewer->id), $reviewer->id, false);
    cursorReplaceHttp(fn ($request) => Http::response(cursorInventoryResponse($multi->fresh(), true, 4, [
        'gid://shopify/Location/1' => 2,
        'gid://shopify/Location/2' => 2,
    ])));
    expect(fn () => (new InventorySyncJob([$multi->id], 'push', $reviewer->id, $multiRequest->sync_batch_id))
        ->handle(app(ProductInventorySyncService::class), $service))->toThrow(RuntimeException::class, 'multiple inventory locations');
    expect($multiRequest->fresh()->status)->not->toBe(InventoryAdjustmentRequest::STATUS_APPLIED);
});

it('keeps duplicate webhook intent and routes receipts away from the claimed receipt queue', function (): void {
    app(InventoryLevelReconciliation::class)->record('123');
    app(InventoryLevelReconciliation::class)->record('123');
    $row = \Illuminate\Support\Facades\DB::table('inventory_reconciliations')->where('inventory_item_id', '123')->first();
    expect((int) $row->version)->toBe(2)->and((int) $row->processed_version)->toBe(0);

    $routed = (new ProcessSupplierReceiptJob(1))->onQueue('procurement');
    expect($routed->connection)->toBe('inventory-receipts')
        ->and($routed->queue)->toBe('inventory-receipts');
});

it('does not replay a supplier delta when Shopify returns a bare throttle', function (): void {
    cursorShopifyConfig();
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;
        if ($calls === 1) {
            return Http::response('', 429);
        }

        return Http::response(['data' => ['inventoryAdjustQuantities' => [
            'inventoryAdjustmentGroup' => ['createdAt' => now()->toIso8601String()],
            'userErrors' => [],
        ]]]);
    });

    expect(fn () => app(ShopifyApiClient::class)->graphql(<<<'GRAPHQL'
mutation ReceiveProcurementStock($input: InventoryAdjustQuantitiesInput!) {
  inventoryAdjustQuantities(input: $input) {
    inventoryAdjustmentGroup { createdAt }
    userErrors { field message }
  }
}
GRAPHQL, ['input' => [
        'name' => 'available',
        'reason' => 'received',
        'referenceDocumentUri' => 'logistics://shopify-editor/procurement-receipt/cursor',
        'changes' => [[
            'inventoryItemId' => 'gid://shopify/InventoryItem/cursor',
            'locationId' => 'gid://shopify/Location/1',
            'delta' => 5,
        ]],
    ]]))->toThrow(RuntimeException::class, 'uncertain');

    expect($calls)->toBe(1);
});

it('blocks engraved bead mutations while both write flags are off', function (): void {
    config([
        'engraved_beads.enabled' => true,
        'engraved_beads.inventory_writes_enabled' => false,
        'engraved_beads.store_contract_verified' => false,
    ]);
    $variant = cursorVariant('99001990', true, 3);
    $variant->forceFill([
        'shopify_id' => 'gid://shopify/ProductVariant/99001990',
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/99001990',
    ])->saveQuietly();
    $variant->product->forceFill([
        'shopify_id' => 'gid://shopify/Product/99001990',
        'tags' => 'gold-bead-family',
        'engraved_bead_quantity' => 1,
    ])->saveQuietly();

    expect(fn () => app(EngravedBeadMutationGuard::class)->graphql(
        'mutation InventorySetQuantities($input: InventorySetQuantitiesInput!) { inventorySetQuantities(input: $input) { userErrors { message } } }',
        ['input' => ['quantities' => [['inventoryItemId' => $variant->shopify_inventory_item_id, 'quantity' => 9]]]],
    ))->toThrow(RuntimeException::class, 'disabled');
});

function cursorRunDaily(): void
{
    (new DailyShopifyInventoryRefreshJob)->handle(
        app(ProductInventorySyncService::class),
        app(StackBundleSellabilityService::class),
        app(StackSellabilityShopifyPushService::class),
        app(StackSellabilitySlackNotifier::class),
    );
}

function cursorStackResponse(array $records): \Illuminate\Http\Client\Response
{
    return Http::response(['data' => ['product' => [
        'id' => $records['stack']->shopify_id,
        'status' => 'ACTIVE',
        'variants' => ['nodes' => [[
            'id' => $records['stackVariant']->shopify_id,
            'sku' => $records['stackVariant']->sku,
            'inventoryQuantity' => 0,
            'inventoryItem' => [
                'id' => $records['stackVariant']->shopify_inventory_item_id,
                'tracked' => true,
                'inventoryLevels' => ['nodes' => [[
                    'location' => ['id' => 'gid://shopify/Location/1'],
                    'quantities' => [
                        ['name' => 'on_hand', 'quantity' => 0],
                        ['name' => 'available', 'quantity' => 0],
                    ],
                ]]],
            ],
        ]]],
    ]]]);
}

it('keeps a previously unsellable stack unsellable for every incomplete component read', function (string $mode): void {
    Bus::fake();
    Notification::fake();
    cursorShopifyConfig();
    $records = cursorStack(5, true);
    $component = $records['componentVariant'];
    Http::fake(function ($request) use ($records, $component, $mode) {
        if (($request['variables']['id'] ?? null) !== $records['component']->shopify_id) {
            return cursorStackResponse($records);
        }
        $node = [
            'id' => $component->shopify_id,
            'sku' => $component->sku,
            'inventoryQuantity' => 9,
            'inventoryItem' => [
                'id' => $component->shopify_inventory_item_id,
                'tracked' => true,
                'inventoryLevels' => ['nodes' => [[
                    'location' => ['id' => 'gid://shopify/Location/1'],
                    'quantities' => [
                        ['name' => 'on_hand', 'quantity' => 9],
                        ['name' => 'available', 'quantity' => 9],
                    ],
                ]]],
            ],
        ];
        $product = ['id' => $records['component']->shopify_id, 'status' => 'ACTIVE', 'variants' => ['nodes' => [$node]]];
        if ($mode === 'empty') {
            $product['variants']['nodes'] = [];
        } elseif ($mode === 'missing-variant') {
            $product['variants']['nodes'][0]['id'] = 'gid://shopify/ProductVariant/someone-else';
            $product['variants']['nodes'][0]['sku'] = 'SOMEONE-ELSE';
            $product['variants']['nodes'][0]['inventoryItem']['id'] = 'gid://shopify/InventoryItem/someone-else';
        } elseif ($mode === 'missing-item') {
            unset($product['variants']['nodes'][0]['inventoryItem']);
        } elseif ($mode === 'null-tracking') {
            $product['variants']['nodes'][0]['inventoryItem']['tracked'] = null;
        } elseif ($mode === 'null-quantity') {
            $product['variants']['nodes'][0]['inventoryQuantity'] = null;
            $product['variants']['nodes'][0]['inventoryItem']['inventoryLevels']['nodes'][0]['quantities'] = [
                ['name' => 'on_hand', 'quantity' => null],
                ['name' => 'available', 'quantity' => null],
            ];
        } elseif ($mode === 'pagination') {
            $product['variants']['pageInfo'] = ['hasNextPage' => true];
        } elseif ($mode === 'timeout') {
            throw new \Illuminate\Http\Client\ConnectionException('timed out');
        } elseif ($mode === 'malformed') {
            return Http::response(['errors' => [['message' => 'malformed inventory payload']]]);
        }

        return Http::response(['data' => ['product' => $product]]);
    });

    cursorRunDaily();

    expect($component->fresh()->inventory_qty)->toBe(5)
        ->and($records['stackVariant']->fresh()->inventory_tracked)->toBeTrue()
        ->and($records['stackVariant']->fresh()->inventory_qty)->toBe(0)
        ->and(\Illuminate\Support\Facades\DB::table('inventory_reconciliations')
            ->where('inventory_item_id', $component->shopify_inventory_item_id)->exists())->toBeTrue();
})->with([
    'empty variants' => ['empty'],
    'missing variant' => ['missing-variant'],
    'missing item' => ['missing-item'],
    'null tracking' => ['null-tracking'],
    'null quantity' => ['null-quantity'],
    'pagination' => ['pagination'],
    'timeout' => ['timeout'],
    'malformed' => ['malformed'],
]);

it('restores a stack only after a later refresh confirms component stock', function (string $mode, ?bool $tracked, ?int $quantity, bool $sellable): void {
    Bus::fake();
    Notification::fake();
    cursorShopifyConfig();
    $records = cursorStack(0, true);
    Http::fake(function ($request) use ($records, $tracked, $quantity) {
        $variant = ($request['variables']['id'] ?? null) === $records['component']->shopify_id
            ? $records['componentVariant']
            : $records['stackVariant'];
        $product = $variant->is($records['componentVariant']) ? $records['component'] : $records['stack'];
        $confirmed = $variant->is($records['componentVariant']) ? $quantity : 0;
        $confirmedTracked = $variant->is($records['componentVariant']) ? $tracked : true;

        return Http::response(['data' => ['product' => [
            'id' => $product->shopify_id,
            'status' => 'ACTIVE',
            'variants' => ['nodes' => [[
                'id' => $variant->shopify_id,
                'sku' => $variant->sku,
                'inventoryQuantity' => $confirmed,
                'inventoryItem' => [
                    'id' => $variant->shopify_inventory_item_id,
                    'tracked' => $confirmedTracked,
                    'inventoryLevels' => ['nodes' => $confirmedTracked === true ? [[
                        'location' => ['id' => 'gid://shopify/Location/1'],
                        'quantities' => [
                            ['name' => 'on_hand', 'quantity' => $confirmed],
                            ['name' => 'available', 'quantity' => $confirmed],
                        ],
                    ]] : []],
                ],
            ]]],
        ]]]);
    });

    cursorRunDaily();

    $stack = $records['stackVariant']->fresh();
    expect($records['componentVariant']->fresh()->inventory_tracked)->toBe($tracked);
    if ($sellable) {
        expect($stack->inventory_tracked)->toBeFalse()->and($stack->inventory_qty)->toBeNull();
    } else {
        expect($stack->inventory_tracked)->toBeTrue()->and($stack->inventory_qty)->toBe(0);
    }
})->with([
    'explicit zero' => ['explicit zero', true, 0, false],
    'confirmed positive' => ['confirmed positive', true, 4, true],
    'explicit untracked' => ['explicit untracked', false, null, true],
]);

it('recovers stack sellability when a complete refresh follows an incomplete one', function (): void {
    Bus::fake();
    Notification::fake();
    cursorShopifyConfig();
    $records = cursorStack(5, true);
    cursorReplaceHttp(fn () => Http::response(['data' => ['product' => ['id' => 'missing', 'variants' => ['nodes' => []]]]]));
    cursorRunDaily();
    expect($records['stackVariant']->fresh()->inventory_tracked)->toBeTrue()
        ->and($records['stackVariant']->fresh()->inventory_qty)->toBe(0);

    cursorReplaceHttp(function ($request) use ($records) {
        $variant = ($request['variables']['id'] ?? null) === $records['component']->shopify_id
            ? $records['componentVariant'] : $records['stackVariant'];
        $product = $variant->is($records['componentVariant']) ? $records['component'] : $records['stack'];
        $quantity = $variant->is($records['componentVariant']) ? 4 : 0;

        return Http::response(['data' => ['product' => [
            'id' => $product->shopify_id,
            'status' => 'ACTIVE',
            'variants' => ['nodes' => [[
                'id' => $variant->shopify_id,
                'sku' => $variant->sku,
                'inventoryQuantity' => $quantity,
                'inventoryItem' => [
                    'id' => $variant->shopify_inventory_item_id,
                    'tracked' => true,
                    'inventoryLevels' => ['nodes' => [[
                        'location' => ['id' => 'gid://shopify/Location/1'],
                        'quantities' => [
                            ['name' => 'on_hand', 'quantity' => $quantity],
                            ['name' => 'available', 'quantity' => $quantity],
                        ],
                    ]]],
                ],
            ]]],
        ]]]);
    });
    cursorRunDaily();

    expect($records['componentVariant']->fresh()->inventory_qty)->toBe(4)
        ->and($records['stackVariant']->fresh()->inventory_tracked)->toBeFalse()
        ->and($records['stackVariant']->fresh()->inventory_qty)->toBeNull();
});

function cursorConfirmedInventory(Variant $variant, bool $tracked, ?int $onHand): array
{
    return cursorInventoryResponse($variant, $tracked, $onHand, $tracked ? ['gid://shopify/Location/1' => (int) $onHand] : []);
}

it('publishes an approved correction after a refresh clears its local dirty flag', function (): void {
    cursorShopifyConfig();
    $requester = inventoryAuthorizedUser();
    $reviewer = inventoryAuthorizedUser();
    $variant = cursorVariant('CURSOR-REFRESHED', false, null);
    $service = app(InventoryAdjustmentApprovalService::class);
    $approved = $service->approve($service->submit([[
        'variant' => $variant,
        'inventory_tracked' => true,
        'on_hand_quantity' => 0,
        'reason' => 'Confirm after refresh',
    ]], $requester->id, $reviewer->id), $reviewer->id, false);
    cursorReplaceHttp(fn () => Http::response(cursorConfirmedInventory($variant->fresh(), false, null)));
    app(ProductInventorySyncService::class)->refreshVariants(collect([$variant->fresh()]), $reviewer->id);
    expect($approved->fresh()->status)->toBe(InventoryAdjustmentRequest::STATUS_APPROVED)
        ->and($variant->fresh()->inventory_local_dirty)->toBeTrue();

    $mutations = 0;
    $remoteTracked = false;
    $remoteOnHand = null;
    cursorReplaceHttp(function ($request) use ($variant, &$mutations, &$remoteTracked, &$remoteOnHand) {
        $query = (string) ($request['query'] ?? '');
        if (str_contains($query, 'InventoryItemTrackingUpdate')) {
            $mutations++;
            $remoteTracked = true;

            return Http::response(['data' => ['inventoryItemUpdate' => ['inventoryItem' => ['id' => $variant->shopify_inventory_item_id, 'tracked' => true], 'userErrors' => []]]]);
        }
        if (str_contains($query, 'mutation InventorySetQuantities')) {
            $mutations++;
            $remoteOnHand = 0;

            return Http::response(['data' => ['inventorySetQuantities' => ['inventoryAdjustmentGroup' => ['createdAt' => now()->toIso8601String()], 'userErrors' => []]]]);
        }

        return Http::response(cursorConfirmedInventory($variant->fresh(), $remoteTracked, $remoteOnHand));
    });
    (new InventorySyncJob([$variant->id], 'push', $reviewer->id, $approved->sync_batch_id))
        ->handle(app(ProductInventorySyncService::class), $service);

    expect($mutations)->toBe(2)
        ->and($approved->fresh()->status)->toBe(InventoryAdjustmentRequest::STATUS_APPLIED)
        ->and($variant->fresh()->inventory_tracked)->toBeTrue()
        ->and($variant->fresh()->current_on_hand_quantity)->toBe(0);
});

it('does not apply an approval when publication is rejected, partial, or unauthorized', function (string $mode): void {
    cursorShopifyConfig();
    $requester = inventoryAuthorizedUser();
    $reviewer = inventoryAuthorizedUser();
    $variant = cursorVariant('CURSOR-'.$mode, false, null);
    $service = app(InventoryAdjustmentApprovalService::class);
    $approved = $service->approve($service->submit([[
        'variant' => $variant,
        'inventory_tracked' => true,
        'on_hand_quantity' => 0,
        'reason' => 'Must be confirmed',
    ]], $requester->id, $reviewer->id), $reviewer->id, false);
    if ($mode === 'permission') {
        $reviewer->revokePermissionTo(\App\Enums\PermissionEnum::InventoryUpdate->value);
    }
    $mutations = 0;
    Http::fake(function ($request) use ($variant, $mode, &$mutations) {
        $query = (string) ($request['query'] ?? '');
        if (str_contains($query, 'InventoryItemTrackingUpdate')) {
            $mutations++;
            $errors = $mode === 'reject-tracking' ? [['message' => 'Tracking rejected']] : [];

            return Http::response(['data' => ['inventoryItemUpdate' => ['inventoryItem' => ['id' => $variant->shopify_inventory_item_id, 'tracked' => $errors === []], 'userErrors' => $errors]]]);
        }
        if (str_contains($query, 'mutation InventorySetQuantities')) {
            $mutations++;
            $errors = $mode === 'quantity-unconfirmed' ? [['message' => 'Quantity rejected']] : [];

            return Http::response(['data' => ['inventorySetQuantities' => ['inventoryAdjustmentGroup' => $errors === [] ? ['createdAt' => now()->toIso8601String()] : null, 'userErrors' => $errors]]]);
        }
        if ($mode === 'incomplete') {
            return Http::response(['data' => ['product' => null]]);
        }

        return Http::response(cursorConfirmedInventory($variant->fresh(), $mutations > 0, $mode === 'quantity-unconfirmed' ? null : 0));
    });

    $caught = null;
    try {
        (new InventorySyncJob([$variant->id], 'push', $reviewer->id, $approved->sync_batch_id))
            ->handle(app(ProductInventorySyncService::class), $service);
    } catch (\Throwable $exception) {
        $caught = $exception;
    }

    expect($caught)->not->toBeNull()
        ->and($approved->fresh()->status)->not->toBe(InventoryAdjustmentRequest::STATUS_APPLIED)
        ->and($variant->fresh()->inventory_tracked)->not->toBeTrue();
    if ($mode === 'permission' || $mode === 'incomplete') {
        expect($mutations)->toBe(0);
    }
})->with([
    'permission',
    'reject-tracking',
    'quantity-unconfirmed',
    'incomplete',
]);

it('does not send a second correction when the same approval job is delivered twice or restarted', function (): void {
    cursorShopifyConfig();
    $requester = inventoryAuthorizedUser();
    $reviewer = inventoryAuthorizedUser();
    $variant = cursorVariant('CURSOR-ONCE', true, 4);
    $service = app(InventoryAdjustmentApprovalService::class);
    $approved = $service->approve($service->submit([[
        'variant' => $variant,
        'inventory_tracked' => true,
        'on_hand_quantity' => 9,
        'reason' => 'Deliver once',
    ]], $requester->id, $reviewer->id), $reviewer->id, false);
    $mutations = 0;
    Http::fake(function ($request) use ($variant, &$mutations) {
        if (str_contains((string) ($request['query'] ?? ''), 'mutation InventorySetQuantities')) {
            $mutations++;

            return Http::response(['data' => ['inventorySetQuantities' => ['inventoryAdjustmentGroup' => ['createdAt' => now()->toIso8601String()], 'userErrors' => []]]]);
        }

        return Http::response(cursorConfirmedInventory($variant->fresh(), true, $mutations > 0 ? 9 : 4));
    });
    $job = new InventorySyncJob([$variant->id], 'push', $reviewer->id, $approved->sync_batch_id);
    $job->handle(app(ProductInventorySyncService::class), $service);
    $job->handle(app(ProductInventorySyncService::class), $service);
    expect($mutations)->toBe(1)->and($approved->fresh()->status)->toBe(InventoryAdjustmentRequest::STATUS_APPLIED);

    $restart = cursorVariant('CURSOR-RESTART', true, 4);
    $restarted = $service->approve($service->submit([[
        'variant' => $restart,
        'inventory_tracked' => true,
        'on_hand_quantity' => 9,
        'reason' => 'Worker restart',
    ]], $requester->id, $reviewer->id), $reviewer->id, false);
    $attempts = 0;
    cursorReplaceHttp(function ($request) use ($restart, &$attempts) {
        if (str_contains((string) ($request['query'] ?? ''), 'mutation InventorySetQuantities')) {
            $attempts++;

            return Http::response('lost', 500);
        }

        return Http::response(cursorConfirmedInventory($restart->fresh(), true, 4));
    });
    $again = new InventorySyncJob([$restart->id], 'push', $reviewer->id, $restarted->sync_batch_id);
    expect(fn () => $again->handle(app(ProductInventorySyncService::class), $service))->toThrow(RuntimeException::class);
    expect(fn () => $again->handle(app(ProductInventorySyncService::class), $service))->toThrow(RuntimeException::class);
    expect($attempts)->toBe(1)
        ->and(InventoryWriteIntent::query()->where('variant_id', $restart->id)->value('status'))->toBe('started')
        ->and($restarted->fresh()->status)->toBe(InventoryAdjustmentRequest::STATUS_APPROVED);
});

it('does not replay an inventory increase after an ambiguous throttle or a lost receipt response', function (string $mode): void {
    cursorShopifyConfig();
    $calls = 0;
    Http::fake(function () use (&$calls, $mode) {
        $calls++;
        if ($calls === 1 && $mode === 'cost') {
            return Http::response([
                'errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]],
                'extensions' => ['cost' => ['actualQueryCost' => 10, 'requestedQueryCost' => 10]],
            ], 429);
        }
        if ($calls === 1 && $mode === 'pre-execution') {
            return Http::response([
                'errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]],
                'extensions' => ['cost' => ['requestedQueryCost' => 10, 'throttleStatus' => ['currentlyAvailable' => 0, 'restoreRate' => 1000]]],
            ]);
        }

        return Http::response(['data' => ['inventoryAdjustQuantities' => ['inventoryAdjustmentGroup' => ['createdAt' => now()->toIso8601String()], 'userErrors' => []]]]);
    });
    $send = fn () => app(ShopifyApiClient::class)->graphql(<<<'GRAPHQL'
mutation ReceiveProcurementStock($input: InventoryAdjustQuantitiesInput!, $idempotencyKey: String!) {
  inventoryAdjustQuantities(input: $input) @idempotent(key: $idempotencyKey) {
    inventoryAdjustmentGroup { createdAt }
    userErrors { field message }
  }
}
GRAPHQL, ['input' => ['changes' => [['delta' => 5]]], 'idempotencyKey' => 'cursor-key']);
    if ($mode === 'pre-execution') {
        expect(data_get($send(), 'inventoryAdjustQuantities.inventoryAdjustmentGroup'))->toBeArray();
        expect($calls)->toBe(2);
    } else {
        expect($send)->toThrow(RuntimeException::class, 'uncertain');
        expect($calls)->toBe(1);
    }
})->with([
    'cost',
    'pre-execution',
]);

it('applies one supplier receipt once and quarantines an uncertain delivery', function (): void {
    cursorShopifyConfig();
    config(['google_sheets.enabled' => false, 'queue.connections.inventory-receipts.after_commit' => false]);
    Queue::fake();
    $variant = cursorVariant('CURSOR-RECEIPT', true, 3);
    $line = app(SupplierOrderService::class)->createForVariant($variant, 'PO-CURSOR', 4, '2026-11-10');
    $receipt = app(SupplierReceiptService::class)->create($line, 2, 'cursor-receipt');
    Queue::assertPushed(ProcessSupplierReceiptJob::class, fn (ProcessSupplierReceiptJob $job): bool => $job->queue === 'inventory-receipts' && $job->connection === 'inventory-receipts');

    $adjustments = 0;
    $key = null;
    Http::fake(function ($request) use ($variant, &$adjustments, &$key) {
        $query = (string) ($request['query'] ?? '');
        if (str_contains($query, 'inventoryAdjustQuantities')) {
            $adjustments++;
            $key = $request['variables']['idempotencyKey'] ?? null;
            expect($request['variables']['input']['changes'][0]['delta'])->toBe(2);

            return Http::response(['data' => ['inventoryAdjustQuantities' => ['inventoryAdjustmentGroup' => ['createdAt' => now()->toIso8601String()], 'userErrors' => []]]]);
        }

        return Http::response(cursorConfirmedInventory($variant->fresh(), true, 5));
    });
    $job = new ProcessSupplierReceiptJob($receipt->id);
    $job->handle(
        app(\App\Services\Shopify\ShopifyInventoryAdjustmentService::class),
        app(ProductInventorySyncService::class),
        app(\App\Services\Procurement\SupplierOrderSummaryService::class),
        app(\App\Services\GoogleSheets\ProcurementSheetSyncService::class),
    );
    $job->handle(
        app(\App\Services\Shopify\ShopifyInventoryAdjustmentService::class),
        app(ProductInventorySyncService::class),
        app(\App\Services\Procurement\SupplierOrderSummaryService::class),
        app(\App\Services\GoogleSheets\ProcurementSheetSyncService::class),
    );
    expect($adjustments)->toBe(1)
        ->and($key)->toBe(hash('sha256', 'inventory-adjust:'.$receipt->shopify_reference_uri))
        ->and($receipt->fresh()->status)->toBe('succeeded');

    $lost = app(SupplierReceiptService::class)->create($line, 1, 'cursor-lost', dispatch: false);
    $lostCalls = 0;
    cursorReplaceHttp(function () use (&$lostCalls) {
        $lostCalls++;

        return Http::response('', 200);
    });
    expect(fn () => (new ProcessSupplierReceiptJob($lost->id))->handle(
        app(\App\Services\Shopify\ShopifyInventoryAdjustmentService::class),
        app(ProductInventorySyncService::class),
        app(\App\Services\Procurement\SupplierOrderSummaryService::class),
        app(\App\Services\GoogleSheets\ProcurementSheetSyncService::class),
    ))->toThrow(RuntimeException::class);
    (new ProcessSupplierReceiptJob($lost->id))->handle(
        app(\App\Services\Shopify\ShopifyInventoryAdjustmentService::class),
        app(ProductInventorySyncService::class),
        app(\App\Services\Procurement\SupplierOrderSummaryService::class),
        app(\App\Services\GoogleSheets\ProcurementSheetSyncService::class),
    );
    expect($lostCalls)->toBe(1)
        ->and($lost->fresh()->status)->toBe('manual_review')
        ->and(fn () => app(SupplierReceiptService::class)->create($line, 1, 'cursor-replacement', dispatch: false))->toThrow(\Illuminate\Validation\ValidationException::class);
});
