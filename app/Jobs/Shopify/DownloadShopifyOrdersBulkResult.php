<?php

namespace App\Jobs\Shopify;

use App\Models\ShopifySyncRun;
use App\Services\Shopify\ShopifyBulkFileDownloader;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DownloadShopifyOrdersBulkResult implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [60, 120, 300, 600];
    public int $timeout = 1200;

    public function __construct(
        public int $syncRunId,
        public string $downloadUrl,
    ) {
    }

    public function handle(ShopifyBulkFileDownloader $downloader): void
    {
        $run = ShopifySyncRun::query()->findOrFail($this->syncRunId);

        try {
            $run->forceFill(['status' => ShopifySyncRun::STATUS_DOWNLOADING])->save();
            $archive = $downloader->downloadAndArchive($run, $this->downloadUrl);

            $run->forceFill([
                'raw_s3_bucket' => config('filesystems.disks.' . config('shopify_sync.s3.disk', 's3') . '.bucket'),
                'raw_s3_key' => $archive['raw_s3_key'],
                'metadata_s3_key' => $archive['metadata_s3_key'],
                'file_size' => $archive['file_size'],
            ])->save();

            ProcessShopifyOrdersJsonl::dispatch($run->id);
        } catch (\Throwable $exception) {
            $run->fail($exception->getMessage());
            throw $exception;
        }
    }
}
