<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\PermissionEnum;
use App\Filament\Pages\InventoryAuditHistory;
use App\Models\Import;
use App\Models\InventoryOperationAudit;
use App\Models\ProcurementSupplierOrder;
use App\Models\ProcurementSupplierOrderLine;
use App\Models\ProcurementSupplierReceipt;
use App\Models\Product;
use App\Models\ShopifyStackInventoryMovement;
use App\Models\ShopifyStackInventoryReservation;
use App\Models\User;
use App\Models\Variant;
use App\Services\Inventory\InventoryAuditContext;
use App\Services\Inventory\InventoryAuditExplanation;
use App\Services\Inventory\InventoryAuditHistoryService;
use App\Services\Inventory\InventoryAuditMatcher;
use App\Services\Inventory\InventoryAuditOperationGrouper;
use App\Jobs\SyncShopifyInventoryHistoryJob;
use App\Models\ShopifyInventoryAdjustment;
use Illuminate\Support\Facades\Queue;
use App\Services\Inventory\ShopifyInventoryHistoryQuery;
use App\Services\Inventory\ShopifyInventoryHistorySync;
use App\Services\Shopify\ShopifyInventoryAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('confirms a shopify history row only from the stored reference', function (): void {
    $local = [[
        'references' => ['logistics://shopify-editor/procurement-receipt/abc'],
        'occurred_at' => Carbon::parse('2026-09-17 10:00:00'),
        'inventory_item_id' => 'gid://shopify/InventoryItem/1',
        'location_id' => 'gid://shopify/Location/9',
        'delta' => 10,
        'state' => 'available',
        'row' => ['confidence' => 'local', 'confidence_label' => 'Local record', 'detail' => [['Shopify history match', 'Not linked']]],
    ]];
    $exact = shopifyCandidate('gid://shopify/InventoryAdjustment/1', 'logistics://shopify-editor/procurement-receipt/abc', 10);
    $local[] = [
        'references' => [],
        'occurred_at' => Carbon::parse('2026-09-17 11:00:00'),
        'inventory_item_id' => 'gid://shopify/InventoryItem/1',
        'location_id' => 'gid://shopify/Location/9',
        'delta' => 7,
        'state' => 'available',
        'row' => ['confidence' => 'local', 'confidence_label' => 'Local record', 'detail' => []],
    ];
    $probable = shopifyCandidate('gid://shopify/InventoryAdjustment/2', '', 7, '2026-09-17 11:00:30');
    $ambiguous = shopifyCandidate('gid://shopify/InventoryAdjustment/3', '', 4, '2026-09-17 10:00:30');

    $rows = app(InventoryAuditMatcher::class)->merge($local, [$exact, $probable, $ambiguous]);

    expect($rows[0]['confidence'])->toBe('confirmed')
        ->and(json_encode($rows[0]['detail']))->toContain('Confirmed from the same reference')
        ->and(json_encode($rows[0]['detail']))->not->toContain('Not linked')
        ->and($rows[1]['confidence'])->toBe('probable')
        ->and($rows[1]['confidence_label'])->toBe('Probable')
        ->and(json_encode($rows[1]['detail']))->toContain('Probable match only')
        ->and($rows[2]['confidence'])->toBe('unmatched')
        ->and(json_encode($rows[2]['detail']))->not->toContain('Confirmed');
});

it('keeps one audit row when the same inventory reference is sent again', function (): void {
    config(['services.shopify.inventory_location_id' => 'gid://shopify/Location/1']);
    $variant = inventoryAuditVariant('LA-RETRY');
    $calls = 0;
    $gateway = Mockery::mock(ShopifyGraphqlGateway::class);
    $gateway->shouldReceive('graphql')->twice()->andReturnUsing(function (string $query, array $variables = []) use (&$calls): array {
        $calls++;
        expect(data_get($variables, 'input.changes.0.delta'))->toBe(10);

        return ['inventoryAdjustQuantities' => ['inventoryAdjustmentGroup' => ['createdAt' => now()->toIso8601String()], 'userErrors' => []]];
    });

    $service = new ShopifyInventoryAdjustmentService($gateway);
    $service->increaseAvailable($variant, 10, 'logistics://shopify-editor/procurement-receipt/retry');
    $service->increaseAvailable($variant, 10, 'logistics://shopify-editor/procurement-receipt/retry');

    expect($calls)->toBe(2)
        ->and(InventoryOperationAudit::query()->count())->toBe(1)
        ->and(InventoryOperationAudit::query()->first()->attempts)->toBe(2)
        ->and(InventoryOperationAudit::query()->first()->status)->toBe('succeeded');
});

it('records a failed adjustment without turning it into a successful one', function (): void {
    config(['services.shopify.inventory_location_id' => 'gid://shopify/Location/1']);
    $variant = inventoryAuditVariant('LA-FAIL');
    $gateway = Mockery::mock(ShopifyGraphqlGateway::class);
    $gateway->shouldReceive('graphql')->once()->andThrow(new RuntimeException('Shopify unavailable'));
    $service = new ShopifyInventoryAdjustmentService($gateway);

    expect(fn () => $service->increaseAvailable($variant, 3, 'logistics://shopify-editor/procurement-receipt/failed'))
        ->toThrow(RuntimeException::class);
    $audit = InventoryOperationAudit::query()->first();
    expect($audit->status)->toBe('failed')
        ->and($audit->delta)->toBe(3)
        ->and(InventoryOperationAudit::query()->count())->toBe(1);
});

it('reports missing shopify report access without hiding local records', function (): void {
    Cache::flush();
    $user = User::factory()->create(['name' => 'Avery Clerk']);
    [$variant] = inventoryAuditCatalog($user, 'LA0544', 'Pearls by the Baltic Necklace');
    inventoryAuditReceipt($user, $variant, 'LA2609', 10);
    inventoryAuditStackMovement($variant);
    $gateway = Mockery::mock(ShopifyGraphqlGateway::class);
    $gateway->shouldReceive('graphql')->once()->andThrow(new RuntimeException('Access denied for shopifyqlQuery field. Required access: `read_reports` access scope.'));
    app()->instance(ShopifyGraphqlGateway::class, $gateway);

    $report = app(InventoryAuditHistoryService::class)->search([
        'query' => 'LA0544',
        'from' => now()->subDays(30)->toDateString(),
        'until' => now()->toDateString(),
    ]);
    $activities = collect($report['rows'])->pluck('activity');

    expect($report['shopify_status'])->toBe('unavailable')
        ->and($activities)->toContain('Supplier inventory received')
        ->and($activities)->toContain('Stack inventory reserved')
        ->and(collect($report['rows'])->firstWhere('activity', 'Supplier inventory received')['triggered_by'])->toContain('Avery Clerk')
        ->and(collect($report['rows'])->firstWhere('activity', 'Stack inventory reserved')['triggered_by'])->toBe('Order processing')
        ->and(collect($report['rows'])->firstWhere('activity', 'Supplier inventory received')['confidence'])->toBe('local');
});

it('filters inventory history by activity and reference', function (): void {
    $user = User::factory()->create();
    [$first] = inventoryAuditCatalog($user, 'LA-ONE', 'First product');
    [$second] = inventoryAuditCatalog($user, 'LA-TWO', 'Second product');
    inventoryAuditReceipt($user, $first, 'LA2609', 10);
    inventoryAuditReceipt($user, $second, 'LA2610', 2);

    $report = app(InventoryAuditHistoryService::class)->search([
        'query' => '',
        'reference' => 'LA2609',
        'activity' => 'received',
        'from' => now()->subDay()->toDateString(),
        'until' => now()->toDateString(),
    ]);

    expect($report['rows'])->toHaveCount(1)
        ->and($report['rows'][0]['activity'])->toBe('Supplier inventory received')
        ->and(json_encode($report['rows'][0]['detail']))->toContain('LA2609');
});

it('labels a verified online-store purchase as an order and confirms a stack reference', function (): void {
    Cache::flush();
    config(['services.shopify.shop' => 'example.myshopify.com']);
    $user = User::factory()->create(['name' => 'Avery Clerk']);
    [$variant] = inventoryAuditCatalog($user, 'LA0544', 'Pearls by the Baltic Necklace');
    inventoryAuditStackMovement($variant);
    $movement = ShopifyStackInventoryMovement::query()->firstOrFail();
    $gateway = Mockery::mock(ShopifyGraphqlGateway::class);
    $gateway->shouldReceive('graphql')->once()->andReturn([
        'shopifyqlQuery' => [
            'parseErrors' => [],
            'tableData' => ['rows' => [
                [
                    'second' => now()->toIso8601String(),
                    'inventory_adjustment_id' => '72258831089800',
                    'inventory_adjustment_change' => '-1',
                    'inventory_state' => 'available',
                    'inventory_change_reason' => 'purchase',
                    'inventory_app_name' => 'Online Store',
                    'staff_member_name' => null,
                    'reference_document_type' => 'Order',
                    'reference_document_uri' => 'gid://shopify/Order/18961870291080',
                    'product_variant_sku' => 'LA0544',
                    'inventory_location_name' => '102 Odell Road',
                ],
                [
                    'second' => now()->toIso8601String(),
                    'inventory_adjustment_id' => '72177212031112',
                    'inventory_adjustment_change' => '1',
                    'inventory_state' => 'reserved',
                    'inventory_change_reason' => 'reservation_created',
                    'inventory_app_name' => 'la-cms-access',
                    'staff_member_name' => null,
                    'reference_document_uri' => 'gid://leigh-avenue-cms/StackInventoryMovement/'.$movement->id,
                    'product_variant_sku' => 'LA0544',
                ],
            ]],
        ],
    ]);
    app()->instance(ShopifyGraphqlGateway::class, $gateway);

    $report = app(InventoryAuditHistoryService::class)->search([
        'query' => 'LA0544',
        'from' => now()->subDay()->toDateString(),
        'until' => now()->toDateString(),
    ]);
    $order = collect($report['rows'])->firstWhere('activity_key', 'order_created');
    $stack = collect($report['rows'])->firstWhere('activity', 'Stack inventory reserved');

    expect($report['shopify_status'])->toBe('ok')
        ->and($order['source'])->toBe('online_store')
        ->and($order['change'])->toBe('-1 available')
        ->and($order['available'])->toBeNull()
        ->and($order['triggered_by'])->toBe('Online Store')
        ->and($order['confidence'])->toBe('unmatched')
        ->and($order['links'][0][1])->toBe('https://example.myshopify.com/admin/orders/18961870291080')
        ->and($stack['confidence'])->toBe('confirmed')
        ->and($stack['triggered_by'])->toBe('Order processing')
        ->and(json_encode($stack['detail']))->toContain('Confirmed from the same reference');
});

it('parses a shopifyql history row and leaves the resulting balance empty', function (): void {
    Cache::flush();
    $gateway = Mockery::mock(ShopifyGraphqlGateway::class);
    $gateway->shouldReceive('graphql')->once()->andReturn([
        'shopifyqlQuery' => [
            'parseErrors' => [],
            'tableData' => ['rows' => [[
                'second' => now()->toIso8601String(),
                'inventory_adjustment_id' => 'gid://shopify/InventoryAdjustment/55',
                'inventory_adjustment_change' => '10',
                'inventory_state' => 'available',
                'inventory_change_reason' => 'received',
                'inventory_app_name' => 'la-cms-access',
                'reference_document_uri' => 'logistics://shopify-editor/procurement-receipt/abc',
                'product_variant_sku' => 'LA0544',
            ]]],
        ],
    ]);
    app()->instance(ShopifyGraphqlGateway::class, $gateway);

    $result = app(ShopifyInventoryHistoryQuery::class)->forSku('LA0544', now()->subDay(), now());

    expect($result['status'])->toBe('ok')
        ->and($result['rows'][0]['inventory_change_reason'])->toBe('received')
        ->and(array_key_exists('quantity_after_change', $result['rows'][0]))->toBeFalse();
});

it('shows the audit page to inventory managers and keeps shopify denial visible', function (): void {
    Cache::flush();
    $user = User::factory()->create(['name' => 'Avery Clerk', 'email' => 'avery@leighavenue.co.za']);
    Permission::findOrCreate(PermissionEnum::InventoryUpdate->value);
    $user->givePermissionTo(PermissionEnum::InventoryUpdate->value);
    [$variant] = inventoryAuditCatalog($user, 'LA0544', 'Pearls by the Baltic Necklace');
    inventoryAuditReceipt($user, $variant, 'LA2609', 10);
    $gateway = Mockery::mock(ShopifyGraphqlGateway::class);
    $gateway->shouldReceive('graphql')->andThrow(new RuntimeException('Access denied for shopifyqlQuery field. Required access: `read_reports` access scope.'));
    app()->instance(ShopifyGraphqlGateway::class, $gateway);
    $this->actingAs($user);

    Livewire::test(InventoryAuditHistory::class)
        ->set('data.query', 'LA0544')
        ->call('search')
        ->assertSee('Supplier inventory received')
        ->assertSee('has not been stored yet')
        ->assertSee('Avery Clerk');

    expect(InventoryAuditHistory::canAccess())->toBeTrue();
    $this->actingAs(User::factory()->create(['email' => 'viewer@leighavenue.co.za']));
    expect(InventoryAuditHistory::canAccess())->toBeFalse();
});

it('attributes a correction to the requesting user and the sync job', function (): void {
    $user = User::factory()->create(['name' => 'Avery Clerk']);
    $context = new InventoryAuditContext(
        correlationKey: 'inventory-set:batch-1:5',
        workflow: 'inventory_correction',
        action: 'inventory_correction',
        actorType: 'user',
        execution: 'queue',
        userId: $user->id,
        processName: 'Inventory sync job',
        explanation: 'Count correction',
        sku: 'LA0544',
    );
    app(\App\Services\Inventory\InventoryAuditRecorder::class)->succeeded($context);
    app(\App\Services\Inventory\InventoryAuditRecorder::class)->succeeded($context);

    $report = app(InventoryAuditHistoryService::class)->search([
        'query' => 'LA0544',
        'from' => now()->subDay()->toDateString(),
        'until' => now()->toDateString(),
    ]);

    expect(InventoryOperationAudit::query()->count())->toBe(1)
        ->and($report['rows'][0]['triggered_by'])->toBe('Avery Clerk (requested; Inventory sync job applied it)')
        ->and(json_encode($report['rows'][0]['detail']))->toContain('Count correction');
});

it('stores one day of shopify history and does not duplicate the adjustment', function (): void {
    Cache::flush();
    $gateway = Mockery::mock(ShopifyGraphqlGateway::class);
    $gateway->shouldReceive('graphql')->once()->andReturn([
        'shopifyqlQuery' => [
            'parseErrors' => [],
            'tableData' => ['rows' => [
                shopifyHistoryLine('81', now()->toIso8601String(), 'gid://leigh-avenue-cms/StackInventoryMovement/79', 'available', '-1', 'reservation_created'),
            ]],
        ],
    ]);
    app()->instance(ShopifyGraphqlGateway::class, $gateway);

    $sync = app(ShopifyInventoryHistorySync::class);
    $sync->syncDay(now());
    $sync->syncDay(now());

    expect(ShopifyInventoryAdjustment::query()->count())->toBe(1)
        ->and(ShopifyInventoryAdjustment::query()->first()->sku)->toBe('LA0544')
        ->and(ShopifyInventoryAdjustment::query()->first()->app_name)->toBe('la-cms-access');
});

it('queues the following day only after the current day is stored', function (): void {
    Cache::flush();
    Queue::fake();
    $gateway = Mockery::mock(ShopifyGraphqlGateway::class);
    $gateway->shouldReceive('graphql')->once()->andReturn([
        'shopifyqlQuery' => [
            'parseErrors' => [],
            'tableData' => ['rows' => [
                shopifyHistoryLine('82', now()->toIso8601String(), 'gid://leigh-avenue-cms/StackInventoryMovement/80', 'available', '-1', 'reservation_created'),
            ]],
        ],
    ]);
    app()->instance(ShopifyGraphqlGateway::class, $gateway);

    $job = new SyncShopifyInventoryHistoryJob(now()->toDateString(), now()->addDay()->toDateString());
    $job->handle(app(ShopifyInventoryHistorySync::class));

    expect(ShopifyInventoryAdjustment::query()->where('adjustment_id', '82')->exists())->toBeTrue();
    Queue::assertPushed(SyncShopifyInventoryHistoryJob::class, function (SyncShopifyInventoryHistoryJob $queued): bool {
        return $queued->day === now()->addDay()->toDateString();
    });
});

it('groups state lines that share a reference and keeps an unmatched cms adjustment unexplained', function (): void {
    $grouper = app(InventoryAuditOperationGrouper::class);
    $second = '2026-10-16T10:00:00Z';
    $reference = 'gid://leigh-avenue-cms/StackInventoryMovement/79';
    $grouped = $grouper->group([
        historyRow('shopify:1', $reference, $second, 'available', -1),
        historyRow('shopify:2', $reference, $second, 'reserved', 1),
        historyRow('shopify:3', 'gid://leigh-avenue-cms/StackInventoryMovement/80', '2026-10-16T11:00:00Z', 'available', -8),
    ]);

    expect($grouped)->toHaveCount(2)
        ->and($grouped[1]['change'])->toContain('available')
        ->and($grouped[1]['change'])->toContain('reserved')
        ->and($grouped[0]['key'])->toBe('shopify:3');
});

it('does not call an unmatched cms adjustment unauthorized', function (): void {
    $row = app(InventoryAuditExplanation::class)->apply([
        'app' => 'la-cms-access',
        'source' => 'cms',
        'confidence' => 'unmatched',
        'triggered_by' => 'la-cms-access',
        'process' => null,
        'expected_state' => null,
        'detail' => [
            ['Reason', 'correction'],
            ['Match', 'No CMS record matched this Shopify adjustment.'],
        ],
        'states' => [['state' => 'available', 'delta' => -8, 'origin' => 'shopify']],
    ]);

    expect($row['flags'])->toContain('no_local_record')
        ->and($row['flags'])->toContain('unknown_actor')
        ->and($row['flags'])->toContain('unknown_workflow')
        ->and($row['flags'])->not->toContain('missing_reason')
        ->and($row['flags'])->not->toContain('unauthorized')
        ->and(implode(' ', $row['flag_labels']))->toContain('database this application is using')
        ->and(implode(' ', $row['flag_labels']))->toContain('not a finding that the adjustment was unauthorized');
});

it('flags a quantity mismatch and an ambiguous match separately', function (): void {
    $explanation = app(InventoryAuditExplanation::class);
    $mismatch = $explanation->apply([
        'app' => 'la-cms-access',
        'source' => 'cms',
        'confidence' => 'confirmed',
        'triggered_by' => 'Avery Clerk (requested; Supplier receipt job applied it)',
        'process' => 'Supplier receipt job',
        'expected_state' => 'available',
        'expected_delta' => 10,
        'detail' => [['Reason', 'Supplier stock received']],
        'states' => [['state' => 'available', 'delta' => 8, 'origin' => 'shopify']],
    ]);
    $ambiguous = $explanation->apply([
        'app' => 'la-cms-access',
        'source' => 'cms',
        'confidence' => 'unmatched',
        'triggered_by' => 'Order processing',
        'process' => 'Order processing',
        'detail' => [
            ['Reason', 'Component inventory was reserved because an order included this stack.'],
            ['Match', 'The Shopify reference appears on more than one CMS record, so it was left unmatched.'],
        ],
        'states' => [],
    ]);

    $aligned = $explanation->apply([
        'app' => 'la-cms-access',
        'source' => 'cms',
        'activity_key' => 'correction',
        'confidence' => 'probable',
        'triggered_by' => 'Avery Clerk (requested; Inventory sync job applied it)',
        'process' => 'Inventory sync job',
        'expected_state' => 'on_hand',
        'expected_delta' => -8,
        'detail' => [['Reason', 'Count correction']],
        'states' => [['state' => 'available', 'delta' => -8, 'origin' => 'shopify']],
    ]);

    expect($mismatch['flags'])->toBe(['quantity_mismatch'])
        ->and($ambiguous['flags'])->toBe(['ambiguous'])
        ->and($aligned['flags'])->toBe([]);
});

it('pages a store-wide shopify query and says when the page limit cuts the result off', function (): void {
    Cache::flush();
    $gateway = Mockery::mock(ShopifyGraphqlGateway::class);
    $gateway->shouldReceive('graphql')->once()->andReturn([
        'shopifyqlQuery' => [
            'parseErrors' => [],
            'tableData' => ['rows' => array_map(fn (int $id): array => [
                'second' => now()->toIso8601String(),
                'inventory_adjustment_id' => (string) $id,
                'inventory_adjustment_change' => '1',
                'inventory_state' => 'available',
                'inventory_app_name' => 'la-cms-access',
            ], [1, 2, 3])],
        ],
    ]);
    app()->instance(ShopifyGraphqlGateway::class, $gateway);

    $result = app(ShopifyInventoryHistoryQuery::class)->collect(now()->subDay(), now(), null, 'la-cms-access', 3, 1);

    expect($result['incomplete'])->toBeTrue()
        ->and($result['rows'])->toHaveCount(3)
        ->and($result['message'])->toContain('incomplete')
        ->and($result['retrieved_at'])->not->toBeNull();
});

it('reads a later shopify page and does not repeat an adjustment id', function (): void {
    Cache::flush();
    $calls = 0;
    $gateway = Mockery::mock(ShopifyGraphqlGateway::class);
    $gateway->shouldReceive('graphql')->twice()->andReturnUsing(function () use (&$calls): array {
        $calls++;
        $rows = $calls === 1
            ? [
                ['second' => now()->toIso8601String(), 'inventory_adjustment_id' => '1', 'inventory_adjustment_change' => '1', 'inventory_state' => 'available'],
                ['second' => now()->toIso8601String(), 'inventory_adjustment_id' => '2', 'inventory_adjustment_change' => '-1', 'inventory_state' => 'reserved'],
            ]
            : [
                ['second' => now()->toIso8601String(), 'inventory_adjustment_id' => '2', 'inventory_adjustment_change' => '-1', 'inventory_state' => 'reserved'],
                ['second' => now()->toIso8601String(), 'inventory_adjustment_id' => '3', 'inventory_adjustment_change' => '4', 'inventory_state' => 'available'],
            ];

        return ['shopifyqlQuery' => ['parseErrors' => [], 'tableData' => ['rows' => $rows]]];
    });
    app()->instance(ShopifyGraphqlGateway::class, $gateway);

    $result = app(ShopifyInventoryHistoryQuery::class)->collect(now()->subDay(), now(), null, null, 2, 2);

    expect($result['incomplete'])->toBeTrue()
        ->and(collect($result['rows'])->pluck('inventory_adjustment_id')->all())->toBe(['1', '2', '3']);
});

it('investigates cms adjustments across products and separates unexplained rows', function (): void {
    Cache::flush();
    $user = User::factory()->create(['name' => 'Avery Clerk']);
    [$variant] = inventoryAuditCatalog($user, 'LA0544', 'Pearls by the Baltic Necklace');
    inventoryAuditStackMovement($variant);
    $movement = ShopifyStackInventoryMovement::query()->firstOrFail();
    $second = now()->toIso8601String();
    $reference = 'gid://leigh-avenue-cms/StackInventoryMovement/'.$movement->id;
    $gateway = Mockery::mock(ShopifyGraphqlGateway::class);
    $gateway->shouldReceive('graphql')->once()->andReturn([
        'shopifyqlQuery' => [
            'parseErrors' => [],
            'tableData' => ['rows' => [
                shopifyHistoryLine('11', $second, $reference, 'available', '-1', 'reservation_created'),
                shopifyHistoryLine('12', $second, $reference, 'reserved', '1', 'reservation_created'),
                shopifyHistoryLine('13', now()->subHour()->toIso8601String(), '', 'available', '-8', 'correction', 'LA0999', 'Other necklace'),
            ]],
        ],
    ]);
    app()->instance(ShopifyGraphqlGateway::class, $gateway);

    $service = app(InventoryAuditHistoryService::class);
    $report = $service->search([
        'storewide' => true,
        'from' => now()->subDays(7)->toDateString(),
        'until' => now()->toDateString(),
        'investigation' => 'cms',
    ]);
    $stack = collect($report['rows'])->firstWhere('activity', 'Stack inventory reserved');
    $correction = collect($report['rows'])->first(fn (array $row): bool => str_contains((string) $row['change'], '-8'));
    $unexplained = $service->search([
        'storewide' => true,
        'from' => now()->subDays(7)->toDateString(),
        'until' => now()->toDateString(),
        'investigation' => 'unexplained',
    ]);

    expect($stack['confidence'])->toBe('confirmed')
        ->and($stack['triggered_by'])->toBe('Order processing')
        ->and(collect($stack['states'])->where('origin', 'shopify')->pluck('state')->sort()->values()->all())->toBe(['available', 'reserved'])
        ->and($correction['flags'])->toContain('no_local_record')
        ->and($correction['sku'])->toBe('LA0999')
        ->and($report['summary']['operations'])->toBe(2)
        ->and($report['summary']['positive_lines'])->toBe(1)
        ->and($report['summary']['negative_lines'])->toBe(2)
        ->and($unexplained['rows'])->toHaveCount(1)
        ->and($unexplained['rows'][0]['sku'])->toBe('LA0999');
});

it('confirms a production stack movement by the shopify reference even when its timestamp is outside the search window', function (): void {
    Cache::flush();
    $user = User::factory()->create(['name' => 'Avery Clerk']);
    [$variant] = inventoryAuditCatalog($user, 'LA0544', 'Pearls by the Baltic Necklace');
    inventoryAuditStackMovement($variant);
    $movement = ShopifyStackInventoryMovement::query()->firstOrFail();
    $movement->forceFill([
        'created_at' => now()->subDays(40),
        'processed_at' => now()->subDays(40),
    ])->save();
    $reference = 'gid://leigh-avenue-cms/StackInventoryMovement/'.$movement->id;
    $gateway = Mockery::mock(ShopifyGraphqlGateway::class);
    $gateway->shouldReceive('graphql')->once()->andReturn([
        'shopifyqlQuery' => [
            'parseErrors' => [],
            'tableData' => ['rows' => [
                shopifyHistoryLine('21', now()->toIso8601String(), $reference, 'available', '-1', 'reservation_created'),
                shopifyHistoryLine('22', now()->toIso8601String(), $reference, 'reserved', '1', 'reservation_created'),
            ]],
        ],
    ]);
    app()->instance(ShopifyGraphqlGateway::class, $gateway);

    $report = app(InventoryAuditHistoryService::class)->search([
        'storewide' => true,
        'from' => now()->subDays(7)->toDateString(),
        'until' => now()->toDateString(),
        'investigation' => 'cms',
    ]);
    $stack = collect($report['rows'])->firstWhere('activity', 'Stack inventory reserved');

    expect($stack['confidence'])->toBe('confirmed')
        ->and($stack['triggered_by'])->toBe('Order processing')
        ->and($stack['flags'])->toBe([])
        ->and(json_encode($stack['detail']))->toContain('#1001')
        ->and(collect($report['rows'])->where('confidence', 'unmatched'))->toHaveCount(0);
});

it('treats one on-hand correction and the shopify available change as a probable match', function (): void {
    $local = [[
        'references' => [],
        'occurred_at' => Carbon::parse('2026-10-14 10:58:00'),
        'inventory_item_id' => 'gid://shopify/InventoryItem/1',
        'location_id' => '',
        'delta' => -8,
        'state' => 'on_hand',
        'row' => ['confidence' => 'local', 'confidence_label' => 'Local record', 'activity_key' => 'correction', 'detail' => []],
    ]];
    $remote = shopifyCandidate('gid://shopify/InventoryAdjustment/9', '', -8, '2026-10-14 10:58:20');
    $remote['state'] = 'available';
    $remote['reason'] = 'correction';
    $remote['row']['activity_key'] = 'correction';

    $rows = app(InventoryAuditMatcher::class)->merge($local, [$remote]);

    expect($rows[0]['confidence'])->toBe('probable')
        ->and(json_encode($rows[0]['detail']))->toContain('Probable match only');
});

function historyRow(string $key, string $reference, string $second, string $state, int $delta): array
{
    return [
        'key' => $key,
        'group_key' => 'ref:'.strtolower($reference).'|'.$second.'|reservation_created',
        'occurred_at_iso' => $second,
        'activity' => 'Reservation created · '.$state,
        'change' => ($delta > 0 ? '+' : '').$delta.' '.$state,
        'source' => 'cms',
        'source_label' => 'CMS',
        'app' => 'la-cms-access',
        'triggered_by' => 'la-cms-access',
        'confidence' => 'unmatched',
        'confidence_label' => 'Unmatched',
        'reported_by' => 'shopify',
        'states' => [['state' => $state, 'delta' => $delta, 'origin' => 'shopify']],
        'detail' => [],
        'links' => [],
        'identifiers' => [$reference],
    ];
}

function shopifyHistoryLine(string $id, string $second, string $reference, string $state, string $delta, string $reason, string $sku = 'LA0544', string $title = 'Pearls by the Baltic Necklace'): array
{
    return [
        'second' => $second,
        'inventory_adjustment_id' => $id,
        'inventory_adjustment_change' => $delta,
        'inventory_state' => $state,
        'inventory_change_reason' => $reason,
        'inventory_app_name' => 'la-cms-access',
        'reference_document_uri' => $reference,
        'product_variant_sku' => $sku,
        'product_variant_title' => $title,
    ];
}

function shopifyCandidate(string $id, string $reference, int $delta, string $second = '2026-09-17 12:00:00'): array
{
    return [
        'reference' => $reference,
        'occurred_at' => Carbon::parse($second),
        'inventory_item_id' => 'gid://shopify/InventoryItem/1',
        'location_id' => 'gid://shopify/Location/9',
        'delta' => $delta,
        'state' => 'available',
        'evidence' => ['Adjustment '.$id],
        'row' => [
            'key' => $id,
            'confidence' => 'unmatched',
            'confidence_label' => 'Unmatched',
            'detail' => [],
        ],
    ];
}

function inventoryAuditVariant(string $sku): Variant
{
    $user = User::factory()->create();
    [$variant] = inventoryAuditCatalog($user, $sku, $sku.' product');

    return $variant;
}

/**
 * @return array{0:Variant,1:Product}
 */
function inventoryAuditCatalog(User $user, string $sku, string $title): array
{
    $import = Import::query()->create([
        'filename' => $sku.'.csv',
        'mode' => 'append',
        'status' => 'ready',
        'created_by' => $user->id,
    ]);
    $product = Product::withoutEvents(fn (): Product => Product::query()->create([
        'import_id' => $import->id,
        'handle' => Str::slug($sku),
        'title' => $title,
        'status' => 'active',
        'shopify_id' => 'gid://shopify/Product/'.random_int(1000, 9999),
    ]));
    $variant = Variant::query()->create([
        'product_id' => $product->id,
        'sku' => $sku,
        'shopify_id' => 'gid://shopify/ProductVariant/'.random_int(1000, 9999),
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/'.random_int(1000, 9999),
    ]);

    return [$variant, $product];
}

function inventoryAuditReceipt(User $user, Variant $variant, string $orderNumber, int $quantity): void
{
    $order = ProcurementSupplierOrder::query()->create([
        'uuid' => (string) Str::uuid(),
        'order_number' => $orderNumber,
        'created_by' => $user->id,
    ]);
    $line = ProcurementSupplierOrderLine::query()->create([
        'supplier_order_id' => $order->id,
        'variant_id' => $variant->id,
        'sku' => $variant->sku,
        'quantity_ordered' => $quantity,
    ]);
    ProcurementSupplierReceipt::query()->create([
        'uuid' => (string) Str::uuid(),
        'supplier_order_line_id' => $line->id,
        'quantity_received' => $quantity,
        'idempotency_key' => (string) Str::uuid(),
        'shopify_reference_uri' => 'logistics://shopify-editor/procurement-receipt/'.Str::uuid(),
        'status' => 'succeeded',
        'created_by' => $user->id,
        'shopify_adjusted_at' => now(),
    ]);
}

function inventoryAuditStackMovement(Variant $variant): void
{
    $reservation = ShopifyStackInventoryReservation::query()->create([
        'shopify_order_id' => 'gid://shopify/Order/1',
        'shopify_order_name' => '#1001',
        'shopify_order_line_item_id' => 'gid://shopify/LineItem/1',
        'stack_quantity_ordered' => 1,
        'configured_component_product_id' => $variant->product_id,
        'component_product_id' => $variant->product_id,
        'component_variant_id' => $variant->id,
        'component_sku' => $variant->sku,
        'component_title' => 'Component',
        'stack_sku' => 'STACK-1',
        'stack_title' => 'Sample stack',
        'component_quantity_per_stack' => 1,
        'total_component_quantity_required' => 1,
        'shopify_inventory_item_id' => $variant->shopify_inventory_item_id,
        'ledger_document_uri' => 'leighavenue-cms://stack-reservations/1',
        'status' => 'reserved',
    ]);
    ShopifyStackInventoryMovement::query()->create([
        'reservation_id' => $reservation->id,
        'event_key' => (string) Str::uuid(),
        'action' => ShopifyStackInventoryMovement::ACTION_RESERVE,
        'quantity' => 1,
        'idempotency_key' => (string) Str::uuid(),
        'status' => ShopifyStackInventoryMovement::STATUS_COMPLETED,
        'processed_at' => now(),
    ]);
}
