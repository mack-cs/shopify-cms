<?php

use App\Enums\RolesEnum;
use App\Filament\Exports\ManagerProductMovementExporter;
use App\Filament\Exports\ProductMovementReportRowExporter;
use App\Filament\Resources\ManagerProductMovementResource\Pages\ListManagerProductMovements;
use App\Models\Import;
use App\Models\Product;
use App\Models\ProductMovementReportRow;
use App\Models\ShopifyInventorySnapshot;
use App\Models\ShopifyOrder;
use App\Models\ShopifyOrderItem;
use App\Models\ShopifySyncRun;
use App\Models\User;
use App\Models\Variant;
use App\Services\ProductMovementReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('classifies every catalogue variant from orders and only observed inventory snapshot days', function (): void {
    $user = User::factory()->create();
    $import = movementReportImport($user);
    $selling = movementReportVariant($import, 'selling-product', 'MOVE-FAST', [
        'price' => '80.00',
        'compare_at_price' => '100.00',
        'inventory_tracked' => true,
        'inventory_qty' => 3,
        'current_available_quantity' => 3,
    ]);
    $noSales = movementReportVariant($import, 'no-sales-product', 'MOVE-NONE', [
        'inventory_tracked' => true,
        'inventory_qty' => 8,
        'current_available_quantity' => 8,
    ]);
    $unavailable = movementReportVariant($import, 'unavailable-product', 'MOVE-OOS', [
        'inventory_tracked' => true,
        'inventory_qty' => 0,
        'current_available_quantity' => 0,
    ]);

    foreach ([$selling, $noSales, $unavailable] as $variant) {
        DB::table('products')->where('id', $variant->product_id)->update([
            'created_at' => '2025-01-01 00:00:00',
        ]);
    }

    $firstOrder = movementReportOrder('1001', '2026-01-15 10:00:00', false);
    movementReportOrderItem($firstOrder, $selling, '5001', 2, 1);
    movementReportOrderItem($firstOrder, $selling, '5002', 1, 1);
    $secondOrder = movementReportOrder('1002', '2026-03-15 10:00:00', false);
    movementReportOrderItem($secondOrder, $selling, '5003', 2, 2);
    $cancelled = movementReportOrder('1003', '2026-02-15 10:00:00', true);
    movementReportOrderItem($cancelled, $selling, '5004', 9, 9);

    movementReportSnapshot($selling, '2026-01-15', 5, 's1');
    movementReportSnapshot($selling, '2026-02-15', 0, 's2');
    movementReportSnapshot($selling, '2026-03-15', 3, 's3');
    movementReportSnapshot($noSales, '2026-01-15', 8, 'n1');
    movementReportSnapshot($noSales, '2026-02-15', 7, 'n2');
    movementReportSnapshot($unavailable, '2026-01-15', 0, 'o1');
    movementReportSnapshot($unavailable, '2026-02-15', 0, 'o2');
    movementReportSnapshot($unavailable, '2026-03-15', 2, 'o3');

    config([
        'product_movement.classification.minimum_snapshot_days' => 2,
        'product_movement.classification.out_of_stock_ratio' => 0.66,
        'product_movement.classification.fast_score' => 0,
        'product_movement.classification.fast_average_monthly_units' => 0,
        'product_movement.classification.fast_consistency_percentage' => 0,
        'product_movement.classification.fast_recent_days' => 999,
    ]);

    $service = app(ProductMovementReportService::class);
    $run = $service->createRun('2026-01-01', '2026-03-31', $user->id);
    $run = $service->generate($run);

    expect($run->status)->toBe('completed')
        ->and($run->row_count)->toBe(3)
        ->and(ProductMovementReportRow::query()->count())->toBe(3);

    $sellingRow = ProductMovementReportRow::query()->where('variant_id', $selling->id)->firstOrFail();
    expect($sellingRow->gross_units_sold)->toBe(5)
        ->and($sellingRow->refunded_units)->toBe(1)
        ->and($sellingRow->net_units_sold)->toBe(4)
        ->and($sellingRow->order_count)->toBe(2)
        ->and($sellingRow->months_with_sales)->toBe(2)
        ->and((float) $sellingRow->sales_consistency_percentage)->toBe(66.67)
        ->and($sellingRow->first_sale_date->toDateString())->toBe('2026-01-15')
        ->and($sellingRow->last_sale_date->toDateString())->toBe('2026-03-15')
        ->and($sellingRow->snapshot_days_available)->toBe(3)
        ->and($sellingRow->in_stock_days)->toBe(2)
        ->and($sellingRow->out_of_stock_days)->toBe(1)
        ->and($sellingRow->opening_snapshot_inventory)->toBe(5)
        ->and((float) $sellingRow->average_snapshot_inventory)->toBe(2.6667)
        ->and($sellingRow->closing_snapshot_inventory)->toBe(3)
        ->and((float) $sellingRow->units_sold_per_30_in_stock_days)->toBe(60.0)
        ->and($sellingRow->currently_on_sale)->toBeTrue()
        ->and((float) $sellingRow->discount_percentage)->toBe(20.0)
        ->and($sellingRow->movement_classification)->toBe('fast_moving')
        ->and($sellingRow->recommended_action)->toBe('maintain')
        ->and($sellingRow->manager_reason)->toContain('Sold 4 units');

    $noSalesRow = ProductMovementReportRow::query()->where('variant_id', $noSales->id)->firstOrFail();
    expect($noSalesRow->movement_classification)->toBe('no_sales')
        ->and($noSalesRow->net_units_sold)->toBe(0)
        ->and($noSalesRow->has_snapshot_history)->toBeTrue()
        ->and($noSalesRow->recommended_action)->toBe('review');

    $unavailableRow = ProductMovementReportRow::query()->where('variant_id', $unavailable->id)->firstOrFail();
    expect($unavailableRow->movement_classification)->toBe('no_sales')
        ->and($unavailableRow->current_inventory_status)->toBe('out_of_stock')
        ->and($unavailableRow->recommended_action)->toBe('review')
        ->and($unavailableRow->out_of_stock_days)->toBe(2);
});

it('includes zero-sale variants without snapshots and exposes all required queued export columns', function (): void {
    $user = User::factory()->create();
    $variant = movementReportVariant(
        movementReportImport($user),
        'snapshot-missing',
        'NO-SNAPSHOT',
        ['inventory_tracked' => false],
    );
    DB::table('products')->where('id', $variant->product_id)->update([
        'created_at' => '2025-01-01 00:00:00',
    ]);

    $service = app(ProductMovementReportService::class);
    $run = $service->createRun('2026-01-01', '2026-06-30', $user->id);
    $service->generate($run);

    $row = ProductMovementReportRow::query()->sole();
    expect($row->movement_classification)->toBe('no_sales')
        ->and($row->has_snapshot_history)->toBeFalse()
        ->and($row->snapshot_days_available)->toBeNull()
        ->and($row->current_inventory_status)->toBe('untracked')
        ->and($row->data_quality_note)->toContain('stock-availability adjustment was not applied');

    $exportColumns = collect(ProductMovementReportRowExporter::getColumns())
        ->map(fn ($column): string => $column->getName())
        ->all();

    expect($exportColumns)->toContain(
        'shopify_product_id',
        'shopify_variant_id',
        'gross_units_sold',
        'refunded_units',
        'net_units_sold',
        'sales_consistency_percentage',
        'snapshot_days_available',
        'movement_score',
        'movement_classification',
        'currently_on_sale',
        'discount_percentage',
        'data_quality_note',
    );

    $managerExportColumns = collect(ManagerProductMovementExporter::getColumns())
        ->map(fn ($column): string => $column->getName())
        ->all();

    expect($managerExportColumns)->toBe([
        'product_title',
        'variant_title',
        'sku',
        'vendor',
        'product_status',
        'product_type',
        'current_inventory',
        'currently_on_sale',
        'discount_percentage',
        'net_units_sold',
        'average_units_per_month',
        'last_sale_date',
        'movement_classification',
        'recommended_action',
        'manager_reason',
    ]);
});

it('keeps movement and stock separate so a fast seller at zero stock is recommended for restocking', function (): void {
    $user = User::factory()->create();
    $variant = movementReportVariant(
        movementReportImport($user),
        'sold-out-fast-product',
        'SOLD-OUT-FAST',
        [
            'inventory_tracked' => true,
            'inventory_qty' => 0,
            'current_available_quantity' => 0,
        ],
    );
    DB::table('products')->where('id', $variant->product_id)->update([
        'created_at' => '2025-01-01 00:00:00',
    ]);

    $order = movementReportOrder('2001', '2026-03-25 10:00:00', false);
    movementReportOrderItem($order, $variant, '6001', 12, 12);

    config([
        'product_movement.classification.fast_score' => 0,
        'product_movement.classification.fast_average_monthly_units' => 0,
        'product_movement.classification.fast_consistency_percentage' => 0,
        'product_movement.classification.fast_recent_days' => 999,
    ]);

    $service = app(ProductMovementReportService::class);
    $run = $service->createRun('2026-01-01', '2026-03-31', $user->id);
    $service->generate($run);

    $row = ProductMovementReportRow::query()->sole();
    expect($row->movement_classification)->toBe('fast_moving')
        ->and($row->current_inventory_status)->toBe('out_of_stock')
        ->and($row->recommended_action)->toBe('restock')
        ->and($row->manager_reason)->toContain('0 units remaining');
});

it('mounts the manager report export as a direct action without the expiring mapping modal', function (): void {
    $user = User::factory()->create();
    Role::findOrCreate(RolesEnum::Admin->value);
    $user->assignRole(RolesEnum::Admin->value);
    $this->actingAs($user);
    Bus::fake();

    $component = Livewire::test(ListManagerProductMovements::class)
        ->assertSuccessful();

    $exportAction = $component->instance()->getTable()->getAction('export');

    expect($exportAction)->not->toBeNull()
        ->and($exportAction->hasColumnMapping())->toBeFalse();

    $component
        ->callTableAction('export')
        ->assertHasNoTableActionErrors();

    expect(DB::table('exports')->where('user_id', $user->id)->count())->toBe(1);

    $component
        ->mountAction('generateReport')
        ->assertActionMounted('generateReport')
        ->assertHasNoActionErrors();
});

function movementReportImport(User $user): Import
{
    return Import::query()->create([
        'filename' => 'movement-report.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
    ]);
}

function movementReportVariant(Import $import, string $handle, string $sku, array $attributes = []): Variant
{
    $number = random_int(100000, 999999);
    $product = Product::withoutEvents(fn (): Product => Product::query()->create([
        'import_id' => $import->id,
        'shopify_id' => "gid://shopify/Product/{$number}",
        'handle' => $handle,
        'title' => str($handle)->replace('-', ' ')->title()->toString(),
        'vendor' => 'Leigh Avenue',
        'type' => 'Jewellery',
        'status' => 'active',
        'approval_version' => 1,
    ]));

    return Variant::withoutEvents(fn (): Variant => Variant::query()->create(array_merge([
        'product_id' => $product->id,
        'shopify_id' => "gid://shopify/ProductVariant/{$number}",
        'shopify_inventory_item_id' => "gid://shopify/InventoryItem/{$number}",
        'sku' => $sku,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'option1_name' => 'Title',
        'option1_value' => 'Default',
    ], $attributes)));
}

function movementReportOrder(string $id, string $date, bool $cancelled): ShopifyOrder
{
    return ShopifyOrder::query()->create([
        'shopify_order_id' => "gid://shopify/Order/{$id}",
        'name' => "#{$id}",
        'created_at_shopify' => $date,
        'processed_at_shopify' => $date,
        'cancelled_at_shopify' => $cancelled ? $date : null,
        'financial_status' => $cancelled ? 'CANCELLED' : 'PAID',
        'is_test' => false,
    ]);
}

function movementReportOrderItem(
    ShopifyOrder $order,
    Variant $variant,
    string $id,
    int $quantity,
    int $currentQuantity,
): ShopifyOrderItem {
    return ShopifyOrderItem::query()->create([
        'shopify_line_item_id' => "gid://shopify/LineItem/{$id}",
        'shopify_order_id' => $order->shopify_order_id,
        'shopify_order_db_id' => $order->id,
        'shopify_product_id' => $variant->product->shopify_id,
        'shopify_variant_id' => $variant->shopify_id,
        'sku' => $variant->sku,
        'quantity' => $quantity,
        'current_quantity' => $currentQuantity,
        'order_created_at_shopify' => $order->created_at_shopify,
    ]);
}

function movementReportSnapshot(Variant $variant, string $date, int $available, string $suffix): void
{
    $run = ShopifySyncRun::query()->create([
        'dataset' => ShopifySyncRun::DATASET_INVENTORY,
        'sync_type' => ShopifySyncRun::SYNC_TYPE_SNAPSHOT,
        'run_mode' => ShopifySyncRun::RUN_MODE_SCHEDULED,
        'business_date' => $date,
        'status' => ShopifySyncRun::STATUS_COMPLETED,
    ]);

    ShopifyInventorySnapshot::query()->create([
        'sync_run_id' => $run->id,
        'business_date' => $date,
        'shopify_inventory_item_id' => $variant->shopify_inventory_item_id,
        'shopify_product_id' => $variant->product->shopify_id,
        'shopify_variant_id' => $variant->shopify_id,
        'shopify_location_id' => "gid://shopify/Location/{$suffix}",
        'sku' => $variant->sku,
        'tracked' => true,
        'available' => $available,
    ]);
}
