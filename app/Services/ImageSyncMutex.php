<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/** MySQL session locks have no lease expiry and disappear on connection death. */
final class ImageSyncMutex
{
    /** @var array<string, int> */
    private static array $depths = [];

    /** @var array<string, mixed> */
    private static array $cacheLocks = [];

    private mixed $lock = null;

    private ?string $name = null;

    private int $localDepth = 0;

    public function __destruct()
    {
        try {
            $this->releaseAll();
        } catch (Throwable) {
            // The holder is gone. Clearing this instance cannot authorize a new mutation.
        }
    }

    public function acquire(int $productId): bool
    {
        return $this->acquireName(ProductShopifyUpdater::IMAGE_SYNC_LOCK_PREFIX.$productId);
    }

    public function acquireRelationshipScan(): bool
    {
        return $this->acquireName(ProductShopifyUpdater::IMAGE_SYNC_LOCK_PREFIX.'relationship-scan');
    }

    public static function ownsProduct(int $productId): bool
    {
        if ($productId < 1) {
            return false;
        }
        $name = ProductShopifyUpdater::IMAGE_SYNC_LOCK_PREFIX.$productId;
        if ((self::$depths[$name] ?? 0) < 1) {
            return false;
        }

        return (new self)->underlyingOwned($name);
    }

    private function acquireName(string $name): bool
    {
        if ($this->name !== null && $this->name !== $name && $this->localDepth > 0) {
            return false;
        }
        $this->name = $name;
        if ((self::$depths[$name] ?? 0) > 0 || $this->localDepth > 0) {
            if (! $this->underlyingOwned($name)) {
                return false;
            }
            self::$depths[$name] = (self::$depths[$name] ?? 0) + 1;
            $this->localDepth++;

            return true;
        }
        if (DB::connection()->getDriverName() === 'mysql') {
            $acquired = (int) DB::connection()->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$name], false)->acquired === 1;
        } elseif (! app()->environment('testing')) {
            throw new RuntimeException('Image synchronization requires a MySQL session mutex.');
        } else {
            $this->lock = Cache::lock($name, 3600);
            $acquired = $this->lock->get();
        }
        if (! $acquired) {
            $this->lock = null;

            return false;
        }
        self::$depths[$name] = 1;
        if ($this->lock !== null) {
            self::$cacheLocks[$name] = $this->lock;
        }
        $this->localDepth = 1;

        return true;
    }

    public function assertOwned(): void
    {
        if ($this->name === null || $this->localDepth < 1 || (self::$depths[$this->name] ?? 0) < 1 || ! $this->underlyingOwned($this->name)) {
            throw new RuntimeException('Image synchronization lock ownership was lost; no further requests were sent.');
        }
    }

    public function release(): void
    {
        if ($this->localDepth < 1 || $this->name === null) {
            return;
        }
        $name = $this->name;
        $this->localDepth--;
        self::$depths[$name] = (self::$depths[$name] ?? 1) - 1;
        if ($this->localDepth > 0 || (self::$depths[$name] ?? 0) > 0) {
            return;
        }
        unset(self::$depths[$name]);
        $cache = self::$cacheLocks[$name] ?? null;
        unset(self::$cacheLocks[$name]);
        $this->lock = null;
        try {
            if ($cache !== null) {
                $cache->release();
            } elseif (DB::connection()->getDriverName() === 'mysql') {
                DB::connection()->selectOne('SELECT RELEASE_LOCK(?) AS released', [$name], false);
            }
        } catch (Throwable) {
            // The session lock is already gone. This release must not leave the depth behind.
        }
    }

    private function releaseAll(): void
    {
        while ($this->localDepth > 0) {
            $this->release();
        }
    }

    private function underlyingOwned(string $name): bool
    {
        if (array_key_exists($name, self::$cacheLocks)) {
            $cache = self::$cacheLocks[$name];

            return is_object($cache) && $cache->isOwnedByCurrentProcess();
        }
        if ((self::$depths[$name] ?? 0) < 1 || DB::connection()->getDriverName() !== 'mysql') {
            return false;
        }
        $owned = DB::connection()->selectOne('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS owned', [$name], false)->owned ?? 0;

        return (int) $owned === 1;
    }
}
