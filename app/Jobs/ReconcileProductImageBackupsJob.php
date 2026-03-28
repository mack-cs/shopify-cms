<?php

namespace App\Jobs;

use App\Models\Image;
use App\Models\Product;
use App\Services\ProductImageBackupService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileProductImageBackupsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function handle(ProductImageBackupService $service): void
    {
        $summary = [
            'products' => 0,
            'processed' => 0,
            'backed_up' => 0,
            'reused' => 0,
            'missing_source' => 0,
            'failed' => 0,
            'restored_candidates' => 0,
            'failures' => [],
        ];

        Product::query()
            ->whereHas('allImages', function ($query): void {
                $query->where('sync_state', '!=', Image::SYNC_STATE_LOCAL_DELETED)
                    ->where(function ($backupQuery): void {
                        $backupQuery
                            ->whereNull('image_asset_id')
                            ->orWhereIn('backup_status', [
                                Image::BACKUP_STATUS_PENDING,
                                Image::BACKUP_STATUS_FAILED,
                                Image::BACKUP_STATUS_MISSING_SOURCE,
                            ])
                            ->orWhere('sync_state', Image::SYNC_STATE_REMOTE_DELETED);
                    });
            })
            ->orderBy('id')
            ->chunkById(100, function ($products) use ($service, &$summary): void {
                $result = $service->backupProducts($products);

                $summary['products'] += $result['products'];
                $summary['processed'] += $result['processed'];
                $summary['backed_up'] += $result['backed_up'];
                $summary['reused'] += $result['reused'];
                $summary['missing_source'] += $result['missing_source'];
                $summary['failed'] += $result['failed'];
                $summary['restored_candidates'] += $result['restored_candidates'];
                $summary['failures'] = array_merge($summary['failures'], $result['failures']);
            });

        logger()->info('Product image backup reconciliation complete.', $summary);
    }
}
