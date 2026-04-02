<?php

namespace App\Observers;

use App\Models\NewProductDraft;
use App\Services\NewProductDraftProductSync;

class NewProductDraftObserver
{
    /**
     * @var array<int, string>
     */
    private array $ignoreForApprovalReset = [
        'updated_at',
        'created_at',
        'approval_version',
        'handle',
        'shopify_id',
    ];

    public function updating(NewProductDraft $draft): void
    {
        $dirty = $draft->getDirty();
        if (empty($dirty)) {
            return;
        }

        $dirtyKeys = array_keys($dirty);
        $meaningful = array_diff($dirtyKeys, $this->ignoreForApprovalReset);

        if (!empty($meaningful)) {
            $draft->approval_version = ($draft->approval_version ?? 1) + 1;
        }
    }

    public function saved(NewProductDraft $draft): void
    {
        if (!$draft->handle) {
            return;
        }

        $changedKeys = array_keys($draft->getChanges());
        $meaningfulChanges = array_diff($changedKeys, $this->ignoreForApprovalReset);
        if (empty($meaningfulChanges)) {
            return;
        }

        $sync = app(NewProductDraftProductSync::class);

        // Existing products mirror draft edits immediately and require fresh approval in Products.
        $mirroredToExisting = $sync->syncToExistingProduct(
            $draft,
            ensureApprovalReset: true,
            attributes: array_values($meaningfulChanges)
        );

        // Keep create-from-draft behavior gated by draft approvals.
        if (!$mirroredToExisting && $draft->isApprovedByTwo()) {
            $sync->syncApprovedDrafts(collect([$draft]));
        }
    }
}
