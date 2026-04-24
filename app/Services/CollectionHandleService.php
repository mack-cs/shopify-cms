<?php

namespace App\Services;

use App\Models\CollectionUrlRedirect;
use App\Models\ShopifyCollection;

class CollectionHandleService
{
    public function promoteHandle(ShopifyCollection $collection, string $newHandle, ?int $createdBy = null): bool
    {
        $newHandle = trim($newHandle);
        $currentHandle = trim((string) ($collection->handle ?? ''));

        if ($newHandle === '' || $newHandle === $currentHandle) {
            return false;
        }

        ShopifyCollection::withoutEvents(function () use ($collection, $newHandle): void {
            $collection->forceFill([
                'handle' => $newHandle,
            ])->save();
        });

        $this->createPendingRedirect($collection, $currentHandle, $newHandle, $createdBy);

        $collection->forceFill(['handle' => $newHandle]);

        return true;
    }

    public function createPendingRedirect(ShopifyCollection $collection, string $oldHandle, string $newHandle, ?int $createdBy = null): void
    {
        $oldHandle = trim($oldHandle);
        $newHandle = trim($newHandle);

        if ($oldHandle === '' || $newHandle === '' || $oldHandle === $newHandle) {
            return;
        }

        CollectionUrlRedirect::query()->updateOrCreate(
            ['path' => "/collections/{$oldHandle}"],
            [
                'collection_id' => $collection->id,
                'created_by' => $createdBy,
                'old_handle' => $oldHandle,
                'new_handle' => $newHandle,
                'target' => "/collections/{$newHandle}",
                'status' => CollectionUrlRedirect::STATUS_PENDING,
                'shopify_redirect_id' => null,
                'last_error' => null,
                'synced_at' => null,
            ]
        );
    }
}
