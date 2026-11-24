<?php

namespace App\Jobs;

use App\Models\Product;
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
        if (! $batch instanceof ShopifyImageImportBatch) {
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

            $stackIds = $this->queueDirectStackRebuild($batch, $result);

            $altErrors = (int) ($result['alt_text_error_count'] ?? 0);
            $batch->refresh();
            $body = 'Batch #'.$batch->id.': '.ShopifyImageImportBatch::formatImportResult($result);
            if ($stackIds !== []) {
                $body .= ' Local stack rebuild: '.($batch->rebuild_status ?: ShopifyImageImportBatch::REBUILD_PENDING).'.';
            }
            $this->notify(
                Notification::make()
                    ->title('Shopify image import complete')
                    ->body($body)
                    ->status($result['failed_count'] > 0 || $altErrors > 0 || in_array($batch->rebuild_status, [ShopifyImageImportBatch::REBUILD_INCOMPLETE, ShopifyImageImportBatch::REBUILD_FAILED], true) ? 'warning' : 'success')
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

    /**
     * A stack whose own file was imported is always rebuilt locally.
     * COMPONENT_STACK_LOCAL_REBUILD still decides whether a component import rebuilds parent stacks.
     *
     * @param  array<string, mixed>  $result
     * @return array<int, int>
     */
    private function queueDirectStackRebuild(ShopifyImageImportBatch $batch, array $result): array
    {
        $direct = array_map('intval', $result['direct_stack_product_ids'] ?? []);
        $dependent = config('images.component_stack_local_rebuild')
            ? array_map('intval', $result['dependent_stack_product_ids'] ?? [])
            : [];
        $stackIds = array_values(array_unique(array_filter(array_merge($direct, $dependent))));
        sort($stackIds);
        if ($stackIds === []) {
            return [];
        }

        $batch->forceFill([
            'rebuild_status' => ShopifyImageImportBatch::REBUILD_PENDING,
            'rebuild_message' => null,
            'rebuild_stack_product_ids' => $stackIds,
        ])->save();
        Product::query()->whereIn('id', $stackIds)
            ->where(function ($query) use ($batch): void {
                $query->whereNull('image_rebuild_batch_id')
                    ->orWhere('image_rebuild_batch_id', '<=', $batch->id);
            })
            ->update([
                'image_rebuild_status' => ShopifyImageImportBatch::REBUILD_PENDING,
                'image_rebuild_message' => null,
                'image_rebuild_batch_id' => $batch->id,
                'image_rebuild_owner' => null,
                'updated_at' => now(),
            ]);

        $job = new RebuildShopifyStackImagesJob($batch->id, $stackIds, $this->userId);
        try {
            dispatch($job);
        } catch (\Throwable $exception) {
            RebuildShopifyStackImagesJob::markDispatchFailed($batch, $stackIds, $exception, $job);
            logger()->error('Local stack image rebuild could not be queued.', [
                'batch_id' => $batch->id,
                'stack_product_ids' => $stackIds,
                'message' => $exception->getMessage(),
            ]);
        }

        return $stackIds;
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
