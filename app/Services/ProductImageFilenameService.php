<?php

namespace App\Services;

use App\Models\Image;
use App\Models\Product;
use Illuminate\Support\Str;

class ProductImageFilenameService
{
    public function generateForImage(Image $image, ?string $title = null, ?int $position = null): string
    {
        $base = Str::slug(trim((string) ($title ?? $image->product?->title ?? '')));
        if ($base === '') {
            $base = 'product-image';
        }

        $index = max(1, (int) ($position ?? $image->position ?? 1));
        $extension = $this->resolveExtension($image);

        return sprintf('%s-%02d.%s', $base, $index, $extension);
    }

    public function generateForProductHandle(Image $image, Product $product, ?int $position = null): string
    {
        $base = Str::slug(trim((string) $product->handle));
        if ($base === '') {
            $base = Str::slug(trim((string) $product->title));
        }
        if ($base === '') {
            $base = 'product-image';
        }

        $index = max(1, (int) ($position ?? $image->position ?? 1));
        $extension = $this->resolveExtension($image);

        return sprintf('%s-%d.%s', $base, $index, $extension);
    }

    public function assignFromCurrentTitle(Product $product, bool $manual = false): int
    {
        $product->loadMissing([
            'allImages' => fn ($query) => $query
                ->where('sync_state', '!=', Image::SYNC_STATE_LOCAL_DELETED)
                ->orderByRaw('CASE WHEN position IS NULL THEN 1 ELSE 0 END')
                ->orderBy('position')
                ->orderBy('id'),
        ]);

        $updated = 0;
        $position = 0;

        foreach ($product->allImages as $image) {
            if (!$image instanceof Image) {
                continue;
            }

            if (!$image->hasManagedSource()) {
                continue;
            }

            $position++;
            $filename = $this->generateForImage($image, $product->title, $position);
            $mode = $manual ? Image::FILENAME_MODE_MANUAL : Image::FILENAME_MODE_AUTO;

            if (
                trim((string) $image->approved_filename) === $filename
                && trim((string) $image->filename_mode) === $mode
            ) {
                continue;
            }

            Image::withoutEvents(function () use ($image, $filename, $mode): void {
                $image->forceFill([
                    'approved_filename' => $filename,
                    'filename_mode' => $mode,
                    'needs_shopify_image_sync' => true,
                    'shopify_image_sync_error' => null,
                ])->save();
            });

            $updated++;
        }

        return $updated;
    }

    private function resolveExtension(Image $image): string
    {
        $candidates = [
            $image->imageAsset?->extension,
            pathinfo((string) $image->image_path, PATHINFO_EXTENSION),
            pathinfo((string) parse_url((string) $image->src, PHP_URL_PATH), PATHINFO_EXTENSION),
        ];

        foreach ($candidates as $candidate) {
            $extension = strtolower(trim((string) $candidate));
            $extension = preg_replace('/[^a-z0-9]+/', '', $extension) ?? $extension;
            if ($extension !== '') {
                return $extension;
            }
        }

        return 'jpg';
    }
}
