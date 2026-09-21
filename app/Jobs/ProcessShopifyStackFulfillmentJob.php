<?php

namespace App\Jobs;

use App\Models\ShopifyFulfillment;
use App\Services\Shopify\StackFulfillmentInventoryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class ProcessShopifyStackFulfillmentJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 300;

    public int $uniqueFor = 3600;

    /** @var array<int, int> */
    public array $backoff = [30, 120, 600, 1800];

    public function __construct(public readonly int $fulfillmentId) {}

    public function uniqueId(): string
    {
        return 'shopify-stack-fulfillment:'.$this->fulfillmentId;
    }

    public function handle(StackFulfillmentInventoryService $service): void
    {
        $fulfillment = ShopifyFulfillment::query()->findOrFail($this->fulfillmentId);
        $service->process($fulfillment);
    }
}
