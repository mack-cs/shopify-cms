<?php

namespace App\Jobs;

use App\Models\Variant;
use App\Services\AdminNotification;
use App\Services\InventoryAdjustmentApprovalService;
use App\Services\ProductInventorySyncService;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;

class InventorySyncJob implements ShouldQueue, \Illuminate\Contracts\Queue\ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 1800;

    public int $tries = 5;

    public int $uniqueFor = 20000;

    public array $backoff = [60, 120, 300, 900];

    public function uniqueId(): string
    {
        $ids = $this->variantIds;
        sort($ids);
        return $this->mode.':'.$this->syncBatchId.':'.hash('sha256', json_encode($ids));
    }

    /**
     * @param array<int, int> $variantIds
     */
    public function __construct(
        public array $variantIds,
        public string $mode,
        public ?int $userId = null,
        public ?string $syncBatchId = null,
    ) {
        $this->onConnection('inventory-sync')->onQueue('inventory-sync');
    }

    public function handle(ProductInventorySyncService $service, InventoryAdjustmentApprovalService $approvals): void
    {
        if ($this->mode === 'push' && ! str_starts_with((string) $this->syncBatchId, 'stack_sellability_')) {
            if (str_starts_with((string) $this->syncBatchId, 'inventory_approval_')) {
                $request = $approvals->authorizeBatch($this->syncBatchId, $this->variantIds, $this->userId);
                if ($request->status === \App\Models\InventoryAdjustmentRequest::STATUS_APPLIED) return;
            } else {
                app(\App\Services\InventoryAccessService::class)->authorizeInventory($this->userId);
            }
        }
        $variants = Variant::query()
            ->whereIn('id', array_values(array_unique(array_map('intval', $this->variantIds))))
            ->get();

        if (!$variants instanceof Collection || $variants->isEmpty()) {
            return;
        }

        // Legacy serialized automatic pushes did not persist their retry intent locally.
        if ($this->mode === 'push' && str_starts_with((string) $this->syncBatchId, 'stack_sellability_')) {
            foreach ($variants as $variant) {
                if ($variant->inventory_local_dirty && $variant->product?->is_bundle) {
                    $variant->forceFill(['inventory_sync_batch_id' => 'stack_sellability_pending'])->saveQuietly();
                }
            }
        }

        $result = $this->mode === 'refresh'
            ? $service->refreshVariants($variants, $this->userId)
            : $service->syncVariants($variants, $this->userId, $this->syncBatchId);

        $failures = $result['failures'] ?? [];
        $retryable = array_values(array_filter(
            $failures,
            fn (string $failure): bool => ! str_contains($failure, 'automatic replay is blocked'),
        ));
        if ($this->mode !== 'refresh' && $retryable !== []) {
            throw new \RuntimeException('Inventory push remains unconfirmed; retry required. '.implode('; ', $failures));
        }
        if ($this->mode !== 'refresh' && $failures !== []) {
            $this->notify($variants, $result);

            return;
        }

        if ($this->mode !== 'refresh' && $this->syncBatchId !== null) {
            $approvals->markAppliedBySyncBatch(
                $this->syncBatchId,
                json_encode($result, JSON_THROW_ON_ERROR),
            );
        }

        $this->notify($variants, $result);
    }

    /**
     * @param Collection<int, Variant> $variants
     * @param array<string, mixed> $result
     */
    private function notify(Collection $variants, array $result): void
    {
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
