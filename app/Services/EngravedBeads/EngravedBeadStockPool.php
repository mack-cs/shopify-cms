<?php

namespace App\Services\EngravedBeads;

use App\Models\EngravedBeadFamilyMember;
use App\Models\Variant;

/** Approved Shopify identities, never SKU-based or ordered-member stock pools. */
final class EngravedBeadStockPool
{
    public function member(Variant $variant): ?EngravedBeadFamilyMember
    {
        $member = EngravedBeadFamilyMember::query()->with('family')->where('shopify_variant_id', $variant->shopify_id)->first();
        if ($member === null || $member->family->status !== 'active'
            || $member->family->activated_at === null || $member->status !== 'active') {
            return null;
        }
        $product = $variant->product;
        $tags = app(EngravedBeadFamilyDiscovery::class)->tags($product?->tags);
        $familyTags = array_values(array_filter($tags, fn ($t) => str_ends_with($t, '-bead-family')));
        $quantities = array_values(array_filter($tags, fn ($t) => preg_match('/^\d+-engraved-beads$/D', $t)));
        if ($familyTags !== [$member->family->family_tag]
            || $quantities !== [$member->required_beads.'-engraved-beads']
            || $product?->shopify_id !== $member->shopify_product_id
            || $variant->shopify_inventory_item_id !== $member->shopify_inventory_item_id
            || trim((string) $variant->sku) !== $member->sku) {
            $member->family->update(['status' => 'mapping_conflict', 'validation_errors' => ['Active member changed; explicit reconciliation required.']]);

            return null;
        }

        return $member;
    }

    public function source(Variant $variant): ?Variant
    {
        $member = $this->member($variant);
        if ($member === null) {
            return null;
        }
        $source = Variant::query()->where('shopify_id', $member->family->source_variant_id)->first();
        if ($source?->inventory_tracked !== true
            || $source->shopify_inventory_item_id !== $member->family->source_inventory_item_id
            || $this->member($source)?->required_beads !== 1
            || trim((string) $member->family->source_location_id) === '') {
            return null;
        }

        return $source;
    }

    public function identifiable(Variant $variant): bool
    {
        return $this->source($variant) !== null;
    }
}
