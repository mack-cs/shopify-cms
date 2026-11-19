<?php

namespace App\Services;

use App\Models\Image;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Matches an incoming Shopify catalogue image to one CMS row.
 * A hash can support a path match. It is never enough on its own.
 */
final class CatalogImageMatcher
{
    /** Collect immutable byte evidence before the caller starts its locking transaction. */
    public function prepareEvidence(Collection $images, array $urls): array
    {
        if (DB::transactionLevel() > 0 && ! app()->environment('testing')) {
            throw new \RuntimeException('Catalogue photograph evidence must be collected outside a database transaction.');
        }
        $evidence = [];
        foreach (array_unique($urls) as $url) {
            $path = ShopifyMediaPath::canonical($url);
            if ($path === null || ShopifyMediaPath::isCmsDeliveryUrl($url)
                || ! $images->contains(fn (Image $image): bool => $this->eligible($image) && $this->paths($image)->contains($path))) {
                continue;
            }
            try {
                $evidence[$url] = app(ImagePhotographEvidence::class)->probe($url)['sha256'];
            } catch (\Throwable) {
                $evidence[$url] = null; // An unreadable candidate remains unresolved, never a new duplicate.
            }
        }

        return $evidence;
    }

    /**
     * @param  Collection<int, Image>  $existingImages
     * @param  array<int, int>  $seenImageIds
     * @return array{image:?Image, ambiguous:bool, candidate_ids:array<int, int>}
     */
    public function match(Collection $existingImages, array $seenImageIds, ?string $shopifyId, ?string $src, array $evidence = []): array
    {
        $unseen = $existingImages
            ->filter(fn (Image $image): bool => ! in_array((int) $image->id, $seenImageIds, true))
            ->values();

        $shopifyId = trim((string) $shopifyId);
        if ($shopifyId !== '') {
            $byId = $unseen
                ->filter(fn (Image $image): bool => $this->eligible($image) && trim((string) $image->shopify_id) === $shopifyId)
                ->values();
            if ($byId->count() === 1) {
                return $this->found($byId->first());
            }
            if ($byId->count() > 1) {
                return $this->ambiguous($byId);
            }
        }

        $incoming = ShopifyMediaPath::canonical($src);
        if ($incoming === null || ShopifyMediaPath::isCmsDeliveryUrl($src)) {
            return $this->none();
        }

        $pathMatches = $unseen
            ->filter(fn (Image $image): bool => $this->eligible($image) && $this->paths($image)->contains($incoming))
            ->values();

        if ($pathMatches->count() === 1) {
            $candidate = $pathMatches->first();

            $hash = (string) $candidate->imageAsset?->sha256;
            $matches = array_key_exists((string) $src, $evidence)
                ? ($hash !== '' && is_string($evidence[$src]) && hash_equals($hash, $evidence[$src]))
                : (DB::transactionLevel() === 0 && app(ImagePhotographEvidence::class)->matches($candidate, (string) $src));

            return ! $candidate->shopify_identity_unresolved && $matches
                ? $this->found($candidate) : $this->ambiguous($pathMatches);
        }
        if ($pathMatches->count() > 1) {
            return $this->ambiguous($pathMatches);
        }

        $localNew = $unseen
            ->filter(fn (Image $image): bool => $image->sync_state === Image::SYNC_STATE_LOCAL_NEW && $this->paths($image)->contains($incoming))
            ->values();
        if ($localNew->isNotEmpty()) {
            return $this->ambiguous($localNew);
        }

        return $this->none();
    }

    private function eligible(Image $image): bool
    {
        if ($image->sync_state === Image::SYNC_STATE_LOCAL_NEW) {
            return false;
        }
        if (in_array($image->sync_state, [Image::SYNC_STATE_LOCAL_DELETED, Image::SYNC_STATE_REMOTE_DELETED], true)) {
            return false;
        }

        return ! (bool) $image->is_duplicate_hidden;
    }

    /**
     * @return Collection<int, string>
     */
    private function paths(Image $image): Collection
    {
        $image->loadMissing('imageAsset');

        return collect([
            $image->src,
            $image->shopify_cdn_url,
            $image->imageAsset?->source_url,
        ])->map(function (?string $url): ?string {
            if (ShopifyMediaPath::isCmsDeliveryUrl($url)) {
                return null;
            }

            return ShopifyMediaPath::canonical($url);
        })->filter()->unique()->values();
    }

    /**
     * @return array{image:?Image, ambiguous:bool, candidate_ids:array<int, int>}
     */
    private function found(Image $image): array
    {
        return ['image' => $image, 'ambiguous' => false, 'candidate_ids' => [(int) $image->id]];
    }

    /**
     * @param  Collection<int, Image>  $images
     * @return array{image:?Image, ambiguous:bool, candidate_ids:array<int, int>}
     */
    private function ambiguous(Collection $images): array
    {
        return [
            'image' => null,
            'ambiguous' => true,
            'candidate_ids' => $images->map(fn (Image $image): int => (int) $image->id)->all(),
        ];
    }

    /**
     * @return array{image:?Image, ambiguous:bool, candidate_ids:array<int, int>}
     */
    private function none(): array
    {
        return ['image' => null, 'ambiguous' => false, 'candidate_ids' => []];
    }
}
