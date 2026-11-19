<?php

namespace App\Services;

use App\Enums\RolesEnum;
use App\Models\Image;
use App\Models\Product;
use App\Models\ProductPartialApprovalRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Append-only approvals bind one sentence to one photograph, never to position. */
final class ImageAltApprovalEvidence
{
    public function recorded(Image $image): ?string
    {
        $image->loadMissing('imageAsset');
        if (! $image->imageAsset || ImageOwnership::effective($image) === ImageOwnership::UNRESOLVED) {
            return null;
        }
        $sentence = trim((string) $image->alt_text);

        return DB::table('image_alt_approvals')->useWritePdo()
            ->where('image_id', $image->id)->where('image_asset_id', $image->image_asset_id)
            ->where('asset_sha256', $image->imageAsset->sha256)
            ->where('alt_text', $sentence)->orderByDesc('id')->pluck('alt_text')
            ->first(fn (string $approved): bool => hash_equals($approved, $sentence));
    }

    public function approve(Image $image, int $userId, string $reason, ?string $reviewedSentence = null, ?int $reviewedAssetId = null): void
    {
        $user = User::findOrFail($userId);
        if (! $user->is_active || ! $user->hasAnyRole([RolesEnum::Admin->value, RolesEnum::SuperAdmin->value])) {
            throw new RuntimeException('Image alt approval requires an active Admin or SuperAdmin.');
        }
        if (mb_strlen(trim($reason)) < 20) {
            throw new RuntimeException('Record a review reason of at least 20 characters.');
        }
        DB::transaction(function () use ($image, $userId, $reason, $reviewedSentence, $reviewedAssetId): void {
            $image = Image::query()->lockForUpdate()->with('imageAsset', 'product')->findOrFail($image->id);
            if (($reviewedSentence !== null && $reviewedSentence !== (string) $image->alt_text)
                || ($reviewedAssetId !== null && $reviewedAssetId !== (int) $image->image_asset_id)) {
                throw new RuntimeException('The photograph or sentence changed during review. Reopen the review and approve the current version.');
            }
            $this->record($image, $userId, 'individual_review', $reason);
            if ($image->alt_confirmation_media_id !== null) {
                return; // Approval never clears an uncertain request.
            }
            Image::withoutEvents(fn () => $image->forceFill(['alt_text_status' => Image::ALT_APPROVED, 'shopify_alt_pending' => true])->save());
        });
    }

    public function captureFullApproval(Product $product, int $userId): void
    {
        if (! $product->isApprovedByTwo()) {
            return;
        }
        foreach ($product->allImages()->active()->with('imageAsset', 'product')->get() as $image) {
            if ($image->imageAsset && $image->imageAsset->isAvailable() && trim((string) $image->alt_text) !== '' && mb_strlen(trim((string) $image->alt_text)) <= 255
                && ImageOwnership::effective($image) !== ImageOwnership::UNRESOLVED) {
                $this->record($image, $userId, 'full_product_approval', 'Sentence and photograph captured at full product approval.');
            }
        }
    }

    private function record(Image $image, int $userId, string $context, string $reason): void
    {
        $text = trim((string) $image->alt_text);
        if (! $image->imageAsset || ! $image->imageAsset->isAvailable() || $text === '' || mb_strlen($text) > 255
            || ImageOwnership::effective($image) === ImageOwnership::UNRESOLVED
            || in_array($image->sync_state, [Image::SYNC_STATE_LOCAL_DELETED, Image::SYNC_STATE_REMOTE_DELETED], true) || $image->is_duplicate_hidden) {
            throw new RuntimeException('Review requires an active classified image, available backup and nonblank alt text of at most 255 characters.');
        }
        DB::table('image_alt_approvals')->insert([
            'image_id' => $image->id, 'image_asset_id' => $image->image_asset_id,
            'asset_sha256' => $image->imageAsset->sha256, 'alt_text' => $text,
            'user_id' => $userId, 'context' => $context, 'reason' => trim($reason),
            'snapshot' => json_encode($image->getAttributes(), JSON_THROW_ON_ERROR), 'approved_at' => now(),
        ]);
    }

    public function snapshotMatches(Image $image, array $row, ProductPartialApprovalRequest $request): bool
    {
        if ((int) ($row['image_id'] ?? 0) !== (int) $image->id
            || trim((string) ($row['proposed_alt'] ?? '')) !== trim((string) $image->alt_text)) {
            return false;
        }
        if (array_key_exists('image_asset_id', $row)) {
            return (int) $row['image_asset_id'] > 0 && (int) $row['image_asset_id'] === (int) $image->image_asset_id;
        }
        // Legacy URL alone is insufficient. Require a pre-approval immutable
        // backup, a matching last-synced asset, and no later replacement evidence.
        $image->loadMissing('imageAsset');
        $asset = $image->imageAsset;
        $approved = $request->approved_at;
        if (! $asset || ! $approved || ! $asset->downloaded_at || $asset->downloaded_at->gt($approved)
            || (int) $image->last_shopify_synced_image_asset_id !== (int) $asset->id
            || $image->shopify_identity_unresolved) {
            return false;
        }
        $saved = ShopifyMediaPath::canonical($row['src'] ?? null);
        $origin = ShopifyMediaPath::canonical($asset->source_url);

        return $saved !== null && $origin !== null && $saved === $origin
            && ! DB::table('image_revisions')->where('image_id', $image->id)->where('created_at', '>', $approved)
                ->where(function ($q): void {
                    $q->where('reason', 'like', '%replacement%')->orWhere('reason', 'like', '%replaced%');
                })->exists();
    }
}
