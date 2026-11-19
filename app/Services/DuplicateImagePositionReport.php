<?php

namespace App\Services;

use App\Models\Image;
use Illuminate\Support\Collection;

/**
 * Classifies active duplicate image positions. It never writes image rows.
 */
final class DuplicateImagePositionReport
{
    /**
     * @return array<int, array<string, string>>
     */
    public function rows(): array
    {
        return Image::query()
            ->with(['product', 'imageAsset'])
            ->whereNotNull('position')
            ->whereNotIn('sync_state', [
                Image::SYNC_STATE_LOCAL_DELETED,
                Image::SYNC_STATE_REMOTE_DELETED,
            ])
            ->where(function ($query): void {
                $query->whereNull('is_duplicate_hidden')
                    ->orWhere('is_duplicate_hidden', false);
            })
            ->orderBy('product_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->groupBy(fn (Image $image): string => $image->product_id.'|'.$image->position)
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->map(fn (Collection $group): array => $this->classify($group))
            ->values()
            ->all();
    }

    /**
     * @param  array<int, array<string, string>>  $rows
     */
    public function writeCsv(string $path, array $rows): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Could not write the duplicate image report.');
        }

        fputcsv($handle, [
            'product',
            'position',
            'keep_row_id',
            'proposed_hide_row_id',
            'canonical_url_comparison',
            'asset_hash_comparison',
            'shopify_id',
            'alt_text_status',
            'proposed_action',
        ]);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['product'],
                $row['position'],
                $row['keep_row_id'],
                $row['proposed_hide_row_id'],
                $row['canonical_url_comparison'],
                $row['asset_hash_comparison'],
                $row['shopify_id'],
                $row['alt_text_status'],
                $row['proposed_action'],
            ]);
        }

        fclose($handle);
    }

    /**
     * @param  Collection<int, Image>  $group
     * @return array<string, string>
     */
    private function classify(Collection $group): array
    {
        $group = $group->sortBy('id')->values();
        $paths = $group->map(fn (Image $image): ?string => $this->path($image));
        $hashes = $group->map(fn (Image $image): ?string => $this->hash($image));
        $presentPaths = $paths->filter()->unique()->values();
        $presentHashes = $hashes->filter()->unique()->values();
        $samePath = $paths->every(fn (?string $path): bool => $path !== null) && $presentPaths->count() === 1;
        $sameHash = $hashes->every(fn (?string $hash): bool => $hash !== null) && $presentHashes->count() === 1;
        $withShopifyId = $group->filter(fn (Image $image): bool => filled(trim((string) $image->shopify_id)))->values();

        $keep = null;
        $hide = collect();
        $action = 'review: the photographs are not the same image, so nothing is hidden';

        if ($group->contains(fn (Image $image): bool => $image->sync_state === Image::SYNC_STATE_LOCAL_NEW)) {
            $action = 'review: a local_new image shares this position and must not be hidden';
        } elseif ($withShopifyId->count() !== 1) {
            $action = 'review: this position does not have exactly one Shopify id';
        } elseif ($presentHashes->count() > 1 || (! $samePath && ! $sameHash)) {
            $action = 'review: canonical URL and asset hash do not identify one photograph';
        } elseif ($samePath || $sameHash) {
            $keep = $withShopifyId->first();
            $hide = $group->reject(fn (Image $image): bool => $image->id === $keep?->id)->values();
            $action = 'hide_unlinked_duplicate';
            $action .= $this->altTransferNote($keep, $hide);
        }

        $product = $group->first()?->product;

        return [
            'product' => trim((string) ($product?->handle ?? $product?->title ?? $group->first()?->product_id)),
            'position' => (string) $group->first()?->position,
            'keep_row_id' => $keep ? (string) $keep->id : '',
            'proposed_hide_row_id' => $hide->pluck('id')->implode('|'),
            'canonical_url_comparison' => $this->comparisonLabel($paths, $samePath),
            'asset_hash_comparison' => $this->comparisonLabel($hashes, $sameHash),
            'shopify_id' => $group->map(fn (Image $image): string => $image->id.':'.(trim((string) $image->shopify_id) !== '' ? $image->shopify_id : 'none'))->implode('; '),
            'alt_text_status' => $group->map(fn (Image $image): string => $image->id.':'.(trim((string) $image->alt_text_status) !== '' ? $image->alt_text_status : 'none'))->implode('; '),
            'proposed_action' => $action,
        ];
    }

    private function path(Image $image): ?string
    {
        foreach ([$image->src, $image->shopify_cdn_url, $image->imageAsset?->source_url] as $candidate) {
            if (ShopifyMediaPath::isCmsDeliveryUrl($candidate)) {
                continue;
            }
            $path = ShopifyMediaPath::canonical(is_string($candidate) ? $candidate : null);
            if ($path !== null) {
                return $path;
            }
        }

        return null;
    }

    private function hash(Image $image): ?string
    {
        $hash = strtolower(trim((string) ($image->imageAsset?->sha256 ?? '')));

        return $hash === '' ? null : $hash;
    }

    /**
     * @param  Collection<int, Image>  $hide
     */
    private function altTransferNote(?Image $keep, Collection $hide): string
    {
        if (! $keep instanceof Image) {
            return '';
        }

        $protected = $hide->filter(function (Image $image): bool {
            return in_array($image->alt_text_status, [
                Image::ALT_PENDING_REVIEW,
                Image::ALT_APPROVED,
                Image::ALT_PUBLISHED,
                Image::ALT_FAILED,
                Image::ALT_AWAITING_CONFIRMATION,
            ], true) && trim((string) $image->alt_text) !== '';
        });

        if ($protected->isEmpty()) {
            return '';
        }

        $ids = $protected->pluck('id')->implode(', ');

        return " Retain alt_text on image {$ids}. Any copy alt_text proposal onto row {$keep->id} requires separate destination approval and an explicit cleanup decision. Approval snapshots stay keyed by image_id and must not be rewritten or deleted.";
    }

    /**
     * @param  Collection<int, ?string>  $values
     */
    private function comparisonLabel(Collection $values, bool $same): string
    {
        if ($same) {
            return 'same';
        }

        $present = $values->filter()->unique();
        if ($present->count() > 1) {
            return 'different';
        }

        return 'incomplete';
    }
}
