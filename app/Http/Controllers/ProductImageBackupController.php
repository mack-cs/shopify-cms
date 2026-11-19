<?php

namespace App\Http\Controllers;

use App\Models\Image;
use App\Models\ImageAsset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImageBackupController extends Controller
{
    public function __invoke(Image $image, ?string $filename = null): StreamedResponse
    {
        $image->loadMissing('imageAsset');

        $downloadName = trim((string) ($filename ?: $image->backupFilename()));

        // Upload operations pin the immutable backup hash, independent of edits
        // to the image row while Shopify asynchronously fetches the source URL.
        $hash = request()->query('asset');
        $asset = $image->imageAsset;
        if ($hash !== null) {
            abort_unless(is_string($hash) && preg_match('/^[a-f0-9]{64}$/', $hash), 404);
            $operation = DB::table('image_upload_operations')
                ->where('image_id', $image->id)->where('asset_sha256', $hash)->first();
            abort_unless($operation !== null, 404);
            $asset = ImageAsset::find($operation->image_asset_id);
            abort_unless($asset !== null && hash_equals($hash, (string) $asset->sha256), 404);
        }
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
                'Content-Disposition' => 'inline; filename="'.addslashes($downloadName !== '' ? $downloadName : basename($path)).'"',
                'Cache-Control' => 'public, max-age=3600',
            ]
        );
    }
}
