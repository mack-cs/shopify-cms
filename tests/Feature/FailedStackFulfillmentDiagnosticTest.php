<?php

use App\Jobs\ProcessShopifyStackFulfillmentJob;
use App\Models\Import;
use App\Models\InventoryOperationAudit;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyFulfillment;
use App\Models\ShopifyStackComponentDeduction;
use App\Models\ShopifyStackInventoryMovement;
use App\Models\ShopifyStackInventoryReservation;
use App\Models\ShopifyStackOrderEvent;
use App\Models\User;
use App\Models\Variant;
use App\Services\Shopify\FailedStackFulfillmentDiagnostic;
use App\Services\Shopify\ReadOnlyInventoryDiagnostic;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['inventory_monitor.enabled' => false]);
    Bus::fake();
    Mail::fake();
});

it('identifies a conditional direct retry candidate from complete confirmed reservations', function () {
    $fixture = diagnosticFixture();
    $group = diagnosticGroup();
    expect($group['classification'])->toBe(FailedStackFulfillmentDiagnostic::POTENTIAL)
        ->and($group['recommended_sequence'])->toBe('fulfillment_directly_after_live_inventory_verification')
        ->and($group['shopify_inventory_verified'])->toBeFalse()
        ->and($group['lines'][0]['missing_component_product_ids'])->toBe([])
        ->and($group['order_movements'][0]['remote_outcome'])->toBe('locally_confirmed')
        ->and($group['stored_fulfillment_records'][0]['attempts'])->toBe(5);
    expect($fixture['reservation']->fresh()->consumed_quantity)->toBe(0);
});

it('does not infer safety solely from a missing-reservation error', function () {
    diagnosticFixture(['reservations' => false]);
    $group = diagnosticGroup();
    expect($group['classification'])->toBe(FailedStackFulfillmentDiagnostic::MANUAL)
        ->and($group['lines'][0]['reservation_state'])->toBe('none')
        ->and($group['order_event_required_to_create_missing_snapshots'])->toBeTrue()
        ->and($group['notes'])->toContain('Existing order events are completed or absent. Replaying a completed event is a no-op; a supported order-event ingestion/reprocessing path must be established without editing database statuses.');
});

it('detects incomplete reservations and reports replayable order events without replaying them', function () {
    $fixture = diagnosticFixture();
    $fixture['draft']->update(['bundle_product_ids' => [$fixture['component']->id, 98765]]);
    $event = ShopifyStackOrderEvent::create(['webhook_id' => 'diagnostic-event', 'topic' => 'orders/create',
        'shopify_order_id' => 'gid://shopify/Order/5855', 'shopify_order_name' => '#5855', 'status' => 'failed', 'payload' => []]);
    $group = diagnosticGroup();
    expect($group['classification'])->toBe(FailedStackFulfillmentDiagnostic::MANUAL)
        ->and($group['lines'][0]['reservation_state'])->toBe('incomplete')
        ->and($group['lines'][0]['missing_component_product_ids'])->toBe([98765])
        ->and($group['replayable_order_event_ids'])->toBe([$event->id])
        ->and($event->fresh()->status)->toBe('failed');
    Bus::assertNothingDispatched();
});

it('groups numeric and GID fulfillment aliases and preserves previous attempts', function () {
    $fixture = diagnosticFixture();
    $other = $fixture['fulfillment']->replicate();
    $other->shopify_fulfillment_id = 'gid://shopify/Fulfillment/7001';
    $other->shopify_order_id = 'gid://shopify/Order/5855';
    $other->attempts = 3;
    $other->save();
    $report = app(FailedStackFulfillmentDiagnostic::class)->report();
    expect($report['counts']['fulfillment_groups'])->toBe(1)
        ->and($report['fulfillments'][0]['classification'])->toBe(FailedStackFulfillmentDiagnostic::POTENTIAL)
        ->and($report['fulfillments'][0]['fulfillment_id_aliases'])->toBe(['gid://shopify/Fulfillment/7001', '7001'])
        ->and(array_column($report['fulfillments'][0]['stored_fulfillment_records'], 'attempts'))->toBe([5, 3]);
});

it('requires reconciliation for attempted unconfirmed movements regardless of retry age', function (string $status) {
    $fixture = diagnosticFixture();
    $movement = diagnosticConsume($fixture, $status, 1);
    $group = diagnosticGroup();
    expect($group['classification'])->toBe(FailedStackFulfillmentDiagnostic::RECONCILE)
        ->and(collect($group['order_movements'])->firstWhere('id', $movement->id)['remote_outcome'])->toBe('possibly_applied_unconfirmed');
})->with(['pending', 'failed', 'processing']);

it('flags old unconfirmed movements blocked by the current retry-age guard', function () {
    $fixture = diagnosticFixture();
    $movement = diagnosticConsume($fixture, 'failed', 1);
    $movement->update(['created_at' => now()->subDays(2)]);
    $row = collect(diagnosticGroup()['order_movements'])->firstWhere('id', $movement->id);
    expect($row['retry_age_guard_would_block'])->toBeTrue();
});

it('recognizes an already completed numeric consume key without deducting again', function () {
    $fixture = diagnosticFixture();
    diagnosticConsume($fixture, 'completed', 1, '7001');
    $fixture['reservation']->update(['consumed_quantity' => 1, 'status' => 'completed']);
    $group = diagnosticGroup();
    expect($group['classification'])->toBe(FailedStackFulfillmentDiagnostic::POTENTIAL)
        ->and($group['lines'][0]['components'][0]['already_consumed_for_this_fulfillment'])->toBeTrue();
    expect(ShopifyStackInventoryMovement::count())->toBe(2);
});

it('detects double consumption recorded under numeric and GID keys', function () {
    $fixture = diagnosticFixture();
    diagnosticConsume($fixture, 'completed', 1, '7001');
    diagnosticConsume($fixture, 'completed', 1, 'gid://shopify/Fulfillment/7001');
    $fixture['reservation']->update(['consumed_quantity' => 2]);
    $group = diagnosticGroup();
    expect($group['classification'])->toBe(FailedStackFulfillmentDiagnostic::RECONCILE)
        ->and(implode(' ', $group['reasons']))->toContain('multiple completed consume events');
});

it('does not treat unexplained consumed counters as proof of safe idempotency', function () {
    $fixture = diagnosticFixture();
    $fixture['reservation']->update(['consumed_quantity' => 1]);
    expect(diagnosticGroup()['classification'])->toBe(FailedStackFulfillmentDiagnostic::RECONCILE);
});

it('detects possible overlap with legacy direct deductions', function () {
    $fixture = diagnosticFixture();
    ShopifyStackComponentDeduction::create(['shopify_fulfillment_id' => $fixture['fulfillment']->id,
        'shopify_fulfillment_line_item_id' => 'gid://shopify/LineItem/10001', 'shopify_order_id' => '5855',
        'shopify_stack_variant_id' => '9001', 'stack_quantity_fulfilled' => 1,
        'configured_component_product_id' => $fixture['component']->id, 'component_quantity_per_stack' => 1,
        'quantity_deducted' => 1, 'idempotency_key' => fake()->uuid(), 'status' => 'completed', 'attempts' => 1]);
    $group = diagnosticGroup();
    expect($group['classification'])->toBe(FailedStackFulfillmentDiagnostic::RECONCILE)
        ->and($group['legacy_deductions'][0]['quantity_deducted'])->toBe(1);
});

it('discovers failures from failed_jobs and reports orphan references', function () {
    $fixture = diagnosticFixture();
    $fixture['fulfillment']->update(['error_message' => 'Different current error']);
    foreach ([$fixture['fulfillment']->id, 99999] as $id) {
        DB::table('failed_jobs')->insert(['uuid' => 'diagnostic-job-'.$id, 'connection' => 'inventory-ledger', 'queue' => 'inventory-ledger',
            'payload' => json_encode(['displayName' => ProcessShopifyStackFulfillmentJob::class,
                'data' => ['command' => serialize(new ProcessShopifyStackFulfillmentJob($id))]]),
            'exception' => 'Incomplete component reservation snapshot for fulfilled Stack line', 'failed_at' => now()]);
    }
    $report = app(FailedStackFulfillmentDiagnostic::class)->report();
    expect($report['counts']['fulfillment_groups'])->toBe(1)->and($report['counts']['unlinked_failed_jobs'])->toBe(1)
        ->and($report['fulfillments'][0]['failed_queue_jobs'][0]['target_id'])->toBe($fixture['fulfillment']->id);
});

it('blocks a controlled retry when a related job is already queued', function () {
    $fixture = diagnosticFixture();
    DB::table('jobs')->insert(['queue' => 'inventory-ledger', 'attempts' => 0, 'reserved_at' => null,
        'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
        'payload' => json_encode(['displayName' => ProcessShopifyStackFulfillmentJob::class,
            'data' => ['command' => serialize(new ProcessShopifyStackFulfillmentJob($fixture['fulfillment']->id))]])]);
    expect(diagnosticGroup()['classification'])->toBe(FailedStackFulfillmentDiagnostic::MANUAL);
});

it('flags differing alias payloads and already completed fulfillment records', function () {
    $fixture = diagnosticFixture();
    $other = $fixture['fulfillment']->replicate();
    $other->shopify_fulfillment_id = 'gid://shopify/Fulfillment/7001';
    $other->processing_status = 'completed';
    $other->payload = ['line_items' => [['id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 2]]];
    $other->save();
    $group = diagnosticGroup();
    expect($group['classification'])->toBe(FailedStackFulfillmentDiagnostic::RECONCILE)
        ->and(implode(' ', $group['reasons']))->toContain('already completed', 'different line/quantity');
});

it('flags legacy numeric reservation IDs instead of treating normalized discovery as runtime compatibility', function () {
    $fixture = diagnosticFixture();
    $fixture['reservation']->update(['shopify_order_id' => '5855']);
    expect(diagnosticGroup()['classification'])->toBe(FailedStackFulfillmentDiagnostic::MANUAL);
});

it('reports missing configuration without aborting all other diagnostics', function () {
    $fixture = diagnosticFixture();
    $fixture['draft']->update(['bundle_product_ids' => []]);
    $group = diagnosticGroup();
    expect($group['classification'])->toBe(FailedStackFulfillmentDiagnostic::MANUAL)
        ->and(implode(' ', $group['reasons']))->toContain('configuration is unavailable or empty');
});

it('flags unconfirmed movements on another order sharing the inventory pool', function () {
    $fixture = diagnosticFixture();
    $other = $fixture['reservation']->replicate();
    $other->shopify_order_id = 'gid://shopify/Order/999';
    $other->save();
    ShopifyStackInventoryMovement::create(['reservation_id' => $other->id, 'event_key' => hash('sha256', 'other'),
        'idempotency_key' => fake()->uuid(), 'action' => 'consume', 'quantity' => 1, 'status' => 'failed', 'attempts' => 1]);
    $group = diagnosticGroup();
    expect($group['classification'])->toBe(FailedStackFulfillmentDiagnostic::RECONCILE)
        ->and($group['unconfirmed_pool_movements'][0]['order_id'])->toBe('gid://shopify/Order/999');
});

it('filters by order number and canonical fulfillment ID', function () {
    diagnosticFixture();
    $diagnostic = app(FailedStackFulfillmentDiagnostic::class);
    expect($diagnostic->report('#5855', 'gid://shopify/Fulfillment/7001')['counts']['fulfillment_groups'])->toBe(1)
        ->and($diagnostic->report('gid://shopify/Order/5855', '7001')['counts']['fulfillment_groups'])->toBe(1)
        ->and($diagnostic->report('999')['counts']['fulfillment_groups'])->toBe(0);
});

it('exports parseable JSON without writing or dispatching anything', function () {
    diagnosticFixture();
    $tables = ['shopify_fulfillments', 'shopify_stack_inventory_reservations', 'shopify_stack_inventory_movements', 'failed_jobs', 'jobs'];
    $snapshot = fn () => collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
    $before = $snapshot();
    expect(Artisan::call('shopify:diagnose-failed-stack-fulfillments', ['--format' => 'json']))->toBe(0);
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
    expect($report['read_only'])->toBeTrue()->and($report['counts']['potential_safe_retry'])->toBe(1)
        ->and($snapshot())->toBe($before);
    Bus::assertNothingDispatched();
    Mail::assertNothingSent();
});

it('enforces read-only SQLite access and restores the connection after errors', function () {
    diagnosticFixture();
    expect(fn () => app(ReadOnlyInventoryDiagnostic::class)->run(fn () => DB::table('shopify_fulfillments')->update(['processing_status' => 'completed'])))
        ->toThrow(QueryException::class);
    expect(ShopifyFulfillment::first()->processing_status)->toBe('failed')
        ->and((int) DB::selectOne('PRAGMA query_only')->query_only)->toBe(0);
});

it('checks every component of two stacks and independently skips known gift packaging', function () {
    $fixture = diagnosticFixture();
    $componentIds = [$fixture['component']->id];
    for ($index = 2; $index <= 6; $index++) {
        $component = Product::withoutEvents(fn () => Product::create(['import_id' => $fixture['import']->id,
            'shopify_id' => 'gid://shopify/Product/'.(5100 + $index), 'handle' => 'component-'.$index, 'title' => 'Component '.$index]));
        $variant = Variant::withoutEvents(fn () => Variant::create(['product_id' => $component->id,
            'shopify_id' => 'gid://shopify/ProductVariant/'.(9100 + $index), 'sku' => 'COMP-'.$index,
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/'.(6100 + $index)]));
        $componentIds[] = $component->id;
        $reservation = $fixture['reservation']->replicate();
        $reservation->fill(['configured_component_product_id' => $component->id, 'component_product_id' => $component->id,
            'component_variant_id' => $variant->id, 'component_sku' => $variant->sku, 'shopify_inventory_item_id' => $variant->shopify_inventory_item_id,
            'ledger_document_uri' => 'leighavenue-cms://stack-reservations/'.$index,
            'shopify_order_line_item_id' => 'gid://shopify/LineItem/'.($index <= 3 ? 10001 : 10002),
            'stack_sku' => $index <= 3 ? 'STACK-A' : 'STACK-B']);
        $reservation->save();
        ShopifyStackInventoryMovement::create(['reservation_id' => $reservation->id, 'event_key' => hash('sha256', 'reserve-'.$index),
            'idempotency_key' => fake()->uuid(), 'action' => 'reserve', 'quantity' => 1, 'status' => 'completed', 'attempts' => 1,
            'shopify_response' => ['createdAt' => now()->toIso8601String()]]);
    }
    NewProductDraft::withoutEvents(fn () => $fixture['draft']->update(['bundle_product_ids' => array_slice($componentIds, 0, 3)]));
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['sku' => 'STACK-B', 'title' => 'Stack B', 'bundle_product_ids' => array_slice($componentIds, 3)]));
    $gift = Product::withoutEvents(fn () => Product::create(['import_id' => $fixture['import']->id, 'shopify_id' => 'gid://shopify/Product/5301',
        'handle' => 'gift-packaging', 'title' => 'Gift Packaging', 'is_bundle' => false]));
    Variant::withoutEvents(fn () => Variant::create(['product_id' => $gift->id, 'shopify_id' => 'gid://shopify/ProductVariant/9301', 'sku' => null]));
    $fixture['fulfillment']->update(['payload' => ['line_items' => [
        ['id' => 10003, 'variant_id' => 9301, 'sku' => null, 'quantity' => 1],
        ['id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 1],
        ['id' => 10002, 'sku' => 'STACK-B', 'quantity' => 1],
    ]]]);
    $group = diagnosticGroup();
    expect($group['classification'])->toBe(FailedStackFulfillmentDiagnostic::POTENTIAL)
        ->and(count($group['order_reservations']))->toBe(6)->and(count($group['order_movements']))->toBe(6)
        ->and(collect($group['lines'])->where('is_stack', true)->count())->toBe(2)
        ->and($group['lines'][0]['is_stack'])->toBeFalse();
});

it('flags a failed adjustment audit even when a movement row says completed', function () {
    $fixture = diagnosticFixture();
    $movement = $fixture['reservation']->movements()->first();
    InventoryOperationAudit::create(['correlation_key' => $movement->idempotency_key,
        'actor_type' => 'system', 'execution' => 'queue', 'workflow' => 'stack_reserve', 'action' => 'reserve', 'status' => 'failed',
        'error_message' => 'Remote response lost', 'related_type' => ShopifyStackInventoryMovement::class, 'related_id' => $movement->id,
        'occurred_at' => now()]);
    expect(diagnosticGroup()['classification'])->toBe(FailedStackFulfillmentDiagnostic::RECONCILE);
});

it('flags ambiguous stack identity without failing the entire report', function () {
    $fixture = diagnosticFixture();
    $duplicate = $fixture['draft']->replicate();
    $duplicate->handle = 'diagnostic-stack-duplicate';
    NewProductDraft::withoutEvents(fn () => $duplicate->save());
    $group = diagnosticGroup();
    expect($group['classification'])->toBe(FailedStackFulfillmentDiagnostic::MANUAL)
        ->and(implode(' ', $group['reasons']))->toContain('ambiguous');
});

it('reports malformed fulfillment IDs for manual investigation', function () {
    $fixture = diagnosticFixture();
    $fixture['fulfillment']->update(['shopify_fulfillment_id' => 'gid://shopify/Order/7001']);
    expect(diagnosticGroup()['classification'])->toBe(FailedStackFulfillmentDiagnostic::MANUAL);
});

it('rejects unsupported output formats without inspecting or changing records', function () {
    $this->artisan('shopify:diagnose-failed-stack-fulfillments', ['--format' => 'csv'])->assertExitCode(2);
    expect(ShopifyFulfillment::count())->toBe(0);
});

it('does not infer queue inactivity when a queued fulfillment payload cannot be decoded', function () {
    diagnosticFixture();
    DB::table('jobs')->insert(['queue' => 'inventory-ledger', 'attempts' => 0, 'reserved_at' => null,
        'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
        'payload' => json_encode(['displayName' => ProcessShopifyStackFulfillmentJob::class, 'data' => ['command' => 'encrypted-or-corrupt']])]);
    $report = app(FailedStackFulfillmentDiagnostic::class)->report();
    expect($report['fulfillments'][0]['classification'])->toBe(FailedStackFulfillmentDiagnostic::MANUAL)
        ->and(count($report['unlinked_queued_jobs']))->toBe(1);
});

it('flags unavailable queue backend evidence rather than assuming it is empty', function () {
    diagnosticFixture();
    config(['queue.connections.inventory-ledger.driver' => 'redis']);
    expect(diagnosticGroup()['classification'])->toBe(FailedStackFulfillmentDiagnostic::MANUAL);
});

it('distinguishes unattempted intents from ambiguous remote calls', function () {
    $fixture = diagnosticFixture();
    $movement = diagnosticConsume($fixture, 'pending', 0);
    $group = diagnosticGroup();
    expect($group['classification'])->toBe(FailedStackFulfillmentDiagnostic::MANUAL)
        ->and(collect($group['order_movements'])->firstWhere('id', $movement->id)['remote_outcome'])->toBe('no_recorded_remote_attempt');
});

it('does not accept a completed movement with no stored confirmation as sufficient evidence', function () {
    $fixture = diagnosticFixture();
    $fixture['reservation']->movements()->first()->update(['shopify_response' => null]);
    expect(diagnosticGroup()['classification'])->toBe(FailedStackFulfillmentDiagnostic::RECONCILE);
});

it('renders the human-readable report with failure and movement history', function () {
    diagnosticFixture();
    $this->artisan('shopify:diagnose-failed-stack-fulfillments')
        ->expectsOutputToContain('READ ONLY')
        ->expectsOutputToContain('potential_safe_retry')
        ->assertExitCode(0);
});

function diagnosticGroup(): array
{
    return app(FailedStackFulfillmentDiagnostic::class)->report()['fulfillments'][0];
}

function diagnosticFixture(array $options = []): array
{
    $import = Import::create(['filename' => 'diagnostic.csv', 'mode' => 'append', 'status' => 'ready', 'created_by' => User::factory()->create()->id]);
    $product = Product::withoutEvents(fn () => Product::create(['import_id' => $import->id, 'shopify_id' => 'gid://shopify/Product/5001',
        'handle' => 'diagnostic-stack', 'title' => 'Diagnostic stack', 'is_bundle' => true]));
    $variant = Variant::withoutEvents(fn () => Variant::create(['product_id' => $product->id, 'shopify_id' => 'gid://shopify/ProductVariant/9001', 'sku' => 'STACK-A']));
    $component = Product::withoutEvents(fn () => Product::create(['import_id' => $import->id, 'shopify_id' => 'gid://shopify/Product/5101', 'handle' => 'diagnostic-component', 'title' => 'Component']));
    $componentVariant = Variant::withoutEvents(fn () => Variant::create(['product_id' => $component->id, 'sku' => 'COMP-A', 'shopify_id' => 'gid://shopify/ProductVariant/9101',
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/6101']));
    $draft = NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['shopify_id' => $product->shopify_id, 'handle' => $product->handle, 'sku' => 'STACK-A',
        'title' => 'Diagnostic stack', 'bundle_product_ids' => [$component->id], 'bundle_component_quantities' => [['product_id' => $component->id, 'quantity' => 1]]]));
    $fulfillment = ShopifyFulfillment::create(['shopify_fulfillment_id' => '7001', 'shopify_order_id' => '5855', 'shopify_status' => 'success',
        'processing_status' => 'failed', 'attempts' => 5, 'error_message' => 'No component reservation exists for fulfilled Stack line gid://shopify/LineItem/10001; retrying after the order webhook.',
        'payload' => ['line_items' => [['id' => 10001, 'variant_id' => 9001, 'sku' => 'STACK-A', 'quantity' => 1]]]]);
    $reservation = null;
    if ($options['reservations'] ?? true) {
        $reservation = ShopifyStackInventoryReservation::create(['shopify_order_id' => 'gid://shopify/Order/5855', 'shopify_order_name' => '#5855',
            'shopify_order_line_item_id' => 'gid://shopify/LineItem/10001', 'configured_component_product_id' => $component->id,
            'component_product_id' => $component->id, 'component_variant_id' => $componentVariant->id, 'stack_sku' => 'STACK-A', 'component_sku' => 'COMP-A',
            'stack_quantity_ordered' => 1, 'component_quantity_per_stack' => 1, 'total_component_quantity_required' => 1, 'reserved_quantity' => 1,
            'shopify_inventory_item_id' => $componentVariant->shopify_inventory_item_id, 'shopify_location_id' => 'gid://shopify/Location/6001',
            'ledger_document_uri' => 'leighavenue-cms://stack-reservations/test', 'status' => 'pending']);
        ShopifyStackInventoryMovement::create(['reservation_id' => $reservation->id, 'event_key' => hash('sha256', 'reserve'), 'idempotency_key' => fake()->uuid(),
            'action' => 'reserve', 'quantity' => 1, 'status' => 'completed', 'attempts' => 1, 'shopify_response' => ['createdAt' => now()->toIso8601String()]]);
    }

    return compact('import', 'product', 'variant', 'component', 'componentVariant', 'draft', 'fulfillment', 'reservation');
}

function diagnosticConsume(array $fixture, string $status, int $attempts, string $fulfillment = 'gid://shopify/Fulfillment/7001'): ShopifyStackInventoryMovement
{
    $reservation = $fixture['reservation'];

    return ShopifyStackInventoryMovement::create(['reservation_id' => $reservation->id,
        'event_key' => hash('sha256', "fulfillment:{$fulfillment}:gid://shopify/LineItem/10001|{$reservation->id}|consume"),
        'idempotency_key' => fake()->uuid(), 'action' => 'consume', 'quantity' => 1, 'status' => $status, 'attempts' => $attempts,
        'shopify_response' => $status === 'completed' ? ['createdAt' => now()->toIso8601String()] : null]);
}
