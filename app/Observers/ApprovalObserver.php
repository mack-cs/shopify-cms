<?php

namespace App\Observers;

use App\Models\Approval;
use App\Services\ProductImageApprovalWorkflowService;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

class ApprovalObserver implements ShouldHandleEventsAfterCommit
{
    public function created(Approval $approval): void
    {
        app(ProductImageApprovalWorkflowService::class)->handleApprovalCreated($approval);
    }
}
