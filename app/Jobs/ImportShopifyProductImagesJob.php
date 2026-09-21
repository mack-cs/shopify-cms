<?php

namespace App\Jobs;

use App\Jobs\RebuildShopifyStackImagesJob;
use App\Models\ShopifyImageImportBatch;
use App\Services\AdminNotification;
use App\Services\ShopifyImageImportService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ImportShopifyProductImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 2;

    public function __construct(
        public int $batchId,
        public ?int $userId = null,
    ) {}

    public function handle(ShopifyImageImportService $service): void
    {
        $batch = ShopifyImageImportBatch::query()->find($this->batchId);
        if (!$batch instanceof ShopifyImageImportBatch) {
            return;
        }

        $batch->forceFill([
            'status' => ShopifyImageImportBatch::STATUS_RUNNING,
            'started_at' => $batch->started_at ?: now(),
            'completed_at' => null,
            'error_message' => null,
        ])->save();

        try {
            $result = $service->runBatch($batch->fresh() ?? $batch);

            $batch->forceFill([
                'status' => ShopifyImageImportBatch::STATUS_COMPLETED,
                'total_files' => $result['total_files'],
                'matched_count' => $result['matched_count'],
                'updated_count' => $result['updated_count'],
                'failed_count' => $result['failed_count'],
                'completed_at' => now(),
            ])->save();

            if (!empty($result['affected_stack_product_ids'])) {
                RebuildShopifyStackImagesJob::dispatch(
                    $batch->id,
                    $result['affected_stack_product_ids'],
                    $this->userId,
                );
            }

            $this->notify(
                Notification::make()
                    ->title('Shopify image import complete')
                    ->body("Batch #{$batch->id}: {$result['updated_count']} updated, {$result['failed_count']} failed from {$result['total_files']} image file(s).")
                    ->status($result['failed_count'] > 0 ? 'warning' : 'success')
            );
        } catch (\Throwable $e) {
            $batch->forceFill([
                'status' => ShopifyImageImportBatch::STATUS_FAILED,
                'completed_at' => now(),
                'error_message' => $e->getMessage(),
            ])->save();

            logger()->error('Shopify image import batch failed.', [
                'batch_id' => $batch->id,
                's3_prefix' => $batch->s3_prefix,
                'message' => $e->getMessage(),
            ]);

            $this->notify(
                Notification::make()
                    ->title('Shopify image import failed')
                    ->body("Batch #{$batch->id}: {$e->getMessage()}")
                    ->danger()
            );

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $batch = ShopifyImageImportBatch::query()->find($this->batchId);
        if ($batch instanceof ShopifyImageImportBatch) {
            $batch->forceFill([
                'status' => ShopifyImageImportBatch::STATUS_FAILED,
                'completed_at' => now(),
                'error_message' => $exception->getMessage(),
            ])->save();
        }
    }

    private function notify(Notification $notification): void
    {
        if ($this->userId) {
            AdminNotification::sendToUserId($notification, $this->userId);
            return;
        }

        AdminNotification::send($notification);
    }
}
