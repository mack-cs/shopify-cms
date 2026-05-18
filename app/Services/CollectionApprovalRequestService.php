<?php

namespace App\Services;

use App\Enums\RolesEnum;
use App\Filament\Resources\ShopifyCollectionResource;
use App\Models\ChangeLog;
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
        return $this->visiblePendingRequestsQuery()
            ->where('requested_by', '!=', $userId)
            ->where(function (Builder $query) use ($userId): void {
                $query->whereNull('target_approver_id')
                    ->orWhere('target_approver_id', $userId);
            })
            ->whereNotExists(function ($sub) use ($userId): void {
                $sub->selectRaw('1')
                    ->from('collection_approvals')
                    ->whereColumn('collection_approvals.collection_id', 'collection_approval_requests.collection_id')
                    ->whereColumn('collection_approvals.approval_version', 'collection_approval_requests.approval_version')
                    ->where('collection_approvals.user_id', $userId);
            });
    }

    public function visiblePendingRequestsQuery(): Builder
    {
        return CollectionApprovalRequest::query()
            ->with(['collection', 'requester', 'targetApprover'])
            ->where('status', CollectionApprovalRequest::STATUS_PENDING)
            ->whereHas('collection', function (Builder $query): void {
                $query->whereColumn('collections.approval_version', 'collection_approval_requests.approval_version');
            });
    }

    public function canApproveRequest(CollectionApprovalRequest $request, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        if ($request->status !== CollectionApprovalRequest::STATUS_PENDING) {
            return false;
        }

        $collection = $request->collection;
        if (!$collection instanceof ShopifyCollection || (int) $request->approval_version !== (int) ($collection->approval_version ?? 0)) {
            return false;
        }

        if ((int) $request->requested_by === $userId) {
            return false;
        }

        if ($request->target_approver_id !== null && (int) $request->target_approver_id !== $userId) {
            return false;
        }

        return !\DB::table('collection_approvals')
            ->where('collection_id', $request->collection_id)
            ->where('approval_version', $request->approval_version)
            ->where('user_id', $userId)
            ->exists();
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

    /**
     * @param Collection<int, CollectionApprovalRequest> $requests
     * @return array{deleted:int,skipped_not_pending:int}
     */
    public function deletePendingRequests(Collection $requests, int $userId): array
    {
        $deleted = 0;
        $skippedNotPending = 0;

        foreach ($requests as $request) {
            if (!$request instanceof CollectionApprovalRequest) {
                continue;
            }

            if ($request->status !== CollectionApprovalRequest::STATUS_PENDING) {
                $skippedNotPending++;
                continue;
            }

            ChangeLog::create([
                'import_id' => null,
                'product_id' => null,
                'changed_by' => $userId,
                'model_type' => CollectionApprovalRequest::class,
                'model_id' => $request->id,
                'field' => 'pending_approval_deleted',
                'old_value' => null,
                'new_value' => json_encode([
                    'status' => 'deleted',
                    'request_id' => (int) $request->id,
                    'request_batch_id' => $request->request_batch_id,
                    'collection_id' => (int) $request->collection_id,
                    'requested_by' => (int) $request->requested_by,
                    'target_approver_id' => $request->target_approver_id !== null ? (int) $request->target_approver_id : null,
                    'approval_version' => (int) $request->approval_version,
                    'deleted_by' => $userId,
                    'deleted_at' => now()->toDateTimeString(),
                ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ]);

            $request->delete();
            $deleted++;
        }

        return [
            'deleted' => $deleted,
            'skipped_not_pending' => $skippedNotPending,
        ];
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
