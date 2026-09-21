<?php

namespace App\Jobs;

use App\Models\ShopifyImageImportBatch;
use App\Services\AdminNotification;
use App\Services\ShopifyImageImportService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class RebuildShopifyStackImagesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;
    public int $tries = 2;

    /**
     * @param array<int, int> $stackProductIds
     */
    public function __construct(
        public int $batchId,
        public array $stackProductIds,
        public ?int $userId = null,
        public bool $manageBatchStatus = false,
    ) {}

    public function handle(ShopifyImageImportService $service): void
    {
        $batch = ShopifyImageImportBatch::query()->find($this->batchId);
        if (!$batch instanceof ShopifyImageImportBatch) {
            return;
        }

        $stackProductIds = array_values(array_unique(array_filter(array_map('intval', $this->stackProductIds))));

        if ($this->manageBatchStatus) {
            $batch->forceFill([
                'status' => ShopifyImageImportBatch::STATUS_RUNNING,
                'started_at' => $batch->started_at ?: now(),
                'completed_at' => null,
                'error_message' => null,
                'matched_count' => count($stackProductIds),
                'updated_count' => 0,
                'failed_count' => 0,
            ])->save();
        }

        try {
            $result = $service->rebuildStacksForBatch($batch, $stackProductIds);
        } catch (\Throwable $e) {
            if ($this->manageBatchStatus) {
                $batch->forceFill([
                    'status' => ShopifyImageImportBatch::STATUS_FAILED,
                    'completed_at' => now(),
                    'error_message' => $e->getMessage(),
                ])->save();
            }

            throw $e;
        }

        if ($this->manageBatchStatus) {
            $batch->forceFill([
                'status' => ShopifyImageImportBatch::STATUS_COMPLETED,
                'matched_count' => count($stackProductIds),
                'updated_count' => $result['rebuilt'],
                'failed_count' => $result['failed'],
                'completed_at' => now(),
            ])->save();
        }

        $message = "Batch #{$batch->id}: rebuilt {$result['rebuilt']} stack(s), {$result['failed']} failed.";

        if (!empty($result['messages'])) {
            $message .= ' ' . collect($result['messages'])->take(4)->implode(' | ');
        }

        $notification = Notification::make()
            ->title('Stack image rebuild complete')
            ->body($message)
            ->status($result['failed'] > 0 ? 'warning' : 'success');

        if ($this->userId) {
            AdminNotification::sendToUserId($notification, $this->userId);
            return;
        }

        AdminNotification::send($notification);
    }

    public function failed(\Throwable $exception): void
    {
        if (!$this->manageBatchStatus) {
            return;
        }

        $batch = ShopifyImageImportBatch::query()->find($this->batchId);
        if ($batch instanceof ShopifyImageImportBatch) {
            $batch->forceFill([
                'status' => ShopifyImageImportBatch::STATUS_FAILED,
                'completed_at' => now(),
                'error_message' => $exception->getMessage(),
            ])->save();
        }
    }
}
