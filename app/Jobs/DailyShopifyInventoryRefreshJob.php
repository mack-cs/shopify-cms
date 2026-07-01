<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\AdminNotification;
use App\Services\AsyncJobStateService;
use App\Services\ProductInventorySyncService;
use App\Services\StackBundleSellabilityService;
use App\Services\StackSellabilityShopifyPushService;
use App\Services\StackSellabilitySlackNotifier;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DailyShopifyInventoryRefreshJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public function __construct(
        public ?int $userId = null,
    ) {
    }

    public function handle(
        ProductInventorySyncService $service,
        StackBundleSellabilityService $stackSellabilityService,
        StackSellabilityShopifyPushService $pushService,
        StackSellabilitySlackNotifier $slackNotifier,
    ): void
    {
        try {
            $summary = [
                'products_checked' => 0,
                'variants_refreshed' => 0,
                'failed' => 0,
                'warnings' => [],
                'failures' => [],
                'stacks_forced_unsellable' => 0,
                'stacks_restored_sellable' => 0,
                'stack_push_queued_variants' => 0,
            ];

            Product::query()
                ->select(['id', 'handle', 'shopify_id'])
                ->where(function ($query): void {
                    $query->whereNotNull('shopify_id')
                        ->where('shopify_id', '!=', '')
                        ->orWhere(function ($handleQuery): void {
                            $handleQuery->whereNotNull('handle')
                                ->where('handle', '!=', '');
                        });
                })
                ->with(['variants' => fn ($query) => $query->orderBy('id')])
                ->orderBy('id')
                ->chunkById(100, function ($products) use ($service, &$summary): void {
                    foreach ($products as $product) {
                        if (!$product instanceof Product) {
                            continue;
                        }

                        $summary['products_checked']++;

                        $variants = $product->variants;
                        if (!$variants || $variants->isEmpty()) {
                            continue;
                        }

                        $result = $service->refreshVariants($variants, $this->userId);

                        $summary['variants_refreshed'] += (int) ($result['refreshed'] ?? 0);
                        $summary['failed'] += (int) ($result['failed'] ?? 0);
                        $summary['warnings'] = array_values(array_unique(array_merge(
                            $summary['warnings'],
                            $result['warnings'] ?? []
                        )));
                        $summary['failures'] = array_values(array_unique(array_merge(
                            $summary['failures'],
                            $result['failures'] ?? []
                        )));
                    }
                });

            $stackSummary = $stackSellabilityService->enforce($this->userId);
            $summary['stacks_forced_unsellable'] = (int) ($stackSummary['forced_unsellable'] ?? 0);
            $summary['stacks_restored_sellable'] = (int) ($stackSummary['restored_sellable'] ?? 0);
            $stackSummary['source'] = 'Daily inventory refresh';
            $stackSummary = $pushService->queuePushForChangedStacks($stackSummary, $this->userId);
            $summary['stack_push_queued_variants'] = (int) ($stackSummary['shopify_push_queued_variants'] ?? 0);
            $slackNotifier->notifyIfChanged($stackSummary);

            if (!$this->userId) {
                return;
            }

            $parts = [
                'Products checked: ' . $summary['products_checked'] . '.',
                'Variants refreshed: ' . $summary['variants_refreshed'] . '.',
            ];

            if ($summary['failed'] > 0) {
                $parts[] = 'Failed: ' . $summary['failed'] . '.';
            }

            if ($summary['stacks_forced_unsellable'] > 0) {
                $parts[] = 'Stacks forced unsellable: ' . $summary['stacks_forced_unsellable'] . '.';
            }

            if ($summary['stacks_restored_sellable'] > 0) {
                $parts[] = 'Stacks restored: ' . $summary['stacks_restored_sellable'] . '.';
            }

            if ($summary['stack_push_queued_variants'] > 0) {
                $parts[] = 'Stack Shopify push variants queued: ' . $summary['stack_push_queued_variants'] . '.';
            }

            if ($summary['warnings'] !== []) {
                $parts[] = 'Warnings: ' . implode(' | ', array_slice($summary['warnings'], 0, 3));
            }

            if ($summary['failures'] !== []) {
                $parts[] = 'Errors: ' . implode(' | ', array_slice($summary['failures'], 0, 2));
            }

            $notification = Notification::make()
                ->title('Daily Shopify inventory refresh complete')
                ->body(implode(' ', $parts));

            if ($summary['failed'] > 0) {
                $notification->warning();
            } else {
                $notification->success();
            }

            AdminNotification::sendToUserId($notification, $this->userId);
        } finally {
            app(AsyncJobStateService::class)->markFinished(AsyncJobStateService::INVENTORY_CHECK);
        }
    }
}
