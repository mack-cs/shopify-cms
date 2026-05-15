<?php

namespace App\Services;

use App\Enums\RolesEnum;
use App\Filament\Resources\ShopifyCollectionResource;
use App\Models\CollectionApprovalRequest;
use App\Models\ShopifyCollection;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class CollectionApprovalRequestService
{
    public function actionableRequestsQuery(int $userId): Builder
    {
        return CollectionApprovalRequest::query()
            ->with(['collection', 'requester', 'targetApprover'])
            ->where('status', CollectionApprovalRequest::STATUS_PENDING)
            ->where('requested_by', '!=', $userId)
            ->where(function (Builder $query) use ($userId): void {
                $query->whereNull('target_approver_id')
                    ->orWhere('target_approver_id', $userId);
            })
            ->whereHas('collection', function (Builder $query): void {
                $query->whereColumn('collections.approval_version', 'collection_approval_requests.approval_version');
            })
            ->whereNotExists(function ($sub) use ($userId): void {
                $sub->selectRaw('1')
                    ->from('collection_approvals')
                    ->whereColumn('collection_approvals.collection_id', 'collection_approval_requests.collection_id')
                    ->whereColumn('collection_approvals.approval_version', 'collection_approval_requests.approval_version')
                    ->where('collection_approvals.user_id', $userId);
            });
    }

    public function eligibleApproversQuery(?int $excludeUserId = null): Builder
    {
        $query = User::query()
            ->where('is_active', true)
            ->whereHas('roles', function (Builder $roleQuery): void {
                $roleQuery->whereIn('name', [
                    RolesEnum::SuperAdmin->value,
                    RolesEnum::Admin->value,
                    RolesEnum::SeoReviewer->value,
                ]);
            })
            ->orderBy('name');

        if ($excludeUserId !== null && $excludeUserId > 0) {
            $query->whereKeyNot($excludeUserId);
        }

        return $query;
    }

    /**
     * @param Collection<int, ShopifyCollection> $collections
     * @return array{requested:int,skipped_existing:int,existing_collections:array<int,array{id:int,title:string}>,request_batch_id:?string}
     */
    public function request(
        Collection $collections,
        int $userId,
        ?int $targetApproverId = null,
        ?string $requestNote = null,
    ): array {
        $summary = [
            'requested' => 0,
            'skipped_existing' => 0,
            'existing_collections' => [],
            'request_batch_id' => null,
        ];

        $targetApproverId = $this->normalizeTargetApproverId($targetApproverId, $userId);
        $requestNote = $this->normalizeRequestNote($requestNote);
        $requestBatchId = (string) Str::uuid();

        foreach ($collections as $collection) {
            if (!$collection instanceof ShopifyCollection) {
                continue;
            }

            $existing = CollectionApprovalRequest::query()
                ->where('collection_id', $collection->id)
                ->where('approval_version', $collection->approval_version)
                ->where('status', CollectionApprovalRequest::STATUS_PENDING)
                ->exists();

            if ($existing) {
                $summary['skipped_existing']++;
                $summary['existing_collections'][] = [
                    'id' => (int) $collection->id,
                    'title' => trim((string) ($collection->title ?? '')) ?: ('Collection #' . $collection->id),
                ];
                continue;
            }

            CollectionApprovalRequest::create([
                'collection_id' => $collection->id,
                'approval_version' => $collection->approval_version,
                'requested_by' => $userId,
                'request_batch_id' => $requestBatchId,
                'target_approver_id' => $targetApproverId,
                'status' => CollectionApprovalRequest::STATUS_PENDING,
                'request_note' => $requestNote,
            ]);

            $summary['requested']++;
            $summary['request_batch_id'] = $requestBatchId;
        }

        return $summary;
    }

    /**
     * @param Collection<int, CollectionApprovalRequest> $requests
     * @return array{approved:int,skipped_not_pending:int,skipped_stale:int,skipped_own_request:int,skipped_targeted:int,skipped_already_approved:int}
     */
    public function approveRequests(Collection $requests, int $userId): array
    {
        $summary = [
            'approved' => 0,
            'skipped_not_pending' => 0,
            'skipped_stale' => 0,
            'skipped_own_request' => 0,
            'skipped_targeted' => 0,
            'skipped_already_approved' => 0,
        ];

        foreach ($requests as $request) {
            if (!$request instanceof CollectionApprovalRequest) {
                continue;
            }

            if ($request->status !== CollectionApprovalRequest::STATUS_PENDING) {
                $summary['skipped_not_pending']++;
                continue;
            }

            $collection = $request->collection;
            if (!$collection instanceof ShopifyCollection || (int) $request->approval_version !== (int) ($collection->approval_version ?? 0)) {
                $summary['skipped_stale']++;
                continue;
            }

            if ((int) $request->requested_by === $userId) {
                $summary['skipped_own_request']++;
                continue;
            }

            if ($request->target_approver_id !== null && (int) $request->target_approver_id !== $userId) {
                $summary['skipped_targeted']++;
                continue;
            }

            $result = ShopifyCollectionResource::approveRecord($collection, $userId);

            if (($result['status'] ?? '') === 'already-approved') {
                $summary['skipped_already_approved']++;
                continue;
            }

            if (($result['status'] ?? '') !== 'approved') {
                $summary['skipped_not_pending']++;
                continue;
            }

            $request->forceFill([
                'status' => CollectionApprovalRequest::STATUS_APPROVED,
                'approved_by' => $userId,
                'approved_at' => now(),
            ])->save();

            $summary['approved']++;
        }

        return $summary;
    }

    public function batchLabel(?string $batchId): string
    {
        $value = trim((string) $batchId);

        if ($value === '') {
            return 'Legacy';
        }

        return strtoupper(substr(str_replace('-', '', $value), 0, 8));
    }

    private function normalizeTargetApproverId(?int $targetApproverId, int $requesterId): ?int
    {
        if ($targetApproverId === null || $targetApproverId <= 0 || $targetApproverId === $requesterId) {
            return null;
        }

        return $this->eligibleApproversQuery($requesterId)
            ->whereKey($targetApproverId)
            ->exists()
            ? $targetApproverId
            : null;
    }

    private function normalizeRequestNote(?string $requestNote): ?string
    {
        $value = trim((string) $requestNote);

        return $value === '' ? null : $value;
    }
}
