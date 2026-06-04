<?php

namespace App\Jobs;

use App\Models\NewProductDraftAssignment;
use App\Notifications\NewProductDraftAssignmentSlackNotification;
use App\Services\NewProductDraftAssignmentService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

class SendNewProductDraftAssignmentSlackJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 3;

    public function __construct(
        private readonly int $assignmentId,
    ) {
    }

    public function handle(NewProductDraftAssignmentService $service): void
    {
        $assignment = NewProductDraftAssignment::query()->find($this->assignmentId);

        if (!$assignment instanceof NewProductDraftAssignment) {
            return;
        }

        $channel = trim((string) ($assignment->notification_channel ?: config('services.slack.channels.assignments')));

        if ($channel === '') {
            throw new RuntimeException('Slack assignment channel is not configured.');
        }

        try {
            Notification::route('slack', $channel)
                ->notify(new NewProductDraftAssignmentSlackNotification($assignment->id));

            $service->markSlackSent($assignment->fresh() ?? $assignment, $channel);
        } catch (\Throwable $e) {
            $service->markSlackFailed($assignment->fresh() ?? $assignment, $e);

            throw $e;
        }
    }
}
