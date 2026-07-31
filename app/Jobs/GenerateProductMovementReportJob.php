<?php

namespace App\Jobs;

use App\Models\ProductMovementReportRun;
use App\Models\Product;
use App\Services\AdminNotification;
use App\Services\ProductInventorySyncService;
use App\Services\ProductMovementReportService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

final class GenerateProductMovementReportJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public function __construct(
        public readonly int $runId,
    ) {
    }

    public function handle(
        ProductMovementReportService $reports,
        ProductInventorySyncService $inventorySync,
    ): void
    {
        $run = ProductMovementReportRun::query()->find($this->runId);
        if (!$run instanceof ProductMovementReportRun) {
            return;
        }

        try {
            $refreshSummary = $this->refreshCurrentShopifyData($inventorySync, $run);
            $run->forceFill([
                'settings' => array_merge((array) $run->settings, [
                    'shopify_refresh' => $refreshSummary,
                ]),
            ])->save();

            $run = $reports->generate($run);

            AdminNotification::sendToUserId(
                Notification::make()
                    ->title('Product movement report ready')
                    ->body("Generated {$run->row_count} variant rows for {$run->analysis_start_date->toDateString()} to {$run->analysis_end_date->toDateString()}.")
                    ->success(),
                $run->requested_by,
            );
        } catch (Throwable $exception) {
            $run->forceFill([
                'status' => ProductMovementReportRun::STATUS_FAILED,
                'failure_message' => $exception->getMessage(),
                'completed_at' => now(),
            ])->save();

            AdminNotification::sendToUserId(
                Notification::make()
                    ->title('Product movement report failed')
                    ->body($exception->getMessage())
                    ->danger(),
                $run->requested_by,
            );

            throw $exception;
        }
    }

    /**
     * @return array{
     *   products_checked:int,
     *   products_failed:int,
     *   variants_refreshed:int,
     *   failed_product_ids:array<int,int>
     * }
     */
    private function refreshCurrentShopifyData(
        ProductInventorySyncService $inventorySync,
        ProductMovementReportRun $run,
    ): array {
        $summary = [
            'products_checked' => 0,
            'products_failed' => 0,
            'variants_refreshed' => 0,
            'failed_product_ids' => [],
        ];

        Product::query()
            ->where(function ($query): void {
                $query->whereNotNull('shopify_id')
                    ->where('shopify_id', '!=', '')
                    ->orWhere(function ($handleQuery): void {
                        $handleQuery->whereNotNull('handle')->where('handle', '!=', '');
                    });
            })
            ->orderBy('id')
            ->chunkById(100, function ($products) use ($inventorySync, $run, &$summary): void {
                foreach ($products as $product) {
                    if (!$product instanceof Product) {
                        continue;
                    }

                    $summary['products_checked']++;
                    $result = $inventorySync->refreshProduct($product, $run->requested_by);
                    $summary['variants_refreshed'] += (int) ($result['refreshed'] ?? 0);
                    if ((int) ($result['failed'] ?? 0) > 0) {
                        $summary['products_failed']++;
                        $summary['failed_product_ids'][] = (int) $product->id;
                    }
                }
            });

        return $summary;
    }
}
