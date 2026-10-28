<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Models\EngravedBeadOrderLine;
use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyFulfillment;
use App\Models\ShopifyStackInventoryMovement;
use App\Models\ShopifyStackInventoryReservation;
use App\Models\ShopifyStackOrderEvent;
use App\Models\User;
use App\Models\Variant;
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
    config(['engraved_beads.enabled' => true, 'engraved_beads.inventory_writes_enabled' => true]);
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
        ->and(EngravedBeadOrderLine::query()->where('sku', 'ONE')->value('configuration'))->toBe('zero');
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

it('does not deduct already fulfilled historical quantity and reserves only the remainder', function (): void {
    beadSku('FOUR', '4-engraved-beads', 9401);
    $inventory = beadInventory();
    $inventory->shouldReceive('moveQuantity')->once()->withArgs(
        fn (Variant $variant, int $quantity): bool => $quantity === 4
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
        ->and($reservation->historical_quantity)->toBe(8)
        ->and($reservation->reserved_quantity)->toBe(4)
        ->and($reservation->remainingReserved())->toBe(4);
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

    return $inventory;
}

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
    $q = App\Services\EngravedBeads\EngravedBeadConfiguration::fromTags($tags)->beads() ?? 4;
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
        'payload' => ['line_items' => [[
            'id' => $lineId,
            'variant_id' => $variantId,
            'sku' => 'FOUR',
            'quantity' => $quantity,
        ]]],
    ]);
}
