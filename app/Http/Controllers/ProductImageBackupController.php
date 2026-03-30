<?php

namespace App\Http\Controllers;

use App\Models\Image;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImageBackupController extends Controller
{
    public function __invoke(Image $image, ?string $filename = null): StreamedResponse
    {
        $image->loadMissing('imageAsset');

        $downloadName = trim((string) ($filename ?: $image->backupFilename()));

        $asset = $image->imageAsset;
        if ($asset && $asset->isAvailable()) {
            return $this->streamDiskResponse(
                $asset->storage_disk,
                $asset->storage_path,
                $downloadName
            );
        }

        $localPath = trim((string) $image->image_path);
        if ($localPath !== '' && Storage::disk('public')->exists($localPath)) {
            return $this->streamDiskResponse('public', $localPath, $downloadName);
        }

        abort(404);
    }

    private function streamDiskResponse(string $disk, string $path, string $downloadName): StreamedResponse
    {
        return Storage::disk($disk)->response(
            $path,
            $downloadName !== '' ? $downloadName : null,
            [
                'Content-Disposition' => 'inline; filename="' . addslashes($downloadName !== '' ? $downloadName : basename($path)) . '"',
                'Cache-Control' => 'public, max-age=3600',
            ]
        );
    }
}
