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

class HandleShopifyInventoryLevelUpdatedJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $uniqueFor = 60;

    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        public string $inventoryItemId,
        public array $payload = [],
        public ?string $webhookId = null,
    ) {
    }

    public function uniqueId(): string
    {
        return 'shopify-inventory-level:' . $this->inventoryItemId;
    }

    public function handle(
        StackBundleSellabilityService $sellabilityService,
        StackSellabilityShopifyPushService $pushService,
        StackSellabilitySlackNotifier $slackNotifier,
    ): void {
        $summary = $sellabilityService->enforceForInventoryItem($this->inventoryItemId, null, [
            'refresh_components' => true,
        ]);

        $summary['source'] = 'Shopify inventory webhook';
        $summary['webhook_id'] = $this->webhookId;
        $summary['inventory_item_id'] = $this->inventoryItemId;
        $summary = $pushService->queuePushForChangedStacks($summary);

        $slackNotifier->notifyIfChanged($summary);
    }
}
