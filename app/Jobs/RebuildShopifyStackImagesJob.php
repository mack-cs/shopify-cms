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
    ) {}

    public function handle(ShopifyImageImportService $service): void
    {
        $batch = ShopifyImageImportBatch::query()->find($this->batchId);
        if (!$batch instanceof ShopifyImageImportBatch) {
            return;
        }

        $result = $service->rebuildStacksForBatch($batch, $this->stackProductIds);
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
}
