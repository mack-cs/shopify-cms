<?php

namespace App\Jobs;

use App\Models\Variant;
use App\Services\AdminNotification;
use App\Services\ProductInventorySyncService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class InventorySyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    /**
     * @param array<int, int> $variantIds
     */
    public function __construct(
        public array $variantIds,
        public string $mode,
        public ?int $userId = null,
        public ?string $syncBatchId = null,
    ) {
    }

    public function handle(ProductInventorySyncService $service): void
    {
        $variants = Variant::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $this->variantIds))))
            ->get();

        if (!$variants instanceof Collection || $variants->isEmpty()) {
            return;
        }

        $result = $this->mode === 'refresh'
            ? $service->refreshVariants($variants, $this->userId)
            : $service->syncVariants($variants, $this->userId, $this->syncBatchId);

        if (!$this->userId) {
            return;
        }

        $parts = [];
        $parts[] = 'Variants: ' . $variants->count() . '.';

        if (($result['synced'] ?? 0) > 0) {
            $parts[] = 'Synced ' . (int) $result['synced'] . '.';
        }

        if (($result['refreshed'] ?? 0) > 0) {
            $parts[] = 'Refreshed ' . (int) $result['refreshed'] . '.';
        }

        if (($result['failed'] ?? 0) > 0) {
            $parts[] = 'Failed ' . (int) $result['failed'] . '.';
        }

        if (!empty($result['warnings'])) {
            $parts[] = 'Warnings: ' . implode(' | ', array_slice($result['warnings'], 0, 3));
        }

        if (!empty($result['failures'])) {
            $parts[] = 'Errors: ' . implode(' | ', array_slice($result['failures'], 0, 2));
        }

        $notification = Notification::make()
            ->title($this->mode === 'refresh' ? 'Inventory refresh complete' : 'Inventory sync complete')
            ->body(implode(' ', $parts));

        if (($result['failed'] ?? 0) > 0) {
            $notification->warning();
        } else {
            $notification->success();
        }

        AdminNotification::sendToUserId($notification, $this->userId);
    }
}
