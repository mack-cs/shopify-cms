<?php

// Independent audit: assertions express required behavior; failures reproduce defects.
use App\Contracts\ShopifyGraphqlGateway;
use App\Models\Product;
use App\Models\ShopifyFulfillment;
use App\Models\ShopifyStackInventoryReservation;
use App\Models\ShopifyStackOrderEvent;
use App\Models\User;
use App\Models\Variant;
use App\Services\Shopify\ProductLaunchService;
use App\Services\Shopify\ShopifyInventoryAdjustmentService;
use App\Services\Shopify\StackFulfillmentInventoryService;
use App\Services\Shopify\StackInventoryMovementService;
use App\Services\Shopify\StackOrderFulfillmentInspector;
use App\Services\Shopify\StackOrderLineMapper;
use App\Services\Shopify\StackOrderReservationService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/EngravedBeadFamilyFixture.php';
beforeEach(function () {
    config(['engraved_beads.enabled' => true, 'engraved_beads.inventory_writes_enabled' => true,
        'engraved_beads.store_contract_verified' => true, 'services.shopify.api_version' => '2026-04']);
    Http::preventStrayRequests();
});

it('AUDIT blocks consumption of an existing bead reservation after writes are disabled', function () {
    auditBeadSku('FOUR', '4-engraved-beads', 9401);
    $inventory = auditBeadInventory();
    $inventory->shouldReceive('moveQuantity')->once()->andReturn([]);
    $inventory->shouldReceive('consumeReserved')->never();
    $movements = new StackInventoryMovementService($inventory);
    auditBeadReservationService($inventory, $movements)->process(auditBeadOrder(9001, [
        ['id' => 11001, 'variant_id' => 9401, 'quantity' => 2],
    ]));
    config(['engraved_beads.inventory_writes_enabled' => false]);
    (new StackFulfillmentInventoryService($movements))->process(auditBeadFulfillment(9901, 9001, 1));
    expect(ShopifyStackInventoryReservation::query()->value('consumed_quantity'))->toBe(0);
});

it('AUDIT never restocks historical beads that were not deducted', function () {
    auditBeadSku('FOUR', '4-engraved-beads', 9401);
    $inventory = auditBeadInventory();
    $inventory->shouldReceive('moveQuantity')->once()->andReturn([]);
    $inventory->shouldReceive('restockAvailable')->never();
    $service = auditBeadReservationService($inventory);
    $lines = [['id' => 11001, 'variant_id' => 9401, 'quantity' => 3, 'unfulfilled_quantity' => 1]];
    $service->process(auditBeadOrder(9002, $lines));
    $service->process(auditBeadOrder(9002, $lines, 'orders/updated', 'audit-refund', [[
        'id' => 701, 'refund_line_items' => [['line_item_id' => 11001, 'quantity' => 1, 'restock_type' => 'return']],
    ]]));
});

it('AUDIT refreshes procurement configuration when tags are imported quietly', function () {
    $sku = auditBeadSku('FOUR', '4-engraved-beads', 9401);
    $product = $sku['product'];
    $product->tags = 'ordinary';
    $product->saveQuietly();
    expect(Product::query()->procurementCatalogEligible()->whereKey($product->id)->exists())->toBeFalse();
});

it('AUDIT does not allow an old historical fulfillment to consume the new remainder', function () {
    auditBeadSku('FOUR', '4-engraved-beads', 9401);
    $inventory = auditBeadInventory();
    $inventory->shouldReceive('moveQuantity')->once()->andReturn([]);
    $inventory->shouldReceive('consumeReserved')->never();
    $movements = new StackInventoryMovementService($inventory);
    auditBeadReservationService($inventory, $movements)->process(auditBeadOrder(9003, [
        ['id' => 11001, 'variant_id' => 9401, 'quantity' => 3, 'unfulfilled_quantity' => 1],
    ]));
    $oldFulfillment = auditBeadFulfillment(9903, 9003, 2);
    $oldFulfillment->update(['fulfilled_at_shopify' => '2026-10-24 00:00:00']);
    (new StackFulfillmentInventoryService($movements))->process($oldFulfillment->fresh());
    expect(ShopifyStackInventoryReservation::query()->first()->remainingReserved())->toBe(12);
});

it('AUDIT ignores an unauthorized launch request at the service boundary', function () {
    Bus::fake();
    $user = User::factory()->create();
    $sku = auditBeadSku('FOUR', '4-engraved-beads', 9401);
    expect(fn () => app(ProductLaunchService::class)->queue(
        collect([$sku['product']]), ['gid://shopify/Publication/1'], $user->id
    ))->toThrow(AuthorizationException::class);
});
it('AUDIT deduplicates a repeated partial refund independently of remaining quantity', function () {
    auditBeadSku('FOUR', '4-engraved-beads', 9401);
    $inventory = auditBeadInventory();
    $inventory->shouldReceive('moveQuantity')->once()->andReturn([]);
    $inventory->shouldReceive('consumeReserved')->once()->andReturn([]);
    $inventory->shouldReceive('restockAvailable')->once()->andReturn([]);
    $movements = new StackInventoryMovementService($inventory);
    $service = auditBeadReservationService($inventory, $movements);
    $lines = [['id' => 11001, 'variant_id' => 9401, 'quantity' => 3]];
    $service->process(auditBeadOrder(9004, $lines));
    (new StackFulfillmentInventoryService($movements))->process(auditBeadFulfillment(9904, 9004, 3));
    $lines[0]['unfulfilled_quantity'] = 0;
    $refunds = [['id' => 702, 'refund_line_items' => [['line_item_id' => 11001, 'quantity' => 2, 'restock_type' => 'return']]]];
    $service->process(auditBeadOrder(9004, $lines, 'orders/updated', 'refund-first', $refunds));
    $service->process(auditBeadOrder(9004, $lines, 'orders/updated', 'refund-replay', $refunds));
    expect(ShopifyStackInventoryReservation::query()->value('restocked_quantity'))->toBe(8);
});
it('AUDIT retry after consumption does not consume another partial fulfillment quantity', function () {
    auditBeadSku('FOUR', '4-engraved-beads', 9401);
    $inventory = auditBeadInventory();
    $inventory->shouldReceive('moveQuantity')->once()->andReturn([]);
    $inventory->shouldReceive('consumeReserved')->once()->andReturn([]);
    $movements = new StackInventoryMovementService($inventory);
    auditBeadReservationService($inventory, $movements)->process(auditBeadOrder(9005, [
        ['id' => 11001, 'variant_id' => 9401, 'quantity' => 3],
    ]));
    $fulfillment = auditBeadFulfillment(9905, 9005, 2);
    $service = new StackFulfillmentInventoryService($movements);
    $service->process($fulfillment);
    // Reproduce the durable state after movement commit but before fulfillment completion.
    $fulfillment->update(['processing_status' => ShopifyFulfillment::STATUS_PENDING]);
    $service->process($fulfillment->fresh());
    expect(ShopifyStackInventoryReservation::query()->value('consumed_quantity'))->toBe(8);
});
function auditBeadInventory(): ShopifyInventoryAdjustmentService
{
    $inventory = Mockery::mock(ShopifyInventoryAdjustmentService::class);
    $inventory->shouldReceive('resolveLocationId')->zeroOrMoreTimes()->andReturn('gid://shopify/Location/6001');
    $inventory->shouldReceive('reservedQuantity')->byDefault()->andReturn(999);
    $inventory->shouldReceive('currentQuantities')->byDefault()->andReturn(['available' => 999, 'on_hand' => 999, 'committed' => 0]);

    return $inventory;
}

function auditBeadReservationService(
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
function auditBeadSku(string $sku, string $tags, int $variantId, ?string $inventoryItem = 'auto'): array
{
    // The audit now exercises a verified shared pool; all original safety assertions remain.
    $fixture = sharedBeadFamily('audit-design-bead-family', $variantId - 4, active: true);
    $member = $fixture['members'][4];
    $member['variant']->update(['sku' => $sku]);
    $fixture['family']->members()->where('required_beads', 4)->update(['sku' => $sku]);

    return $member;
}

function auditBeadOrder(int $orderId, array $lines, string $topic = 'orders/create', ?string $webhookId = null, array $refunds = []): ShopifyStackOrderEvent
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

function auditBeadFulfillment(int $fulfillmentId, int $orderId, int $quantity, int $lineId = 11001, int $variantId = 9401): ShopifyFulfillment
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
