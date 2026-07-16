<?php

namespace App\Jobs\Shopify;

use App\Models\ShopifySyncRun;
use App\Services\Shopify\ShopifyDemandCalculator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RecalculateAffectedSkuDemand implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;
    public array $backoff = [60, 120, 300, 600];
    public int $timeout = 1200;

    public function __construct(public int $syncRunId)
    {
    }

    public function handle(ShopifyDemandCalculator $calculator): void
    {
        $run = ShopifySyncRun::query()->findOrFail($this->syncRunId);

        try {
            $summary = $calculator->recalculateForRun($run);
            $run->forceFill([
                'metadata' => array_merge($run->metadata ?? [], [
                    'demand_recalculated_sku_dates' => $summary['sku_dates'],
                ]),
            ])->save();

            FinalizeShopifyOrdersSync::dispatch($run->id);
        } catch (\Throwable $exception) {
            $run->fail($exception->getMessage());
            throw $exception;
        }
    }
}
