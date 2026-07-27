<?php

use App\Models\Import;
use App\Models\Product;
use App\Models\ProductInventorySnapshot;
use App\Models\User;
use App\Models\Variant;
use App\Services\BulkInventoryTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('starts tracking only explicitly untracked variants and records inventory history', function (): void {
    $user = User::factory()->create();
    $import = Import::query()->create([
        'filename' => 'bulk-inventory-tracking-test.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
    ]);
    $product = Product::withoutEvents(fn (): Product => Product::query()->create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/7001',
        'handle' => 'bulk-tracking-product',
        'title' => 'Bulk Tracking Product',
        'status' => 'active',
        'approval_version' => 1,
    ]));

    $untracked = createBulkTrackingVariant($product, 'BULK-UNTRACKED', false, null);
    $tracked = createBulkTrackingVariant($product, 'BULK-TRACKED', true, 9);
    $unknown = createBulkTrackingVariant($product, 'BULK-UNKNOWN', null, null);

    $result = app(BulkInventoryTrackingService::class)->startTracking(
        collect([$untracked, $tracked, $unknown]),
        4,
        $user->id,
    );

    expect($result['updated'])->toBe(1)
        ->and($result['already_tracked'])->toBe(1)
        ->and($result['unknown_skipped'])->toBe(1)
        ->and($result['changed_variant_ids'])->toBe([$untracked->id])
        ->and($result['snapshots'])->toBe(1)
        ->and($untracked->fresh()->inventory_tracked)->toBeTrue()
        ->and($untracked->fresh()->inventory_qty)->toBe(4)
        ->and($untracked->fresh()->inventory_local_dirty)->toBeTrue()
        ->and($tracked->fresh()->inventory_qty)->toBe(9)
        ->and($unknown->fresh()->inventory_tracked)->toBeNull()
        ->and($product->fresh()->approval_version)->toBe(1);

    $snapshot = ProductInventorySnapshot::query()->firstOrFail();

    expect($snapshot->source)->toBe(ProductInventorySnapshot::SOURCE_LOCAL_UPDATE)
        ->and($snapshot->observed_by)->toBe($user->id)
        ->and($snapshot->tracked_variant_count)->toBe(2)
        ->and($snapshot->unknown_inventory_variant_count)->toBe(1);
});

function createBulkTrackingVariant(Product $product, string $sku, ?bool $tracked, ?int $quantity): Variant
{
    return Variant::withoutEvents(fn (): Variant => Variant::query()->create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/' . abs(crc32($sku)),
        'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/' . abs(crc32('inventory-' . $sku)),
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'local_dirty' => false,
        'sku' => $sku,
        'inventory_tracked' => $tracked,
        'inventory_qty' => $quantity,
        'inventory_local_dirty' => false,
    ]));
}
