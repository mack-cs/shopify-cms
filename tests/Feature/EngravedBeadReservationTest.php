<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Models\EngravedBeadFamily;
use App\Models\EngravedBeadOrderLine;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyFulfillment;
use App\Models\ShopifyStackInventoryMovement;
use App\Models\ShopifyStackInventoryReservation;
use App\Models\ShopifyStackOrderEvent;
use App\Models\Variant;
use App\Services\EngravedBeads\EngravedBeadConfiguration;
use App\Services\EngravedBeads\EngravedBeadFamilyDiscovery;
use App\Services\EngravedBeads\EngravedBeadFulfillmentReconciliation;
use App\Services\Shopify\ShopifyInventoryAdjustmentService;
use App\Services\Shopify\StackFulfillmentInventoryService;
use App\Services\Shopify\StackInventoryMovementService;
use App\Services\Shopify\StackOrderFulfillmentInspector;
use App\Services\Shopify\StackOrderLineMapper;
use App\Services\Shopify\StackOrderReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/EngravedBeadFamilyFixture.php';

beforeEach(function (): void {
    config(['engraved_beads.enabled' => true, 'engraved_beads.inventory_writes_enabled' => true,
        'engraved_beads.store_contract_verified' => true, 'services.shopify.api_version' => '2026-04']);
});

it('reserves full multi-bead demand on the family source and leaves one-bead commitments to Shopify', function (): void {
    $four = beadSku('FOUR', '4-engraved-beads', 9401);
    $three = beadSku('THREE', '3-engraved-beads', 9402);
    $one = beadSku('ONE', '1-engraved-beads', 9403);
    $inventory = beadInventory();
    $inventory->shouldReceive('moveQuantity')->twice()->withArgs(
        fn (Variant $variant, int $quantity): bool => ($variant->is($four['source']) && $quantity === 8)
            || ($variant->is($three['source']) && $quantity === 6)
    )->andReturn(['createdAt' => now()->toIso8601String()]);

    beadReservationService($inventory)->process(beadOrder(8101, [
        ['id' => 11001, 'variant_id' => 9401, 'sku' => 'FOUR', 'quantity' => 2],
        ['id' => 11002, 'variant_id' => 9402, 'sku' => 'THREE', 'quantity' => 2],
        ['id' => 11003, 'variant_id' => 9403, 'sku' => 'ONE', 'quantity' => 3],
    ]));

    expect(ShopifyStackInventoryReservation::query()->sum('reserved_quantity'))->toBe(14)
        ->and(ShopifyStackInventoryReservation::query()->where('component_sku', 'ONE')->exists())->toBeFalse()
        ->and(EngravedBeadOrderLine::query()->where('sku', 'ONE')->value('configuration'))->toBe('zero')
        ->and(EngravedBeadOrderLine::query()->where('sku', 'ONE')->value('first_bead_per_unit'))->toBe(0)
        ->and(EngravedBeadOrderLine::query()->where('sku', 'ONE')->value('additional_per_unit'))->toBe(0)
        ->and(EngravedBeadOrderLine::query()->where('sku', 'FOUR')->value('beads'))->toBe(4)
        ->and(EngravedBeadOrderLine::query()->where('sku', 'FOUR')->value('first_bead_per_unit'))->toBe(1)
        ->and(EngravedBeadOrderLine::query()->where('sku', 'FOUR')->value('additional_per_unit'))->toBe(3)
        ->and(EngravedBeadOrderLine::query()->where('sku', 'THREE')->value('first_bead_per_unit'))->toBe(1)
        ->and(EngravedBeadOrderLine::query()->where('sku', 'THREE')->value('additional_per_unit'))->toBe(2);
});

it('consumes only the fulfilled physical quantity and keeps the rest reserved', function (): void {
    beadSku('FOUR', '4-engraved-beads', 9401);
    $inventory = beadInventory();
    $inventory->shouldReceive('moveQuantity')->once()->withArgs(
        fn (Variant $variant, int $quantity, string $from, string $to): bool => $quantity === 12 && $from === 'available' && $to === 'reserved'
    )->andReturn(['createdAt' => now()->toIso8601String()]);
    $inventory->shouldReceive('consumeReserved')->once()->withArgs(
        fn (Variant $variant, int $quantity): bool => $quantity === 4
    )->andReturn(['createdAt' => now()->toIso8601String()]);
    $movements = new StackInventoryMovementService($inventory);
    $orders = beadReservationService($inventory, $movements);
    $orders->process(beadOrder(8102, [
        ['id' => 11001, 'variant_id' => 9401, 'sku' => 'FOUR', 'quantity' => 3],
    ]));

    $fulfillment = beadFulfillment(7102, 8102, 1);
    $fulfillments = new StackFulfillmentInventoryService($movements);
    $fulfillments->process($fulfillment);
    $fulfillments->process($fulfillment->fresh());

    $reservation = ShopifyStackInventoryReservation::query()->first();
    expect($reservation->consumed_quantity)->toBe(4)
        ->and($reservation->remainingReserved())->toBe(8)
        ->and(ShopifyStackInventoryMovement::query()->where('action', 'consume')->count())->toBe(1);
});

it('releases an outstanding reservation on cancellation and does not release it twice', function (): void {
    beadSku('TWO', '2-engraved-beads', 9404);
    $inventory = beadInventory();
    $inventory->shouldReceive('moveQuantity')->twice()->andReturn(['createdAt' => now()->toIso8601String()]);
    $service = beadReservationService($inventory);
    $service->process(beadOrder(8103, [
        ['id' => 11004, 'variant_id' => 9404, 'sku' => 'TWO', 'quantity' => 2],
    ]));
    $service->process(beadOrder(8103, [
        ['id' => 11004, 'variant_id' => 9404, 'sku' => 'TWO', 'quantity' => 2],
    ], 'orders/cancelled', 'cancel-8103'));

    $reservation = ShopifyStackInventoryReservation::query()->first();
    expect($reservation->reserved_quantity)->toBe(4)
        ->and($reservation->released_quantity)->toBe(4)
        ->and($reservation->remainingReserved())->toBe(0)
        ->and($reservation->status)->toBe(ShopifyStackInventoryReservation::STATUS_RELEASED);
});

it('does not reserve or deduct historical fulfilled or canceled orders', function (): void {
    beadSku('FOUR', '4-engraved-beads', 9401);
    EngravedBeadFamily::query()->update(['activated_at' => '2026-10-27 00:00:00']);
    $inventory = beadInventory();
    $inventory->shouldReceive('moveQuantity')->never();
    $service = beadReservationService($inventory, fulfillmentStatus: 'FULFILLED');
    $service->process(beadOrder(8104, [
        ['id' => 11001, 'variant_id' => 9401, 'sku' => 'FOUR', 'quantity' => 2],
    ]));
    $service->process(beadOrder(8105, [
        ['id' => 11005, 'variant_id' => 9401, 'sku' => 'FOUR', 'quantity' => 1],
    ], 'orders/cancelled', 'cancel-historical'));

    expect(ShopifyStackInventoryReservation::query()->count())->toBe(0)
        ->and(EngravedBeadOrderLine::query()->where('configuration', 'historical_skip')->count())->toBe(2);
});

it('reserves a post-cutover partial order in full without inventing consumed stock', function (): void {
    beadSku('FOUR', '4-engraved-beads', 9401);
    $inventory = beadInventory();
    $inventory->shouldReceive('moveQuantity')->once()->withArgs(
        fn (Variant $variant, int $quantity): bool => $quantity === 12
    )->andReturn(['createdAt' => now()->toIso8601String()]);

    beadReservationService($inventory)->process(beadOrder(8106, [[
        'id' => 11006,
        'variant_id' => 9401,
        'sku' => 'FOUR',
        'quantity' => 3,
        'unfulfilled_quantity' => 1,
    ]]));

    $reservation = ShopifyStackInventoryReservation::query()->first();
    expect($reservation->consumed_quantity)->toBe(0)
        ->and($reservation->historical_quantity)->toBe(0)
        ->and($reservation->reserved_quantity)->toBe(12)
        ->and($reservation->remainingReserved())->toBe(12);
});

it('restocks consumed physical beads only on a confirmed restock and ignores refunds that do not', function (): void {
    beadSku('THREE', '3-engraved-beads', 9402);
    $inventory = beadInventory();
    $inventory->shouldReceive('moveQuantity')->once()->andReturn(['createdAt' => now()->toIso8601String()]);
    $inventory->shouldReceive('consumeReserved')->once()->andReturn(['createdAt' => now()->toIso8601String()]);
    $inventory->shouldReceive('restockAvailable')->once()->withArgs(
        fn (Variant $variant, int $quantity): bool => $quantity === 3
    )->andReturn(['createdAt' => now()->toIso8601String()]);
    $movements = new StackInventoryMovementService($inventory);
    $orders = beadReservationService($inventory, $movements);
    $orders->process(beadOrder(8107, [
        ['id' => 11007, 'variant_id' => 9402, 'sku' => 'THREE', 'quantity' => 1],
    ]));
    (new StackFulfillmentInventoryService($movements))->process(beadFulfillment(7107, 8107, 1, 11007));
    $orders->process(beadOrder(8107, [[
        'id' => 11007,
        'variant_id' => 9402,
        'sku' => 'THREE',
        'quantity' => 1,
        'unfulfilled_quantity' => 0,
    ]], 'orders/updated', 'refund-none', [[
        'id' => 501,
        'refund_line_items' => [[
            'line_item_id' => 11007,
            'quantity' => 1,
            'restock_type' => 'no_restock',
        ]],
    ]]));

    expect(ShopifyStackInventoryReservation::query()->value('restocked_quantity'))->toBe(0);

    $orders->process(beadOrder(8107, [[
        'id' => 11007,
        'variant_id' => 9402,
        'sku' => 'THREE',
        'quantity' => 1,
        'unfulfilled_quantity' => 0,
    ]], 'orders/updated', 'refund-return', [[
        'id' => 502,
        'refund_line_items' => [[
            'line_item_id' => 11007,
            'quantity' => 1,
            'restock_type' => 'return',
        ]],
    ]]));
    $orders->process(beadOrder(8107, [[
        'id' => 11007,
        'variant_id' => 9402,
        'sku' => 'THREE',
        'quantity' => 1,
        'unfulfilled_quantity' => 0,
    ]], 'orders/updated', 'refund-return-duplicate', [[
        'id' => 502,
        'refund_line_items' => [[
            'line_item_id' => 11007,
            'quantity' => 1,
            'restock_type' => 'return',
        ]],
    ]]));

    expect(ShopifyStackInventoryReservation::query()->value('restocked_quantity'))->toBe(3)
        ->and(ShopifyStackInventoryMovement::query()->where('action', 'restock')->count())->toBe(1);
});

it('waits for the order reservation when fulfillment arrives first and then consumes once', function (): void {
    beadSku('TWO', '2-engraved-beads', 9404);
    $inventory = beadInventory();
    $inventory->shouldReceive('moveQuantity')->once()->andReturn(['createdAt' => now()->toIso8601String()]);
    $inventory->shouldReceive('consumeReserved')->once()->andReturn(['createdAt' => now()->toIso8601String()]);
    $movements = new StackInventoryMovementService($inventory);
    $fulfillment = beadFulfillment(7108, 8108, 1, 11008, 9404);
    $fulfillments = new StackFulfillmentInventoryService($movements);

    expect(fn () => $fulfillments->process($fulfillment))->toThrow(RuntimeException::class);

    beadReservationService($inventory, $movements)->process(beadOrder(8108, [
        ['id' => 11008, 'variant_id' => 9404, 'sku' => 'TWO', 'quantity' => 1],
    ]));
    $fulfillments->process($fulfillment->fresh());

    expect(ShopifyStackInventoryReservation::query()->value('consumed_quantity'))->toBe(2)
        ->and(ShopifyStackInventoryReservation::query()->value('remaining_reserved') ?? ShopifyStackInventoryReservation::query()->first()->remainingReserved())->toBe(0);
});

it('blocks invalid configurations and unresolved inventory items without adjusting stock', function (): void {
    beadSku('BAD', '2-engraved-beads, 4-engraved-beads', 9405);
    beadSku('UNMAPPED', '3-engraved-beads', 9406, inventoryItem: null);
    $inventory = beadInventory();
    $inventory->shouldReceive('moveQuantity')->never();

    beadReservationService($inventory)->process(beadOrder(8109, [
        ['id' => 11009, 'variant_id' => 9405, 'sku' => 'BAD', 'quantity' => 1, 'title' => 'Rounded 4 engraved beads'],
        ['id' => 11010, 'variant_id' => 9406, 'sku' => 'UNMAPPED', 'quantity' => 1],
    ]));

    expect(ShopifyStackInventoryReservation::query()->count())->toBe(0)
        ->and(EngravedBeadOrderLine::query()->where('sku', 'BAD')->value('configuration'))->toBe('invalid')
        ->and(EngravedBeadOrderLine::query()->where('sku', 'UNMAPPED')->value('configuration'))->toBe('blocked');
});

it('keeps the full physical deduction when the safety buffer is raised', function (): void {
    config(['engraved_beads.thresholds.4' => 10, 'engraved_beads.thresholds.3' => 7, 'engraved_beads.thresholds.2' => 5]);
    $four = beadSku('FOUR', '4-engraved-beads', 9401);
    $inventory = beadInventory();
    $inventory->shouldReceive('moveQuantity')->once()->withArgs(
        fn (Variant $variant, int $quantity): bool => $variant->is($four['source']) && $quantity === 8
    )->andReturn([]);
    beadReservationService($inventory)->process(beadOrder(8112, [
        ['id' => 11012, 'variant_id' => 9401, 'sku' => 'FOUR', 'quantity' => 2],
    ]));
    $line = EngravedBeadOrderLine::query()->first();
    expect($line->first_bead_per_unit)->toBe(1)
        ->and($line->additional_per_unit)->toBe(3)
        ->and(ShopifyStackInventoryReservation::query()->value('total_component_quantity_required'))->toBe(8);
});

it('does not reserve when the store contract flag is off', function (): void {
    config(['engraved_beads.store_contract_verified' => false]);
    beadSku('FOUR', '4-engraved-beads', 9401);
    $inventory = beadInventory();
    $inventory->shouldReceive('moveQuantity')->never();
    beadReservationService($inventory)->process(beadOrder(8113, [
        ['id' => 11013, 'variant_id' => 9401, 'sku' => 'FOUR', 'quantity' => 1],
    ]));
    expect(ShopifyStackInventoryReservation::query()->count())->toBe(0)
        ->and(EngravedBeadOrderLine::query()->value('configuration'))->toBe('blocked');
});

it('ignores an older order webhook after a newer one was processed', function (): void {
    beadSku('FOUR', '4-engraved-beads', 9401);
    $inventory = beadInventory();
    $inventory->shouldReceive('moveQuantity')->once()->andReturn([]);
    $service = beadReservationService($inventory);
    $newer = beadOrder(8301, [['id' => 11001, 'variant_id' => 9401, 'quantity' => 2]], 'orders/updated', 'newer-webhook');
    $newer->update(['shopify_updated_at' => '2026-11-04 12:00:00']);
    $service->process($newer->fresh());
    $older = beadOrder(8301, [['id' => 11001, 'variant_id' => 9401, 'quantity' => 1]], 'orders/updated', 'older-webhook');
    $older->update(['shopify_updated_at' => '2026-11-04 11:00:00']);
    $service->process($older->fresh());
    expect($older->fresh()->error_message)->toContain('newer')
        ->and(ShopifyStackInventoryReservation::query()->value('reserved_quantity'))->toBe(8);
});

it('does not write inventory while engraved bead inventory writes are disabled', function (): void {
    config(['engraved_beads.inventory_writes_enabled' => false]);
    beadSku('FOUR', '4-engraved-beads', 9401);
    $inventory = beadInventory();
    $inventory->shouldReceive('moveQuantity')->never();

    beadReservationService($inventory)->process(beadOrder(8110, [
        ['id' => 11011, 'variant_id' => 9401, 'sku' => 'FOUR', 'quantity' => 1],
    ]));

    expect(ShopifyStackInventoryReservation::query()->count())->toBe(0)
        ->and(EngravedBeadOrderLine::query()->value('configuration'))->toBe('blocked');
});

function beadInventory(): ShopifyInventoryAdjustmentService
{
    $inventory = Mockery::mock(ShopifyInventoryAdjustmentService::class);
    $inventory->shouldReceive('resolveLocationId')->zeroOrMoreTimes()->andReturn('gid://shopify/Location/6001');
    $inventory->shouldReceive('reservedQuantity')->byDefault()->andReturn(999);
    $inventory->shouldReceive('currentQuantities')->byDefault()->andReturn(['available' => 999, 'on_hand' => 999, 'committed' => 0]);

    return $inventory;
}

it('routes different bead-member stack components to the source and converts each component once', function (string $fulfillmentStatus) {
    $f = sharedBeadFamily(active: true);
    $stack = Product::withoutEvents(fn () => Product::query()->create([
        'import_id' => $f['members'][1]['product']->import_id, 'shopify_id' => 'gid://shopify/Product/99001',
        'handle' => 'shared-bead-stack', 'title' => 'Shared bead stack', 'status' => 'active', 'is_bundle' => true,
    ]));
    $variant = Variant::withoutEvents(fn () => Variant::query()->create(['product_id' => $stack->id,
        'shopify_id' => 'gid://shopify/ProductVariant/99001', 'sku' => 'BEAD-STACK', 'sync_state' => Variant::SYNC_STATE_SYNCED]));
    NewProductDraft::withoutEvents(fn () => NewProductDraft::query()->create([
        'shopify_id' => $stack->shopify_id, 'handle' => $stack->handle, 'sku' => $variant->sku,
        'title' => $stack->title, 'tags' => 'bundles', 'status' => 'active', 'origin' => 'draft_tool',
        'bundle_product_ids' => [$f['members'][2]['product']->id, $f['members'][3]['product']->id],
        'bundle_component_quantities' => [
            ['product_id' => $f['members'][2]['product']->id, 'quantity' => 1],
            ['product_id' => $f['members'][3]['product']->id, 'quantity' => 1],
        ],
    ]));
    $inventory = beadInventory();
    $inventory->shouldReceive('moveQuantity')->twice()->withArgs(fn ($source, $quantity) => $source->id === $f['members'][1]['variant']->id && in_array($quantity, [4, 6], true))->andReturn([]);
    beadReservationService($inventory, fulfillmentStatus: $fulfillmentStatus)->process(beadOrder(8290, [['id' => 11990, 'variant_id' => 99001, 'quantity' => 2]]));
    expect(ShopifyStackInventoryReservation::query()->sum('reserved_quantity'))->toBe(10)
        ->and(ShopifyStackInventoryReservation::query()->distinct()->count('component_variant_id'))->toBe(1)
        ->and(ShopifyStackInventoryReservation::query()->distinct()->count('configured_component_product_id'))->toBe(2);
})->with(['UNFULFILLED', 'FULFILLED']);

it('accounts for a post-cutover fast fulfillment instead of classifying it as historical', function () {
    beadSku('FOUR', '4-engraved-beads', 9401);
    $inventory = beadInventory();
    $inventory->shouldReceive('moveQuantity')->once()->withArgs(fn ($variant, $qty) => $qty === 8)->andReturn([]);
    $inventory->shouldReceive('consumeReserved')->once()->withArgs(fn ($variant, $qty) => $qty === 8)->andReturn([]);
    $movements = new StackInventoryMovementService($inventory);
    beadReservationService($inventory, $movements, 'FULFILLED')->process(beadOrder(8201, [
        ['id' => 11001, 'variant_id' => 9401, 'quantity' => 2],
    ]));
    (new StackFulfillmentInventoryService($movements))->process(beadFulfillment(7201, 8201, 2));
    expect(ShopifyStackInventoryReservation::query()->value('consumed_quantity'))->toBe(8);
});

it('releases removed lines and can reserve a line re-added in a later order edit', function () {
    beadSku('FOUR', '4-engraved-beads', 9401);
    $inventory = beadInventory();
    $inventory->shouldReceive('moveQuantity')->times(3)->andReturn([]);
    $service = beadReservationService($inventory);
    $lines = [['id' => 11001, 'variant_id' => 9401, 'quantity' => 2]];
    $service->process(beadOrder(8202, $lines));
    $service->process(beadOrder(8202, [], 'orders/updated', 'removed-line'));
    expect(ShopifyStackInventoryReservation::query()->first()->remainingReserved())->toBe(0);
    $service->process(beadOrder(8202, $lines, 'orders/updated', 're-added-line'));
    expect(ShopifyStackInventoryReservation::query()->first()->remainingReserved())->toBe(8);
});

it('recovers the original completed order event after disabled writes are enabled', function () {
    beadSku('FOUR', '4-engraved-beads', 9401);
    $inventory = beadInventory();
    $inventory->shouldReceive('moveQuantity')->once()->andReturn([]);
    $service = beadReservationService($inventory);
    $event = beadOrder(8203, [['id' => 11001, 'variant_id' => 9401, 'quantity' => 2]]);
    config(['engraved_beads.inventory_writes_enabled' => false]);
    $service->process($event);
    expect(ShopifyStackInventoryReservation::query()->count())->toBe(0);
    config(['engraved_beads.inventory_writes_enabled' => true]);
    $service->process($event->fresh());
    expect(ShopifyStackInventoryReservation::query()->value('reserved_quantity'))->toBe(8);
});

it('does not consume or restock again when fulfillment is reversed', function () {
    beadSku('FOUR', '4-engraved-beads', 9401);
    $inventory = beadInventory();
    $inventory->shouldReceive('moveQuantity')->once()->andReturn([]);
    $inventory->shouldReceive('consumeReserved')->once()->andReturn([]);
    $inventory->shouldReceive('restockAvailable')->never();
    $movements = new StackInventoryMovementService($inventory);
    beadReservationService($inventory, $movements)->process(beadOrder(8204, [['id' => 11001, 'variant_id' => 9401, 'quantity' => 1]]));
    $fulfillment = beadFulfillment(7204, 8204, 1);
    $service = new StackFulfillmentInventoryService($movements);
    $service->process($fulfillment);
    $fulfillment->update(['shopify_status' => 'cancelled', 'processing_status' => ShopifyFulfillment::STATUS_PENDING]);
    $service->process($fulfillment->fresh());
    expect(ShopifyStackInventoryReservation::query()->value('consumed_quantity'))->toBe(4)
        ->and(EngravedBeadFamily::query()->value('status'))->toBe('reconciliation_required');
});

it('reserves from the source safety-stock ledger without temporarily making the source sellable', function () {
    $member = beadSku('FOUR', '4-engraved-beads', 9401);
    $family = EngravedBeadFamily::query()->firstOrFail();
    $family->update(['automation_held_quantity' => 12]);
    $family->members()->where('required_beads', 1)->update(['automation_disabled' => true]);
    $inventory = beadInventory();
    $inventory->shouldReceive('currentQuantities')->andReturn(['available' => 0, 'on_hand' => 12, 'committed' => 0]);
    $inventory->shouldReceive('moveQuantity')->once()->withArgs(function ($variant, $qty, $from, $to, $reason, $reference, $ledger, $key, $location, $audit, $sourceLedger) use ($family): bool {
        return $qty === 8 && $from === 'safety_stock' && $to === 'reserved'
            && $sourceLedger === 'leighavenue-cms://bead-source-safety/'.$family->id
            && str_starts_with($ledger, 'leighavenue-cms://engraved-bead-reservations/');
    })->andReturn([]);
    beadReservationService($inventory)->process(beadOrder(8205, [['id' => 11001, 'variant_id' => 9401, 'quantity' => 2]]));
    expect($family->fresh()->automation_held_quantity)->toBe(4)
        ->and(ShopifyStackInventoryReservation::query()->value('reserved_quantity'))->toBe(8)
        ->and($member['source']->fresh()->inventory_tracked)->toBeTrue();
});

it('recovers missed partial fulfillment webhooks from live snapshots using the same immutable fulfillment IDs', function () {
    beadSku('FOUR', '4-engraved-beads', 9401);
    $inventory = beadInventory();
    $inventory->shouldReceive('moveQuantity')->once()->andReturn([]);
    $inventory->shouldReceive('consumeReserved')->twice()->andReturn([]);
    $movements = new StackInventoryMovementService($inventory);
    beadReservationService($inventory, $movements)->process(beadOrder(8206, [['id' => 11001, 'variant_id' => 9401, 'quantity' => 3, 'unfulfilled_quantity' => 1]]));
    $gateway = Mockery::mock(ShopifyGraphqlGateway::class);
    $gateway->shouldReceive('graphql')->twice()->andReturn(['order' => ['id' => 'gid://shopify/Order/8206',
        'fulfillments' => array_map(fn ($q) => ['id' => 'gid://shopify/Fulfillment/'.(7300 + $q), 'status' => 'SUCCESS',
            'createdAt' => now()->toIso8601String(), 'location' => ['id' => 'gid://shopify/Location/6001'],
            'fulfillmentLineItems' => ['nodes' => [['quantity' => $q, 'lineItem' => ['id' => 'gid://shopify/LineItem/11001',
                'sku' => 'FOUR', 'variant' => ['id' => 'gid://shopify/ProductVariant/9401']]]],
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]], [1, 2]),
    ]]);
    $service = new EngravedBeadFulfillmentReconciliation($gateway, new StackFulfillmentInventoryService($movements));
    $service->reconcile('gid://shopify/Order/8206');
    $service->reconcile('gid://shopify/Order/8206');
    expect(ShopifyStackInventoryReservation::query()->value('consumed_quantity'))->toBe(12)
        ->and(ShopifyFulfillment::query()->count())->toBe(2);
});

function beadReservationService(
    ShopifyInventoryAdjustmentService $inventory,
    ?StackInventoryMovementService $movements = null,
    string $fulfillmentStatus = 'UNFULFILLED',
): StackOrderReservationService {
    $gateway = Mockery::mock(ShopifyGraphqlGateway::class);
    $gateway->shouldReceive('graphql')->zeroOrMoreTimes()->andReturnUsing(
        function (string $query, array $variables) use ($fulfillmentStatus): array {
            $orderId = (string) ($variables['id'] ?? '');
            $event = ShopifyStackOrderEvent::query()->where('shopify_order_id', $orderId)->latest('id')->first();
            $lineNodes = [];
            foreach ((array) data_get($event?->payload, 'line_items', []) as $line) {
                if (! is_array($line)) {
                    continue;
                }
                $current = max(0, (int) ($line['quantity'] ?? 0));
                $unfulfilled = array_key_exists('unfulfilled_quantity', $line)
                    ? (int) $line['unfulfilled_quantity']
                    : ($fulfillmentStatus === 'FULFILLED' ? 0 : $current);
                $lineId = 'gid://shopify/LineItem/'.($line['id'] ?? '');
                $lineNodes[] = ['id' => $lineId, 'currentQuantity' => $current, 'unfulfilledQuantity' => $unfulfilled];
            }
            $page = ['nodes' => $lineNodes, 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]];

            return ['order' => [
                'id' => $orderId,
                'name' => null,
                'displayFulfillmentStatus' => $fulfillmentStatus,
                'cancelledAt' => null,
                'lineItems' => $page,
            ]];
        }
    );
    $movements ??= new StackInventoryMovementService($inventory);

    return new StackOrderReservationService(
        $inventory,
        $movements,
        new StackOrderLineMapper,
        new StackOrderFulfillmentInspector($gateway),
    );
}

/** @return array{product:Product,variant:Variant} */
function beadSku(string $sku, string $tags, int $variantId, ?string $inventoryItem = 'auto'): array
{
    $fixture = sharedBeadFamily('test-'.$variantId.'-bead-family', $variantId * 10, active: true);
    $q = EngravedBeadConfiguration::fromTags($tags)->beads() ?? 4;
    $product = $fixture['members'][$q]['product'];
    $variant = $fixture['members'][$q]['variant'];
    $product->update(['tags' => $fixture['family']->family_tag.', '.$tags]);
    $variant->update(['shopify_id' => 'gid://shopify/ProductVariant/'.$variantId,
        'shopify_inventory_item_id' => $inventoryItem === 'auto' ? 'gid://shopify/InventoryItem/'.$variantId : $inventoryItem,
        'sku' => $sku]);
    if ($inventoryItem !== null) {
        $fixture['family']->members()->where('required_beads', $q)->update([
            'shopify_variant_id' => $variant->shopify_id, 'shopify_inventory_item_id' => $variant->shopify_inventory_item_id, 'sku' => $sku,
        ]);
    }
    if ($q === 1) {
        $fixture['family']->update(['source_variant_id' => $variant->shopify_id, 'source_inventory_item_id' => $variant->shopify_inventory_item_id]);
    }
    $source = $q === 1 ? $variant : $fixture['members'][1]['variant'];
    $observed = app(EngravedBeadFamilyDiscovery::class)->inspect();
    $fixture['family']->update(['mapping_hash' => $observed[$fixture['family']->family_tag]['mapping_hash']]);

    return compact('product', 'variant', 'source');
}

function beadOrder(int $orderId, array $lines, string $topic = 'orders/create', ?string $webhookId = null, array $refunds = []): ShopifyStackOrderEvent
{
    return ShopifyStackOrderEvent::query()->create([
        'webhook_id' => $webhookId ?? 'bead-order-'.$orderId.'-'.uniqid(),
        'topic' => $topic,
        'shopify_order_id' => 'gid://shopify/Order/'.$orderId,
        'shopify_order_name' => '#'.$orderId,
        'payload' => [
            'id' => $orderId,
            'admin_graphql_api_id' => 'gid://shopify/Order/'.$orderId,
            'name' => '#'.$orderId,
            'created_at' => '2026-10-26T10:00:00+02:00',
            'line_items' => $lines,
            'refunds' => $refunds,
        ],
    ]);
}

function beadFulfillment(int $fulfillmentId, int $orderId, int $quantity, int $lineId = 11001, int $variantId = 9401): ShopifyFulfillment
{
    return ShopifyFulfillment::query()->create([
        'shopify_fulfillment_id' => 'gid://shopify/Fulfillment/'.$fulfillmentId,
        'shopify_order_id' => (string) $orderId,
        'shopify_location_id' => '6001',
        'shopify_status' => 'success',
        'fulfilled_at_shopify' => now(),
        'payload' => ['line_items' => [[
            'id' => $lineId,
            'variant_id' => $variantId,
            'sku' => 'FOUR',
            'quantity' => $quantity,
        ]]],
    ]);
}
