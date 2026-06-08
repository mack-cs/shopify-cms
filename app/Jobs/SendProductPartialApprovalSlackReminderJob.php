<?php

namespace App\Jobs;

use App\Notifications\ProductPartialApprovalSlackReminderNotification;
use App\Services\ProductPartialApprovalService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Notification;
use RuntimeException;

class SendProductPartialApprovalSlackReminderJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $timeout = 120;
    public int $tries = 3;
    public int $uniqueFor = 2400;

    public function __construct(
        private readonly ?int $targetApproverId,
    ) {
    }

    public function uniqueId(): string
    {
        return 'partial-approval-slack-reminder:' . ($this->targetApproverId ?: 'any');
    }

    public function handle(ProductPartialApprovalService $service): void
    {
        $query = $service->visiblePendingRequestsQuery();

        if ($this->targetApproverId !== null && $this->targetApproverId > 0) {
            $query
                ->where('target_approver_id', $this->targetApproverId)
                ->where('requested_by', '!=', $this->targetApproverId);
        } else {
            $query->whereNull('target_approver_id');
        }

        $requestIds = $query
            ->orderBy('created_at')
            ->limit(25)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        if ($requestIds === []) {
            return;
        }

        $channel = trim((string) config('services.slack.channels.partial_approvals'));

        if ($channel === '') {
            throw new RuntimeException('Slack partial approval channel is not configured.');
        }

        Notification::route('slack', $channel)
            ->notify(new ProductPartialApprovalSlackReminderNotification(
                $this->targetApproverId,
                $requestIds,
            ));
    }
}
