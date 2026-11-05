<?php

namespace App\Jobs;

use App\Services\EngravedBeads\EngravedBeadRecalculation;
use App\Services\StackBundleSellabilityService;
use App\Services\StackSellabilityShopifyPushService;
use App\Services\StackSellabilitySlackNotifier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class HandleShopifyInventoryLevelUpdatedJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public int $uniqueFor = 60;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $inventoryItemId,
        public array $payload = [],
        public ?string $webhookId = null,
    ) { $this->onConnection('inventory-events')->onQueue('inventory-events'); }

    public function uniqueId(): string
    {
        return 'shopify-inventory-level:'.$this->inventoryItemId;
    }

    public function handle(
        StackBundleSellabilityService $sellabilityService,
        StackSellabilityShopifyPushService $pushService,
        StackSellabilitySlackNotifier $slackNotifier,
    ): void {
        $lock = \Illuminate\Support\Facades\Cache::lock('inventory-reconcile:'.$this->inventoryItemId, 360);
        if (! $lock->get()) return; // Durable version remains pending for the scheduler.
        try {
        $row = \Illuminate\Support\Facades\DB::table('inventory_reconciliations')->where('inventory_item_id',$this->inventoryItemId)->first();
        $version = $row?->version;
        app(EngravedBeadRecalculation::class)->inventoryItem($this->inventoryItemId);
        $summary = $sellabilityService->enforceForInventoryItem($this->inventoryItemId, null, [
            'refresh_components' => true,
        ]);

        $summary['source'] = 'Shopify inventory webhook';
        $summary['webhook_id'] = $this->webhookId;
        $summary['inventory_item_id'] = $this->inventoryItemId;
        $summary = $pushService->queuePushForChangedStacks($summary);

        $slackNotifier->notifyIfChanged($summary);
        if ($version !== null && (int) ($summary['shopify_component_refresh_failures'] ?? 0) === 0
            && (int) ($summary['skipped_locked_stacks'] ?? 0) === 0) {
            \Illuminate\Support\Facades\DB::table('inventory_reconciliations')->where('id',$row->id)
                ->where('processed_version','<',$version)->update(['processed_version'=>$version,'updated_at'=>now()]);
        }
        } finally { $lock->release(); }
    }
}
