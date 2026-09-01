<?php

use App\Enums\PermissionEnum;
use App\Filament\Resources\InventoryResource\Pages\ListInventories;
use App\Models\ChangeLog;
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
        'current_available_quantity' => 12,
        'current_committed_quantity' => 2,
        'current_on_hand_quantity' => 14,
        'inventory_tracked' => true,
    ]);

    $this->actingAs($user);

    Livewire::test(ListInventories::class)
        ->assertSet('activeTab', 'everyday')
        ->assertTableColumnVisible('product.id')
        ->assertTableColumnVisible('inventory_qty')
        ->assertTableColumnVisible('current_committed_quantity')
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
        ->assertDontSee('Import Stock CSV');
});

it('stages a manual physical count as on hand without overwriting available', function (): void {
    $user = User::factory()->create();
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
        ]);

    expect($variant->fresh()->inventory_qty)->toBe(4)
        ->and($variant->fresh()->current_available_quantity)->toBe(4)
        ->and($variant->fresh()->current_committed_quantity)->toBe(2)
        ->and($variant->fresh()->current_on_hand_quantity)->toBe(10)
        ->and($variant->fresh()->inventory_local_dirty)->toBeTrue()
        ->and(ChangeLog::query()
            ->where('model_type', Variant::class)
            ->where('model_id', $variant->id)
            ->where('field', 'current_on_hand_quantity')
            ->where('changed_by', $user->id)
            ->exists())->toBeTrue();
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
