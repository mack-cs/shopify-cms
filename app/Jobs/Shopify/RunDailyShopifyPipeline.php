<?php

namespace App\Jobs\Shopify;

use App\Models\ShopifySyncRun;
use App\Services\Shopify\ShopifySyncWindowService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RunDailyShopifyPipeline implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $timeout = 120;

    public function __construct(
        public ?string $businessDate = null,
        public string $runMode = ShopifySyncRun::RUN_MODE_SCHEDULED,
    ) {
    }

    public function handle(ShopifySyncWindowService $windows): void
    {
        $businessDate = $this->businessDate ?: $windows->yesterdayBusinessDate();
        $window = $windows->forBusinessDate($businessDate);

        if (!$this->hasActiveRun(ShopifySyncRun::DATASET_ORDERS, $window['business_date'])) {
            $ordersRun = ShopifySyncRun::query()->create([
                'dataset' => ShopifySyncRun::DATASET_ORDERS,
                'sync_type' => ShopifySyncRun::SYNC_TYPE_DAILY,
                'run_mode' => $this->runMode,
                'business_date' => $window['business_date'],
                'business_timezone' => $window['timezone'],
                'window_start' => $window['window_start'],
                'window_end' => $window['window_end'],
                'lookback_days' => $window['lookback_days'],
                'status' => ShopifySyncRun::STATUS_PENDING,
                'metadata' => [
                    'reporting_window_start' => $window['reporting_start']->toIso8601String(),
                    'reporting_window_end' => $window['reporting_end']->toIso8601String(),
                ],
            ]);

            StartShopifyOrdersBulkExport::dispatch($ordersRun->id);
        }

        if (!$this->hasActiveRun(ShopifySyncRun::DATASET_INVENTORY, $window['business_date'])) {
            $inventoryRun = ShopifySyncRun::query()->create([
                'dataset' => ShopifySyncRun::DATASET_INVENTORY,
                'sync_type' => ShopifySyncRun::SYNC_TYPE_SNAPSHOT,
                'run_mode' => $this->runMode,
                'business_date' => $window['business_date'],
                'business_timezone' => $window['timezone'],
                'status' => ShopifySyncRun::STATUS_PENDING,
            ]);

            StartShopifyInventoryBulkExport::dispatch($inventoryRun->id);
        }
    }

    private function hasActiveRun(string $dataset, string $businessDate): bool
    {
        return ShopifySyncRun::query()
            ->where('dataset', $dataset)
            ->whereDate('business_date', $businessDate)
            ->whereIn('status', self::activeStatuses())
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    private static function activeStatuses(): array
    {
        return [
            ShopifySyncRun::STATUS_PENDING,
            ShopifySyncRun::STATUS_STARTING,
            ShopifySyncRun::STATUS_RUNNING,
            ShopifySyncRun::STATUS_DOWNLOADING,
            ShopifySyncRun::STATUS_PROCESSING,
        ];
    }
}
