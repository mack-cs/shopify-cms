<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Http\Middleware\MonitorInventoryWebhooks;
use App\Jobs\ProcessShopifyStackFulfillmentJob;
use App\Mail\InventoryPipelineAlertMail;
use App\Models\EngravedBeadFamily;
use App\Models\InventoryOperationAudit;
use App\Models\InventoryPipelineAlert;
use App\Models\InventoryPipelineIncident;
use App\Models\NewProductDraft;
use App\Models\ShopifyFulfillment;
use App\Models\ShopifyStackInventoryMovement;
use App\Models\ShopifyStackInventoryReservation;
use App\Models\ShopifyStackOrderEvent;
use App\Services\Shopify\InventoryPipelineHealthCheck;
use App\Services\Shopify\InventoryPipelineNotifications;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['inventory_monitor.enabled' => true, 'inventory_monitor.recipient' => 'shonayimack@mackscs.com']);
    Mail::fake();
});

it('deduplicates retry messages and sends a single recovery after confirmed completion', function () {
    $fulfillment = monitorFulfillment('failed');
    $health = app(InventoryPipelineHealthCheck::class);
    $health->scan();
    $fulfillment->update(['attempts' => 2, 'error_message' => 'A different retry error']);
    $health->scan();
    $notifications = app(InventoryPipelineNotifications::class);
    $notifications->deliver();
    $health->scan();
    $notifications->deliver();
    Mail::assertSentCount(1);
    Mail::assertSent(InventoryPipelineAlertMail::class, fn ($mail) => $mail->hasTo('shonayimack@mackscs.com')
        && $mail->details['retry_attempts'] === 2 && $mail->details['environment'] === 'testing'
        && $mail->details['fulfillment_id'] === 'gid://shopify/Fulfillment/7001');
    $fulfillment->update(['processing_status' => 'processing', 'error_message' => null]);
    $health->scan();
    expect(InventoryPipelineIncident::first()->resolved_at)->toBeNull();
    $fulfillment->update(['processing_status' => 'completed']);
    $health->scan();
    $health->scan();
    $notifications->deliver();
    Mail::assertSentCount(2);
    Mail::assertSent(InventoryPipelineAlertMail::class, fn ($mail) => $mail->type === 'recovery' && $mail->details['status'] === 'completed');
});

it('detects stale pending and crashed processing records without changing inventory', function () {
    $reservation = monitorReservation(['created_at' => now()->subHours(2)]);
    $fulfillment = monitorFulfillment('processing', ['processing_started_at' => now()->subMinutes(20)]);
    $movement = ShopifyStackInventoryMovement::create(['reservation_id' => $reservation->id, 'event_key' => str_repeat('a', 64),
        'idempotency_key' => fake()->uuid(), 'action' => 'consume', 'quantity' => 1, 'status' => 'processing', 'updated_at' => now()->subMinutes(20)]);
    $before = [$reservation->fresh()->getAttributes(), $movement->fresh()->getAttributes(), $fulfillment->fresh()->getAttributes()];
    app(InventoryPipelineHealthCheck::class)->scan();
    expect(InventoryPipelineIncident::where('kind', 'pipeline_record_stale')->count())->toBe(3)
        ->and([$reservation->fresh()->getAttributes(), $movement->fresh()->getAttributes(), $fulfillment->fresh()->getAttributes()])->toBe($before);
});

it('reports missing stack reservations immediately through the failed fulfillment record', function () {
    monitorFulfillment('failed', ['error_message' => 'No component reservation exists for fulfilled Stack line gid://shopify/LineItem/10001']);
    app(InventoryPipelineHealthCheck::class)->scan();
    app(InventoryPipelineNotifications::class)->deliver();
    Mail::assertSent(InventoryPipelineAlertMail::class, fn ($mail) => str_contains($mail->details['error_message'], 'No component reservation'));
});

it('detects incomplete snapshots including a completed event and ignores ordinary gift lines', function () {
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['sku' => 'STACK-A', 'title' => 'Stack A',
        'bundle_product_ids' => [101, 102, 103], 'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL]));
    ShopifyStackOrderEvent::create(['webhook_id' => 'snapshot-order', 'topic' => 'orders/create', 'shopify_order_id' => 'gid://shopify/Order/5855',
        'shopify_order_name' => '#5855', 'status' => 'completed', 'created_at' => now()->subMinutes(20),
        'payload' => ['line_items' => [['id' => 10001, 'sku' => 'STACK-A', 'quantity' => 1], ['id' => 2, 'sku' => null, 'quantity' => 1]]]]);
    monitorReservation();
    app(InventoryPipelineHealthCheck::class)->scan();
    $incident = InventoryPipelineIncident::where('kind', 'incomplete_component_snapshot')->sole();
    expect($incident->details['missing_component_product_ids'])->toBe([102, 103])
        ->and($incident->details['stack_skus'])->toBe(['STACK-A']);
    monitorReservation(['configured_component_product_id' => 102]);
    monitorReservation(['configured_component_product_id' => 103]);
    app(InventoryPipelineHealthCheck::class)->scan();
    expect($incident->fresh()->resolved_at)->not->toBeNull();
});

it('does not classify an intentionally skipped historical order as an incomplete snapshot', function () {
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['sku' => 'STACK-A', 'title' => 'Stack A', 'bundle_product_ids' => [101]]));
    ShopifyStackOrderEvent::create(['webhook_id' => 'historical-order', 'topic' => 'orders/updated', 'shopify_order_id' => '1', 'status' => 'completed',
        'created_at' => now()->subMinutes(20), 'error_message' => 'Skipped reservation because live Shopify shows this order as fulfilled.',
        'payload' => ['line_items' => [['id' => 1, 'sku' => 'STACK-A', 'quantity' => 1]]]]);
    app(InventoryPipelineHealthCheck::class)->scan();
    expect(InventoryPipelineIncident::count())->toBe(0);
});

it('detects queue starvation and stuck reservations but respects deliberately delayed jobs', function () {
    $base = ['queue' => 'inventory-ledger', 'payload' => '{}', 'attempts' => 0, 'created_at' => now()->subHour()->timestamp];
    $waiting = DB::table('jobs')->insertGetId($base + ['available_at' => now()->subMinutes(20)->timestamp, 'reserved_at' => null]);
    DB::table('jobs')->insert($base + ['available_at' => now()->addHour()->timestamp, 'reserved_at' => null]);
    $health = app(InventoryPipelineHealthCheck::class);
    $health->scan();
    expect(InventoryPipelineIncident::where('kind', 'queue_not_processing')->count())->toBe(1);
    DB::table('jobs')->where('id', $waiting)->delete(); // Simulate a worker having processed this synthetic test job.
    $health->scan();
    expect(InventoryPipelineIncident::where('kind', 'queue_not_processing')->sole()->resolved_at)->not->toBeNull();
});

it('captures exhausted job callbacks and resolves them only when their target completes', function () {
    $fulfillment = monitorFulfillment('failed');
    $payload = monitorJobPayload($fulfillment);
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('payload')->andReturn($payload);
    $job->shouldReceive('getQueue')->andReturn('inventory-ledger');
    $job->shouldReceive('attempts')->andReturn(5);
    Event::dispatch(new JobFailed('inventory-ledger', $job, new RuntimeException('Exhausted')));
    Event::dispatch(new JobFailed('inventory-ledger', $job, new RuntimeException('Exhausted')));
    expect(InventoryPipelineIncident::where('kind', 'job_retries_exhausted')->count())->toBe(1);
    app(InventoryPipelineHealthCheck::class)->scan();
    expect(InventoryPipelineIncident::where('kind', 'job_retries_exhausted')->sole()->resolved_at)->toBeNull();
    $fulfillment->update(['processing_status' => 'completed', 'error_message' => null]);
    app(InventoryPipelineHealthCheck::class)->scan();
    expect(InventoryPipelineIncident::where('kind', 'job_retries_exhausted')->sole()->resolved_at)->not->toBeNull();
});

it('finds exhausted jobs from failed_jobs when the callback never ran', function () {
    $fulfillment = monitorFulfillment('failed');
    DB::table('failed_jobs')->insert(['uuid' => 'failed-fallback', 'connection' => 'inventory-ledger', 'queue' => 'inventory-ledger',
        'payload' => json_encode(monitorJobPayload($fulfillment)), 'exception' => 'Worker exhausted retries', 'failed_at' => now()]);
    app(InventoryPipelineHealthCheck::class)->scan();
    expect(InventoryPipelineIncident::where('kind', 'job_retries_exhausted')->sole()->details['retry_attempts'])->toBe(5);
});

it('persists mail failures and retries without losing failure or recovery ordering', function () {
    $notifications = app(InventoryPipelineNotifications::class);
    $notifications->observe('test_failure', 'test', 1, true);
    $notifications->observe('test_failure', 'test', 1, false);
    $mailFake = Mail::getFacadeRoot();
    Mail::swap(Mockery::mock(Mailer::class, function ($mock) {
        $mock->shouldReceive('to')->andReturnSelf();
        $mock->shouldReceive('send')->once()->andThrow(new RuntimeException('SMTP unavailable'));
    }));
    expect($notifications->deliver())->toBe(['sent' => 0, 'failed' => 1]);
    expect(InventoryPipelineAlert::whereNotNull('sent_at')->count())->toBe(0)
        ->and(InventoryPipelineAlert::first()->delivery_error)->toBe('SMTP unavailable');
    Mail::swap($mailFake);
    $this->travel(6)->minutes();
    expect($notifications->deliver())->toBe(['sent' => 2, 'failed' => 0]);
    $notifications->deliver();
    Mail::assertSentCount(2);
    expect(InventoryPipelineAlert::orderBy('id')->pluck('type')->all())->toBe(['failure', 'recovery']);
});

it('sends configurable reminders and a fresh failure after recurrence', function () {
    config(['inventory_monitor.reminder_minutes' => 30]);
    $notifications = app(InventoryPipelineNotifications::class);
    $notifications->observe('failure', 'test', 1, true);
    $notifications->deliver();
    $this->travel(31)->minutes();
    $notifications->observe('failure', 'test', 1, true);
    $notifications->observe('failure', 'test', 1, true);
    $notifications->deliver();
    expect(InventoryPipelineAlert::where('type', 'reminder')->count())->toBe(1);
    $notifications->observe('failure', 'test', 1, false);
    $notifications->observe('failure', 'test', 1, true);
    $notifications->deliver();
    expect(InventoryPipelineIncident::sole()->generation)->toBe(2);
    Mail::assertSentCount(4);
});

it('monitors engraved bead errors while inventory writes are disabled', function () {
    config(['engraved_beads.inventory_writes_enabled' => false]);
    $family = EngravedBeadFamily::create(['family_tag' => 'test-monitor-family', 'display_name' => 'Test family',
        'status' => 'reconciliation_required', 'sync_error' => 'Unconfirmed physical movement']);
    app(InventoryPipelineHealthCheck::class)->scan();
    expect(InventoryPipelineIncident::where('kind', 'engraved_bead_reconciliation')->count())->toBe(1);
    $family->update(['status' => 'active', 'sync_error' => null]);
    app(InventoryPipelineHealthCheck::class)->scan();
    expect(InventoryPipelineIncident::where('kind', 'engraved_bead_reconciliation')->sole()->resolved_at)->not->toBeNull();
});

it('captures webhook exceptions and observes acceptance recovery without swallowing the exception', function () {
    $request = Request::create('/webhooks/shopify/stack-orders', 'POST', server: ['HTTP_X_SHOPIFY_TOPIC' => 'orders/create'],
        content: json_encode(['id' => 5855, 'name' => '#5855']));
    $middleware = new MonitorInventoryWebhooks;
    expect(fn () => $middleware->handle($request, fn () => throw new RuntimeException('Queue database unavailable')))->toThrow(RuntimeException::class);
    expect(InventoryPipelineIncident::sole()->details['order_number'])->toBe('#5855');
    $middleware->handle($request, fn () => response()->json(['status' => 'queued'], 202));
    expect(InventoryPipelineIncident::sole()->resolved_at)->not->toBeNull();
});

it('renders escaped operational details with CMS links', function () {
    $notifications = app(InventoryPipelineNotifications::class);
    $notifications->observe('failure', 'test', 1, true, ['error_message' => '<script>bad</script>', 'cms_url' => 'https://cms.test/records']);
    $mail = new InventoryPipelineAlertMail(InventoryPipelineAlert::first()->details, 'failure');
    expect($mail->render())->toContain('&lt;script&gt;', 'https://cms.test/records', 'Unavailable', 'before any manual retry');
});

it('does nothing when disabled', function () {
    config(['inventory_monitor.enabled' => false]);
    monitorFulfillment('failed');
    app(InventoryPipelineHealthCheck::class)->scan();
    app(InventoryPipelineNotifications::class)->observe('test', 'test', 1, true);
    app(InventoryPipelineNotifications::class)->deliver();
    expect(InventoryPipelineIncident::count())->toBe(0);
    Mail::assertNothingSent();
});

it('deduplicates independent monitor instances against the shared durable outbox', function () {
    $first = new InventoryPipelineNotifications;
    $second = new InventoryPipelineNotifications;
    $first->observe('same_failure', 'movement', 42, true);
    $second->observe('same_failure', 'movement', 42, true);
    $first->deliver();
    $second->deliver();
    expect(InventoryPipelineIncident::count())->toBe(1)->and(InventoryPipelineAlert::count())->toBe(1);
    Mail::assertSentCount(1);
});

it('retains an alert when the recipient is invalid and suppresses reminders when disabled', function () {
    config(['inventory_monitor.recipient' => 'invalid', 'inventory_monitor.reminder_minutes' => 0]);
    $notifications = app(InventoryPipelineNotifications::class);
    $notifications->observe('failure', 'test', 1, true);
    expect($notifications->deliver()['failed'])->toBe(1);
    Mail::assertNothingSent();
    config(['inventory_monitor.recipient' => 'shonayimack@mackscs.com']);
    $this->travel(6)->minutes();
    $notifications->deliver();
    $this->travel(2)->days();
    $notifications->observe('failure', 'test', 1, true);
    expect(InventoryPipelineAlert::count())->toBe(1);
});

it('detects an ambiguous inventory audit with its order and component context', function () {
    $reservation = monitorReservation();
    $movement = ShopifyStackInventoryMovement::create(['reservation_id' => $reservation->id, 'event_key' => str_repeat('b', 64),
        'idempotency_key' => fake()->uuid(), 'action' => 'consume', 'quantity' => 1, 'status' => 'failed', 'error_message' => 'Connection reset']);
    $audit = InventoryOperationAudit::create(['correlation_key' => 'ambiguous-1', 'actor_type' => 'system', 'execution' => 'queue',
        'workflow' => 'stack_consume', 'action' => 'consume', 'status' => 'failed', 'error_message' => 'Connection reset',
        'related_type' => ShopifyStackInventoryMovement::class, 'related_id' => $movement->id, 'occurred_at' => now()]);
    app(InventoryPipelineHealthCheck::class)->scan();
    $incident = InventoryPipelineIncident::where('kind', 'inventory_adjustment_failed_or_unconfirmed')->sole();
    expect($incident->details['order_number'])->toBe('#5855')->and($incident->details['component_skus'])->toBe(['COMP-A']);
    $audit->update(['status' => 'succeeded', 'error_message' => null]);
    app(InventoryPipelineHealthCheck::class)->scan();
    expect($incident->fresh()->resolved_at)->not->toBeNull();
});

it('detects known stacks with empty configuration using product identity', function () {
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['shopify_id' => 'gid://shopify/Product/55',
        'title' => 'Empty stack', 'tags' => 'stacks', 'bundle_product_ids' => []]));
    ShopifyStackOrderEvent::create(['webhook_id' => 'empty-stack', 'topic' => 'orders/create', 'shopify_order_id' => '55',
        'status' => 'completed', 'created_at' => now()->subMinutes(20),
        'payload' => ['line_items' => [['id' => 1, 'product_id' => 55, 'sku' => null, 'quantity' => 1]]]]);
    app(InventoryPipelineHealthCheck::class)->scan();
    expect(InventoryPipelineIncident::where('kind', 'stack_configuration_missing')->count())->toBe(1);
});

it('reuses the existing live leftover audit without issuing inventory mutations', function () {
    $reservation = monitorReservation();
    $this->mock(ShopifyGraphqlGateway::class, function ($mock) use ($reservation) {
        $mock->shouldReceive('graphql')->once()->andReturn(['nodes' => [
            ['id' => $reservation->shopify_order_id, 'displayFulfillmentStatus' => 'FULFILLED', 'name' => '#5855', 'cancelledAt' => null],
        ]]);
    });
    app(InventoryPipelineHealthCheck::class)->scan(true);
    expect(InventoryPipelineIncident::where('kind', 'live_leftover_reservation')->count())->toBe(1)
        ->and($reservation->fresh()->reserved_quantity)->toBe(1);
});

it('reports a health scan failure and retries existing notifications independently', function () {
    config(['queue.connections.inventory-ledger.table' => 'missing_jobs_table']);
    $this->artisan('shopify:inventory-health')->assertExitCode(1);
    Mail::assertSent(InventoryPipelineAlertMail::class, fn ($mail) => $mail->details['kind'] === 'health_check_failed');
});

it('recovers a generic exhausted inventory job only after a successful callback', function () {
    $health = app(InventoryPipelineHealthCheck::class);
    $payload = ['uuid' => 'generic-inventory-job', 'displayName' => 'App\\Jobs\\SomeInventoryJob'];
    $health->exhausted($payload, 'inventory-ledger', 'Failed', 5);
    $health->completedJob($payload, 'inventory-ledger');
    expect(InventoryPipelineIncident::sole()->resolved_at)->not->toBeNull();
    DB::table('failed_jobs')->insert(['uuid' => 'generic-inventory-job', 'connection' => 'inventory-ledger', 'queue' => 'inventory-ledger',
        'payload' => json_encode($payload), 'exception' => 'Failed', 'failed_at' => now()->subMinute()]);
    $health->scan();
    expect(InventoryPipelineIncident::sole()->generation)->toBe(1)->and(InventoryPipelineIncident::sole()->resolved_at)->not->toBeNull();
});

function monitorFulfillment(string $status, array $attributes = []): ShopifyFulfillment
{
    return ShopifyFulfillment::create(array_replace(['shopify_fulfillment_id' => 'gid://shopify/Fulfillment/7001',
        'shopify_order_id' => 'gid://shopify/Order/5855', 'shopify_status' => 'success', 'processing_status' => $status,
        'attempts' => 5, 'payload' => ['line_items' => []], 'error_message' => $status === 'failed' ? 'Shopify request failed' : null], $attributes));
}

function monitorReservation(array $attributes = []): ShopifyStackInventoryReservation
{
    return ShopifyStackInventoryReservation::create(array_replace(['shopify_order_id' => 'gid://shopify/Order/5855',
        'shopify_order_name' => '#5855', 'shopify_order_line_item_id' => 'gid://shopify/LineItem/10001',
        'configured_component_product_id' => 101, 'stack_quantity_ordered' => 1, 'stack_sku' => 'STACK-A', 'component_sku' => 'COMP-A',
        'component_quantity_per_stack' => 1, 'total_component_quantity_required' => 1, 'reserved_quantity' => 1,
        'ledger_document_uri' => 'leighavenue-cms://stack-reservations/test', 'status' => 'pending'], $attributes));
}

function monitorJobPayload(ShopifyFulfillment $fulfillment): array
{
    return ['uuid' => 'monitor-job-uuid', 'displayName' => ProcessShopifyStackFulfillmentJob::class,
        'data' => ['command' => serialize(new ProcessShopifyStackFulfillmentJob($fulfillment->id))]];
}
