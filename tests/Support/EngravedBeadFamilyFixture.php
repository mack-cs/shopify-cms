<?php

use App\Models\EngravedBeadFamily;
use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyInventorySnapshot;
use App\Models\ShopifySyncRun;
use App\Models\User;
use App\Models\Variant;
use App\Services\EngravedBeads\EngravedBeadFamilyDiscovery;

function sharedBeadFamily(string $tag = 'test-design-bead-family', int $base = 50000, bool $active = false): array
{
    $import = Import::query()->create(['filename' => $tag.'.csv', 'mode' => 'append', 'status' => 'ready', 'created_by' => User::factory()->create()->id]);
    $members = [];
    for ($q = 1; $q <= 4; $q++) {
        $id = $base + $q;
        $product = Product::query()->create(['import_id' => $import->id, 'handle' => $tag.'-'.$q,
            'shopify_id' => 'gid://shopify/Product/'.$id, 'title' => 'Design '.$q,
            'tags' => $tag.', '.$q.'-engraved-beads', 'status' => 'unlisted', 'is_bundle' => false]);
        $variant = Variant::query()->create(['product_id' => $product->id,
            'shopify_id' => 'gid://shopify/ProductVariant/'.$id,
            'shopify_inventory_item_id' => 'gid://shopify/InventoryItem/'.$id,
            'sku' => 'BEAD-'.$id, 'inventory_tracked' => $q === 1, 'inventory_location_count' => 1,
            'inventory_qty' => $q === 1 ? 20 : null, 'current_available_quantity' => $q === 1 ? 20 : null,
            'current_on_hand_quantity' => $q === 1 ? 20 : 0, 'sync_state' => Variant::SYNC_STATE_SYNCED]);
        $members[$q] = compact('product', 'variant');
    }
    $run = ShopifySyncRun::query()->create(['dataset' => 'inventory', 'sync_type' => 'snapshot', 'run_mode' => 'manual']);
    ShopifyInventorySnapshot::query()->create(['sync_run_id' => $run->id, 'business_date' => now()->toDateString(),
        'shopify_inventory_item_id' => $members[1]['variant']->shopify_inventory_item_id,
        'shopify_location_id' => 'gid://shopify/Location/6001', 'location_active' => true,
        'tracked' => true, 'available' => 20, 'on_hand' => 20]);
    app(EngravedBeadFamilyDiscovery::class)->synchronize();
    $family = EngravedBeadFamily::query()->where('family_tag', $tag)->firstOrFail();
    if ($active) {
        // Tests simulate an explicitly approved cutover; never used outside SQLite fixtures.
        $family->update(['status' => 'active', 'activated_at' => '2026-10-25 00:00:00', 'approved_by' => $import->created_by]);
        $family->members()->update(['status' => 'active']);
    }

    return compact('family', 'members');
}
