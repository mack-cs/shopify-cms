<?php

namespace App\Jobs;

use App\Models\ProductLaunchItem;
use App\Models\ProductLaunchRun;
use App\Services\Shopify\ProductLaunchService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class LaunchProductsOnShopifyJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct(public readonly int $runId)
    {
        $this->onConnection('product-launch')->onQueue((string) config('product_launch.queue', 'product-launch'));
    }

    public function uniqueId(): string
    {
        return 'product-launch:'.$this->runId;
    }

    public function handle(ProductLaunchService $launch): void
    {
        $run = ProductLaunchRun::query()->find($this->runId);
        if (! $run instanceof ProductLaunchRun || $run->isFinished()) {
            return;
        }

        $result = $launch->run($run);
        if ($result->items()->where('outcome', ProductLaunchItem::OUTCOME_FAILED)->exists()) {
            $result->update(['status' => ProductLaunchRun::STATUS_QUEUED, 'finished_at' => null]);
            throw new \RuntimeException('Some launch items failed; retrying the same persisted operations.');
        }
    }

    public function failed(\Throwable $exception): void
    {
        ProductLaunchRun::query()->whereKey($this->runId)->update([
            'status' => ProductLaunchRun::STATUS_COMPLETED_WITH_FAILURES,
            'finished_at' => now(), 'error_message' => $exception->getMessage(),
        ]);
    }
}
