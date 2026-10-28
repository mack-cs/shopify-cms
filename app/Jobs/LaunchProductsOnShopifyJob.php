<?php

namespace App\Jobs;

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
        $this->onQueue((string) config('product_launch.queue', 'default'));
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

        $launch->run($run);
    }
}
