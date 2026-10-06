<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\RolesEnum;
use App\Filament\Resources\ShopifyStackInventoryReservationResource\Pages\ListShopifyStackInventoryReservations;
use App\Jobs\ProcessShopifyStackOrderEventJob;
use App\Models\Import;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyFulfillment;
use App\Models\ShopifyOrder;
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
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('accepts each order webhook once and queues automatic processing', function (): void {
    Queue::fake();
    config(['services.shopify.verify_webhooks' => true, 'services.shopify.webhook_secret' => 'secret']);
    $payload = stackOrderPayload(8001, [['id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 1]]);
    $json = json_encode($payload, JSON_THROW_ON_ERROR);
    $server = [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_X_SHOPIFY_HMAC_SHA256' => base64_encode(hash_hmac('sha256', $json, 'secret', true)),
        'HTTP_X_SHOPIFY_TOPIC' => 'orders/create',
        'HTTP_X_SHOPIFY_WEBHOOK_ID' => 'order-webhook-1',
    ];

    $this->call('POST', '/webhooks/shopify/stack-orders', [], [], [], $server, $json)->assertStatus(202);
    $this->call('POST', '/webhooks/shopify/stack-orders', [], [], [], $server, $json)->assertOk();

    expect(ShopifyStackOrderEvent::query()->count())->toBe(1);
    Queue::assertPushed(ProcessShopifyStackOrderEventJob::class, 1);
});

it('ignores normal products and reserves multiplied component quantities for a Stack', function (): void {
    $records = stackReservationRecords();
    $inventory = stackInventoryMock();
    $inventory->shouldReceive('moveQuantity')->twice()->withArgs(
        fn (Variant $variant, int $quantity, string $from, string $to): bool => $from === 'available' && $to === 'reserved'
            && (($variant->is($records['component_x_variant']) && $quantity === 2)
                || ($variant->is($records['component_y_variant']) && $quantity === 4))
    )->andReturn(['createdAt' => now()->toIso8601String()]);

    $event = stackOrderEvent(8001, [
        ['id' => 10000, 'variant_id' => 9999, 'sku' => 'NORMAL', 'quantity' => 1],
        ['id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 2],
    ]);
    stackOrderService($inventory)->process($event);

    $reservations = ShopifyStackInventoryReservation::query()->orderBy('component_sku')->get();
    expect($reservations)->toHaveCount(2)
        ->and($reservations->pluck('total_component_quantity_required')->all())->toBe([2, 4])
        ->and($reservations->pluck('reserved_quantity')->all())->toBe([2, 4])
        ->and($reservations->pluck('status')->unique()->all())->toBe([ShopifyStackInventoryReservation::STATUS_PENDING]);
});

it('handles multiple Stacks sharing a component without losing line-item auditability', function (): void {
    $records = stackReservationRecords();
    createSecondStack($records['import'], $records['component_x_product']);
    $inventory = stackInventoryMock();
    $inventory->shouldReceive('moveQuantity')->times(3)->andReturn(['createdAt' => now()->toIso8601String()]);

    stackOrderService($inventory)->process(stackOrderEvent(8002, [
        ['id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 2],
        ['id' => 10002, 'variant_id' => 9002, 'sku' => 'STACK-B', 'quantity' => 1],
    ]));

    expect(ShopifyStackInventoryReservation::query()->count())->toBe(3)
        ->and(ShopifyStackInventoryReservation::query()->where('component_sku', 'COMP-X')->sum('reserved_quantity'))->toBe(4)
        ->and(ShopifyStackInventoryReservation::query()->where('component_sku', 'COMP-X')
            ->distinct()->count('shopify_order_line_item_id'))->toBe(2);
});

it('does not reserve twice when an order event is processed again', function (): void {
    stackReservationRecords();
    $inventory = stackInventoryMock();
    $inventory->shouldReceive('moveQuantity')->twice()->andReturn(['createdAt' => now()->toIso8601String()]);
    $service = stackOrderService($inventory);
    $event = stackOrderEvent(8003, [['id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 1]]);

    $service->process($event);
    $service->process($event->fresh());

    expect(ShopifyStackInventoryMovement::query()->where('action', 'reserve')->count())->toBe(2)
        ->and(ShopifyStackInventoryReservation::query()->sum('reserved_quantity'))->toBe(3);
});

it('consumes reserved inventory on full fulfilment and ignores duplicate fulfilment', function (): void {
    stackReservationRecords();
    $inventory = stackInventoryMock();
    $inventory->shouldReceive('moveQuantity')->twice()->andReturn(['createdAt' => now()->toIso8601String()]);
    $inventory->shouldReceive('consumeReserved')->twice()->andReturn(['createdAt' => now()->toIso8601String()]);
    stackOrderService($inventory)->process(stackOrderEvent(8004, [
        ['id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 2],
    ]));
    $fulfillment = stackFulfillment(7001, 8004, 2);
    $service = new StackFulfillmentInventoryService(new StackInventoryMovementService($inventory));

    $service->process($fulfillment);
    $service->process($fulfillment->fresh());

    expect(ShopifyStackInventoryReservation::query()->sum('consumed_quantity'))->toBe(6)
        ->and(ShopifyStackInventoryReservation::query()->pluck('status')->unique()->all())
        ->toBe([ShopifyStackInventoryReservation::STATUS_COMPLETED]);
});

it('supports partial fulfilment then releases only the unfulfilled remainder on cancellation', function (): void {
    stackReservationRecords();
    $inventory = stackInventoryMock();
    $inventory->shouldReceive('moveQuantity')->times(4)->andReturn(['createdAt' => now()->toIso8601String()]);
    $inventory->shouldReceive('consumeReserved')->twice()->andReturn(['createdAt' => now()->toIso8601String()]);
    stackOrderService($inventory)->process(stackOrderEvent(8005, [
        ['id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 3],
    ]));
    (new StackFulfillmentInventoryService(new StackInventoryMovementService($inventory)))
        ->process(stackFulfillment(7002, 8005, 1));
    $cancellation = stackOrderEvent(8005, [], 'orders/cancelled', 'cancel-8005');
    $cancellationService = stackOrderService($inventory);
    $cancellationService->process($cancellation);
    $cancellationService->process($cancellation->fresh());

    expect(ShopifyStackInventoryReservation::query()->sum('consumed_quantity'))->toBe(3)
        ->and(ShopifyStackInventoryReservation::query()->sum('released_quantity'))->toBe(6)
        ->and(ShopifyStackInventoryReservation::query()->sum('reserved_quantity'))->toBe(9)
        ->and(ShopifyStackInventoryReservation::query()->pluck('status')->unique()->all())
        ->toBe([ShopifyStackInventoryReservation::STATUS_RELEASED]);
});

it('reserves only an order increase and releases only an order decrease', function (): void {
    stackReservationRecords();
    $inventory = stackInventoryMock();
    $inventory->shouldReceive('moveQuantity')->times(6)->andReturn(['createdAt' => now()->toIso8601String()]);
    $service = stackOrderService($inventory);
    $service->process(stackOrderEvent(8006, [['id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 2]]));
    $service->process(stackOrderEvent(8006, [['id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 3]], 'orders/updated', 'increase'));
    $service->process(stackOrderEvent(8006, [['id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 1]], 'orders/updated', 'decrease'));

    expect(ShopifyStackInventoryMovement::query()->where('action', 'reserve')->sum('quantity'))->toBe(9)
        ->and(ShopifyStackInventoryMovement::query()->where('action', 'release')->sum('quantity'))->toBe(6)
        ->and(ShopifyStackInventoryReservation::query()->sum('total_component_quantity_required'))->toBe(3);
});

it('reuses the same idempotency key after a Shopify failure', function (): void {
    stackReservationRecords();
    $keys = [];
    $attempts = 0;
    $inventory = stackInventoryMock();
    $inventory->shouldReceive('moveQuantity')->andReturnUsing(function (...$arguments) use (&$keys, &$attempts): array {
        $keys[] = $arguments[7];
        if (++$attempts === 1) {
            throw new RuntimeException('Temporary Shopify failure');
        }

        return ['createdAt' => now()->toIso8601String()];
    });
    $service = stackOrderService($inventory);
    $event = stackOrderEvent(8007, [['id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 1]]);

    expect(fn () => $service->process($event))->toThrow(RuntimeException::class, 'Temporary Shopify failure');
    $service->process($event->fresh());

    expect($keys[0])->toBe($keys[1])
        ->and(ShopifyStackInventoryMovement::query()->first()->attempts)->toBe(2);
});

it('records insufficient Shopify inventory as a visible retryable failure', function (): void {
    stackReservationRecords();
    $inventory = stackInventoryMock();
    $inventory->shouldReceive('moveQuantity')->andThrow(new RuntimeException('Insufficient available quantity'));
    $event = stackOrderEvent(8008, [['id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 20]]);

    expect(fn () => stackOrderService($inventory)->process($event))
        ->toThrow(RuntimeException::class, 'Insufficient available quantity');

    expect(ShopifyStackInventoryReservation::query()->first()->status)->toBe(ShopifyStackInventoryReservation::STATUS_FAILED)
        ->and(ShopifyStackInventoryMovement::query()->first()->status)->toBe(ShopifyStackInventoryMovement::STATUS_FAILED);
});

it('lets a super admin cancel leftover reservations on fulfilled orders without reversing consumed units', function (): void {
    stackReservationRecords();
    $inventory = stackInventoryMock();
    $inventory->shouldReceive('moveQuantity')->times(4)->andReturn(['createdAt' => now()->toIso8601String()]);
    $inventory->shouldReceive('consumeReserved')->twice()->andReturn(['createdAt' => now()->toIso8601String()]);
    $service = stackOrderService($inventory, 'FULFILLED');
    $service->process(stackOrderEvent(8010, [
        ['id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 2],
    ]));
    (new StackFulfillmentInventoryService(new StackInventoryMovementService($inventory)))
        ->process(stackFulfillment(7010, 8010, 1));

    $result = $service->cancelManually(ShopifyStackInventoryReservation::query()->get());

    expect($result['cancelled'])->toBe(2)
        ->and($result['skipped'])->toBe(0)
        ->and($result['failed'])->toBe(0)
        ->and(ShopifyStackInventoryReservation::query()->pluck('status')->unique()->all())
        ->toBe([ShopifyStackInventoryReservation::STATUS_RELEASED])
        ->and(ShopifyStackInventoryReservation::query()->sum('consumed_quantity'))->toBe(3)
        ->and(ShopifyStackInventoryReservation::query()->sum('released_quantity'))->toBe(3);
});

it('does not reserve again after a manual cancel when the same open order is updated', function (): void {
    stackReservationRecords();
    $inventory = stackInventoryMock();
    $inventory->shouldReceive('moveQuantity')->times(4)->andReturn(['createdAt' => now()->toIso8601String()]);
    $service = stackOrderService($inventory, 'FULFILLED');
    $service->process(stackOrderEvent(8011, [
        ['id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 1],
    ]));
    $service->cancelManually(ShopifyStackInventoryReservation::query()->get());
    $service->process(stackOrderEvent(8011, [
        ['id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 1],
    ], 'orders/updated', 'after-manual-cancel'));

    expect(ShopifyStackInventoryMovement::query()->where('action', 'reserve')->count())->toBe(2)
        ->and(ShopifyStackInventoryReservation::query()->pluck('status')->unique()->all())
        ->toBe([ShopifyStackInventoryReservation::STATUS_RELEASED]);
});

it('marks snapshot-only reservations released without a Shopify inventory move', function (): void {
    $records = stackReservationRecords();
    $inventory = stackInventoryMock();
    $inventory->shouldNotReceive('moveQuantity');
    $reservation = ShopifyStackInventoryReservation::query()->create([
        'shopify_order_id' => 'gid://shopify/Order/8012',
        'shopify_order_name' => '#8012',
        'shopify_order_line_item_id' => 'gid://shopify/LineItem/10001',
        'configured_component_product_id' => $records['component_x_product']->id,
        'stack_product_id' => $records['stack']->id,
        'stack_variant_id' => $records['stack_variant']->id,
        'component_product_id' => $records['component_x_product']->id,
        'component_variant_id' => $records['component_x_variant']->id,
        'component_sku' => 'COMP-X',
        'component_quantity_per_stack' => 1,
        'stack_quantity_ordered' => 1,
        'total_component_quantity_required' => 1,
        'ledger_document_uri' => 'leighavenue-cms://stack-reservations/8012',
        'reserved_quantity' => 1,
        'consumed_quantity' => 1,
        'released_quantity' => 0,
        'status' => ShopifyStackInventoryReservation::STATUS_PENDING_PROCESSING,
    ]);

    $result = stackOrderService($inventory, 'FULFILLED')->cancelManually([$reservation]);

    expect($result['cancelled'])->toBe(1)
        ->and($reservation->fresh()->status)->toBe(ShopifyStackInventoryReservation::STATUS_RELEASED)
        ->and($reservation->fresh()->error_message)->toContain('Manually cancelled');
});

it('does not let a super admin cancel reservations for unfulfilled stack orders', function (): void {
    stackReservationRecords();
    stackShopifyOrder(8014, 'FULFILLED');
    $inventory = stackInventoryMock();
    $inventory->shouldReceive('moveQuantity')->twice()->andReturn(['createdAt' => now()->toIso8601String()]);
    $service = stackOrderService($inventory, 'UNFULFILLED');
    $service->process(stackOrderEvent(8014, [
        ['id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 1],
    ]));

    $result = $service->cancelManually(ShopifyStackInventoryReservation::query()->get());

    expect($result['cancelled'])->toBe(0)
        ->and($result['skipped'])->toBe(2)
        ->and(ShopifyStackInventoryReservation::query()->pluck('status')->unique()->all())
        ->toBe([ShopifyStackInventoryReservation::STATUS_PENDING])
        ->and(ShopifyStackInventoryReservation::query()->sum('released_quantity'))->toBe(0)
        ->and(ShopifyStackInventoryReservation::query()->first()->error_message)->toContain('live Shopify');
});

it('cancels using live Shopify even when CMS order data is still unfulfilled', function (): void {
    stackReservationRecords();
    stackShopifyOrder(8015, 'UNFULFILLED');
    $inventory = stackInventoryMock();
    $inventory->shouldReceive('moveQuantity')->times(4)->andReturn(['createdAt' => now()->toIso8601String()]);
    $service = stackOrderService($inventory, 'FULFILLED');
    $service->process(stackOrderEvent(8015, [
        ['id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 1],
    ]));

    $result = $service->cancelManually(ShopifyStackInventoryReservation::query()->get());

    expect($result['cancelled'])->toBe(2)
        ->and(ShopifyStackInventoryReservation::query()->pluck('status')->unique()->all())
        ->toBe([ShopifyStackInventoryReservation::STATUS_RELEASED]);
});

it('does not cancel when live Shopify fulfillment status cannot be loaded', function (): void {
    stackReservationRecords();
    $inventory = stackInventoryMock();
    $inventory->shouldReceive('moveQuantity')->twice()->andReturn(['createdAt' => now()->toIso8601String()]);
    $service = stackOrderService($inventory, lookupFails: true);
    $service->process(stackOrderEvent(8016, [
        ['id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 1],
    ]));

    $result = $service->cancelManually(ShopifyStackInventoryReservation::query()->get());

    expect($result['cancelled'])->toBe(0)
        ->and($result['skipped'])->toBe(2)
        ->and(ShopifyStackInventoryReservation::query()->pluck('status')->unique()->all())
        ->toBe([ShopifyStackInventoryReservation::STATUS_PENDING]);
});

it('snapshots a fully fulfilled stack line without moving Shopify inventory', function (): void {
    stackReservationRecords();
    $inventory = stackInventoryMock();
    $inventory->shouldNotReceive('moveQuantity');
    $event = stackOrderEvent(8020, [[
        'id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 1,
        'current_quantity' => 1, 'unfulfilled_quantity' => 0,
    ]], 'orders/updated', 'backfill-8020');

    $summary = stackOrderService($inventory)->process($event);

    expect($summary['reserved'])->toBe(0)
        ->and(ShopifyStackInventoryReservation::query()->pluck('status')->unique()->all())
        ->toBe([ShopifyStackInventoryReservation::STATUS_COMPLETED])
        ->and(ShopifyStackInventoryReservation::query()->sum('consumed_quantity'))->toBe(3)
        ->and(ShopifyStackInventoryReservation::query()->sum('reserved_quantity'))->toBe(3)
        ->and(ShopifyStackInventoryReservation::query()->first()->shopify_order_created_at?->eq(
            \Illuminate\Support\Carbon::parse('2026-08-19T10:41:00+02:00')
        ))->toBeTrue();
});

it('reserves only the unfulfilled remainder when backfilling a partial stack order', function (): void {
    $records = stackReservationRecords();
    $inventory = stackInventoryMock();
    $inventory->shouldReceive('moveQuantity')->twice()->withArgs(
        fn (Variant $variant, int $quantity, string $from, string $to): bool => $from === 'available' && $to === 'reserved'
            && (($variant->is($records['component_x_variant']) && $quantity === 1)
                || ($variant->is($records['component_y_variant']) && $quantity === 2))
    )->andReturn(['createdAt' => now()->toIso8601String()]);

    stackOrderService($inventory)->process(stackOrderEvent(8021, [[
        'id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 2,
        'current_quantity' => 2, 'unfulfilled_quantity' => 1,
    ]], 'orders/updated', 'backfill-8021'));

    expect(ShopifyStackInventoryReservation::query()->where('component_sku', 'COMP-X')->first())
        ->consumed_quantity->toBe(1)
        ->reserved_quantity->toBe(2)
        ->status->toBe(ShopifyStackInventoryReservation::STATUS_PENDING);
});

it('does not reserve a late order update when Shopify unfulfilled quantity is unknown', function (): void {
    stackReservationRecords();
    $inventory = stackInventoryMock();
    $inventory->shouldNotReceive('moveQuantity');

    $summary = stackOrderService($inventory)->process(stackOrderEvent(8022, [[
        'id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 1,
    ]], 'orders/updated', 'late-update-8022'));

    expect($summary['reserved'])->toBe(0)
        ->and(ShopifyStackInventoryReservation::query()->count())->toBe(0);
});

it('uses REST fulfillable quantity so fulfilled updates do not reserve again', function (): void {
    stackReservationRecords();
    $inventory = stackInventoryMock();
    $inventory->shouldNotReceive('moveQuantity');

    stackOrderService($inventory)->process(stackOrderEvent(8023, [[
        'id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 1,
        'fulfillable_quantity' => 0, 'fulfillment_status' => 'fulfilled',
    ]], 'orders/updated', 'rest-fulfilled-8023'));

    expect(ShopifyStackInventoryReservation::query()->pluck('status')->unique()->all())
        ->toBe([ShopifyStackInventoryReservation::STATUS_COMPLETED])
        ->and(ShopifyStackInventoryMovement::query()->count())->toBe(0);
});

it('maps GraphQL lines only when unfulfilledQuantity is present', function (): void {
    $mapper = new StackOrderLineMapper;
    expect($mapper->fromGraphqlLine(['id' => 'gid://shopify/LineItem/1', 'quantity' => 1, 'currentQuantity' => 1]))
        ->toBeNull()
        ->and($mapper->fromGraphqlLine([
            'id' => 'gid://shopify/LineItem/1',
            'quantity' => 2,
            'currentQuantity' => 2,
            'unfulfilledQuantity' => 0,
            'variant' => ['id' => 'gid://shopify/ProductVariant/9'],
        ])['_stack_baseline_fulfilled_quantity'])->toBe(2)
        ->and($mapper->fulfilledBaseline(['quantity' => 1], 'orders/create'))->toBe(0)
        ->and($mapper->fulfilledBaseline(['quantity' => 1], 'orders/updated'))->toBeNull();
});

it('shows stack reservation cancel actions only to super admins', function (): void {
    Role::findOrCreate(RolesEnum::Admin->value);
    Role::findOrCreate(RolesEnum::SuperAdmin->value);
    $records = stackReservationRecords();
    $reservation = ShopifyStackInventoryReservation::query()->create([
        'shopify_order_id' => 'gid://shopify/Order/8013',
        'shopify_order_name' => '#8013',
        'shopify_order_line_item_id' => 'gid://shopify/LineItem/10001',
        'configured_component_product_id' => $records['component_x_product']->id,
        'stack_product_id' => $records['stack']->id,
        'stack_variant_id' => $records['stack_variant']->id,
        'component_product_id' => $records['component_x_product']->id,
        'component_variant_id' => $records['component_x_variant']->id,
        'component_sku' => 'COMP-X',
        'component_quantity_per_stack' => 1,
        'stack_quantity_ordered' => 1,
        'total_component_quantity_required' => 1,
        'ledger_document_uri' => 'leighavenue-cms://stack-reservations/8013',
        'reserved_quantity' => 1,
        'released_quantity' => 0,
        'status' => ShopifyStackInventoryReservation::STATUS_PENDING,
    ]);

    $admin = User::factory()->create();
    $admin->assignRole(RolesEnum::Admin->value);
    $this->actingAs($admin);
    Livewire::test(ListShopifyStackInventoryReservations::class)
        ->assertCanSeeTableRecords([$reservation])
        ->assertTableActionHidden('cancelReservation', $reservation)
        ->assertTableBulkActionHidden('cancelReservations');

    $super = User::factory()->create();
    $super->assignRole(RolesEnum::SuperAdmin->value);
    $this->actingAs($super);
    Livewire::test(ListShopifyStackInventoryReservations::class)
        ->assertTableActionVisible('cancelReservation', $reservation)
        ->assertTableBulkActionVisible('cancelReservations');
});

it('uses Shopify state movement and consumes reserved without changing available', function (): void {
    $records = stackReservationRecords();
    $gateway = Mockery::mock(ShopifyGraphqlGateway::class);
    $gateway->shouldReceive('graphql')->twice()->andReturnUsing(function (string $query, array $variables): array {
        expect($query)->toContain('@idempotent(key: $idempotencyKey)');
        if (str_contains($query, 'inventoryMoveQuantities')) {
            expect(data_get($variables, 'input.changes.0.from.name'))->toBe('available')
                ->and(data_get($variables, 'input.changes.0.to.name'))->toBe('reserved');

            return ['inventoryMoveQuantities' => ['userErrors' => [], 'inventoryAdjustmentGroup' => ['createdAt' => now()->toIso8601String()]]];
        }
        expect(data_get($variables, 'input.name'))->toBe('reserved')
            ->and(data_get($variables, 'input.changes.0.delta'))->toBe(-2);

        return ['inventoryAdjustQuantities' => ['userErrors' => [], 'inventoryAdjustmentGroup' => ['createdAt' => now()->toIso8601String()]]];
    });
    $service = new ShopifyInventoryAdjustmentService($gateway);
    $service->moveQuantity($records['component_x_variant'], 2, 'available', 'reserved', 'reservation_created',
        'gid://leigh-avenue-cms/Test/1', 'leighavenue-cms://reservation/1', '11111111-1111-4111-8111-111111111111', 'gid://shopify/Location/6001');
    $service->consumeReserved($records['component_x_variant'], 2, 'gid://leigh-avenue-cms/Test/2',
        'leighavenue-cms://reservation/1', '22222222-2222-4222-8222-222222222222', 'gid://shopify/Location/6001');
});

function stackInventoryMock(): ShopifyInventoryAdjustmentService
{
    $inventory = Mockery::mock(ShopifyInventoryAdjustmentService::class);
    $inventory->shouldReceive('resolveLocationId')->zeroOrMoreTimes()->andReturn('gid://shopify/Location/6001');

    return $inventory;
}

function stackOrderService(
    ShopifyInventoryAdjustmentService $inventory,
    string $shopifyFulfillmentStatus = 'UNFULFILLED',
    bool $lookupFails = false,
): StackOrderReservationService {
    $gateway = Mockery::mock(ShopifyGraphqlGateway::class);
    if ($lookupFails) {
        $gateway->shouldReceive('graphql')->zeroOrMoreTimes()
            ->andThrow(new RuntimeException('Shopify fulfillment lookup failed.'));
    } else {
        $gateway->shouldReceive('graphql')->zeroOrMoreTimes()->andReturn([
            'order' => [
                'displayFulfillmentStatus' => $shopifyFulfillmentStatus,
                'cancelledAt' => null,
            ],
        ]);
    }

    return new StackOrderReservationService(
        $inventory,
        new StackInventoryMovementService($inventory),
        new StackOrderLineMapper,
        new StackOrderFulfillmentInspector($gateway),
    );
}

function stackShopifyOrder(int $orderId, string $fulfillmentStatus): ShopifyOrder
{
    return ShopifyOrder::factory()->create([
        'shopify_order_id' => "gid://shopify/Order/{$orderId}",
        'name' => "#{$orderId}",
        'fulfillment_status' => $fulfillmentStatus,
    ]);
}

function stackOrderEvent(int $orderId, array $lines, string $topic = 'orders/create', ?string $webhookId = null): ShopifyStackOrderEvent
{
    return ShopifyStackOrderEvent::create([
        'webhook_id' => $webhookId ?? "order-event-{$orderId}-".uniqid(),
        'topic' => $topic,
        'shopify_order_id' => "gid://shopify/Order/{$orderId}",
        'shopify_order_name' => "#{$orderId}",
        'payload' => stackOrderPayload($orderId, $lines),
    ]);
}

function stackOrderPayload(int $orderId, array $lines): array
{
    return [
        'id' => $orderId,
        'admin_graphql_api_id' => "gid://shopify/Order/{$orderId}",
        'name' => "#{$orderId}",
        'created_at' => '2026-08-19T10:41:00+02:00',
        'line_items' => $lines,
    ];
}

function stackFulfillment(int $fulfillmentId, int $orderId, int $quantity): ShopifyFulfillment
{
    return ShopifyFulfillment::create([
        'shopify_fulfillment_id' => "gid://shopify/Fulfillment/{$fulfillmentId}",
        'shopify_order_id' => (string) $orderId,
        'shopify_location_id' => '6001',
        'shopify_status' => 'success',
        'payload' => ['line_items' => [['id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => $quantity]]],
    ]);
}

/** @return array<string, mixed> */
function stackReservationRecords(): array
{
    $user = User::factory()->create();
    $import = Import::create(['filename' => 'stack-reservation.csv', 'mode' => 'overwrite', 'status' => 'ready', 'created_by' => $user->id, 'is_current' => true]);
    $stack = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id, 'shopify_id' => 'gid://shopify/Product/5001', 'handle' => 'stack-a',
        'title' => 'Stack A', 'status' => 'active', 'is_bundle' => true, 'approval_version' => 1,
    ]));
    $stackVariant = Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $stack->id, 'shopify_id' => 'gid://shopify/ProductVariant/9001', 'sku' => 'STACK-A', 'sync_state' => Variant::SYNC_STATE_SYNCED,
    ]));
    $componentX = stackComponent($import, 5101, 9101, 'COMP-X');
    $componentY = stackComponent($import, 5102, 9102, 'COMP-Y');
    NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'shopify_id' => $stack->shopify_id, 'handle' => $stack->handle, 'sku' => 'STACK-A', 'title' => 'Stack A',
        'tags' => 'bundles', 'status' => 'active', 'bundle_product_ids' => [$componentX['product']->id, $componentY['product']->id],
        'bundle_component_quantities' => [['product_id' => $componentX['product']->id, 'quantity' => 1], ['product_id' => $componentY['product']->id, 'quantity' => 2]],
        'approval_version' => 1, 'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));

    return ['import' => $import, 'stack' => $stack, 'stack_variant' => $stackVariant,
        'component_x_product' => $componentX['product'], 'component_x_variant' => $componentX['variant'], 'component_y_variant' => $componentY['variant']];
}

function createSecondStack(Import $import, Product $component): void
{
    $stack = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id, 'shopify_id' => 'gid://shopify/Product/5002', 'handle' => 'stack-b',
        'title' => 'Stack B', 'status' => 'active', 'is_bundle' => true, 'approval_version' => 1,
    ]));
    Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $stack->id, 'shopify_id' => 'gid://shopify/ProductVariant/9002', 'sku' => 'STACK-B', 'sync_state' => Variant::SYNC_STATE_SYNCED,
    ]));
    NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'shopify_id' => $stack->shopify_id, 'handle' => $stack->handle, 'sku' => 'STACK-B', 'title' => 'Stack B',
        'tags' => 'bundles', 'status' => 'active', 'bundle_product_ids' => [$component->id],
        'bundle_component_quantities' => [['product_id' => $component->id, 'quantity' => 2]],
        'approval_version' => 1, 'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]));
}

/** @return array{product:Product,variant:Variant} */
function stackComponent(Import $import, int $productId, int $variantId, string $sku): array
{
    $product = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id, 'shopify_id' => "gid://shopify/Product/{$productId}", 'handle' => strtolower($sku),
        'title' => $sku, 'status' => 'active', 'is_bundle' => false, 'approval_version' => 1,
    ]));
    $variant = Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $product->id, 'shopify_id' => "gid://shopify/ProductVariant/{$variantId}",
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/'.($variantId + 1000), 'sku' => $sku,
        'inventory_tracked' => true, 'inventory_qty' => 20, 'sync_state' => Variant::SYNC_STATE_SYNCED,
    ]));

    return compact('product', 'variant');
}
