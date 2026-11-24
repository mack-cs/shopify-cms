<?php

namespace App\Services;

use App\Contracts\ShopifyGraphqlGateway;
use App\Models\Image;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reads a product's current media and returns one verified MediaImage id.
 * It never sends a Shopify mutation.
 */
final class ShopifyMediaImageResolver
{
    public function __construct(private readonly ShopifyGraphqlGateway $shopify) {}

    public function resolve(Image $image): string
    {
        $decision = $this->decision($image);
        if (! in_array($decision['classification'], ['verified_current_media', 'recoverable_historical'], true)) {
            throw new RuntimeException($this->rejection($image, $decision));
        }

        $resolved = (string) $decision['proposed_media_id'];
        $localId = trim((string) $image->shopify_id);
        $cdnUrl = ShopifyMediaPath::rememberableCdnUrl($decision['media_url']);
        if ($resolved !== $localId || ($cdnUrl !== null && $cdnUrl !== $image->shopify_cdn_url)) {
            Image::withoutEvents(function () use ($image, $resolved, $cdnUrl): void {
                $updates = ['shopify_id' => $resolved];
                if ($cdnUrl !== null) {
                    $updates['shopify_cdn_url'] = $cdnUrl;
                }
                DB::transaction(function () use ($image, $updates): void {
                    $current = Image::query()->lockForUpdate()->findOrFail($image->id);
                    if ($current->image_asset_id !== $image->image_asset_id || $current->shopify_identity_unresolved) {
                        throw new RuntimeException('Photograph generation changed while identity was being verified.');
                    }
                    $current->forceFill($updates)->save();
                });
                $image->refresh();
            });
        }

        return $resolved;
    }

    /**
     * Read-only classification. This does not change the image row.
     *
     * @return array{classification:string, proposed_media_id:?string, evidence:string, media_url:?string, match_count:int}
     */
    public function diagnose(Image $image): array
    {
        try {
            return $this->decision($image);
        } catch (\Throwable $exception) {
            return $this->outcome('api_error', null, $exception->getMessage(), null, 0);
        }
    }

    /**
     * @return array{classification:string, proposed_media_id:?string, evidence:string, media_url:?string, match_count:int}
     */
    private function decision(Image $image): array
    {
        $image->loadMissing('imageAsset');
        if ($image->shopify_identity_unresolved || $this->replacedAsset($image)) {
            return $this->outcome(
                'replaced_or_invalid',
                null,
                'The stored asset hash differs from the last Shopify sync, so the old media file is not this photograph.',
                null,
                0,
            );
        }

        if (! $image->image_asset_id) {
            return $this->outcome('unresolved_provenance', null, 'No current backup asset establishes photograph identity.', null, 0);
        }

        $productGid = trim((string) ($image->product?->shopify_id ?? ''));
        if ($productGid === '') {
            throw new RuntimeException('Image '.$image->id.' has no Shopify product, so its alt text stayed in the CMS.');
        }

        $product = $this->product($productGid, (int) $image->id);
        $media = collect(is_array($product['media']['nodes'] ?? null) ? $product['media']['nodes'] : [])
            ->filter(fn ($node): bool => is_array($node) && preg_match('~^gid://shopify/MediaImage/\d+$~', (string) ($node['id'] ?? '')) === 1)
            ->values();
        $localId = trim((string) $image->shopify_id);

        if (preg_match('~^gid://shopify/MediaImage/\d+$~', $localId) === 1) {
            $attached = $media->first(fn (array $node): bool => $node['id'] === $localId);
            if (is_array($attached)) {
                if (! ShopifyProductMediaReader::ready($attached)) {
                    return $this->outcome('processing', null, 'Shopify file is not ready.', null, 0);
                }
                if ($image->image_asset_id && ! app(ImageUploadOperations::class)->confirmedAttachment($image, $localId)
                    && ! app(ImagePhotographEvidence::class)->matchesMedia($image, $attached)) {
                    return $this->outcome('unresolved_provenance', null, 'Current Shopify bytes do not verify the CMS photograph.', null, 0);
                }

                return $this->outcome(
                    'verified_current_media',
                    $localId,
                    'stored MediaImage id is attached to this product',
                    (string) ($attached['image']['url'] ?? ''),
                    1,
                );
            }
        }

        $matches = [];
        foreach ($this->candidates($image, $product, $localId) as $candidate) {
            $hits = $media->filter(function (array $node) use ($candidate): bool {
                $mediaUrl = (string) ($node['image']['url'] ?? '');

                return ! ShopifyMediaPath::isCmsDeliveryUrl($mediaUrl)
                    && ShopifyMediaPath::canonical($mediaUrl) === $candidate['key'];
            })->values();
            if ($hits->count() > 1) {
                return $this->outcome(
                    'ambiguous',
                    null,
                    $candidate['evidence'].' matches '.$hits->count().' Shopify media files',
                    null,
                    $hits->count(),
                );
            }
            if ($hits->count() === 1) {
                $matches[] = [
                    'id' => (string) $hits[0]['id'],
                    'url' => (string) ($hits[0]['image']['url'] ?? ''),
                    'evidence' => $candidate['evidence'],
                ];
            }
        }

        $ids = collect($matches)->pluck('id')->unique()->values();
        if ($ids->count() > 1) {
            $evidence = collect($matches)->pluck('evidence')->unique()->implode(' and ');

            return $this->outcome('ambiguous', null, $evidence.' identify different Shopify files', null, $ids->count());
        }
        if ($ids->count() === 1) {
            $match = $matches[0];
            $node = $media->first(fn (array $node): bool => $node['id'] === $match['id']);
            if (! ShopifyProductMediaReader::ready($node)) {
                return $this->outcome('processing', null, 'Shopify file is not ready.', null, 0);
            }
            if (! app(ImagePhotographEvidence::class)->matchesMedia($image, $node)) {
                return $this->outcome('unresolved_provenance', null, 'Historical URL is only a candidate; current bytes do not verify the CMS asset.', null, 0);
            }

            return $this->outcome(
                'recoverable_historical',
                $match['id'],
                $match['evidence'].' matches one current media file',
                $match['url'],
                1,
            );
        }

        $verified = [];
        $processing = false;
        foreach ($media as $node) {
            if (! ShopifyProductMediaReader::ready($node)) {
                $processing = true;

                continue;
            }
            if (app(ImagePhotographEvidence::class)->matchesMedia($image, $node)) {
                $verified[] = $node;
            }
        }
        if (count($verified) > 1) {
            return $this->outcome('ambiguous', null, 'Multiple attached files contain the same original bytes.', null, count($verified));
        }
        if (count($verified) === 1) {
            return $this->outcome('recoverable_historical', $verified[0]['id'], 'Current product attachment and verified SHA-256 original bytes establish the photograph despite a changed URL.', $verified[0]['image']['url'] ?? null, 1);
        }
        if ($processing) {
            return $this->outcome('processing', null, 'Current media processing prevents proving absence.', null, 0);
        }
        if ($this->candidates($image, $product, $localId) === []) {
            return $this->outcome(
                'missing',
                null,
                'Image '.$image->id.' has no MediaImage id and no photograph URL that can be matched.',
                null,
                0,
            );
        }

        return $this->outcome(
            'missing',
            null,
            'Image '.$image->id.' matches 0 Shopify media files. Alt text was not changed.',
            null,
            0,
        );
    }

    /**
     * @param  array<string, mixed>  $product
     * @return array<int, array{key:string, evidence:string}>
     */
    private function candidates(Image $image, array $product, string $localId): array
    {
        $urls = [];
        if (preg_match('~^gid://shopify/ProductImage/\d+$~', $localId) === 1) {
            $legacy = collect(is_array($product['images']['nodes'] ?? null) ? $product['images']['nodes'] : [])
                ->first(fn ($node): bool => is_array($node) && (string) ($node['id'] ?? '') === $localId);
            if (is_array($legacy)) {
                $urls[] = ['url' => (string) ($legacy['url'] ?? ''), 'evidence' => 'legacy ProductImage url'];
            }
        }
        $urls[] = ['url' => (string) ($image->shopify_cdn_url ?? ''), 'evidence' => 'shopify_cdn_url'];
        $urls[] = ['url' => (string) ($image->imageAsset?->source_url ?? ''), 'evidence' => 'image_assets.source_url'];
        $urls[] = ['url' => (string) ($image->src ?? ''), 'evidence' => 'image src'];

        $candidates = [];
        foreach ($urls as $candidate) {
            $key = ShopifyMediaPath::canonical($candidate['url']);
            if ($key === null || ShopifyMediaPath::isCmsDeliveryUrl($candidate['url'])) {
                continue;
            }
            $candidates[] = ['key' => $key, 'evidence' => $candidate['evidence']];
        }

        return $candidates;
    }

    private function replacedAsset(Image $image): bool
    {
        $last = (int) ($image->last_shopify_synced_image_asset_id ?? 0);
        $current = (int) ($image->image_asset_id ?? 0);

        return $last > 0 && $current > 0 && $last !== $current;
    }

    /**
     * @param  array{classification:string, evidence:string, match_count:int}  $decision
     */
    private function rejection(Image $image, array $decision): string
    {
        if ($decision['classification'] === 'ambiguous') {
            return 'Image '.$image->id.' matches '.$decision['match_count'].' Shopify media files. Alt text was not changed.';
        }
        if ($decision['classification'] === 'replaced_or_invalid') {
            return 'Image '.$image->id.' replaced its photograph, so alt text was not written to the old Shopify file.';
        }

        $evidence = trim((string) ($decision['evidence'] ?? ''));

        return $evidence !== ''
            ? $evidence
            : 'Image '.$image->id.' has no MediaImage id and no photograph URL that can be matched.';
    }

    /**
     * @return array{classification:string, proposed_media_id:?string, evidence:string, media_url:?string, match_count:int}
     */
    private function outcome(string $classification, ?string $mediaId, string $evidence, ?string $mediaUrl, int $matchCount): array
    {
        return [
            'classification' => $classification,
            'proposed_media_id' => $mediaId,
            'evidence' => $evidence,
            'media_url' => $mediaUrl,
            'match_count' => $matchCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function product(string $productGid, int $imageId): array
    {
        return (new ShopifyProductMediaReader($this->shopify))->read($productGid);
    }
}
