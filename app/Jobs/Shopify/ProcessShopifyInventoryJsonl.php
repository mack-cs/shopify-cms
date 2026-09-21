<?php

namespace App\Jobs\Shopify;

use App\Models\ShopifySyncRun;
use App\Services\Shopify\ShopifyBulkFileDownloader;
use App\Services\Shopify\ShopifyInventoryJsonlProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessShopifyInventoryJsonl implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [60, 120, 300, 600];
    public int $timeout = 1800;

    public function __construct(public int $syncRunId)
    {
    }

    public function handle(ShopifyBulkFileDownloader $downloader, ShopifyInventoryJsonlProcessor $processor): void
    {
        $run = ShopifySyncRun::query()->findOrFail($this->syncRunId);
        $path = null;

        try {
            $run->forceFill([
                'status' => ShopifySyncRun::STATUS_PROCESSING,
                'processing_started_at' => $run->processing_started_at ?? now(),
            ])->save();

            $path = $downloader->archiveToLocalTemp($run);
            $processor->process($path, $run);

            FinalizeShopifyInventorySync::dispatch($run->id);
        } catch (\Throwable $exception) {
            $run->fail($exception->getMessage());
            throw $exception;
        } finally {
            if (is_string($path)) {
                @unlink($path);
            }
        }
    }
}
