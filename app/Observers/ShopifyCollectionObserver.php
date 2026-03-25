<?php

namespace App\Observers;

use App\Models\ShopifyCollection;

class ShopifyCollectionObserver
{
    public function updating(ShopifyCollection $collection): void
    {
        $dirty = $collection->getDirty();
        if (empty($dirty)) {
            return;
        }

        $ignoreForApprovalReset = [
            'updated_at',
            'created_at',
            'batch',
            'sync_status',
            'last_synced_at',
        ];

        $dirtyKeys = array_keys($dirty);
        $meaningful = array_diff($dirtyKeys, $ignoreForApprovalReset);
        $meaningful = array_diff($meaningful, ['approval_version']);

        if (!empty($meaningful)) {
            $collection->approval_version = ($collection->approval_version ?? 1) + 1;
            $collection->sync_status = ShopifyCollection::SYNC_STATUS_PENDING;
        }
    }
}
