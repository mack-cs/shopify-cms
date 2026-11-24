<?php

namespace App\Services;

use App\Models\Image;
use App\Models\Product;

/** The current primary CMS image and its backup asset, not the oldest Shopify media id. */
final class ComponentPhotographSelector
{
    public function selected(Product $product): ?Image
    {
        $image = $this->positionOne($product);

        return $image && (int) $image->image_asset_id > 0 ? $image : null;
    }

    public function positionOne(Product $product): ?Image
    {
        $primary = $product->images()->where('position', 1)->with('imageAsset')->limit(2)->get();
        if ($primary->count() !== 1) {
            return null;
        }

        return $primary->first();
    }

    public function confirmed(Image $image): bool
    {
        return filled(trim((string) $image->shopify_id))
            && ! $image->shopify_identity_unresolved
            && ! $image->shopify_media_create_uncertain
            && ! $image->needs_shopify_image_sync
            && (int) $image->last_shopify_synced_image_asset_id === (int) $image->image_asset_id
            && (int) $image->image_asset_id > 0;
    }

    /**
     * The inherited photograph may be published only when this source is the current
     * primary, the component is approved, and a confirmed generation records the
     * Shopify verification of these exact bytes.
     */
    public function publicationAllowed(Image $source): bool
    {
        $source = Image::query()->useWritePdo()->find($source->id);
        if (! $source instanceof Image || ! $this->confirmed($source)) {
            return false;
        }
        $product = Product::query()->useWritePdo()->find($source->product_id);
        if (! $product instanceof Product || $product->isStackProduct() || ! $product->isApprovedByTwo()) {
            return false;
        }
        $selected = $this->selected($product);
        if (! $selected instanceof Image || (int) $selected->id !== (int) $source->id || (int) $selected->image_asset_id !== (int) $source->image_asset_id) {
            return false;
        }

        $generation = \Illuminate\Support\Facades\DB::table('component_photograph_generations')
            ->where('component_product_id', $product->id)
            ->where('component_image_id', $source->id)
            ->where('image_asset_id', $source->image_asset_id)
            ->where('status', 'confirmed')
            ->orderByDesc('selection_revision')
            ->orderByDesc('id')
            ->first();
        if (! $generation || trim((string) $generation->shopify_media_id) !== trim((string) $source->shopify_id)) {
            return false;
        }
        $latest = \Illuminate\Support\Facades\DB::table('component_photograph_generations')
            ->where('component_product_id', $product->id)
            ->where('status', 'confirmed')
            ->orderByDesc('selection_revision')
            ->orderByDesc('id')
            ->first();

        return $latest !== null && (int) $latest->id === (int) $generation->id;
    }
}
