<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

class AsyncJobStateService
{
    public const INVENTORY_CHECK = 'inventory_check';
    public const COMPLEMENTARY_AUDIT = 'complementary_audit';
    public const COMPLEMENTARY_RECONCILIATION = 'complementary_reconciliation';
    public const NEW_PRODUCT_SHOPIFY_CREATE = 'new_product_shopify_create';

    /**
     * @return array{is_running:bool,count:int,started_at:?Carbon}
     */
    public function snapshot(string $job): array
    {
        $state = Cache::get($this->cacheKey($job), []);
        $count = max(0, (int) ($state['count'] ?? 0));
        $startedAt = $state['started_at'] ?? null;

        return [
            'is_running' => $count > 0,
            'count' => $count,
            'started_at' => is_string($startedAt) ? Carbon::parse($startedAt) : null,
        ];
    }

    public function markQueued(string $job): void
    {
        $snapshot = $this->snapshot($job);

        Cache::put($this->cacheKey($job), [
            'count' => $snapshot['count'] + 1,
            'started_at' => ($snapshot['started_at'] ?? now())->toIso8601String(),
        ], now()->addHours(6));
    }

    public function markFinished(string $job): void
    {
        $snapshot = $this->snapshot($job);
        $remaining = max(0, $snapshot['count'] - 1);

        if ($remaining === 0) {
            Cache::forget($this->cacheKey($job));

            return;
        }

        Cache::put($this->cacheKey($job), [
            'count' => $remaining,
            'started_at' => ($snapshot['started_at'] ?? now())->toIso8601String(),
        ], now()->addHours(6));
    }

    private function cacheKey(string $job): string
    {
        return 'async_job_state:' . $job;
    }
}
