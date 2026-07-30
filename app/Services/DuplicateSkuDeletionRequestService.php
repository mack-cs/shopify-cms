<?php

namespace App\Services;

use App\Models\Product;
use Illuminate\Support\Collection;
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
     *     failed:int
     * }
     */
    public function requestArchivedDuplicates(
        iterable $records,
        int $userId,
        ?string $reason = null,
    ): array {
        $records = $records instanceof Collection ? $records : collect($records);
        $eligibleIds = array_fill_keys($this->auditService->conflictingProductIds('archived'), true);
        $summary = [
            'selected' => $records->count(),
            'requested' => 0,
            'skipped_ineligible' => 0,
            'skipped_existing' => 0,
            'failed' => 0,
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
                $this->deletionRequests->submit($record, $userId, $reason);
                $summary['requested']++;
            } catch (Throwable) {
                $summary['failed']++;
            }
        }

        return $summary;
    }
}
