<?php

use App\Models\Import;
use App\Models\ProcurementSupplierOrderLine;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\Procurement\SupplierOrderService;
use App\Services\Procurement\SupplierReceiptService;
use App\Services\Procurement\SupplierOrderCsvService;
use App\Services\Shopify\ShopifyInventoryAdjustmentService;
use App\Contracts\ShopifyGraphqlGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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
        'uuid' => (string) \Illuminate\Support\Str::uuid(), 'quantity_received' => 5,
        'idempotency_key' => 'done-a', 'source' => 'test', 'status' => 'succeeded',
        'post_process_status' => 'completed', 'shopify_reference_uri' => 'test://done-a',
    ]);
    $first->update(['status' => 'completed']);
    app(\App\Services\Procurement\SupplierOrderProjectionService::class)->projectVariant($variant->fresh(['procurementIncomingStock']));

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
