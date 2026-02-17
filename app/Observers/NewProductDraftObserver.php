<?php

namespace App\Observers;

use App\Models\NewProductDraft;

class NewProductDraftObserver
{
    public function updating(NewProductDraft $draft): void
    {
        $dirty = $draft->getDirty();
        if (empty($dirty)) {
            return;
        }

        $ignoreForApprovalReset = [
            'updated_at',
            'created_at',
            'approval_version',
            'handle',
            'shopify_id',
        ];

        $dirtyKeys = array_keys($dirty);
        $meaningful = array_diff($dirtyKeys, $ignoreForApprovalReset);

        if (!empty($meaningful)) {
            $draft->approval_version = ($draft->approval_version ?? 1) + 1;
        }
    }
}
