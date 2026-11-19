<?php

namespace App\Services;

use App\Enums\RolesEnum;
use App\Models\Image;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class ImageAltRecovery
{
    public function prepareRebuild(Image $image, int $userId, string $reason): void
    {
        app(ImageRecoveryOperator::class)->authorize((string) $userId);
        $reason = app(ImageRecoveryOperator::class)->reason($reason);
        $mutex = new ImageSyncMutex;
        if (! $mutex->acquire((int) $image->product_id)) {
            throw new RuntimeException('Another image operation is running.');
        }
        try {
            $image = $image->fresh(['product', 'imageAsset']);
            if (! $image || ! $image->imageAsset?->isAvailable() || ImageOwnership::effective($image) === ImageOwnership::UNRESOLVED
                || $image->alt_confirmation_media_id !== null || $image->shopify_media_create_uncertain
                || app(ImageUploadOperations::class)->active($image)) {
                throw new RuntimeException('Classify and back up the image and resolve pending operations before preparing a rebuild.');
            }
            $decision = app(ShopifyMediaImageResolver::class)->diagnose($image);
            if ($decision['classification'] === 'api_error') {
                throw new RuntimeException('Shopify could not be read. Do not rebuild while its state is unknown.');
            }
            $shared = Image::query()->active()->whereKeyNot($image->id)->where('shopify_id', $image->shopify_id)->exists();
            if (in_array($decision['classification'], ['verified_current_media', 'recoverable_historical'], true) && ! $shared) {
                throw new RuntimeException('Existing media is verifiable. Publish alt text using that identity; no rebuild is needed.');
            }
            DB::transaction(function () use ($image, $userId, $reason): void {
                app(ImagePhotographEvidence::class)->archive($image, 'Operator prepared a separate media rebuild');
                DB::table('image_alt_review_events')->insert(['image_id' => $image->id, 'user_id' => $userId, 'action' => 'prepare_rebuild', 'reason' => $reason, 'snapshot' => json_encode($image->getAttributes(), JSON_THROW_ON_ERROR), 'created_at' => now()]);
                Image::withoutEvents(fn () => $image->forceFill(['shopify_id' => null, 'shopify_cdn_url' => null, 'shopify_identity_unresolved' => true, 'needs_shopify_image_sync' => true, 'sync_state' => Image::SYNC_STATE_LOCAL_NEW, 'local_dirty' => true])->save());
            });
        } finally {
            $mutex->release();
        }
    }

    public function classify(Image $image, string $ownership, ?int $sourceId, int $userId, string $reason): void
    {
        $user = User::findOrFail($userId);
        if (! $user->is_active || ! $user->hasAnyRole([RolesEnum::Admin->value, RolesEnum::SuperAdmin->value]) || mb_strlen(trim($reason)) < 20) {
            throw new RuntimeException('Classification requires an active administrator and a documented reason.');
        }
        $mutex = new ImageSyncMutex;
        if (! $mutex->acquire((int) $image->product_id)) {
            throw new RuntimeException('Another image operation is running.');
        }
        try {
            DB::transaction(function () use ($image, $ownership, $sourceId, $userId, $reason): void {
                $image = Image::query()->lockForUpdate()->with('product')->findOrFail($image->id);
                if (! $image->product?->isStackProduct() || ! in_array($ownership, [ImageOwnership::STACK_OWNED, ImageOwnership::COMPONENT_INHERITED], true)) {
                    throw new RuntimeException('Choose an explicit stack image ownership.');
                }
                if ($image->alt_confirmation_media_id !== null || $image->shopify_media_create_uncertain || app(ImageUploadOperations::class)->active($image)) {
                    throw new RuntimeException('Reconcile the pending Shopify operation before changing ownership.');
                }
                $source = $ownership === ImageOwnership::COMPONENT_INHERITED ? Image::find($sourceId) : null;
                if ($ownership === ImageOwnership::COMPONENT_INHERITED && (! $source
                    || ! in_array((int) $source->product_id, app(StackComponentCatalog::class)->componentIds($image->product), true)
                    || ! $source->image_asset_id || (int) $source->image_asset_id !== (int) $image->image_asset_id
                    || $source->is_duplicate_hidden || in_array($source->sync_state, [Image::SYNC_STATE_LOCAL_DELETED, Image::SYNC_STATE_REMOTE_DELETED], true))) {
                    throw new RuntimeException('Inherited ownership needs a current linked component image with the same backup asset.');
                }
                DB::table('image_alt_review_events')->insert([
                    'image_id' => $image->id, 'user_id' => $userId, 'action' => 'classify', 'reason' => trim($reason),
                    'snapshot' => json_encode($image->getAttributes(), JSON_THROW_ON_ERROR), 'created_at' => now(),
                ]);
                app(ImagePhotographEvidence::class)->archive($image, 'Operator classified image ownership');
                if ($ownership === ImageOwnership::STACK_OWNED) {
                    foreach (DB::table('inherited_image_sources')->where('image_id', $image->id)->whereNull('removed_at')->get() as $link) {
                        app(ImagePhotographEvidence::class)->archiveSource($link, 'classify_owned');
                    }
                    DB::table('inherited_image_sources')->where('image_id', $image->id)->whereNull('removed_at')->update(['removed_at' => now(), 'updated_at' => now()]);
                }
                Image::withoutEvents(fn () => $image->forceFill([
                    'ownership' => $ownership, 'source_image_id' => $source?->id,
                    'alt_inheritance_mode' => $source ? 'component' : 'independent',
                ])->save());
                if ($source) {
                    $previousLink = DB::table('inherited_image_sources')->where('image_id', $image->id)
                        ->where('component_image_id', $source->id)->where('image_asset_id', $source->image_asset_id)->first();
                    if ($previousLink) {
                        app(ImagePhotographEvidence::class)->archiveSource($previousLink, 'classify_inherited');
                    }
                    DB::table('inherited_image_sources')->updateOrInsert([
                        'image_id' => $image->id, 'component_image_id' => $source->id, 'image_asset_id' => $source->image_asset_id,
                    ], ['component_product_id' => $source->product_id, 'removed_at' => null, 'created_at' => now(), 'updated_at' => now()]);
                }
            });
        } finally {
            $mutex->release();
        }
    }

    public function issues(Image $image): string
    {
        $issues = [];
        if (ImageOwnership::effective($image) === ImageOwnership::UNRESOLVED) {
            $issues[] = 'Review ownership';
        }
        if (! $image->image_asset_id) {
            $issues[] = 'Backup photograph';
        }
        if ($image->shopify_identity_unresolved || ! preg_match('~^gid://shopify/MediaImage/\d+$~', (string) $image->shopify_id)) {
            $issues[] = 'Verify/rebuild media';
        }
        if (trim((string) $image->alt_text) === '') {
            $issues[] = 'Add description';
        } elseif (app(ImageAltTextBackfillService::class)->approvedUploadAlt($image) === null) {
            $issues[] = 'Review approval evidence';
        }
        if ($image->alt_confirmation_media_id !== null) {
            $issues[] = 'Awaiting Shopify confirmation';
        } elseif ($image->alt_text_status === Image::ALT_FAILED) {
            $issues[] = 'Retry failed publication';
        }
        if ($image->shopify_media_create_uncertain || app(ImageUploadOperations::class)->active($image)) {
            $issues[] = 'Uncertain upload: review before adoption or retry';
        }
        if (DB::table('image_variant_operations')->where('image_id', $image->id)->whereNotNull('active_key')->exists()) {
            $issues[] = 'Reconcile variant association';
        }

        return $issues === [] ? 'Local checks passed; verify Shopify on publish' : implode('; ', $issues);
    }
}
