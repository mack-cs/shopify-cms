<?php

namespace App\Observers;

use App\Models\Approval;
use App\Services\ProductImageApprovalWorkflowService;
use App\Services\StyleProfileSeoTimelineService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class ApprovalObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Approval $approval): void
    {
        app(ProductImageApprovalWorkflowService::class)->handleApprovalCreated($approval);

        $product = $approval->product?->fresh();
        if (!$product || $product->approvalsForCurrentVersionCount() !== 2) {
            return;
        }

        app(StyleProfileSeoTimelineService::class)->markApprovedForSync(
            $product,
            (int) $approval->user_id,
            null,
            StyleProfileSeoTimelineService::APPROVAL_SOURCE_FULL
        );
    }
}
