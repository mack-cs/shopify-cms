<?php

namespace App\Services\EngravedBeads;

use App\Models\Variant;

/**
 * Stock-pool decision
 *
 * This CMS stores a Shopify inventory item on each variant. Stack products are the
 * only shared-component model, and engraved bead products are not linked to one.
 * Separate SKUs are therefore not treated as a shared bead pool.
 *
 * The additional CMS reservation is applied to the ordered variant's own inventory
 * item. Shopify keeps its one-unit commitment on that same item. When that item
 * cannot be identified, or the variant is not inventory-tracked, no adjustment is made.
 */
final class EngravedBeadStockPool
{
    public function identifiable(Variant $variant): bool
    {
        $variant->loadMissing('product.variants');
        $product = $variant->product;
        if ($product === null || $variant->inventory_tracked !== true) {
            return false;
        }
        if (trim((string) $variant->shopify_inventory_item_id) === '') {
            return false;
        }

        $tracked = $product->variants->filter(fn (Variant $item): bool => $item->inventory_tracked === true
            && trim((string) $item->shopify_inventory_item_id) !== '');

        return $tracked->count() === 1 && (int) $tracked->first()->id === (int) $variant->id;
    }
}
