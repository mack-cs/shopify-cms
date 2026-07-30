<?php

namespace App\Services;

use App\Models\Product;
use App\Models\User;
use App\Notifications\DuplicateSkuDeletionRequestSlackNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

final class DuplicateSkuDeletionRequestService
{
    public function __construct(
        private readonly DuplicateSkuAuditService $auditService,
        private readonly DeletionRequestWorkflowService $deletionRequests,
    ) {
    }

    /**
     * @param iterable<int, mixed> $records
     * @return array{
     *     selected:int,
     *     requested:int,
     *     skipped_ineligible:int,
     *     skipped_existing:int,
     *     failed:int,
     *     slack_sent:bool,
     *     request_ids:array<int, int>
     * }
     */
    public function requestArchivedDuplicates(
        iterable $records,
        int $userId,
        int $targetApproverId,
        ?string $reason = null,
    ): array {
        $records = $records instanceof Collection ? $records : collect($records);
        $targetApprover = User::query()->find($targetApproverId);
        if (!$targetApprover instanceof User) {
            throw new \InvalidArgumentException('Select a valid deletion approver.');
        }

        $eligibleIds = array_fill_keys($this->auditService->conflictingProductIds('archived'), true);
        $summary = [
            'selected' => $records->count(),
            'requested' => 0,
            'skipped_ineligible' => 0,
            'skipped_existing' => 0,
            'failed' => 0,
            'slack_sent' => false,
            'request_ids' => [],
        ];

        foreach ($records as $record) {
            if (
                !$record instanceof Product
                || !isset($eligibleIds[(int) $record->id])
                || strtolower(trim((string) $record->status)) !== 'archived'
            ) {
                $summary['skipped_ineligible']++;
                continue;
            }

            if ($this->deletionRequests->openRequestFor($record) !== null) {
                $summary['skipped_existing']++;
                continue;
            }

            try {
                $request = $this->deletionRequests->submit(
                    $record,
                    $userId,
                    $reason,
                    $targetApproverId,
                );
                $summary['requested']++;
                $summary['request_ids'][] = (int) $request->id;
            } catch (Throwable) {
                $summary['failed']++;
            }
        }

        $channel = trim((string) config('services.slack.channels.duplicate_skus'));
        if ($summary['request_ids'] !== [] && $channel !== '') {
            try {
                Notification::route('slack', $channel)
                    ->notify(new DuplicateSkuDeletionRequestSlackNotification(
                        $summary['request_ids'],
                        $targetApproverId,
                    ));
                $summary['slack_sent'] = true;
            } catch (Throwable $exception) {
                Log::error('Duplicate SKU deletion request Slack notification failed.', [
                    'request_ids' => $summary['request_ids'],
                    'target_approver_id' => $targetApproverId,
                    'exception' => $exception,
                ]);
            }
        }

        return $summary;
    }
}
