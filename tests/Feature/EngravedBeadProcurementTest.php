<?php

use App\Filament\Resources\InventoryResource\Pages\ListInventories;
use App\Models\Import;
use App\Models\ProcurementSupplierOrderLine;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use App\Enums\PermissionEnum;
use App\Services\Procurement\SupplierOrderService;
use App\Services\ProductInventoryCsvExporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

it('includes only unlisted products with exactly one engraved bead quantity tag in procurement', function (): void {
    $import = beadImport();
    $listed = beadProduct($import, 'active', 'listed-normal', null);
    $plainUnlisted = beadProduct($import, 'unlisted', 'plain-unlisted', null);
    $one = beadProduct($import, 'unlisted', 'bead-1', '1-engraved-beads');
    $two = beadProduct($import, 'unlisted', 'bead-2', '2-engraved-beads');
    $three = beadProduct($import, 'unlisted', 'bead-3', '3-engraved-beads');
    $four = beadProduct($import, 'unlisted', 'bead-4', '4-engraved-beads');
    $invalid = beadProduct($import, 'unlisted', 'bead-invalid', '2-engraved-beads, 4-engraved-beads');
    $identified = beadProduct($import, 'unlisted', 'bead-identified', 'engraved_beads_unlisted');

    $eligible = Product::query()->procurementCatalogEligible()->pluck('id');

    expect($eligible)->toContain($listed->id, $one->id, $two->id, $three->id, $four->id)
        ->not->toContain($plainUnlisted->id, $invalid->id, $identified->id)
        ->and($one->engraved_bead_quantity)->toBe(1)
        ->and($invalid->engraved_bead_configuration)->toBe('invalid')
        ->and($one->status)->toBe('unlisted');
});

it('removes the procurement exception when the quantity tag is removed and does not publish the product', function (): void {
    $product = beadProduct(beadImport(), 'unlisted', 'bead-removable', '3-engraved-beads');
    expect(Product::query()->procurementCatalogEligible()->whereKey($product->id)->exists())->toBeTrue();

    $product->update(['tags' => 'engraved_beads_unlisted']);

    expect($product->fresh()->status)->toBe('unlisted')
        ->and($product->fresh()->engraved_bead_configuration)->toBe('invalid')
        ->and(Product::query()->procurementCatalogEligible()->whereKey($product->id)->exists())->toBeFalse();
});

it('shows eligible unlisted engraved beads in inventory search and procurement exports', function (): void {
    $user = User::factory()->create();
    Permission::findOrCreate(PermissionEnum::InventoryUpdate->value);
    $user->givePermissionTo(PermissionEnum::InventoryUpdate->value);
    $import = beadImport($user);
    $eligible = beadVariant($import, 'unlisted', 'BEAD-SEARCH', '4-engraved-beads');
    $excluded = beadVariant($import, 'unlisted', 'PLAIN-SEARCH', null);

    expect(app(ProductInventoryCsvExporter::class)->exportToString())
        ->toContain('BEAD-SEARCH')
        ->not->toContain('PLAIN-SEARCH');

    $this->actingAs($user);
    Livewire::test(ListInventories::class)
        ->assertCanSeeTableRecords([$eligible])
        ->assertCanNotSeeTableRecords([$excluded])
        ->searchTable('BEAD-SEARCH')
        ->assertCanSeeTableRecords([$eligible])
        ->assertSee('Unlisted — Procurement Eligible');
});

it('lets a valid unlisted engraved bead use the normal supplier order workflow', function (): void {
    $user = User::factory()->create();
    $variant = beadVariant(beadImport($user), 'unlisted', 'BEAD-ORDER', '2-engraved-beads');

    $line = app(SupplierOrderService::class)->createFromRow([
        'sku' => 'BEAD-ORDER',
        'order_id' => 'PO-BEAD',
        'quantity_ordered' => 4,
        'eta' => '01/12/2026',
    ], $user->id);

    expect($line)->toBeInstanceOf(ProcurementSupplierOrderLine::class)
        ->and($line->variant_id)->toBe($variant->id)
        ->and($variant->product->fresh()->status)->toBe('unlisted');
});

function beadImport(?User $user = null): Import
{
    $user ??= User::factory()->create();

    return Import::query()->create([
        'filename' => 'engraved-beads.csv',
        'mode' => 'append',
        'status' => 'ready',
        'created_by' => $user->id,
    ]);
}

function beadProduct(Import $import, string $status, string $handle, ?string $tags): Product
{
    return Product::query()->create([
        'import_id' => $import->id,
        'handle' => $handle,
        'title' => 'Large Facets 4 engraved beads '.$handle,
        'status' => $status,
        'tags' => $tags,
        'shopify_id' => 'gid://shopify/Product/'.$handle,
    ]);
}

function beadVariant(Import $import, string $status, string $sku, ?string $tags): Variant
{
    $product = beadProduct($import, $status, strtolower($sku), $tags);

    return Variant::query()->create([
        'product_id' => $product->id,
        'sku' => $sku,
        'inventory_qty' => 8,
        'inventory_tracked' => true,
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/'.$sku,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
    ]);
}
