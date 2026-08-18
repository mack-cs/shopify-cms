<?php

use App\Enums\PermissionEnum;
use App\Filament\Resources\InventoryResource\Pages\ListInventories;
use App\Models\Import;
use App\Models\ProcurementIncomingStock;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
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
        'status' => 'active',
    ]);
    $variant = Variant::query()->create([
        'product_id' => $product->id,
        'sku' => 'TAB-001',
        'inventory_qty' => 12,
        'inventory_tracked' => true,
    ]);

    $this->actingAs($user);

    Livewire::test(ListInventories::class)
        ->assertSet('activeTab', 'everyday')
        ->assertTableColumnVisible('product.id')
        ->assertTableColumnHidden('quantity_on_order')
        ->assertTableFilterHidden('order_state')
        ->assertTableActionVisible('editInventory', $variant)
        ->assertTableActionVisible('updateStatus', $variant)
        ->assertTableActionVisible('refreshFromShopify', $variant)
        ->assertTableActionVisible('pushToShopify', $variant)
        ->assertTableActionHidden('addSupplierOrder', $variant)
        ->assertTableActionHidden('receiveSupplierStock', $variant)
        ->assertTableActionHidden('viewSupplierOrders', $variant)
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
        ->assertDontSee('Confirm Supplier Import')
        ->assertDontSee('Import Stock CSV');
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

it('filters products by any incomplete or complete on-order quantities', function (): void {
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
    $incomplete = Variant::query()->create(['product_id' => $product->id, 'sku' => 'ORDER-INCOMPLETE']);
    $complete = Variant::query()->create(['product_id' => $product->id, 'sku' => 'ORDER-COMPLETE']);

    ProcurementIncomingStock::query()->create([
        'variant_id' => $incomplete->id,
        'sku' => $incomplete->sku,
        'quantity_on_order_phase_1' => 20,
        'total_quantity_on_order' => 20,
        'total_confirmed_quantity_on_order' => 0,
    ]);
    ProcurementIncomingStock::query()->create([
        'variant_id' => $complete->id,
        'sku' => $complete->sku,
        'quantity_on_order_phase_1' => 15,
        'order_id_phase_1' => 'PO-COMPLETE',
        'eta_date_phase_1' => '2026-09-30',
        'confirmed_quantity_on_order_phase_1' => 15,
        'total_quantity_on_order' => 15,
        'total_confirmed_quantity_on_order' => 15,
    ]);

    $this->actingAs($user);

    Livewire::test(ListInventories::class)
        ->set('activeTab', 'orders')
        ->filterTable('order_state', 'any_on_order')
        ->assertCanSeeTableRecords([$incomplete, $complete])
        ->assertCanNotSeeTableRecords([$none])
        ->filterTable('order_state', 'incomplete_details')
        ->assertCanSeeTableRecords([$incomplete])
        ->assertCanNotSeeTableRecords([$none, $complete])
        ->filterTable('order_state', 'complete_details')
        ->assertCanSeeTableRecords([$complete])
        ->assertCanNotSeeTableRecords([$none, $incomplete]);
});
