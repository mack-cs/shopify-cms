<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ReportImageUploadOperations extends Command
{
    protected $signature = 'images:upload-reconciliation-report {--output=}';

    protected $description = 'Read-only CSV of unresolved upload operations; never synchronizes, adopts, or authorizes retries';

    public function handle(): int
    {
        $path = $this->option('output') ?: storage_path('app/image-upload-reconciliation.csv');
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            $this->error('Could not open report output.');

            return self::FAILURE;
        }
        try {
            fputcsv($handle, ['operation_id', 'image_id', 'asset_id', 'asset_sha256', 'product_gid', 'status', 'returned_media_id', 'error', 'recommended_action']);
            foreach (DB::table('image_upload_operations')->whereNotNull('active_image_id')->orderBy('created_at')->cursor() as $operation) {
                fputcsv($handle, [$operation->id, $operation->image_id, $operation->image_asset_id, $operation->asset_sha256, $operation->product_gid, $operation->status, $operation->media_id, $operation->error,
                    'Review complete Shopify media and photograph bytes. Reconciliation may confirm a ready file. A retry needs an explicit recorded resolution; this report grants no authorization.']);
            }
        } finally {
            fclose($handle);
        }
        $this->info('Wrote a read-only upload report. No Shopify requests or record changes were made.');

        return self::SUCCESS;
    }
}
