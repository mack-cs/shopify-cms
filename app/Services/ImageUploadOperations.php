<?php

namespace App\Services;

use App\Models\Image;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

final class ImageUploadOperations
{
    public function active(Image $image): ?object
    {
        return DB::table('image_upload_operations')->useWritePdo()->where('active_image_id', $image->id)->first();
    }

    /** Committed before the HTTP call: a killed worker cannot erase this claim. */
    public function claim(Image $image, string $productId, array $media): ?object
    {
        if (DB::transactionLevel() > 0 && ! app()->environment('testing')) {
            throw new RuntimeException('Upload claims cannot be made inside an enclosing transaction.');
        }

        return DB::transaction(function () use ($image, $productId, $media): ?object {
            $fresh = Image::query()->lockForUpdate()->findOrFail($image->id);
            if ($this->active($fresh) !== null || $fresh->shopify_media_create_uncertain) {
                return null;
            }
            $fresh->loadMissing('imageAsset');
            if (! $fresh->backupReady() || (int) $fresh->image_asset_id !== (int) $image->image_asset_id) {
                throw new RuntimeException('Upload requires an unchanged SHA-256 backup asset.');
            }
            $key = hash('sha256', $productId.':'.$fresh->imageAsset->sha256);
            $existing = DB::table('image_upload_operations')->useWritePdo()->where('asset_key', $key)->lockForUpdate()->first();
            $sourceUrl = $fresh->desiredSyncSourceUrl().'?asset='.$fresh->imageAsset->sha256;
            $before = json_encode(array_column($media, 'id'), JSON_THROW_ON_ERROR);
            if ($existing) {
                $stillAttached = filled($existing->media_id) && collect($media)->contains(
                    fn ($node): bool => is_array($node) && ($node['id'] ?? null) === $existing->media_id
                );
                $reusable = $existing->status === 'confirmed'
                    && $existing->active_image_id === null
                    && ! $stillAttached;
                if (! $reusable) {
                    return null;
                }
                DB::table('image_upload_operations')->useWritePdo()->where('id', $existing->id)->update([
                    'image_id' => $fresh->id,
                    'active_image_id' => $fresh->id,
                    'image_asset_id' => $fresh->image_asset_id,
                    'asset_sha256' => $fresh->imageAsset->sha256,
                    'source_url' => $sourceUrl,
                    'before_media_ids' => $before,
                    'media_id' => null,
                    'status' => 'submitting',
                    'error' => null,
                    'updated_at' => now(),
                ]);
                Image::withoutEvents(fn () => $fresh->forceFill(['shopify_media_create_uncertain' => true])->save());

                return $this->active($fresh);
            }
            $id = (string) Str::uuid();
            DB::table('image_upload_operations')->useWritePdo()->insert([
                'asset_key' => $key, 'id' => $id, 'image_id' => $fresh->id, 'active_image_id' => $fresh->id,
                'image_asset_id' => $fresh->image_asset_id, 'asset_sha256' => $fresh->imageAsset->sha256,
                'product_gid' => $productId,
                'source_url' => $sourceUrl,
                'before_media_ids' => $before,
                'status' => 'submitting', 'created_at' => now(), 'updated_at' => now(),
            ]);
            Image::withoutEvents(fn () => $fresh->forceFill(['shopify_media_create_uncertain' => true])->save());

            return $this->active($fresh);
        });
    }

    public function record(object $operation, string $status, ?string $mediaId = null, ?string $error = null): void
    {
        DB::table('image_upload_operations')->useWritePdo()->where('id', $operation->id)->update([
            'status' => $status, 'media_id' => $mediaId ?: $operation->media_id,
            'error' => $error, 'updated_at' => now(),
        ]);
    }

    /** Returns only a ready, attached file proved to contain this operation's bytes. */
    public function reconcile(Image $image, object $operation, array $media): ?array
    {
        $image->refresh()->load('imageAsset');
        if ((int) $image->image_asset_id !== (int) $operation->image_asset_id
            || $image->imageAsset?->sha256 !== $operation->asset_sha256) {
            $this->record($operation, 'needs_review', error: 'The image changed while an upload was unresolved.');

            return null;
        }
        $before = json_decode($operation->before_media_ids, true, flags: JSON_THROW_ON_ERROR);
        if (! $operation->media_id) {
            $this->record($operation, 'needs_review', error: 'The creation response contained no durable media id. A matching new file alone cannot prove operation ownership; use explicit reviewed adoption.');

            return null;
        }
        $image->loadMissing('product');
        if ($operation->product_gid !== $image->product?->shopify_id) {
            $this->record($operation, 'needs_review', error: 'Upload product identity changed. No file was adopted.');

            return null;
        }
        $usedIds = Image::query()->active()->whereKeyNot($image->id)->whereNotNull('shopify_id')->pluck('shopify_id');
        $sourceIds = DB::table('inherited_image_sources')->where('image_id', $image->id)->pluck('component_image_id')->push($image->source_image_id)->filter();
        $usedIds = $usedIds->merge(Image::query()->whereIn('id', $sourceIds)->pluck('shopify_id'))->filter()->all();
        if (in_array($operation->media_id, $usedIds, true)) {
            $this->record($operation, 'needs_review', error: 'The returned file is already referenced by another CMS image or component. Review independent ownership; no file was adopted.');
            return null;
        }
        $candidates = collect($media)->filter(fn (array $node): bool => preg_match('~^gid://shopify/MediaImage/\d+$~', (string) ($node['id'] ?? '')) === 1
            && ! in_array($node['id'], $usedIds, true)
            && ($operation->media_id ? $node['id'] === $operation->media_id : ! in_array($node['id'], $before, true))
        );
        $matches = [];
        foreach ($candidates as $node) {
            if (ShopifyProductMediaReader::ready($node)
                && app(ImagePhotographEvidence::class)->matchesMedia($image, $node)) {
                $matches[] = $node;
            }
        }
        if (count($matches) !== 1) {
            $this->record($operation, $operation->media_id ? 'processing' : 'needs_review', error: 'No unique ready file has verified bytes. Reconcile or resolve manually; creation is blocked.');

            return null;
        }

        return $matches[0];
    }

    public function complete(object $operation, string $mediaId): void
    {
        DB::table('image_upload_operations')->useWritePdo()->where('id', $operation->id)->update([
            'media_id' => $mediaId, 'status' => 'confirmed', 'active_image_id' => null, 'error' => null, 'updated_at' => now(),
        ]);
    }
}
