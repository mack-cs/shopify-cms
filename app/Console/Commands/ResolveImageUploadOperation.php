<?php

namespace App\Console\Commands;

use App\Contracts\ShopifyGraphqlGateway;
use App\Models\Image;
use App\Models\User;
use App\Services\ImagePhotographEvidence;
use App\Services\ImageRecoveryOperator;
use App\Services\ImageSyncMutex;
use App\Services\ShopifyMediaPath;
use App\Services\ShopifyProductMediaReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Explicit operator decision; never sends Shopify mutations or uploads files. */
final class ResolveImageUploadOperation extends Command
{
    protected $signature = 'images:resolve-upload {operation} {--adopt-media-id=} {--confirmed-not-created} {--superseded} {--confirmed-deleted} {--operator=} {--reason=}';

    protected $description = 'Record reviewed evidence that an uncertain upload created no Shopify file, allowing a later separately authorized retry';

    public function handle(): int
    {
        if ($this->option('adopt-media-id')) {
            return $this->adoptReviewedFile();
        }
        if ($this->option('confirmed-deleted')) {
            return $this->releaseDeletedFile();
        }
        if ((! $this->option('confirmed-not-created') && ! $this->option('superseded')) || ! ctype_digit((string) $this->option('operator'))
            || strlen(trim((string) $this->option('reason'))) < 20) {
            $this->error('Explicit --confirmed-not-created, --operator user id, and a detailed --reason are required.');

            return self::FAILURE;
        }
        if (! User::query()->whereKey((int) $this->option('operator'))->exists()) {
            $this->error('Operator user does not exist.');

            return self::FAILURE;
        }
        try {
            app(ImageRecoveryOperator::class)->authorize($this->option('operator'));
            app(ImageRecoveryOperator::class)->reason($this->option('reason'));
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
        $operation = DB::table('image_upload_operations')->where('id', $this->argument('operation'))->first();
        if (! $operation || ! $operation->active_image_id || ($operation->media_id && ! $this->option('superseded'))) {
            $this->error('Only unresolved operations without a returned media id may be released.');

            return self::FAILURE;
        }
        $mutex = new ImageSyncMutex;
        $image = Image::findOrFail($operation->image_id);
        if (! $mutex->acquire((int) $image->product_id)) {
            return self::FAILURE;
        }
        try {
            $mutex->assertOwned();
            if (! User::query()->whereKey((int) $this->option('operator'))->exists()) {
                $this->error('Operator user does not exist.');

                return self::FAILURE;
            }
            $operation = DB::table('image_upload_operations')->where('id', $operation->id)->first();
            if (! $operation->active_image_id || ($operation->media_id && ! $this->option('superseded'))) {
                return self::FAILURE;
            }
            $image->refresh()->load('imageAsset');
            if ($this->option('superseded') && (! $image->imageAsset || $image->imageAsset->sha256 === $operation->asset_sha256)) {
                $this->error('Superseding an operation requires a different current photograph.');

                return self::FAILURE;
            }
            DB::transaction(function () use ($operation, $image): void {
                DB::table('image_upload_operations')->where('id', $operation->id)->whereNotNull('active_image_id')->update([
                    'active_image_id' => null,
                    'asset_key' => $this->option('superseded') ? $operation->asset_key : null,
                    'status' => $this->option('superseded') ? 'superseded' : 'resolved_absent',
                    'resolved_by' => (int) $this->option('operator'),
                    'resolution_reason' => trim($this->option('reason')), 'updated_at' => now(),
                ]);
                Image::withoutEvents(fn () => $image->forceFill(['shopify_media_create_uncertain' => false])->save());
            });
        } finally {
            $mutex->release();
        }
        $this->info('Recorded manual resolution only. No Shopify request was sent.');

        return self::SUCCESS;
    }

    private function adoptReviewedFile(): int
    {
        $mutex = new ImageSyncMutex;
        $held = false;
        try {
            $operator = app(ImageRecoveryOperator::class)->authorize($this->option('operator'));
            $reason = app(ImageRecoveryOperator::class)->reason($this->option('reason'));
            $id = (string) $this->option('adopt-media-id');
            if (! preg_match('~^gid://shopify/MediaImage/\d+$~', $id)
                || $this->option('confirmed-not-created') || $this->option('superseded') || $this->option('confirmed-deleted')) {
                throw new \RuntimeException('Choose one valid reviewed MediaImage adoption.');
            }
            $operation = DB::table('image_upload_operations')->find($this->argument('operation'));
            if (! $operation || ! $operation->active_image_id) {
                throw new \RuntimeException('Choose an unresolved upload operation.');
            }
            $image = Image::with('product', 'imageAsset')->findOrFail($operation->image_id);
            $held = $mutex->acquire((int) $image->product_id);
            if (! $held) {
                throw new \RuntimeException('Another image operation is running.');
            }
            $image->refresh()->load(['product', 'imageAsset']);
            $nodes = (new ShopifyProductMediaReader(app(ShopifyGraphqlGateway::class)))->media($operation->product_gid, 'ReviewUncertainUploadAdoption');
            $node = collect($nodes)->first(fn ($node) => ($node['id'] ?? null) === $id);
            $used = Image::query()->whereKeyNot($image->id)->where('shopify_id', $id)->exists();
            $before = json_decode($operation->before_media_ids, true, flags: JSON_THROW_ON_ERROR);
            if ($used || ($operation->media_id && $operation->media_id !== $id) || in_array($id, $before, true) || ! is_array($node) || ! ShopifyProductMediaReader::ready($node)
                || $image->product?->shopify_id !== $operation->product_gid || (int) $image->image_asset_id !== (int) $operation->image_asset_id
                || $image->imageAsset?->sha256 !== $operation->asset_sha256 || ! app(ImagePhotographEvidence::class)->matchesMedia($image, $node)) {
                throw new \RuntimeException('The chosen file does not verify a new independent attachment for these exact photograph bytes.');
            }
            $mutex->assertOwned();
            DB::transaction(function () use ($image, $operation, $operator, $reason, $node, $id): void {
                $current = Image::query()->lockForUpdate()->findOrFail($image->id);
                $live = DB::table('image_upload_operations')->where('id', $operation->id)->lockForUpdate()->first();
                if (! $live?->active_image_id || (int) $current->image_asset_id !== (int) $operation->image_asset_id || $current->alt_confirmation_media_id !== null) {
                    throw new \RuntimeException('The operation changed during review or has a separate pending alt request.');
                }
                app(ImagePhotographEvidence::class)->archive($current, 'Operator reviewed uncertain upload adoption');
                Image::withoutEvents(fn () => $current->forceFill([
                    'shopify_id' => $id, 'shopify_cdn_url' => ShopifyMediaPath::rememberableCdnUrl($node['image']['url'] ?? null),
                    'last_shopify_synced_image_asset_id' => $current->image_asset_id, 'shopify_identity_unresolved' => false,
                    'shopify_media_create_uncertain' => false, 'needs_shopify_image_sync' => false,
                    'sync_state' => Image::SYNC_STATE_SYNCED, 'local_dirty' => false,
                ])->save());
                DB::table('image_upload_operations')->where('id', $operation->id)->update([
                    'media_id' => $id, 'status' => 'confirmed', 'active_image_id' => null,
                    'resolved_by' => $operator->id, 'resolution_reason' => $reason, 'error' => null, 'updated_at' => now(),
                ]);
            });
            $this->info('Recorded reviewed independent file adoption. No Shopify mutation or upload was sent.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($held) {
                $mutex->release();
            }
        }
    }

    private function releaseDeletedFile(): int
    {
        $mutex = new ImageSyncMutex;
        $held = false;
        try {
            if ($this->option('superseded') || $this->option('confirmed-not-created')) {
                throw new \RuntimeException('Choose exactly one resolution action.');
            }
            $operator = app(ImageRecoveryOperator::class)->authorize($this->option('operator'));
            $reason = app(ImageRecoveryOperator::class)->reason($this->option('reason'));
            $operation = DB::table('image_upload_operations')->useWritePdo()->where('id', $this->argument('operation'))->first();
            if (! $operation || $operation->status !== 'confirmed' || ! preg_match('~^gid://shopify/MediaImage/\d+$~', (string) $operation->media_id) || ! $operation->asset_key) {
                throw new \RuntimeException('A confirmed operation with a returned file id is required.');
            }
            $image = Image::query()->useWritePdo()->findOrFail($operation->image_id);
            $held = $mutex->acquire((int) $image->product_id);
            if (! $held) {
                throw new \RuntimeException('Another image operation is running.');
            }
            // Product detachment and CDN 404s cannot prove global file deletion.
            $result = app(ShopifyGraphqlGateway::class)->graphql('query VerifyDeletedImageFile($id: ID!) { currentAppInstallation { accessScopes { handle } } node(id: $id) { id } }', ['id' => $operation->media_id]);
            $scopes = collect($result['currentAppInstallation']['accessScopes'] ?? [])->pluck('handle');
            if (! $scopes->intersect(['read_files', 'write_files'])->isNotEmpty()) {
                throw new \RuntimeException('Global file deletion verification requires read_files or write_files access.');
            }
            if (isset($result['errors']) || ! array_key_exists('node', $result) || $result['node'] !== null) {
                throw new \RuntimeException('Shopify did not confirm global file deletion. No upload key was released.');
            }
            $mutex->assertOwned();
            DB::transaction(function () use ($operation, $image, $operator, $reason): void {
                $current = DB::table('image_upload_operations')->where('id', $operation->id)->lockForUpdate()->first();
                $image = Image::query()->lockForUpdate()->findOrFail($image->id);
                if ($current->status !== 'confirmed' || $current->media_id !== $operation->media_id || $current->asset_key !== $operation->asset_key
                    || DB::table('image_upload_operations')->where('active_image_id', $image->id)->exists()) {
                    throw new \RuntimeException('Upload state changed during deletion verification.');
                }
                app(ImagePhotographEvidence::class)->archive($image, 'Confirmed Shopify file deletion; operator='.$operator->id.'; '.$reason);
                DB::table('image_upload_operations')->where('id', $operation->id)->update([
                    'asset_key' => null, 'status' => 'resolved_deleted', 'resolved_by' => $operator->id, 'resolution_reason' => $reason, 'updated_at' => now(),
                ]);
                if ($image->shopify_id === $operation->media_id) {
                    Image::withoutEvents(fn () => $image->forceFill([
                        'shopify_id' => null, 'shopify_cdn_url' => null, 'shopify_identity_unresolved' => true,
                        'needs_shopify_image_sync' => true, 'shopify_media_create_uncertain' => false,
                    ])->save());
                }
            });
            $this->info('Recorded confirmed file deletion. No Shopify mutation or upload was sent. Pending alt confirmations were retained.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } finally {
            if ($held) {
                $mutex->release();
            }
        }
    }
}
