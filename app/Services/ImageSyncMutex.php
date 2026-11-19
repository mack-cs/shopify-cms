<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** MySQL session locks have no lease expiry and disappear on connection death. */
final class ImageSyncMutex
{
    private mixed $lock = null;

    private ?string $name = null;

    public function acquire(int $productId): bool
    {
        return $this->acquireName(ProductShopifyUpdater::IMAGE_SYNC_LOCK_PREFIX.$productId);
    }

    public function acquireRelationshipScan(): bool
    {
        return $this->acquireName(ProductShopifyUpdater::IMAGE_SYNC_LOCK_PREFIX.'relationship-scan');
    }

    private function acquireName(string $name): bool
    {
        $this->name = $name;
        if (DB::connection()->getDriverName() === 'mysql') {
            return (int) DB::connection()->selectOne('SELECT GET_LOCK(?, 0) AS acquired', [$this->name], false)->acquired === 1;
        }
        if (! app()->environment('testing')) {
            throw new RuntimeException('Image synchronization requires a MySQL session mutex.');
        }
        $this->lock = Cache::lock($this->name, 3600);

        return $this->lock->get();
    }

    public function assertOwned(): void
    {
        $owned = $this->lock !== null ? $this->lock->isOwnedByCurrentProcess()
            : (int) DB::connection()->selectOne('SELECT IS_USED_LOCK(?) = CONNECTION_ID() AS owned', [$this->name], false)->owned === 1;
        if (! $owned) {
            throw new RuntimeException('Image synchronization lock ownership was lost; no further requests were sent.');
        }
    }

    public function release(): void
    {
        if ($this->lock !== null) {
            $this->lock->release();
        } elseif ($this->name !== null) {
            DB::connection()->selectOne('SELECT RELEASE_LOCK(?) AS released', [$this->name], false);
        }
    }
}
