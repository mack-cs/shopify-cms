<?php

namespace App\Jobs\Shopify;

use App\Models\ShopifySyncRun;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class FinalizeShopifyOrdersSync implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(public int $syncRunId)
    {
    }

    public function handle(): void
    {
        ShopifySyncRun::query()
            ->whereKey($this->syncRunId)
            ->update([
                'status' => ShopifySyncRun::STATUS_COMPLETED,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);
    }
}
