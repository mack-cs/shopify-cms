<?php

namespace App\Jobs;

use App\Models\Product;
use App\Services\AdminNotification;
use App\Services\AsyncJobStateService;
use App\Services\ProductInventorySyncService;
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

    public function handle(ProductInventorySyncService $service): void
    {
        try {
            $summary = [
                'products_checked' => 0,
                'variants_refreshed' => 0,
                'failed' => 0,
                'warnings' => [],
                'failures' => [],
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
