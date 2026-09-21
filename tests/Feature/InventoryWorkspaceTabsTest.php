<?php

use App\Enums\PermissionEnum;
use App\Filament\Resources\InventoryResource\Pages\ListInventories;
use App\Models\Import;
use App\Models\InventoryAdjustmentRequest;
use App\Models\ProcurementIncomingStock;
use App\Models\ProcurementSupplierOrder;
use App\Models\ProcurementSupplierReceipt;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Services\InventoryAdjustmentApprovalService;
use App\Services\Procurement\SupplierOrderService;
use App\Services\Procurement\SupplierReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('separates everyday inventory controls from supplier order controls', function (): void {
    $user = User::factory()->create();

    Permission::findOrCreate(PermissionEnum::InventoryUpdate->value);
    Permission::findOrCreate(PermissionEnum::InventoryStatusUpdate->value);
    $user->givePermissionTo([
        PermissionEnum::InventoryUpdate->value,
        PermissionEnum::InventoryStatusUpdate->value,
    ]);

    $import = Import::query()->create([
        'filename' => 'inventory-tabs.csv',
        'mode' => 'append',
        'status' => 'ready',
        'created_by' => $user->id,
    ]);
    $product = Product::query()->create([
        'import_id' => $import->id,
        'handle' => 'inventory-tabs-product',
        'title' => 'Inventory Tabs Product',
        'vendor' => 'Report Supplier',
        'status' => 'active',
    ]);
    $variant = Variant::query()->create([
        'product_id' => $product->id,
        'sku' => 'TAB-001',
        'inventory_qty' => 12,
        'current_available_quantity' => 12,
        'current_committed_quantity' => 2,
        'current_reserved_quantity' => 1,
        'current_on_hand_quantity' => 14,
        'inventory_tracked' => true,
    ]);
    $line = app(SupplierOrderService::class)->createForVariant($variant, 'PO-TAB-001', 8, '2026-10-01', $user->id);
    $amender = User::factory()->create(['name' => 'Order Amender']);
    app(SupplierOrderService::class)->amendLine($line, [
        'quantity_ordered' => 9,
    ], $amender->id, 'Supplier confirmed one extra unit');
    $line->refresh();
    $receipt = app(SupplierReceiptService::class)->create($line, 3, 'tab-grv-1', $user->id, dispatch: false);
    $order = ProcurementSupplierOrder::query()->where('order_number', 'PO-TAB-001')->firstOrFail();
    $approver = User::factory()->create(['name' => 'Inventory Approver']);
    app(InventoryAdjustmentApprovalService::class)->submit([[
        'variant' => $variant,
        'inventory_tracked' => true,
        'on_hand_quantity' => 16,
        'reason' => 'Opening balance check',
    ]], $user->id, $approver->id);

    $this->actingAs($user);

    Livewire::test(ListInventories::class)
        ->assertSet('activeTab', 'everyday')
        ->assertTableColumnVisible('product.id')
        ->assertTableColumnVisible('inventory_qty')
        ->assertTableColumnVisible('current_committed_quantity')
        ->assertTableColumnVisible('current_reserved_quantity')
        ->assertTableColumnVisible('current_on_hand_quantity')
        ->assertTableColumnHidden('quantity_on_order')
        ->assertTableFilterHidden('order_state')
        ->assertTableActionVisible('editInventory', $variant)
        ->assertTableActionVisible('updateStatus', $variant)
        ->assertTableActionVisible('refreshFromShopify', $variant)
        ->assertTableActionVisible('pushToShopify', $variant)
        ->assertTableActionHidden('addSupplierOrder', $variant)
        ->assertTableActionHidden('receiveSupplierStock', $variant)
        ->assertTableActionHidden('viewSupplierOrders', $variant)
        ->assertTableActionHidden('viewInventoryAdjustments', $variant)
        ->assertTableBulkActionVisible('exportSelectedInventory')
        ->assertTableBulkActionHidden('exportSelectedOrders')
        ->assertTableBulkActionHidden('pushReceivedToShopify')
        ->assertSee('Check Shopify Inventory')
        ->assertSee('Import Stock CSV')
        ->assertDontSee('Upload Supplier Orders')
        ->set('activeTab', 'orders')
        ->assertTableColumnHidden('product.id')
        ->assertTableColumnVisible('inventory_qty')
        ->assertTableColumnVisible('quantity_on_order')
        ->assertTableColumnVisible('wip_orders')
        ->assertTableColumnVisible('next_eta')
        ->assertTableFilterVisible('order_state')
        ->assertTableActionVisible('addSupplierOrder', $variant)
        ->assertTableActionVisible('viewSupplierOrders', $variant)
        ->assertTableActionVisible('refreshFromShopify', $variant)
        ->assertTableActionHidden('editInventory', $variant)
        ->assertTableActionHidden('updateStatus', $variant)
        ->assertTableActionHidden('pushToShopify', $variant)
        ->assertTableBulkActionHidden('exportSelectedInventory')
        ->assertTableBulkActionVisible('exportSelectedOrders')
        ->assertTableBulkActionVisible('exportSelectedReceipts')
        ->assertTableBulkActionVisible('pushReceivedToShopify')
        ->assertSee('Upload Supplier Orders')
        ->assertSee('Upload Receipts')
        ->assertSee('Paste Purchase Order')
        ->assertSee('Paste Received Orders')
        ->assertSee('Recalculate Procurement')
        ->assertDontSee('Confirm Paste')
        ->assertDontSee('Confirm Supplier Import')
        ->assertDontSee('Import Stock CSV')
        ->set('activeTab', 'supplier_reports')
        ->assertCanSeeTableRecords([$order])
        ->assertTableColumnVisible('supplier_report_order_number')
        ->assertTableColumnVisible('supplier_report_created_by')
        ->assertTableColumnVisible('supplier_report_status')
        ->assertTableColumnVisible('supplier_report_ordered')
        ->assertTableColumnVisible('supplier_report_received')
        ->assertTableColumnVisible('supplier_report_outstanding')
        ->assertTableColumnVisible('supplier_report_order_date')
        ->assertTableColumnVisible('supplier_report_grvs')
        ->assertTableColumnHidden('inventory_qty')
        ->assertTableFilterVisible('supplier_report_order_id')
        ->assertTableFilterVisible('supplier_report_created_by')
        ->assertTableFilterVisible('supplier_report_status_filter')
        ->assertTableFilterVisible('supplier_report_date_range')
        ->assertTableFilterVisible('supplier_report_grv')
        ->assertTableFilterHidden('order_state')
        ->assertTableActionVisible('viewSupplierOrderReport', $order)
        ->assertTableActionHidden('viewSupplierOrders', $order)
        ->assertTableActionHidden('addSupplierOrder', $variant)
        ->assertTableActionHidden('receiveSupplierStock', $variant)
        ->assertTableActionHidden('editInventory', $variant)
        ->assertTableBulkActionHidden('exportSelectedOrders')
        ->assertTableBulkActionHidden('exportSelectedReceipts')
        ->assertTableBulkActionHidden('exportSelectedOrderHistory')
        ->filterTable('supplier_report_order_id', ['order_id' => 'PO-TAB-001'])
        ->assertCanSeeTableRecords([$order])
        ->filterTable('supplier_report_created_by', ['created_by' => $user->name])
        ->assertCanSeeTableRecords([$order])
        ->set('activeTab', 'grv_reports')
        ->assertCanSeeTableRecords([$receipt])
        ->assertTableColumnVisible('grv_report_number')
        ->assertTableColumnVisible('grv_report_order')
        ->assertTableColumnVisible('grv_report_received_at')
        ->assertTableColumnVisible('grv_report_received_by')
        ->assertTableColumnVisible('grv_report_sku_preview')
        ->assertTableFilterVisible('grv_report_grv')
        ->assertTableFilterVisible('grv_report_order_id')
        ->assertTableActionVisible('viewGrvReport', $receipt)
        ->assertTableActionHidden('viewSupplierOrderReport', $receipt)
        ->filterTable('grv_report_grv', ['grv_number' => $receipt->grv_number])
        ->assertCanSeeTableRecords([$receipt])
        ->assertDontSee('Upload Supplier Orders')
        ->assertDontSee('Import Stock CSV')
        ->set('activeTab', 'inventory_adjustments')
        ->assertTableColumnVisible('adjustment_status')
        ->assertTableColumnVisible('adjustment_requested_change')
        ->assertTableColumnVisible('adjustment_reason')
        ->assertTableColumnVisible('adjustment_requester')
        ->assertTableColumnVisible('adjustment_approver')
        ->assertTableColumnVisible('adjustment_reviewed_by')
        ->assertTableColumnVisible('adjustment_submitted_at')
        ->assertTableColumnHidden('inventory_qty')
        ->assertTableFilterVisible('adjustment_status_filter')
        ->assertTableFilterVisible('adjustment_user')
        ->assertTableFilterHidden('supplier_report_order_id')
        ->assertTableFilterHidden('order_state')
        ->assertTableActionVisible('viewInventoryAdjustments', $variant)
        ->assertTableActionHidden('viewSupplierOrders', $variant)
        ->assertTableActionHidden('addSupplierOrder', $variant)
        ->assertTableActionHidden('receiveSupplierStock', $variant)
        ->assertTableActionHidden('editInventory', $variant)
        ->assertDontSee('Upload Supplier Orders')
        ->assertDontSee('Import Stock CSV');

    $orderDetails = view('filament.inventory.supplier-order-report', [
        'order' => $order->fresh()->load(['createdBy', 'amendments.amendedBy', 'lines.variant.product', 'lines.draft', 'lines.receipts']),
    ])->render();

    expect($orderDetails)
        ->toContain('Created By')
        ->toContain($user->name)
        ->toContain('Last Amended By')
        ->toContain($amender->name)
        ->toContain('Export CSV')
        ->toContain(route('inventory.supplier-orders.export', $order));

    $grvDetails = view('filament.inventory.grv-report', [
        'receipt' => $receipt->fresh(['line.order', 'line.variant.product', 'createdBy']),
        'receipts' => ProcurementSupplierReceipt::query()
            ->with(['line.order', 'line.variant.product', 'createdBy'])
            ->where('grv_number', $receipt->grv_number)
            ->get(),
    ])->render();

    expect($grvDetails)
        ->toContain('Order quantity before this receipt')
        ->toContain('Quantity already received before this GRV')
        ->toContain('Quantity received in this GRV')
        ->toContain('Outstanding quantity after this GRV')
        ->toContain('Receipt status')
        ->toContain('Ordered Quantity')
        ->toContain('Previously Received')
        ->toContain('Outstanding After Receipt');
});

it('submits a manual physical count as a pending approval without overwriting available', function (): void {
    $user = User::factory()->create();
    $approver = User::factory()->create();
    Permission::findOrCreate(PermissionEnum::InventoryUpdate->value);
    $user->givePermissionTo(PermissionEnum::InventoryUpdate->value);
    $import = Import::query()->create([
        'filename' => 'on-hand-edit.csv', 'mode' => 'append', 'status' => 'ready', 'created_by' => $user->id,
    ]);
    $product = Product::query()->create([
        'import_id' => $import->id, 'handle' => 'on-hand-edit', 'title' => 'On Hand Edit', 'status' => 'active',
    ]);
    $variant = Variant::withoutEvents(fn (): Variant => Variant::query()->create([
        'product_id' => $product->id, 'sku' => 'ON-HAND-1',
        'inventory_tracked' => true, 'inventory_qty' => 4,
        'current_available_quantity' => 4, 'current_committed_quantity' => 2,
        'current_on_hand_quantity' => 6, 'inventory_local_dirty' => false,
    ]));
    $this->actingAs($user);

    Livewire::test(ListInventories::class)
        ->callTableAction('editInventory', $variant, data: [
            'inventory_tracked' => true,
            'on_hand_quantity' => 10,
            'reason' => 'Cycle count correction',
            'approver_id' => $approver->id,
        ]);

    expect($variant->fresh()->inventory_qty)->toBe(4)
        ->and($variant->fresh()->current_available_quantity)->toBe(4)
        ->and($variant->fresh()->current_committed_quantity)->toBe(2)
        ->and($variant->fresh()->current_on_hand_quantity)->toBe(6)
        ->and($variant->fresh()->inventory_local_dirty)->toBeFalse()
        ->and(InventoryAdjustmentRequest::query()->where('requester_id', $user->id)->where('approver_id', $approver->id)->count())->toBe(1)
        ->and(InventoryAdjustmentRequest::query()->first()->items()->first()->requested_on_hand_quantity)->toBe(10);
});

it('filters the inventory table using a pasted SKU list', function (): void {
    $user = User::factory()->create();
    Permission::findOrCreate(PermissionEnum::InventoryUpdate->value);
    $user->givePermissionTo(PermissionEnum::InventoryUpdate->value);
    $import = Import::query()->create([
        'filename' => 'sku-filter.csv', 'mode' => 'append', 'status' => 'ready', 'created_by' => $user->id,
    ]);
    $product = Product::query()->create([
        'import_id' => $import->id, 'handle' => 'sku-filter', 'title' => 'SKU Filter', 'status' => 'active',
    ]);
    $wanted = Variant::query()->create(['product_id' => $product->id, 'sku' => 'WANTED-1']);
    $other = Variant::query()->create(['product_id' => $product->id, 'sku' => 'OTHER-2']);
    $this->actingAs($user);

    Livewire::test(ListInventories::class)
        ->filterTable('sku_list', ['skus' => "missing-0,\nWANTED-1"])
        ->assertCanSeeTableRecords([$wanted])
        ->assertCanNotSeeTableRecords([$other]);
});

it('shows only active products in the inventory workspace', function (): void {
    $user = User::factory()->create();
    Permission::findOrCreate(PermissionEnum::InventoryUpdate->value);
    $user->givePermissionTo(PermissionEnum::InventoryUpdate->value);
    $import = Import::query()->create([
        'filename' => 'inventory-eligibility.csv', 'mode' => 'append', 'status' => 'ready', 'created_by' => $user->id,
    ]);
    $variant = function (string $status, string $sku, ?int $inventory = null) use ($import): Variant {
        $product = Product::query()->create([
            'import_id' => $import->id,
            'handle' => strtolower($sku),
            'title' => $sku,
            'status' => $status,
        ]);

        return Variant::query()->create([
            'product_id' => $product->id,
            'sku' => $sku,
            'inventory_qty' => $inventory,
        ]);
    };

    $active = $variant('active', 'ACTIVE-NO-STOCK');
    $draftWithoutInventory = $variant('draft', 'DRAFT-NO-STOCK');
    $draftWithInventory = $variant('draft', 'DRAFT-WITH-STOCK', 0);
    $archived = $variant('archived', 'ARCHIVED-STOCK', 10);
    $unlisted = $variant('unlisted', 'UNLISTED-STOCK', 10);

    expect(Variant::query()->inventoryWorkspaceEligible()->pluck('id')->all())
        ->toContain($active->id)
        ->not->toContain($draftWithoutInventory->id, $draftWithInventory->id, $archived->id, $unlisted->id);

    $this->actingAs($user);
    Livewire::test(ListInventories::class)
        ->assertCanSeeTableRecords([$active])
        ->assertCanNotSeeTableRecords([$draftWithoutInventory, $draftWithInventory, $archived, $unlisted]);
});

it('filters products by placed, planned, and multiple WIP order summaries', function (): void {
    $user = User::factory()->create();
    Permission::findOrCreate(PermissionEnum::InventoryUpdate->value);
    $user->givePermissionTo(PermissionEnum::InventoryUpdate->value);

    $import = Import::query()->create([
        'filename' => 'order-filter.csv',
        'mode' => 'append',
        'status' => 'ready',
        'created_by' => $user->id,
    ]);
    $product = Product::query()->create([
        'import_id' => $import->id,
        'handle' => 'order-filter-product',
        'title' => 'Order Filter Product',
        'status' => 'active',
    ]);

    $none = Variant::query()->create(['product_id' => $product->id, 'sku' => 'ORDER-NONE']);
    $planned = Variant::query()->create(['product_id' => $product->id, 'sku' => 'ORDER-PLANNED']);
    $placed = Variant::query()->create(['product_id' => $product->id, 'sku' => 'ORDER-PLACED']);

    ProcurementIncomingStock::query()->create([
        'variant_id' => $planned->id,
        'sku' => $planned->sku,
        'quantity_to_order' => 20,
        'total_quantity_on_order' => 0,
    ]);
    ProcurementIncomingStock::query()->create([
        'variant_id' => $placed->id,
        'sku' => $placed->sku,
        'total_quantity_on_order' => 15,
        'total_confirmed_quantity_on_order' => 15,
        'number_of_wip_orders' => 2,
    ]);

    $this->actingAs($user);

    Livewire::test(ListInventories::class)
        ->set('activeTab', 'orders')
        ->filterTable('order_state', 'any_on_order')
        ->assertCanSeeTableRecords([$placed])
        ->assertCanNotSeeTableRecords([$none, $planned])
        ->filterTable('order_state', 'planned_only')
        ->assertCanSeeTableRecords([$planned])
        ->assertCanNotSeeTableRecords([$none, $placed])
        ->filterTable('order_state', 'multiple_wip')
        ->assertCanSeeTableRecords([$placed])
        ->assertCanNotSeeTableRecords([$none, $planned]);
});
