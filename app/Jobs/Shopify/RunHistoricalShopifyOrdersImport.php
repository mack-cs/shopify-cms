<?php

namespace App\Jobs\Shopify;

use App\Models\ShopifySyncRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunHistoricalShopifyOrdersImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public string $runMode = ShopifySyncRun::RUN_MODE_MANUAL,
    ) {
    }

    public function handle(): void
    {
        $run = ShopifySyncRun::query()->create([
            'dataset' => ShopifySyncRun::DATASET_ORDERS,
            'sync_type' => ShopifySyncRun::SYNC_TYPE_FULL,
            'run_mode' => $this->runMode,
            'business_timezone' => (string) config('shopify_sync.timezone', 'Africa/Johannesburg'),
            'status' => ShopifySyncRun::STATUS_PENDING,
        ]);

        StartShopifyOrdersBulkExport::dispatch($run->id);
    }
}
