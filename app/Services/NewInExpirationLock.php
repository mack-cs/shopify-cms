<?php

namespace App\Services;

use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use RuntimeException;
use WeakMap;

/**
 * Single shared execution lock for New In expiration / recovery.
 *
 * Key: new-in-tag-expiration
 */
final class NewInExpirationLock
{
    public const KEY = 'new-in-tag-expiration';

    public const PRODUCT_KEY_PREFIX = 'new-in-tag-product:';

    /** @var WeakMap<Lock, array{wall: float, monotonic: float}>|null */
    private static ?WeakMap $deadlines = null;

    // Shopify HTTP requests time out at 60 seconds. Allow for cache clock granularity.
    public const SHOPIFY_WRITE_RESERVE = 65;

    public function executionSeconds(): int
    {
        return max(120, (int) config('new_in_expiration.lock_seconds', 7200));
    }

    public function productSeconds(): int
    {
        return max(120, (int) config('new_in_expiration.product_lock_seconds', 180));
    }

    public function acquireExecution(?int $seconds = null): Lock
    {
        $lock = $this->tryAcquireExecution($seconds);
        if ($lock === null) {
            throw new RuntimeException('Another New In expiration or recovery run is already in progress.');
        }

        return $lock;
    }

    public function tryAcquireExecution(?int $seconds = null): ?Lock
    {
        return $this->acquire(self::KEY, $seconds ?? $this->executionSeconds());
    }

    public function acquireProduct(string $productGid, ?int $seconds = null): Lock
    {
        $lock = $this->acquire(self::PRODUCT_KEY_PREFIX.$productGid, $seconds ?? $this->productSeconds());
        if ($lock === null) {
            throw new RuntimeException('Another New In tag write is in progress for '.$productGid.'.');
        }

        return $lock;
    }

    /**
     * Block until the product lock is available (outbound writers).
     */
    public function blockProduct(string $productGid, int $waitSeconds = 30): Lock
    {
        return $this->acquire(self::PRODUCT_KEY_PREFIX.$productGid, $this->productSeconds(), $waitSeconds);
    }

    /**
     * Ensure this process still owns the lock before continuing Shopify writes.
     */
    public function assertOwned(Lock $lock, string $context = 'New In expiration', int $reserveSeconds = 0): void
    {
        $deadline = self::$deadlines[$lock] ?? null;
        if ($deadline === null || $deadline['wall'] <= $this->now() + $reserveSeconds
            || $deadline['monotonic'] <= hrtime(true) / 1e9 + $reserveSeconds
            || ! $lock->isOwnedByCurrentProcess()) {
            throw new RuntimeException("{$context}: lock lease expired, has insufficient time left, or was taken by another owner.");
        }
    }

    public function release(Lock $lock): void
    {
        try {
            $lock->release();
        } catch (\Throwable) {
            // Owner-safe: releasing after lease expiry / successor ownership is a no-op.
        }
    }

    private function acquire(string $key, int $seconds, int $waitSeconds = 0): ?Lock
    {
        $lock = Cache::lock($key, $seconds);
        $started = $this->now();
        $monotonic = hrtime(true) / 1e9;
        $acquired = $waitSeconds > 0 ? $lock->block($waitSeconds) : $lock->get();
        if (! $acquired) {
            return null;
        }

        // The lease started at or after this timestamp, even if acquisition waited.
        self::$deadlines ??= new WeakMap;
        self::$deadlines[$lock] = ['wall' => $started + $seconds, 'monotonic' => $monotonic + $seconds];

        return $lock;
    }

    private function now(): float
    {
        return (float) Carbon::now()->format('U.u');
    }
}
