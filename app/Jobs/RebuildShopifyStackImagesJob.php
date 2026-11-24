<?php

namespace App\Jobs;

use App\Models\Product;
use App\Models\ShopifyImageImportBatch;
use App\Services\AdminNotification;
use App\Services\ShopifyImageImportService;
use App\Services\StackRebuildLockStore;
use Filament\Notifications\Notification;
use Illuminate\Bus\Queueable;
use Illuminate\Bus\UniqueLock;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RebuildShopifyStackImagesJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 3600;

    public int $tries = 2;

    public ?string $claimOwner = null;

    public bool $claimAcquired = false;

    /**
     * @param array<int, int> $stackProductIds
     */
    public function __construct(
        public int $batchId,
        public array $stackProductIds,
        public ?int $userId = null,
        public bool $manageBatchStatus = false,
    ) {
        $this->claimOwner = (string) Str::uuid();
    }

    public function uniqueId(): string
    {
        $ids = array_values(array_unique(array_map('intval', $this->stackProductIds)));
        sort($ids);

        return $this->batchId.':'.hash('sha256', implode(',', $ids));
    }

    /** The claim is a database row for this job's owner token, released only by that token. */
    public function uniqueVia(): StackRebuildLockStore
    {
        return StackRebuildLockStore::boundTo($this);
    }

    public function handle(ShopifyImageImportService $service): void
    {
        $claim = $this->activeClaim();
        if ($this->claimAcquired && $claim === null) {
            return;
        }

        $batch = ShopifyImageImportBatch::query()->find($this->batchId);
        if (!$batch instanceof ShopifyImageImportBatch) {
            return;
        }

        $stackProductIds = array_values(array_unique(array_filter(array_map('intval', $this->stackProductIds))));
        if ($claim !== null) {
            $this->claimProductAttempts($claim, $stackProductIds);
        }

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
            $result = $service->rebuildStacksForBatch($batch, $stackProductIds, $claim);
        } catch (\Throwable $e) {
            if ($this->manageBatchStatus && $this->failureMayRecord()) {
                $batch->forceFill([
                    'status' => ShopifyImageImportBatch::STATUS_FAILED,
                    'completed_at' => now(),
                    'error_message' => $e->getMessage(),
                ])->save();
            }

            throw $e;
        }

        if (($result['recorded'] ?? true) === false) {
            return;
        }

        if ($this->manageBatchStatus) {
            $batch->forceFill([
                'status' => $result['failed'] > 0 ? ShopifyImageImportBatch::STATUS_FAILED : ShopifyImageImportBatch::STATUS_COMPLETED,
                'matched_count' => count($stackProductIds),
                'updated_count' => $result['rebuilt'],
                'failed_count' => $result['failed'],
                'completed_at' => now(),
                'error_message' => $result['failed'] > 0 ? collect($result['messages'])->take(8)->implode(' | ') : null,
            ])->save();
        }

        $incomplete = (int) ($result['incomplete'] ?? 0);
        $message = "Batch #{$batch->id}: rebuilt {$result['rebuilt']} stack image set(s), {$incomplete} incomplete, {$result['failed']} failed, updated compare-at price on {$result['compare_at_updated']} stack(s).";

        if (!empty($result['messages'])) {
            $message .= ' ' . collect($result['messages'])->take(4)->implode(' | ');
        }

        $needsAttention = $result['failed'] > 0 || $incomplete > 0;
        $notification = Notification::make()
            ->title($needsAttention ? 'Stack image rebuild incomplete' : 'Stack image rebuild complete')
            ->body($message)
            ->status($needsAttention ? 'warning' : 'success');

        if ($this->userId) {
            AdminNotification::sendToUserId($notification, $this->userId);
            return;
        }

        AdminNotification::send($notification);
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->claimAcquired && ! $this->failureMayRecord()) {
            return;
        }

        $batch = ShopifyImageImportBatch::query()->find($this->batchId);
        if (! $batch instanceof ShopifyImageImportBatch) {
            return;
        }

        $message = Str::limit($exception->getMessage(), 2000, '');
        $batch->forceFill([
            'rebuild_status' => ShopifyImageImportBatch::REBUILD_FAILED,
            'rebuild_message' => $message,
        ])->save();

        self::failPendingProducts(
            $this->batchId,
            $this->stackProductIds,
            $message,
            $this->claimAcquired ? (string) $this->claimOwner : null,
        );

        if (! $this->manageBatchStatus) {
            return;
        }

        $batch->forceFill([
            'status' => ShopifyImageImportBatch::STATUS_FAILED,
            'completed_at' => now(),
            'error_message' => $exception->getMessage(),
        ])->save();
    }

    /**
     * A dispatch that throws must leave the batch retryable instead of pending.
     * The release matches the dispatched job's owner token.
     *
     * @param  array<int, int>  $stackProductIds
     */
    public static function markDispatchFailed(
        ShopifyImageImportBatch $batch,
        array $stackProductIds,
        \Throwable $exception,
        ?self $job = null,
    ): void {
        $message = Str::limit($exception->getMessage(), 2000, '');
        $batch->forceFill([
            'rebuild_status' => ShopifyImageImportBatch::REBUILD_FAILED,
            'rebuild_message' => $message,
        ])->save();
        self::failPendingProducts($batch->id, $stackProductIds, $message);
        ($job ?? new self($batch->id, $stackProductIds))->releaseClaim();
    }

    public function releaseClaim(): void
    {
        if (! is_string($this->claimOwner) || $this->claimOwner === '') {
            return;
        }

        app(StackRebuildLockStore::class)->release(UniqueLock::getKey($this), $this->claimOwner);
    }

    /**
     * @param  array<int, int>  $stackProductIds
     */
    public static function failPendingProducts(int $batchId, array $stackProductIds, string $message, ?string $owner = null): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $stackProductIds))));
        if ($ids === []) {
            return;
        }

        $query = Product::query()
            ->whereIn('id', $ids)
            ->where('image_rebuild_batch_id', $batchId)
            ->where('image_rebuild_status', ShopifyImageImportBatch::REBUILD_PENDING);
        if ($owner !== null) {
            $query->where('image_rebuild_owner', $owner);
        }
        $query->update([
            'image_rebuild_status' => ShopifyImageImportBatch::REBUILD_FAILED,
            'image_rebuild_message' => $message,
            'updated_at' => now(),
        ]);
    }

    /**
     * @return array{key: string, owner: string}|null
     */
    private function activeClaim(): ?array
    {
        if (! $this->claimAcquired || ! is_string($this->claimOwner) || $this->claimOwner === '') {
            return null;
        }

        $key = UniqueLock::getKey($this);
        if (! app(StackRebuildLockStore::class)->markRunning($key, $this->claimOwner)) {
            return null;
        }

        return ['key' => $key, 'owner' => $this->claimOwner];
    }

    /**
     * @param  array{key: string, owner: string}  $claim
     * @param  array<int, int>  $stackProductIds
     */
    private function claimProductAttempts(array $claim, array $stackProductIds): void
    {
        if ($stackProductIds === []) {
            return;
        }

        Product::query()
            ->whereIn('id', $stackProductIds)
            ->where(function ($query): void {
                $query->whereNull('image_rebuild_batch_id')
                    ->orWhere('image_rebuild_batch_id', '<=', $this->batchId);
            })
            ->whereExists(function ($query) use ($claim): void {
                $query->selectRaw('1')
                    ->from('stack_rebuild_claims')
                    ->where('claim_key', $claim['key'])
                    ->where('owner', $claim['owner']);
            })
            ->update([
                'image_rebuild_owner' => $claim['owner'],
                'updated_at' => now(),
            ]);
    }

    private function failureMayRecord(): bool
    {
        if (! is_string($this->claimOwner) || $this->claimOwner === '') {
            return false;
        }

        $key = UniqueLock::getKey($this);
        $claim = DB::table('stack_rebuild_claims')->where('claim_key', $key)->first();
        if ($claim !== null && (string) $claim->owner !== $this->claimOwner) {
            return false;
        }

        $ids = array_values(array_unique(array_filter(array_map('intval', $this->stackProductIds))));
        if ($ids === []) {
            return $claim === null || (string) $claim->owner === $this->claimOwner;
        }

        return ! Product::query()
            ->whereIn('id', $ids)
            ->where('image_rebuild_batch_id', $this->batchId)
            ->whereNotNull('image_rebuild_owner')
            ->where('image_rebuild_owner', '!=', $this->claimOwner)
            ->exists();
    }
}
