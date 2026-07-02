<?php

namespace App\Jobs;

use App\Services\StackBundleSellabilityService;
use App\Services\StackSellabilityShopifyPushService;
use App\Services\StackSellabilitySlackNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class HandleShopifyProductUpdatedJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $uniqueFor = 60;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $shopifyProductId,
        public ?string $handle = null,
        public array $payload = [],
        public ?string $webhookId = null,
    ) {
    }

    public function uniqueId(): string
    {
        $key = trim($this->shopifyProductId) !== ''
            ? $this->shopifyProductId
            : (string) $this->handle;

        return 'shopify-product-update:' . $key;
    }

    public function handle(
        StackBundleSellabilityService $sellabilityService,
        StackSellabilityShopifyPushService $pushService,
        StackSellabilitySlackNotifier $slackNotifier,
    ): void {
        $summary = $sellabilityService->enforceForProductUpdate(
            $this->shopifyProductId,
            $this->handle,
            null,
            ['refresh_product' => true]
        );

        $summary['source'] = 'Shopify product webhook';
        $summary['webhook_id'] = $this->webhookId;
        $summary['shopify_product_id'] = $this->shopifyProductId;
        $summary['handle'] = $this->handle;
        $summary = $pushService->queuePushForChangedStacks($summary);

        $slackNotifier->notifyIfChanged($summary);
    }
}
