<?php

namespace App\Jobs;

use App\Mail\NewProductDraftAssignmentMail;
use App\Models\NewProductDraftAssignment;
use App\Services\NewProductDraftAssignmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;

class SendNewProductDraftAssignmentEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 300;

    public function __construct(
        private readonly int $assignmentId,
    ) {
    }

    public function handle(NewProductDraftAssignmentService $service): void
    {
        $assignment = NewProductDraftAssignment::query()
            ->with(['items', 'sender'])
            ->find($this->assignmentId);

        if (!$assignment) {
            return;
        }

        try {
            Mail::to($assignment->to_emails)
                ->cc($assignment->cc_emails ?? [])
                ->send(new NewProductDraftAssignmentMail($assignment, $service));

            $service->markSent($assignment);
        } catch (\Throwable $e) {
            $service->markFailed($assignment, $e);
            throw $e;
        }
    }
}
