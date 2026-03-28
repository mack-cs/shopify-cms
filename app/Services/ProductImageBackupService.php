<?php

namespace App\Services;

use App\Models\Image;
use App\Models\ImageAsset;
use App\Models\Product;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProductImageBackupService
{
    /**
     * @param Collection<int, Product> $products
     * @return array{
     *   products:int,
     *   processed:int,
     *   backed_up:int,
     *   reused:int,
     *   missing_source:int,
     *   failed:int,
     *   restored_candidates:int,
     *   failures:array<int, array{image_id:int, product_id:int, message:string}>
     * }
     */
    public function backupProducts(Collection $products): array
    {
        $summary = [
            'products' => $products->count(),
            'processed' => 0,
            'backed_up' => 0,
            'reused' => 0,
            'missing_source' => 0,
            'failed' => 0,
            'restored_candidates' => 0,
            'failures' => [],
        ];

        foreach ($products as $product) {
            if (!$product instanceof Product) {
                continue;
            }

            $product->loadMissing([
                'allImages' => fn ($query) => $query->orderByRaw('CASE WHEN position IS NULL THEN 1 ELSE 0 END')
                    ->orderBy('position')
                    ->orderBy('id'),
            ]);

            foreach ($product->allImages as $image) {
                if (!$image instanceof Image || $image->sync_state === Image::SYNC_STATE_LOCAL_DELETED) {
                    continue;
                }

                $summary['processed']++;

                if ($image->sync_state === Image::SYNC_STATE_REMOTE_DELETED) {
                    $summary['restored_candidates']++;
                }

                try {
                    $result = $this->backupImage($image);

                    if ($result === 'backed_up') {
                        $summary['backed_up']++;
                        continue;
                    }

                    if ($result === 'reused') {
                        $summary['reused']++;
                        continue;
                    }

                    if ($result === 'missing_source') {
                        $summary['missing_source']++;
                    }
                } catch (\Throwable $e) {
                    $summary['failed']++;
                    $summary['failures'][] = [
                        'image_id' => $image->id,
                        'product_id' => $product->id,
                        'message' => $e->getMessage(),
                    ];

                    $this->markImageFailed($image, $e->getMessage());

                    logger()->error('Product image backup failed.', [
                        'image_id' => $image->id,
                        'product_id' => $product->id,
                        'message' => $e->getMessage(),
                    ]);
                }
            }
        }

        return $summary;
    }

    /**
     * @return 'backed_up'|'reused'|'missing_source'
     */
    public function backupImage(Image $image): string
    {
        $image->loadMissing(['product', 'imageAsset']);

        if (
            $image->imageAsset
            && $image->imageAsset->isAvailable()
            && $image->backup_status === Image::BACKUP_STATUS_BACKED_UP
        ) {
            $this->completeImageBackup($image, $image->imageAsset);
            return 'reused';
        }

        $localPath = $this->normalizePath($image->image_path);
        if ($localPath !== null) {
            $disk = Storage::disk('public');
            if ($disk->exists($localPath)) {
                $bytes = $disk->get($localPath);
                $extension = $this->extensionFromName($localPath);
                $mimeType = $this->mimeTypeFromPath($disk->path($localPath)) ?? $this->mimeTypeFromExtension($extension);
                $originalFilename = basename($localPath);

                $asset = $this->storeBytesAsAsset(
                    bytes: $bytes,
                    extension: $extension,
                    mimeType: $mimeType,
                    originalFilename: $originalFilename,
                    sourceUrl: $this->normalizeUrl($image->src),
                );

                $alreadyLinked = (int) $image->image_asset_id === (int) $asset->id;
                $this->completeImageBackup($image, $asset);

                return $alreadyLinked ? 'reused' : 'backed_up';
            }
        }

        $sourceUrl = $this->normalizeUrl($image->src);
        if ($sourceUrl === null) {
            $this->markImageMissingSource($image);
            return 'missing_source';
        }

        $existingAsset = Image::query()
            ->whereKeyNot($image->id)
            ->where('src', $sourceUrl)
            ->whereNotNull('image_asset_id')
            ->with('imageAsset')
            ->orderByDesc('backup_completed_at')
            ->first()?->imageAsset;

        if ($existingAsset && $this->ensureAssetPresent($existingAsset)) {
            $this->completeImageBackup($image, $existingAsset);
            return 'reused';
        }

        $response = Http::timeout(120)
            ->retry(3, 1500, throw: false)
            ->accept('image/*')
            ->get($sourceUrl);

        if (!$response->successful()) {
            $this->markImageFailed($image, "Download failed with status {$response->status()}.");
            throw new \RuntimeException("Image download failed with status {$response->status()}.");
        }

        $bytes = $response->body();
        if ($bytes === '') {
            $this->markImageFailed($image, 'Downloaded image response was empty.');
            throw new \RuntimeException('Downloaded image response was empty.');
        }

        $extension = $this->detectExtension($image, $sourceUrl, $response->header('Content-Type'));
        $mimeType = $this->normalizeMimeType($response->header('Content-Type')) ?? $this->mimeTypeFromExtension($extension);
        $originalFilename = $this->filenameFromUrl($sourceUrl);

        $asset = $this->storeBytesAsAsset(
            bytes: $bytes,
            extension: $extension,
            mimeType: $mimeType,
            originalFilename: $originalFilename,
            sourceUrl: $sourceUrl,
        );

        $this->completeImageBackup($image, $asset);

        return 'backed_up';
    }

    private function storeBytesAsAsset(
        string $bytes,
        ?string $extension,
        ?string $mimeType,
        ?string $originalFilename,
        ?string $sourceUrl,
    ): ImageAsset {
        $sha256 = hash('sha256', $bytes);
        $normalizedExtension = $this->normalizeExtension($extension, $mimeType);

        $asset = ImageAsset::query()->where('sha256', $sha256)->first();
        if ($asset) {
            $this->ensureAssetPresent($asset, $bytes, $normalizedExtension, $mimeType, $originalFilename, $sourceUrl);
            return $asset->fresh();
        }

        $storagePath = $this->storagePathForHash($sha256, $normalizedExtension);
        Storage::disk('public')->put($storagePath, $bytes);

        return ImageAsset::create([
            'sha256' => $sha256,
            'storage_disk' => 'public',
            'storage_path' => $storagePath,
            'original_filename' => $originalFilename,
            'source_url' => $sourceUrl,
            'mime_type' => $mimeType,
            'extension' => $normalizedExtension,
            'file_size' => strlen($bytes),
            'downloaded_at' => now(),
            'last_verified_at' => now(),
            'missing_at' => null,
            'status' => ImageAsset::STATUS_AVAILABLE,
        ]);
    }

    private function ensureAssetPresent(
        ImageAsset $asset,
        ?string $bytes = null,
        ?string $extension = null,
        ?string $mimeType = null,
        ?string $originalFilename = null,
        ?string $sourceUrl = null,
    ): bool {
        $disk = Storage::disk($asset->storage_disk ?: 'public');
        $path = trim((string) $asset->storage_path);

        if ($path !== '' && $disk->exists($path)) {
            ImageAsset::withoutEvents(function () use ($asset, $originalFilename, $sourceUrl): void {
                $asset->forceFill([
                    'status' => ImageAsset::STATUS_AVAILABLE,
                    'missing_at' => null,
                    'last_verified_at' => now(),
                    'original_filename' => $asset->original_filename ?: $originalFilename,
                    'source_url' => $asset->source_url ?: $sourceUrl,
                ])->save();
            });

            return true;
        }

        if ($bytes === null) {
            ImageAsset::withoutEvents(function () use ($asset): void {
                $asset->forceFill([
                    'status' => ImageAsset::STATUS_MISSING,
                    'missing_at' => now(),
                ])->save();
            });

            return false;
        }

        $normalizedExtension = $this->normalizeExtension($extension, $mimeType);
        $storagePath = $path !== '' ? $path : $this->storagePathForHash($asset->sha256, $normalizedExtension);
        $disk->put($storagePath, $bytes);

        ImageAsset::withoutEvents(function () use ($asset, $storagePath, $normalizedExtension, $mimeType, $originalFilename, $sourceUrl, $bytes): void {
            $asset->forceFill([
                'storage_disk' => $asset->storage_disk ?: 'public',
                'storage_path' => $storagePath,
                'extension' => $normalizedExtension,
                'mime_type' => $mimeType ?: $asset->mime_type,
                'original_filename' => $asset->original_filename ?: $originalFilename,
                'source_url' => $asset->source_url ?: $sourceUrl,
                'file_size' => strlen($bytes),
                'status' => ImageAsset::STATUS_AVAILABLE,
                'downloaded_at' => $asset->downloaded_at ?: now(),
                'last_verified_at' => now(),
                'missing_at' => null,
            ])->save();
        });

        return true;
    }

    private function completeImageBackup(Image $image, ImageAsset $asset): void
    {
        Image::withoutEvents(function () use ($image, $asset): void {
            $image->forceFill([
                'image_asset_id' => $asset->id,
                'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
                'backup_completed_at' => $image->backup_completed_at ?: now(),
                'backup_error' => null,
            ])->save();
        });
    }

    private function markImageMissingSource(Image $image): void
    {
        Image::withoutEvents(function () use ($image): void {
            $image->forceFill([
                'backup_status' => Image::BACKUP_STATUS_MISSING_SOURCE,
                'backup_completed_at' => null,
                'backup_error' => 'Image has no local file and no source URL to download.',
            ])->save();
        });
    }

    private function markImageFailed(Image $image, string $message): void
    {
        Image::withoutEvents(function () use ($image, $message): void {
            $image->forceFill([
                'backup_status' => Image::BACKUP_STATUS_FAILED,
                'backup_completed_at' => null,
                'backup_error' => Str::limit($message, 1000, ''),
            ])->save();
        });
    }

    private function storagePathForHash(string $sha256, ?string $extension): string
    {
        $prefixA = substr($sha256, 0, 2);
        $prefixB = substr($sha256, 2, 2);
        $suffix = $extension ? ".{$extension}" : '';

        return "product-image-assets/{$prefixA}/{$prefixB}/{$sha256}{$suffix}";
    }

    private function normalizeUrl(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (str_starts_with($trimmed, '//')) {
            return 'https:' . $trimmed;
        }

        return $trimmed;
    }

    private function normalizePath(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed !== '' ? $trimmed : null;
    }

    private function detectExtension(Image $image, string $sourceUrl, ?string $contentType): ?string
    {
        return $this->normalizeExtension(
            $this->extensionFromName($this->filenameFromUrl($sourceUrl) ?? $image->image_path),
            $contentType
        );
    }

    private function normalizeExtension(?string $extension, ?string $mimeType = null): ?string
    {
        $normalized = strtolower(trim((string) $extension));
        $normalized = preg_replace('/[^a-z0-9]+/', '', $normalized) ?? $normalized;

        if ($normalized !== '') {
            return $normalized;
        }

        return match ($this->normalizeMimeType($mimeType)) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            'image/avif' => 'avif',
            'image/svg+xml' => 'svg',
            default => null,
        };
    }

    private function normalizeMimeType(?string $mimeType): ?string
    {
        $normalized = strtolower(trim((string) $mimeType));
        if ($normalized === '') {
            return null;
        }

        $parts = explode(';', $normalized);
        $value = trim($parts[0] ?? '');

        return $value !== '' ? $value : null;
    }

    private function filenameFromUrl(?string $url): ?string
    {
        $path = parse_url((string) $url, PHP_URL_PATH);
        $name = is_string($path) ? basename($path) : '';

        return $name !== '' ? $name : null;
    }

    private function extensionFromName(?string $value): ?string
    {
        $name = trim((string) $value);
        if ($name === '') {
            return null;
        }

        $extension = pathinfo($name, PATHINFO_EXTENSION);
        $extension = strtolower(trim((string) $extension));

        return $extension !== '' ? $extension : null;
    }

    private function mimeTypeFromPath(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }

        $mimeType = mime_content_type($path);
        return $this->normalizeMimeType(is_string($mimeType) ? $mimeType : null);
    }

    private function mimeTypeFromExtension(?string $extension): ?string
    {
        return match ($this->normalizeExtension($extension)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            'gif' => 'image/gif',
            'avif' => 'image/avif',
            'svg' => 'image/svg+xml',
            default => null,
        };
    }
}
