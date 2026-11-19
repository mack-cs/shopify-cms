<?php

namespace App\Console\Commands;

use App\Models\Image;
use App\Services\ImageAltTextBackfillService;
use App\Services\ImagePhotographEvidence;
use App\Services\ImageRecoveryOperator;
use App\Services\ImageSyncMutex;
use App\Services\ShopifyProductMediaReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Reads Shopify only. Local resolution is explicit, attributed, and archived. */
final class ResolveAltTextConfirmation extends Command
{
    protected $signature = 'images:resolve-alt-confirmation {image} {--operator=} {--abandon} {--reason=}';

    protected $description = 'Confirm or explicitly abandon a pending alt update without sending any Shopify mutation';

    public function handle(ImageRecoveryOperator $operators, ShopifyProductMediaReader $reader, ImagePhotographEvidence $evidence, ImageAltTextBackfillService $alt): int
    {
        $mutex = new ImageSyncMutex;
        $held = false;
        try {
            $operator = $operators->authorize($this->option('operator'));
            $reason = $this->option('abandon') ? $operators->reason($this->option('reason')) : 'Fresh Shopify read-back confirmed the approved sentence.';
            $image = Image::query()->useWritePdo()->findOrFail($this->argument('image'));
            $held = $mutex->acquire((int) $image->product_id);
            if (! $held) {
                throw new RuntimeException('Another image operation is running.');
            }
            $image->refresh()->load(['product', 'imageAsset']);
            $expected = $image->alt_confirmation_text;
            $mediaId = (string) $image->alt_confirmation_media_id;
            if (! is_string($expected) || trim($expected) === '' || ! preg_match('~^gid://shopify/MediaImage/\d+$~', $mediaId)) {
                throw new RuntimeException('This image has no complete pending confirmation to resolve.');
            }
            $fields = ['product_id', 'image_asset_id', 'source_image_id', 'shopify_id', 'shopify_identity_unresolved', 'alt_text', 'alt_text_status', 'alt_confirmation_media_id', 'alt_confirmation_text'];
            $before = $image->only($fields);
            $productId = (string) $image->product?->shopify_id;
            if ($productId === '') {
                throw new RuntimeException('The current Shopify product is unknown.');
            }
            $nodes = $reader->media($productId, 'RecoverAttachedImageAlt');
            $node = collect($nodes)->first(fn (array $node): bool => ($node['id'] ?? null) === $mediaId);
            $confirmed = $node !== null && ShopifyProductMediaReader::ready($node)
                && ($node['alt'] ?? null) === $expected && $image->shopify_id === $mediaId
                && $image->alt_text === $expected && ! $image->shopify_identity_unresolved
                && $alt->approvedUploadAlt($image) === $expected && $evidence->matchesMedia($image, $node);
            if (! $confirmed && ! $this->option('abandon')) {
                throw new RuntimeException('The attached media, photograph, approval, or expected sentence is not confirmed. Pending state was retained.');
            }
            $mutex->assertOwned();
            DB::transaction(function () use ($image, $fields, $before, $operator, $reason, $confirmed, $expected, $evidence, $alt): void {
                Image::query()->whereIn('id', array_filter([$image->id, $image->source_image_id]))->orderBy('id')->lockForUpdate()->get();
                $current = $image->fresh(['product', 'imageAsset']);
                if ($current->only($fields) !== $before || $current->product?->shopify_id !== $image->product?->shopify_id) {
                    throw new RuntimeException('The image changed during recovery. No pending state was cleared.');
                }
                $approved = $alt->approvedUploadAlt($current);
                if ($confirmed && $approved !== $expected) {
                    throw new RuntimeException('Approval changed during recovery.');
                }
                $evidence->archive($current, ($confirmed ? 'Alt confirmation verified' : 'Alt confirmation abandoned').'; operator='.$operator->id.'; '.$reason);
                $updates = [
                    'alt_confirmation_media_id' => null, 'alt_confirmation_text' => null, 'shopify_alt_pending' => false,
                    'alt_text_status' => $confirmed ? Image::ALT_PUBLISHED : ($approved !== null ? Image::ALT_APPROVED : Image::ALT_PENDING_REVIEW),
                    'shopify_alt_sync_error' => $confirmed ? null : 'Confirmation abandoned by operator '.$operator->id.'. '.$reason,
                ];
                if ($confirmed) {
                    $updates['shopify_alt_text'] = $expected;
                }
                Image::withoutEvents(fn () => $current->forceFill($updates)->save());
            });
            $this->info($confirmed ? 'Confirmed the existing Shopify sentence. No Shopify mutation was sent.' : 'Abandoned local confirmation tracking. No Shopify request was replayed; future authorized synchronization may retry the current approved sentence.');

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
