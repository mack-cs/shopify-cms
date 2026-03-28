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

        $asset = $image->imageAsset;
        if (!$asset || !$asset->isAvailable()) {
            abort(404);
        }

        $downloadName = trim((string) ($filename ?: $image->backupFilename()));

        return Storage::disk($asset->storage_disk)->response(
            $asset->storage_path,
            $downloadName !== '' ? $downloadName : null,
            [
                'Content-Disposition' => 'inline; filename="' . addslashes($downloadName !== '' ? $downloadName : basename($asset->storage_path)) . '"',
                'Cache-Control' => 'public, max-age=3600',
            ]
        );
    }
}
