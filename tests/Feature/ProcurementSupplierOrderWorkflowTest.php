<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Jobs\ProcessSupplierReceiptJob;
use App\Mail\PendingSupplierReceiptPushReminderMail;
use App\Models\Import;
use App\Models\ProcurementPrediction;
use App\Models\ProcurementPredictionRun;
use App\Models\ProcurementSupplierOrder;
use App\Models\ProcurementSupplierOrderLine;
use App\Models\NewProductDraft;
use App\Models\ProcurementSupplierReceipt;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Notifications\PendingSupplierReceiptPushSlackNotification;
use App\Services\Procurement\PendingSupplierReceiptPushReminderService;
use App\Services\GoogleSheets\ProcurementSheetDatasetBuilder;
use App\Services\Procurement\ProcurementSelectionCsvExporter;
use App\Services\Procurement\SupplierOrderReportingReconciliationService;
use App\Services\Procurement\SupplierOrderCsvService;
use App\Services\Procurement\SupplierOrderProjectionService;
use App\Services\Procurement\SupplierOrderService;
use App\Services\Procurement\SupplierOrderSummaryService;
use App\Services\Procurement\SupplierReceiptService;
use App\Services\Shopify\ShopifyInventoryAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('creates an order and updates the CMS-owned outstanding summary', function (): void {
    $variant = supplierWorkflowVariant('ORDER-1');
    $line = app(SupplierOrderService::class)->createForVariant($variant, 'PO-100', 25, '2026-09-15');
    $stock = $variant->procurementIncomingStock()->firstOrFail();

    expect($line->quantity_ordered)->toBe(25)
        ->and($stock->total_quantity_on_order)->toBe(25)
        ->and($stock->number_of_wip_orders)->toBe(1);
});

it('rejects stack products from procurement orders', function (): void {
    $variant = supplierWorkflowVariant('STACK-NOT-ORDERED');
    Product::withoutEvents(fn () => $variant->product->update(['is_bundle' => true]));

    expect(fn () => app(SupplierOrderService::class)
        ->createForVariant($variant->fresh(), 'PO-STACK', 5, '2026-09-15'))
        ->toThrow(ValidationException::class, 'Stack products cannot be added');

    expect(ProcurementSupplierOrderLine::query()->count())->toBe(0);
});

it('supports partial receipts and prevents duplicate or excessive receipt requests', function (): void {
    Bus::fake();
    $variant = supplierWorkflowVariant('RECEIVE-1');
    $line = app(SupplierOrderService::class)->createForVariant($variant, 'PO-200', 10, '2026-09-20');
    $service = app(SupplierReceiptService::class);

    $first = $service->create($line, 4, 'receipt-key-1');
    $duplicate = $service->create($line, 4, 'receipt-key-1');

    expect($duplicate->id)->toBe($first->id)
        ->and($line->receipts()->count())->toBe(1);

    expect(fn () => $service->create($line, 7, 'receipt-key-2', dispatch: false))
        ->toThrow(ValidationException::class, 'Only 6 unit(s) remain outstanding.');
});

it('requires an audit reason when receiving a later ETA supplier order before an earlier pending order', function (): void {
    $user = User::factory()->create();
    $variant = supplierWorkflowVariant('SEQ-SINGLE');
    app(SupplierOrderService::class)->createForVariant($variant, 'PO-SEQ-EARLY', 5, '2026-09-20');
    $later = app(SupplierOrderService::class)->createForVariant($variant, 'PO-SEQ-LATE', 7, '2026-10-10', allowExistingOrder: false);
    $service = app(SupplierReceiptService::class);

    expect(fn () => $service->create($later, 2, 'seq-single-blocked', $user->id, dispatch: false))
        ->toThrow(ValidationException::class, 'There is an earlier pending supplier order for this SKU');

    $receipt = $service->create(
        $later,
        2,
        'seq-single-confirmed',
        $user->id,
        dispatch: false,
        outOfSequenceReason: 'Supplier delivered the later PO first.',
        outOfSequenceConfirmedBy: $user->id,
    );

    expect($receipt->out_of_sequence_confirmed_by)->toBe($user->id)
        ->and($receipt->out_of_sequence_reason)->toBe('Supplier delivered the later PO first.')
        ->and($receipt->out_of_sequence_audit['received_order_number'])->toBe('PO-SEQ-LATE')
        ->and($receipt->out_of_sequence_audit['earlier_orders'][0]['order_number'])->toBe('PO-SEQ-EARLY')
        ->and($receipt->out_of_sequence_audit['earlier_orders'][0]['eta'])->toBe('2026-09-20')
        ->and($receipt->out_of_sequence_audit['received_eta'])->toBe('2026-10-10');
});

it('requires bulk receipt confirmation when pasted receipts receive later ETA orders first', function (): void {
    $user = User::factory()->create();
    $variant = supplierWorkflowVariant('SEQ-BULK');
    app(SupplierOrderService::class)->createForVariant($variant, 'PO-BULK-EARLY', 5, '2026-09-20');
    app(SupplierOrderService::class)->createForVariant($variant, 'PO-BULK-LATE', 7, '2026-10-10', allowExistingOrder: false);
    $csv = app(SupplierOrderCsvService::class);

    $preview = $csv->previewPastedReceipt("PO-BULK-LATE\tSEQ-BULK\t3", $user->id);
    $warnings = $csv->outOfSequenceWarnings($preview);

    expect($warnings)->toHaveCount(1)
        ->and($warnings->first()['earlier_order_number'])->toBe('PO-BULK-EARLY');

    expect(fn () => $csv->confirm($preview->uuid, $user->id, dispatchReceipts: false))
        ->toThrow(ValidationException::class, 'Confirm the out-of-sequence receipt warning');

    $csv->confirm($preview->uuid, $user->id, dispatchReceipts: false, outOfSequenceReason: 'Container arrived out of ETA order.');
    $receipt = ProcurementSupplierReceipt::query()->firstOrFail();

    expect($receipt->out_of_sequence_confirmed_by)->toBe($user->id)
        ->and($receipt->out_of_sequence_reason)->toBe('Container arrived out of ETA order.')
        ->and($receipt->out_of_sequence_audit['received_order_number'])->toBe('PO-BULK-LATE')
        ->and($receipt->out_of_sequence_audit['earlier_orders'][0]['order_number'])->toBe('PO-BULK-EARLY');
});

it('emails the receipt creator and posts to inventory Slack when a pending receipt is not pushed after thirty minutes', function (): void {
    Mail::fake();
    Notification::fake();
    config([
        'services.slack.channels.inventory' => '#inventory-updates',
        'procurement.pending_receipt_push_reminder_minutes' => 30,
    ]);

    $user = User::factory()->create([
        'email' => 'receiver@example.com',
        'slack_user_id' => 'U0RECEIVER',
        'slack_notifications_enabled' => true,
    ]);
    $variant = supplierWorkflowVariant('PENDING-PUSH');
    $line = app(SupplierOrderService::class)->createForVariant($variant, 'PO-PENDING-PUSH', 4, '2026-10-10', $user->id);
    $receipt = app(SupplierReceiptService::class)->create($line, 2, 'pending-push-reminder', $user->id, dispatch: false);
    $receipt->forceFill(['created_at' => now()->subMinutes(31), 'updated_at' => now()->subMinutes(31)])->save();

    $result = app(PendingSupplierReceiptPushReminderService::class)->sendDueReminders();

    expect($result)->toMatchArray([
        'pending_count' => 1,
        'email_sent' => 1,
        'slack_sent' => true,
        'errors' => [],
    ]);

    Mail::assertSent(
        PendingSupplierReceiptPushReminderMail::class,
        fn (PendingSupplierReceiptPushReminderMail $mail): bool => $mail->hasTo('receiver@example.com')
            && $mail->receipt->id === $receipt->id
    );

    Notification::assertSentOnDemand(
        PendingSupplierReceiptPushSlackNotification::class,
        function (
            PendingSupplierReceiptPushSlackNotification $notification,
            array $channels,
            AnonymousNotifiable $notifiable
        ): bool {
            $payload = $notification->toSlack($notifiable)->toArray();
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);

            return $channels === ['slack']
                && $notifiable->routeNotificationFor('slack') === '#inventory-updates'
                && is_string($json)
                && str_contains($json, 'PENDING-PUSH')
                && str_contains($json, 'PO-PENDING-PUSH')
                && str_contains($json, 'GRV-000001');
        }
    );

    expect($receipt->fresh()->pending_push_reminded_at)->not->toBeNull();

    Mail::fake();
    Notification::fake();

    $second = app(PendingSupplierReceiptPushReminderService::class)->sendDueReminders();

    expect($second['pending_count'])->toBe(0);
    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

it('does not remind for supplier receipts before the thirty minute window or after Shopify push starts', function (): void {
    Mail::fake();
    Notification::fake();
    config([
        'services.slack.channels.inventory' => '#inventory-updates',
        'procurement.pending_receipt_push_reminder_minutes' => 30,
    ]);

    $user = User::factory()->create(['email' => 'receiver@example.com']);
    $variant = supplierWorkflowVariant('NO-PENDING-REMINDER');
    $youngLine = app(SupplierOrderService::class)->createForVariant($variant, 'PO-YOUNG-RECEIPT', 4, '2026-10-10', $user->id);
    app(SupplierReceiptService::class)->create($youngLine, 1, 'young-pending-receipt', $user->id, dispatch: false);
    $pushedVariant = supplierWorkflowVariant('NO-PENDING-PUSHED');
    $pushedLine = app(SupplierOrderService::class)->createForVariant($pushedVariant, 'PO-PUSHED-RECEIPT', 4, '2026-10-11', $user->id);
    $pushed = app(SupplierReceiptService::class)->create($pushedLine, 1, 'pushed-receipt', $user->id, dispatch: false);
    $pushed->forceFill(['status' => 'processing', 'created_at' => now()->subMinutes(45), 'updated_at' => now()->subMinutes(45)])->save();

    $result = app(PendingSupplierReceiptPushReminderService::class)->sendDueReminders();

    expect($result['pending_count'])->toBe(0);
    Mail::assertNothingSent();
    Notification::assertNothingSent();
});

it('allows pending supplier order lines to be amended and records the audit trail', function (): void {
    $user = User::factory()->create();
    $variant = supplierWorkflowVariant('AMEND-1');
    $line = app(SupplierOrderService::class)->createForVariant($variant, 'PO-AMEND', 10, '2026-09-20');

    $updated = app(SupplierOrderService::class)->amendLine($line, [
        'quantity_ordered' => 12,
        'eta_date' => '2026-09-25',
    ], $user->id, 'Supplier confirmed two extra units');

    expect($updated->quantity_ordered)->toBe(12)
        ->and($updated->quantity_outstanding)->toBe(12)
        ->and(\App\Models\ProcurementSupplierOrderAmendment::query()->where('supplier_order_line_id', $line->id)->count())->toBe(2);
});

it('prevents completed supplier order lines from being amended', function (): void {
    $variant = supplierWorkflowVariant('AMEND-COMPLETE');
    $line = app(SupplierOrderService::class)->createForVariant($variant, 'PO-AMEND-COMPLETE', 10, '2026-09-20');
    $line->update(['status' => 'completed']);

    expect(fn () => app(SupplierOrderService::class)->amendLine($line, ['quantity_ordered' => 12]))
        ->toThrow(ValidationException::class, 'Only open supplier order lines can be amended.');
});

it('can receive an amended quantity and assigns unique GRV numbers', function (): void {
    Bus::fake();
    $variant = supplierWorkflowVariant('GRV-1');
    $line = app(SupplierOrderService::class)->createForVariant($variant, 'PO-GRV', 10, '2026-09-20');
    app(SupplierOrderService::class)->amendLine($line, ['quantity_ordered' => 12]);
    $service = app(SupplierReceiptService::class);

    $first = $service->create($line->fresh(), 5, 'grv-key-1', dispatch: false);
    $second = $service->create($line->fresh(), 7, 'grv-key-2', dispatch: false);

    expect($first->grv_number)->toBe('GRV-000001')
        ->and($second->grv_number)->toBe('GRV-000002')
        ->and($first->grv_number)->not->toBe($second->grv_number)
        ->and(ProcurementSupplierReceipt::query()->where('grv_number', $second->grv_number)->first()->quantity_received)->toBe(7);
});

it('backfills GRV numbers for historical receipts that were created before GRV tracking', function (): void {
    $variant = supplierWorkflowVariant('GRV-BACKFILL');
    $first = app(SupplierOrderService::class)->createForVariant($variant, 'PO-GRV-BACKFILL-A', 10, '2026-09-20');
    $second = app(SupplierOrderService::class)->createForVariant($variant, 'PO-GRV-BACKFILL-B', 10, '2026-09-21', allowExistingOrder: false);

    $batch = \App\Models\ProcurementSupplierImportBatch::query()->create([
        'uuid' => (string) Str::uuid(),
        'type' => 'receipt',
        'file_hash' => 'historical-grv-backfill',
        'status' => 'completed',
        'preview_rows' => [],
        'valid_count' => 0,
        'invalid_count' => 0,
    ]);
    $batchedA = $first->receipts()->create([
        'uuid' => (string) Str::uuid(), 'quantity_received' => 4,
        'idempotency_key' => 'historical-batched-a', 'source' => 'csv', 'status' => 'succeeded',
        'post_process_status' => 'completed', 'shopify_reference_uri' => 'test://historical-batched-a',
        'import_batch_id' => $batch->id,
    ]);
    $batchedB = $second->receipts()->create([
        'uuid' => (string) Str::uuid(), 'quantity_received' => 3,
        'idempotency_key' => 'historical-batched-b', 'source' => 'csv', 'status' => 'succeeded',
        'post_process_status' => 'completed', 'shopify_reference_uri' => 'test://historical-batched-b',
        'import_batch_id' => $batch->id,
    ]);
    $standalone = $second->receipts()->create([
        'uuid' => (string) Str::uuid(), 'quantity_received' => 2,
        'idempotency_key' => 'historical-standalone', 'source' => 'cms', 'status' => 'succeeded',
        'post_process_status' => 'completed', 'shopify_reference_uri' => 'test://historical-standalone',
    ]);

    $dryRun = app(SupplierReceiptService::class)->backfillMissingGrvNumbers(dryRun: true);
    expect($dryRun['receipt_count'])->toBe(3)
        ->and($dryRun['grv_count'])->toBe(2)
        ->and(ProcurementSupplierReceipt::query()->whereNull('grv_number')->count())->toBe(3);

    $result = app(SupplierReceiptService::class)->backfillMissingGrvNumbers();
    $batchedGrv = $batchedA->fresh()->grv_number;
    $standaloneGrv = $standalone->fresh()->grv_number;

    expect($result['receipt_count'])->toBe(3)
        ->and($result['grv_count'])->toBe(2)
        ->and($batchedGrv)->toStartWith('GRV-')
        ->and($batchedB->fresh()->grv_number)->toBe($batchedGrv)
        ->and($standaloneGrv)->toStartWith('GRV-')
        ->and($standaloneGrv)->not->toBe($batchedGrv);
});

it('reconciles supplier reporting summaries after historical receipts are backfilled', function (): void {
    $variant = supplierWorkflowVariant('REPORT-RECON');
    $line = app(SupplierOrderService::class)->createForVariant($variant, 'PO-REPORT-RECON', 8, '2026-09-20');
    $line->receipts()->create([
        'uuid' => (string) Str::uuid(), 'quantity_received' => 8,
        'idempotency_key' => 'historical-report-recon', 'source' => 'csv', 'status' => 'succeeded',
        'post_process_status' => 'completed', 'shopify_reference_uri' => 'test://historical-report-recon',
    ]);

    expect($line->fresh()->status)->toBe('open')
        ->and($variant->procurementIncomingStock()->value('total_quantity_on_order'))->toBe(8);

    $dryRun = app(SupplierOrderReportingReconciliationService::class)->reconcile(dryRun: true);

    expect($dryRun)->toMatchArray([
        'lines_checked' => 1,
        'lines_updated' => 1,
        'variants_refreshed' => 1,
    ])
        ->and($line->fresh()->status)->toBe('open')
        ->and($variant->procurementIncomingStock()->value('total_quantity_on_order'))->toBe(8);

    $result = app(SupplierOrderReportingReconciliationService::class)->reconcile();

    expect($result)->toMatchArray([
        'lines_checked' => 1,
        'lines_updated' => 1,
        'variants_refreshed' => 1,
    ])
        ->and($line->fresh()->status)->toBe('completed')
        ->and($line->fresh()->completed_at)->not->toBeNull()
        ->and($variant->procurementIncomingStock()->value('total_quantity_on_order'))->toBe(0)
        ->and($variant->procurementIncomingStock()->value('number_of_wip_orders'))->toBe(0);
});

it('recalculates receipt transfers from fresh inventory without an artificial shortage', function (): void {
    $this->travelTo('2026-09-01 00:00:00');
    $variant = supplierWorkflowVariant('RECEIPT-TRANSFER');
    Variant::withoutEvents(fn () => $variant->forceFill([
        'inventory_qty' => 20,
        'current_inventory_quantity' => 20,
        'current_on_hand_quantity' => 20,
    ])->save());
    $run = ProcurementPredictionRun::query()->create([
        'run_uuid' => (string) Str::uuid(),
        'calculation_date' => '2026-09-01',
        'status' => ProcurementPredictionRun::STATUS_COMPLETED,
        'default_lead_time_days' => 56,
        'attention_horizon_days' => 21,
    ]);
    ProcurementPrediction::query()->create([
        'procurement_prediction_run_id' => $run->id,
        'shopify_variant_id' => $variant->shopify_id,
        'sku' => $variant->sku,
        'current_inventory' => 20,
        'attention_horizon_days' => 21,
        'lead_time_days_used' => 56,
        'lead_time_source' => 'GLOBAL_DEFAULT',
        'predicted_weekly_demand' => 7,
        'predicted_runout_date' => '2026-09-30',
        'recommended_order_before_incoming_stock' => 100,
        'additional_order_required' => 100,
        'action_status' => 'ORDER_NOW',
        'generated_at' => now(),
    ]);
    $line = app(SupplierOrderService::class)
        ->createForVariant($variant, 'PO-TRANSFER', 100, '2026-09-15');

    $line->receipts()->create([
        'uuid' => (string) Str::uuid(),
        'quantity_received' => 40,
        'idempotency_key' => 'transfer-partial',
        'source' => 'test',
        'status' => 'succeeded',
        'post_process_status' => 'completed',
        'shopify_reference_uri' => 'test://transfer-partial',
    ]);
    Variant::withoutEvents(fn () => $variant->forceFill([
        'inventory_qty' => 60,
        'current_inventory_quantity' => 60,
        'current_on_hand_quantity' => 60,
    ])->save());
    app(SupplierOrderSummaryService::class)->refreshVariant($variant->fresh());

    $partial = $run->predictions()->firstOrFail();
    $partialStock = $variant->procurementIncomingStock()->firstOrFail();
    expect($partial->recommended_order_before_incoming_stock)->toBe(60)
        ->and($partial->additional_order_required)->toBe(0)
        ->and($partialStock->total_quantity_on_order)->toBe(60)
        ->and($partialStock->number_of_wip_orders)->toBe(1);

    $line->receipts()->create([
        'uuid' => (string) Str::uuid(),
        'quantity_received' => 60,
        'idempotency_key' => 'transfer-full',
        'source' => 'test',
        'status' => 'succeeded',
        'post_process_status' => 'completed',
        'shopify_reference_uri' => 'test://transfer-full',
    ]);
    $line->update(['status' => 'completed']);
    Variant::withoutEvents(fn () => $variant->forceFill([
        'inventory_qty' => 120,
        'current_inventory_quantity' => 120,
        'current_on_hand_quantity' => 120,
    ])->save());
    app(SupplierOrderSummaryService::class)->refreshVariant($variant->fresh());

    $complete = $run->predictions()->firstOrFail();
    $completeStock = $variant->procurementIncomingStock()->firstOrFail();
    expect($complete->recommended_order_before_incoming_stock)->toBe(0)
        ->and($complete->additional_order_required)->toBe(0)
        ->and($completeStock->total_quantity_on_order)->toBe(0)
        ->and($completeStock->number_of_wip_orders)->toBe(0);
});

it('recalculates totals and WIP count when an order is completed', function (): void {
    $variant = supplierWorkflowVariant('PHASES-1');
    $orders = app(SupplierOrderService::class);
    $first = $orders->createForVariant($variant, 'PO-A', 5, '2026-09-01');
    $orders->createForVariant($variant, 'PO-B', 7, '2026-10-01');
    $first->receipts()->create([
        'uuid' => (string) Str::uuid(), 'quantity_received' => 2,
        'idempotency_key' => 'partial-a', 'source' => 'test', 'status' => 'succeeded',
        'post_process_status' => 'completed', 'shopify_reference_uri' => 'test://partial-a',
    ]);
    app(SupplierOrderProjectionService::class)->projectVariant($variant->fresh(['procurementIncomingStock']));
    $partialStock = $variant->procurementIncomingStock()->firstOrFail();
    $partialSheet = collect(app(ProcurementSheetDatasetBuilder::class)->records())
        ->firstWhere('sku', 'PHASES-1');
    expect($partialStock->total_quantity_on_order)->toBe(10)
        ->and($partialStock->number_of_wip_orders)->toBe(2)
        ->and($partialSheet['next_order_id'])->toBe('PO-A')
        ->and($partialSheet['replenishment_date'])->toBe('01/09/2026');

    $first->receipts()->create([
        'uuid' => (string) Str::uuid(), 'quantity_received' => 3,
        'idempotency_key' => 'done-a', 'source' => 'test', 'status' => 'succeeded',
        'post_process_status' => 'completed', 'shopify_reference_uri' => 'test://done-a',
    ]);
    $first->update(['status' => 'completed']);
    app(SupplierOrderProjectionService::class)->projectVariant($variant->fresh(['procurementIncomingStock']));

    $stock = $variant->procurementIncomingStock()->firstOrFail();
    $sheet = collect(app(ProcurementSheetDatasetBuilder::class)->records())
        ->firstWhere('sku', 'PHASES-1');
    expect($stock->total_quantity_on_order)->toBe(7)
        ->and($stock->number_of_wip_orders)->toBe(1)
        ->and($sheet['next_order_id'])->toBe('PO-B')
        ->and($sheet['replenishment_date'])->toBe('01/10/2026')
        ->and($sheet['second_order_id'])->toBeNull()
        ->and($sheet['between_orders_stock_gap_status'])->toBe('NO_SECOND_ORDER');
});

it('supports more than three WIP orders for one SKU', function (): void {
    $variant = supplierWorkflowVariant('UNLIMITED-1');
    $orders = app(SupplierOrderService::class);
    foreach (range(1, 5) as $number) {
        $orders->createForVariant($variant, "PO-UNLIMITED-{$number}", 10, '2026-10-01');
    }

    $stock = $variant->procurementIncomingStock()->firstOrFail();
    expect($stock->total_quantity_on_order)->toBe(50)
        ->and($stock->number_of_wip_orders)->toBe(5);
});

it('previews pasted tab-separated orders and clears quantity to order after confirmation', function (): void {
    config(['google_sheets.enabled' => false]);
    $variant = supplierWorkflowVariant('PASTE-1');
    $variant->procurementIncomingStock()->create([
        'sku' => 'PASTE-1', 'quantity_to_order' => 30,
        'quantity_on_order_phase_1' => 0, 'quantity_on_order_phase_2' => 0,
        'quantity_on_order_phase_3' => 0,
    ]);
    $csv = app(SupplierOrderCsvService::class);
    $originalTitle = $variant->product->title;
    $originalVendor = $variant->product->vendor;
    $batch = $csv->previewPastedOrder("Item\tSKU\tQuantity Ordered\tOrder ID\tETA Date\n1\tPASTE-1\t30\tPO-PASTE\t07/09/2026");

    expect($batch->valid_count)->toBe(1)->and($batch->invalid_count)->toBe(0);
    $csv->confirm($batch->uuid);

    expect($variant->procurementIncomingStock()->value('quantity_to_order'))->toBe(0)
        ->and($variant->procurementIncomingStock()->value('total_quantity_on_order'))->toBe(30)
        ->and(ProcurementSupplierOrderLine::query()->where('sku', 'PASTE-1')->count())->toBe(1)
        ->and($variant->product->fresh()->title)->toBe($originalTitle)
        ->and($variant->product->fresh()->vendor)->toBe($originalVendor);
});

it('previews and confirms supplier orders for draft skus', function (): void {
    config(['google_sheets.enabled' => false]);
    $draft = NewProductDraft::create([
        'sku' => 'DRAFT-PO-1',
        'status' => 'draft',
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]);

    $csv = app(SupplierOrderCsvService::class);
    $batch = $csv->previewPastedOrder("Item\tSKU\tQuantity Ordered\tOrder ID\tETA Date\n1\tDRAFT-PO-1\t18\tPO-DRAFT\t07/09/2026");

    expect($batch->valid_count)->toBe(1)
        ->and($batch->invalid_count)->toBe(0);

    $csv->confirm($batch->uuid);

    $line = ProcurementSupplierOrderLine::query()->where('sku', 'DRAFT-PO-1')->first();

    expect($line)->not->toBeNull()
        ->and($line->variant_id)->toBeNull()
        ->and($line->new_product_draft_id)->toBe($draft->id)
        ->and($line->quantity_ordered)->toBe(18);
});

it('rejects supplier orders when a sku matches both a draft and an active product', function (): void {
    supplierWorkflowVariant('AMBIG-DRAFT-1');
    NewProductDraft::create([
        'sku' => 'AMBIG-DRAFT-1',
        'status' => 'draft',
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
    ]);

    $batch = app(SupplierOrderCsvService::class)
        ->previewPastedOrder("Item\tSKU\tQuantity Ordered\tOrder ID\tETA Date\n1\tAMBIG-DRAFT-1\t18\tPO-AMBIG\t07/09/2026");

    expect($batch->valid_count)->toBe(0)
        ->and($batch->invalid_count)->toBe(1)
        ->and($batch->errors['2'][0])->toContain('SKU must match exactly one');
});

it('treats an active variant and its mirrored draft as one supplier-order SKU', function (): void {
    config(['google_sheets.enabled' => false]);
    $variant = supplierWorkflowVariant('MIRROR-PO-1');
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create([
        'sku' => 'MIRROR-PO-1',
        'shopify_id' => $variant->product->shopify_id,
        'handle' => $variant->product->handle,
        'title' => $variant->product->title,
        'status' => 'active',
        'origin' => NewProductDraft::ORIGIN_PRODUCT_MIRROR,
    ]));

    $csv = app(SupplierOrderCsvService::class);
    $batch = $csv->previewPastedOrder("Item\tSKU\tQuantity Ordered\tOrder ID\tETA Date\n1\tMIRROR-PO-1\t3\tPO-MIRROR\t15/09/2026");

    expect($batch->valid_count)->toBe(1)->and($batch->invalid_count)->toBe(0);
    $csv->confirm($batch->uuid);
    expect(ProcurementSupplierOrderLine::where('sku', 'MIRROR-PO-1')->value('variant_id'))->toBe($variant->id);
});

it('previews pasted received orders and stages them for Shopify review', function (): void {
    Bus::fake();
    config(['google_sheets.enabled' => false]);
    $variant = supplierWorkflowVariant('PASTE-RECEIPT-1');
    app(SupplierOrderService::class)->createForVariant($variant, 'PO-PASTE-RECEIPT', 10, '07/09/2026');
    $csv = app(SupplierOrderCsvService::class);

    $batch = $csv->previewPastedReceipt("Order ID\tSKU\tQuantity Received\nPO-PASTE-RECEIPT\tPASTE-RECEIPT-1\t4");

    expect($batch->type)->toBe('receipt')
        ->and($batch->valid_count)->toBe(1)
        ->and($batch->invalid_count)->toBe(0);

    $csv->confirm($batch->uuid, dispatchReceipts: false);

    expect(ProcurementSupplierReceipt::query()->value('status'))->toBe('pending')
        ->and(ProcurementSupplierReceipt::query()->value('quantity_received'))->toBe(4);
    Bus::assertNotDispatched(ProcessSupplierReceiptJob::class);
});

it('uses one GRV number for all lines in one received-order batch', function (): void {
    Bus::fake();
    config(['google_sheets.enabled' => false]);
    $first = supplierWorkflowVariant('GRV-BATCH-1');
    $second = supplierWorkflowVariant('GRV-BATCH-2');
    app(SupplierOrderService::class)->createForVariant($first, 'PO-GRV-BATCH', 10, '07/09/2026');
    app(SupplierOrderService::class)->createForVariant($second, 'PO-GRV-BATCH', 12, '07/09/2026', allowExistingOrder: true);

    $csv = app(SupplierOrderCsvService::class);
    $batch = $csv->previewPastedReceipt("Order ID\tSKU\tQuantity Received\nPO-GRV-BATCH\tGRV-BATCH-1\t4\nPO-GRV-BATCH\tGRV-BATCH-2\t5");
    $csv->confirm($batch->uuid, dispatchReceipts: false);

    $receipts = ProcurementSupplierReceipt::query()->orderBy('id')->get();

    expect($receipts)->toHaveCount(2)
        ->and($receipts->pluck('grv_number')->unique()->values()->all())->toBe(['GRV-000001'])
        ->and($receipts->pluck('quantity_received')->all())->toBe([4, 5]);
});

it('uses separate GRV numbers for separate received-order batches', function (): void {
    Bus::fake();
    config(['google_sheets.enabled' => false]);
    $variant = supplierWorkflowVariant('GRV-STAGED');
    app(SupplierOrderService::class)->createForVariant($variant, 'PO-GRV-STAGED', 10, '07/09/2026');
    $csv = app(SupplierOrderCsvService::class);

    $first = $csv->previewPastedReceipt("Order ID\tSKU\tQuantity Received\nPO-GRV-STAGED\tGRV-STAGED\t4");
    $csv->confirm($first->uuid, dispatchReceipts: false);
    $second = $csv->previewPastedReceipt("Order ID\tSKU\tQuantity Received\nPO-GRV-STAGED\tGRV-STAGED\t3");
    $csv->confirm($second->uuid, dispatchReceipts: false);

    expect(ProcurementSupplierReceipt::query()->pluck('grv_number')->unique()->values()->all())
        ->toBe(['GRV-000001', 'GRV-000002']);
});

it('rejects invalid pasted received orders without staging any receipts', function (): void {
    config(['google_sheets.enabled' => false]);
    $variant = supplierWorkflowVariant('PASTE-RECEIPT-BAD');
    app(SupplierOrderService::class)->createForVariant($variant, 'PO-PASTE-BAD', 5, '07/09/2026');

    $batch = app(SupplierOrderCsvService::class)->previewPastedReceipt(
        "Order ID\tSKU\tQuantity Received\nPO-PASTE-BAD\tPASTE-RECEIPT-BAD\t6"
    );

    expect($batch->valid_count)->toBe(0)
        ->and($batch->invalid_count)->toBe(1)
        ->and($batch->errors['2'])->toContain('quantity exceeds the outstanding order quantity')
        ->and(ProcurementSupplierReceipt::query()->count())->toBe(0);
});

it('previews CSV without changing orders and confirms the same file only once', function (): void {
    config(['google_sheets.enabled' => false]);
    supplierWorkflowVariant('CSV-1');
    $path = tempnam(sys_get_temp_dir(), 'supplier-orders-');
    file_put_contents($path, "SKU,Order ID,Quantity Ordered,ETA\nCSV-1,PO-CSV,12,01/11/2026\n");
    $csv = app(SupplierOrderCsvService::class);

    $preview = $csv->preview($path, 'order', filename: 'orders.csv');
    expect($preview->valid_count)->toBe(1)
        ->and($preview->invalid_count)->toBe(0)
        ->and(ProcurementSupplierOrderLine::query()->count())->toBe(0);

    $csv->confirm($preview->uuid);
    $samePreview = $csv->preview($path, 'order', filename: 'orders.csv');
    $csv->confirm($samePreview->uuid);
    @unlink($path);

    expect($samePreview->id)->toBe($preview->id)
        ->and(ProcurementSupplierOrderLine::query()->count())->toBe(1)
        ->and(ProcurementSupplierOrderLine::query()->first()->quantity_ordered)->toBe(12)
        ->and(ProcurementSupplierOrderLine::query()->first()->eta_date->toDateString())->toBe('2026-11-01');
});

it('stages received-order uploads until selected rows are pushed', function (): void {
    Bus::fake();
    config(['google_sheets.enabled' => false]);
    $variant = supplierWorkflowVariant('STAGED-1');
    app(SupplierOrderService::class)->createForVariant($variant, 'PO-STAGED', 10, '01/11/2026');
    $path = tempnam(sys_get_temp_dir(), 'supplier-receipts-');
    file_put_contents($path, "Order ID,SKU,Quantity Received\nPO-STAGED,STAGED-1,4\n");
    $csv = app(SupplierOrderCsvService::class);
    $preview = $csv->preview($path, 'receipt');
    $csv->confirm($preview->uuid, dispatchReceipts: false);
    @unlink($path);

    expect(ProcurementSupplierReceipt::query()->value('status'))->toBe('pending')
        ->and(ProcurementSupplierReceipt::query()->value('quantity_received'))->toBe(4);
    Bus::assertNotDispatched(ProcessSupplierReceiptJob::class);
});

it('exports populated templates only for selected eligible products', function (): void {
    $selected = supplierWorkflowVariant('EXPORT-SELECTED');
    $excluded = supplierWorkflowVariant('EXPORT-UNLISTED');
    $excluded->product()->update(['status' => 'unlisted']);
    app(SupplierOrderService::class)->createForVariant($selected, 'PO-EXPORT', 8, '01/11/2026');
    $exporter = app(ProcurementSelectionCsvExporter::class);

    expect($exporter->pendingOrders(collect([$selected, $excluded])))
        ->toContain('EXPORT-SELECTED')
        ->not->toContain('EXPORT-UNLISTED')
        ->and($exporter->receipts(collect([$selected, $excluded])))
        ->toContain('PO-EXPORT,EXPORT-SELECTED,8')
        ->not->toContain('EXPORT-UNLISTED');
});

it('receives Shopify inventory with a delta mutation and a unique reference', function (): void {
    $variant = supplierWorkflowVariant('DELTA-1');
    config(['services.shopify.inventory_location_id' => 'gid://shopify/Location/1']);
    $client = Mockery::mock(ShopifyGraphqlGateway::class);
    $client->shouldReceive('graphql')->once()->withArgs(function (string $query, array $variables): bool {
        return str_contains($query, 'inventoryAdjustQuantities')
            && data_get($variables, 'input.changes.0.delta') === 3
            && data_get($variables, 'input.referenceDocumentUri') === 'logistics://receipt/unique-1';
    })->andReturn(['inventoryAdjustQuantities' => ['inventoryAdjustmentGroup' => ['createdAt' => now()->toIso8601String()], 'userErrors' => []]]);

    (new ShopifyInventoryAdjustmentService($client))->increaseAvailable($variant, 3, 'logistics://receipt/unique-1');
});

it('resolves a Shopify location without requesting the protected location name', function (): void {
    $variant = supplierWorkflowVariant('LOCATION-1');
    config(['services.shopify.inventory_location_id' => null]);
    $client = Mockery::mock(ShopifyGraphqlGateway::class);
    $client->shouldReceive('graphql')->once()->withArgs(fn (string $query): bool => str_contains($query, 'ProcurementLocation')
        && str_contains($query, 'nodes { id }')
        && ! str_contains($query, 'name'))->andReturn([
            'locations' => ['nodes' => [['id' => 'gid://shopify/Location/1']]],
        ]);
    $client->shouldReceive('graphql')->once()->withArgs(fn (string $query, array $variables): bool => str_contains($query, 'inventoryAdjustQuantities')
        && data_get($variables, 'input.changes.0.locationId') === 'gid://shopify/Location/1')->andReturn([
            'inventoryAdjustQuantities' => [
                'inventoryAdjustmentGroup' => ['createdAt' => now()->toIso8601String()],
                'userErrors' => [],
            ],
        ]);

    (new ShopifyInventoryAdjustmentService($client))->increaseAvailable($variant, 2, 'logistics://receipt/location-1');
});

it('allows several SKUs on one new order CSV', function (): void {
    config(['google_sheets.enabled' => false]);
    supplierWorkflowVariant('MULTI-1');
    supplierWorkflowVariant('MULTI-2');
    $path = tempnam(sys_get_temp_dir(), 'supplier-multi-');
    file_put_contents($path, implode("\n", [
        'SKU,Order ID,Quantity Ordered,ETA',
        'MULTI-1,PO-MULTI,10,01/11/2026',
        'MULTI-2,PO-MULTI,20,01/11/2026',
    ])."\n");
    $csv = app(SupplierOrderCsvService::class);
    $preview = $csv->preview($path, 'order');
    expect($preview->valid_count)->toBe(2)->and($preview->invalid_count)->toBe(0);
    $csv->confirm($preview->uuid);
    @unlink($path);

    expect(ProcurementSupplierOrder::query()->where('order_number', 'PO-MULTI')->count())->toBe(1)
        ->and(ProcurementSupplierOrderLine::query()->whereHas('order', fn ($query) => $query->where('order_number', 'PO-MULTI'))->count())->toBe(2);
});

it('revalidates an unchanged pasted order after an earlier invalid preview', function (): void {
    $contents = "SKU\tQuantity Ordered\tOrder ID\tETA Date\nREVALIDATE-1\t3\tPO-REVALIDATE\t15/09/2026";
    $csv = app(SupplierOrderCsvService::class);
    $invalid = $csv->previewPastedOrder($contents);
    expect($invalid->invalid_count)->toBe(1);

    supplierWorkflowVariant('REVALIDATE-1');
    $valid = $csv->previewPastedOrder($contents);

    expect($valid->id)->toBe($invalid->id)
        ->and($valid->valid_count)->toBe(1)
        ->and($valid->invalid_count)->toBe(0)
        ->and($valid->errors)->toBeNull();
});

it('rejects duplicate Order ID and SKU lines within one pending-order CSV', function (): void {
    config(['google_sheets.enabled' => false]);
    supplierWorkflowVariant('DUP-LINE-1');
    $path = tempnam(sys_get_temp_dir(), 'supplier-duplicate-line-');
    file_put_contents($path, implode("\n", [
        'SKU,Order ID,Quantity Ordered,ETA',
        'DUP-LINE-1,PO-DUP-LINE,10,01/11/2026',
        'DUP-LINE-1,PO-DUP-LINE,10,01/11/2026',
    ])."\n");

    $preview = app(SupplierOrderCsvService::class)->preview($path, 'order');
    @unlink($path);

    expect($preview->valid_count)->toBe(1)
        ->and($preview->invalid_count)->toBe(1)
        ->and(data_get($preview->errors, '3.0'))->toContain('duplicated within this CSV');
});

it('rejects a later pending-order upload when its Order ID already exists', function (): void {
    config(['google_sheets.enabled' => false]);
    $first = supplierWorkflowVariant('DUP-ORDER-1');
    supplierWorkflowVariant('DUP-ORDER-2');
    app(SupplierOrderService::class)->createForVariant($first, 'PO-EXISTS', 10, '01/11/2026');
    $path = tempnam(sys_get_temp_dir(), 'supplier-duplicate-order-');
    file_put_contents($path, "SKU,Order ID,Quantity Ordered,ETA\nDUP-ORDER-2,PO-EXISTS,5,02/11/2026\n");

    $preview = app(SupplierOrderCsvService::class)->preview($path, 'order');
    @unlink($path);

    expect($preview->valid_count)->toBe(0)
        ->and($preview->invalid_count)->toBe(1)
        ->and(data_get($preview->errors, '2.0'))->toContain('Order ID already exists');
});

it('rechecks Order IDs during confirmation to close the preview race window', function (): void {
    config(['google_sheets.enabled' => false]);
    supplierWorkflowVariant('RACE-UPLOAD');
    $winner = supplierWorkflowVariant('RACE-WINNER');
    $path = tempnam(sys_get_temp_dir(), 'supplier-race-');
    file_put_contents($path, "SKU,Order ID,Quantity Ordered,ETA\nRACE-UPLOAD,PO-RACE,5,02/11/2026\n");
    $csv = app(SupplierOrderCsvService::class);
    $preview = $csv->preview($path, 'order');
    app(SupplierOrderService::class)->createForVariant($winner, 'PO-RACE', 8, '02/11/2026');

    expect(fn () => $csv->confirm($preview->uuid))
        ->toThrow(ValidationException::class, 'Order ID(s) already exist');
    @unlink($path);

    expect(ProcurementSupplierOrderLine::query()->where('sku', 'RACE-UPLOAD')->count())->toBe(0)
        ->and($preview->fresh()->status)->toBe('previewed');
});

it('ships separate clean order and receipt CSV templates', function (): void {
    expect(trim((string) file_get_contents(resource_path('templates/procurement-supplier-orders.csv'))))
        ->toStartWith('Item,SKU,Quantity Ordered,Order ID,ETA Date')
        ->and(trim((string) file_get_contents(resource_path('templates/procurement-supplier-receipts.csv'))))
        ->toStartWith('Order ID,SKU,Quantity Received');
});

function supplierWorkflowVariant(string $sku): Variant
{
    $user = User::factory()->create();
    $import = Import::query()->create(['filename' => 'supplier.csv', 'mode' => 'overwrite', 'status' => 'ready', 'created_by' => $user->id]);
    $product = Product::withoutEvents(fn (): Product => Product::query()->create([
        'import_id' => $import->id, 'shopify_id' => 'gid://shopify/Product/'.$sku,
        'handle' => strtolower($sku), 'title' => 'Product '.$sku, 'vendor' => 'Leigh Avenue',
        'type' => 'Jewellery', 'status' => 'active', 'tags' => 'livi-road', 'approval_version' => 1,
    ]));

    return Variant::withoutEvents(fn (): Variant => Variant::query()->create([
        'product_id' => $product->id, 'shopify_id' => 'gid://shopify/ProductVariant/'.$sku,
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/'.$sku,
        'sku' => $sku, 'sync_state' => Variant::SYNC_STATE_SYNCED, 'inventory_tracked' => true,
        'current_inventory_quantity' => 0, 'inventory_qty' => 0, 'price' => 100,
    ]));
}
