<?php

namespace App\Services;

use App\Models\Image;
use Illuminate\Support\Facades\DB;

/** One conflict decision applies only to the exact active sources and membership. */
final class InheritedAltResolution
{
    public function context(Image $image): string
    {
        $image->loadMissing('product');
        $members = app(StackComponentCatalog::class)->componentIds($image->product);
        sort($members);
        $sources = DB::table('inherited_image_sources')->where('image_id', $image->id)
            ->whereNull('removed_at')->orderBy('component_image_id')->get()->map(function ($link) use ($members): array {
                $source = Image::query()->active()->find($link->component_image_id);
                $generation = $source ? DB::table('component_photograph_generations')
                    ->where('component_product_id', $source->product_id)->orderByDesc('selection_revision')->orderByDesc('id')->first() : null;

                return [
                    (int) $link->component_image_id, (int) $link->image_asset_id, $link->generation_id,
                    $link->created_at, $link->updated_at,
                    $source ? (int) $source->image_asset_id : null,
                    $source && in_array((int) $source->product_id, $members, true)
                        ? app(ProductPartialApprovalService::class)->approvedAlt($source) : null,
                    $generation ? [(int) $generation->id, $generation->status] : null,
                ];
            })->all();

        return hash('sha256', json_encode([
            (int) $image->id, (int) $image->image_asset_id, $members,
            DB::table('stack_membership_revisions')->where('stack_product_id', $image->product_id)->max('revision'),
            $sources,
        ], JSON_THROW_ON_ERROR));
    }

    public function current(Image $image): ?string
    {
        $row = DB::table('inherited_alt_resolutions')->where('image_id', $image->id)
            ->where('image_asset_id', $image->image_asset_id)->orderByDesc('id')->first();
        if (! $row || ! is_string($row->context_hash) || ! hash_equals($row->context_hash, $this->context($image))) {
            return null;
        }
        $members = app(StackComponentCatalog::class)->componentIds($image->product);
        $sourceIds = DB::table('inherited_image_sources')->where('image_id', $image->id)->whereNull('removed_at')->pluck('component_image_id');
        $approved = Image::query()->active()->whereIn('id', $sourceIds)->whereIn('product_id', $members)
            ->where('image_asset_id', $image->image_asset_id)->get()
            ->contains(fn (Image $source): bool => app(ProductPartialApprovalService::class)->approvedAlt($source) === $row->alt_text);

        return $approved ? $row->alt_text : null;
    }
}
