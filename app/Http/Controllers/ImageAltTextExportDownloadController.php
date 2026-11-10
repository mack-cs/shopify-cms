<?php

namespace App\Http\Controllers;

use App\Models\ImageAltTextExport;
use App\Services\ImageAltTextExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImageAltTextExportDownloadController
{
    public function __invoke(Request $request, ImageAltTextExport $export, ImageAltTextExportService $exports): StreamedResponse
    {
        $exports->authorize($request->user(), $export);
        abort_unless($export->status === 'completed' && $export->path, 409, 'This export is not ready.');
        $disk = Storage::disk($export->disk);
        abort_unless($disk->exists($export->path), 404, 'The export file is no longer available.');

        return $disk->download($export->path, 'image-alt-text-'.$export->created_at->format('Y-m-d').($export->include_images ? '.zip' : '.csv'), [
            'Content-Type' => $export->include_images ? 'application/zip' : 'text/csv; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
