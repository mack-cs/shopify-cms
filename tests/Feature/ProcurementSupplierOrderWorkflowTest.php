<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Jobs\ProcessSupplierReceiptJob;
use App\Models\Import;
use App\Models\ProcurementSupplierOrder;
use App\Models\ProcurementSupplierOrderLine;
use App\Models\ProcurementSupplierReceipt;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\Procurement\ProcurementSelectionCsvExporter;
use App\Services\Procurement\SupplierOrderCsvService;
use App\Services\Procurement\SupplierOrderProjectionService;
use App\Services\Procurement\SupplierOrderService;
use App\Services\Procurement\SupplierReceiptService;
use App\Services\Shopify\ShopifyInventoryAdjustmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

it('creates an order and projects its outstanding quantity into the procurement phases', function (): void {
    $variant = supplierWorkflowVariant('ORDER-1');
    $line = app(SupplierOrderService::class)->createForVariant($variant, 'PO-100', 25, '2026-09-15');
    $stock = $variant->procurementIncomingStock()->firstOrFail();

    expect($line->quantity_ordered)->toBe(25)
        ->and($stock->quantity_on_order_phase_1)->toBe(25)
        ->and($stock->order_id_phase_1)->toBe('PO-100')
        ->and($stock->eta_date_phase_1->toDateString())->toBe('2026-09-15')
        ->and($stock->total_confirmed_quantity_on_order)->toBe(25);
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

it('moves later orders forward when an earlier order is completed', function (): void {
    $variant = supplierWorkflowVariant('PHASES-1');
    $orders = app(SupplierOrderService::class);
    $first = $orders->createForVariant($variant, 'PO-A', 5, '2026-09-01');
    $orders->createForVariant($variant, 'PO-B', 7, '2026-10-01');
    $first->receipts()->create([
        'uuid' => (string) Str::uuid(), 'quantity_received' => 5,
        'idempotency_key' => 'done-a', 'source' => 'test', 'status' => 'succeeded',
        'post_process_status' => 'completed', 'shopify_reference_uri' => 'test://done-a',
    ]);
    $first->update(['status' => 'completed']);
    app(SupplierOrderProjectionService::class)->projectVariant($variant->fresh(['procurementIncomingStock']));

    $stock = $variant->procurementIncomingStock()->firstOrFail();
    expect($stock->order_id_phase_1)->toBe('PO-B')
        ->and($stock->quantity_on_order_phase_1)->toBe(7)
        ->and($stock->quantity_on_order_phase_2)->toBe(0);
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
        ->toContain('PO-EXPORT,EXPORT-SELECTED')
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
        ->toStartWith('SKU,Order ID,Quantity Ordered,ETA')
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
