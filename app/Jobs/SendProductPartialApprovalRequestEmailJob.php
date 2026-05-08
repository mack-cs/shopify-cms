<?php

namespace App\Jobs;

use App\Mail\ProductPartialApprovalRequestMail;
use App\Models\ProductPartialApprovalRequest;
use App\Services\ProductPartialApprovalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendProductPartialApprovalRequestEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(
        private readonly string $requestBatchId,
    ) {
    }

    public function handle(ProductPartialApprovalService $service): void
    {
        $requests = ProductPartialApprovalRequest::query()
            ->with(['product', 'requester', 'targetApprover'])
            ->where('request_batch_id', $this->requestBatchId)
            ->orderBy('id')
            ->get();

        if ($requests->isEmpty()) {
            return;
        }

        $firstRequest = $requests->first();
        $requester = $firstRequest?->requester;

        if (!$firstRequest || !$requester) {
            return;
        }

        $recipientEmails = $service->approvalRequestRecipientEmails($firstRequest, (int) $requester->id);
        if ($recipientEmails === []) {
            return;
        }

        Mail::to($recipientEmails)->send(
            new ProductPartialApprovalRequestMail(
                $requester,
                $requests->all(),
                $firstRequest->targetApprover,
                $service->partialApprovalQueueUrl(),
                $service,
            )
        );
    }
}
