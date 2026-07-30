<?php

namespace App\Services;

use App\Jobs\ProcessDeletionRequestJob;
use App\Enums\RolesEnum;
use App\Models\ChangeLog;
use App\Models\DeletionRequest;
use App\Models\DeletionRequestApproval;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyCollection;
use App\Models\User;
use App\Services\AdminNotification;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

final class DeletionRequestWorkflowService
{
    public function submit(
        Model $record,
        int $userId,
        ?string $reason = null,
        ?int $targetApproverId = null,
    ): DeletionRequest
    {
        $existing = $this->openRequestFor($record);
        if ($existing) {
            throw new \RuntimeException('A delete request is already pending for this record.');
        }

        if ($targetApproverId !== null) {
            $isEligible = $this->eligibleApproversQuery($record, $userId)
                ->whereKey($targetApproverId)
                ->exists();

            if (!$isEligible) {
                throw new \RuntimeException('The selected deletion approver is not eligible for this request.');
            }
        }

        $request = DeletionRequest::create([
            'deletable_type' => $record::class,
            'deletable_id' => $record->getKey(),
            'import_id' => $this->importIdFor($record),
            'requested_by' => $userId,
            'target_approver_id' => $targetApproverId,
            'entity_type' => $this->entityTypeFor($record),
            'entity_title' => $this->entityTitleFor($record),
            'entity_handle' => $this->entityHandleFor($record),
            'shopify_id' => $this->shopifyIdFor($record),
            'reason' => $this->trimToNull($reason),
            'status' => DeletionRequest::STATUS_PENDING,
        ]);

        DeletionRequestApproval::create([
            'deletion_request_id' => $request->id,
            'user_id' => $userId,
        ]);

        $this->log($request, 'deletion_requested', $userId, [
            'status' => $request->status,
            'reason' => $request->reason,
            'entity_type' => $request->entity_type,
            'title' => $request->entity_title,
            'handle' => $request->entity_handle,
            'shopify_id' => $request->shopify_id,
            'approvals' => 1,
            'target_approver_id' => $targetApproverId,
        ]);

        $this->notifyApprovers($request, $record, $userId);

        return $request->fresh(['approvals']) ?? $request;
    }

    /**
     * @return array{request:DeletionRequest,queued:bool}
     */
    public function approve(Model $record, int $userId): array
    {
        $request = $this->openRequestFor($record);
        if (!$request) {
            throw new \RuntimeException('No open delete request exists for this record.');
        }

        return $this->approveRequest($request, $userId);
    }

    /**
     * @return array{request:DeletionRequest,queued:bool}
     */
    public function approveRequest(DeletionRequest $request, int $userId): array
    {
        if ($request->status !== DeletionRequest::STATUS_PENDING) {
            throw new \RuntimeException('This delete request is already being processed.');
        }

        if ($request->userHasApproved($userId)) {
            throw new \RuntimeException('You have already approved this delete request.');
        }

        if (
            $request->target_approver_id !== null
            && (int) $request->target_approver_id !== $userId
        ) {
            throw new \RuntimeException('This delete request is assigned to another approver.');
        }

        DeletionRequestApproval::create([
            'deletion_request_id' => $request->id,
            'user_id' => $userId,
        ]);

        $request = $request->fresh(['approvals']) ?? $request;

        $this->log($request, 'deletion_approved', $userId, [
            'status' => $request->status,
            'entity_type' => $request->entity_type,
            'title' => $request->entity_title,
            'handle' => $request->entity_handle,
            'shopify_id' => $request->shopify_id,
            'approvals' => $request->approvalCount(),
        ]);

        $queued = false;
        if ($request->isApprovedByTwo()) {
            $request->forceFill([
                'status' => DeletionRequest::STATUS_PROCESSING,
            ])->save();

            ProcessDeletionRequestJob::dispatch($request->id, $userId);
            $queued = true;
        }

        return [
            'request' => $request->fresh(['approvals']) ?? $request,
            'queued' => $queued,
        ];
    }

    public function openRequestFor(Model $record): ?DeletionRequest
    {
        return DeletionRequest::query()
            ->where('deletable_type', $record::class)
            ->where('deletable_id', $record->getKey())
            ->whereIn('status', [DeletionRequest::STATUS_PENDING, DeletionRequest::STATUS_PROCESSING])
            ->latest('id')
            ->first();
    }

    public function reject(Model $record, int $userId, ?string $reason = null): DeletionRequest
    {
        $request = $this->openRequestFor($record);
        if (!$request) {
            throw new \RuntimeException('No open delete request exists for this record.');
        }

        return $this->rejectRequest($request, $userId, $reason);
    }

    public function rejectRequest(DeletionRequest $request, int $userId, ?string $reason = null): DeletionRequest
    {
        if ($request->status !== DeletionRequest::STATUS_PENDING) {
            throw new \RuntimeException('This delete request can no longer be rejected.');
        }

        if ($request->userHasApproved($userId)) {
            throw new \RuntimeException('You cannot reject a delete request you already approved.');
        }

        if (
            $request->target_approver_id !== null
            && (int) $request->target_approver_id !== $userId
        ) {
            throw new \RuntimeException('This delete request is assigned to another approver.');
        }

        $request->forceFill([
            'status' => DeletionRequest::STATUS_REJECTED,
            'rejected_by' => $userId,
            'rejected_at' => now(),
            'rejection_reason' => $this->trimToNull($reason),
            'failure_message' => null,
        ])->save();

        $this->log($request, 'deletion_rejected', $userId, [
            'status' => $request->status,
            'entity_type' => $request->entity_type,
            'title' => $request->entity_title,
            'handle' => $request->entity_handle,
            'shopify_id' => $request->shopify_id,
            'reason' => $request->rejection_reason,
        ]);

        $this->notifyRequesterOfRejection($request);

        return $request->fresh(['approvals']) ?? $request;
    }

    private function entityTypeFor(Model $record): string
    {
        return match (true) {
            $record instanceof Product => 'product',
            $record instanceof ShopifyCollection => 'collection',
            $record instanceof NewProductDraft => 'draft',
            default => 'record',
        };
    }

    private function importIdFor(Model $record): ?int
    {
        $importId = $record->import_id ?? null;
        if ($importId !== null) {
            return (int) $importId;
        }

        if ($record instanceof NewProductDraft) {
            return $record->product?->import_id;
        }

        return null;
    }

    private function entityTitleFor(Model $record): ?string
    {
        return $this->trimToNull($record->title ?? null);
    }

    private function entityHandleFor(Model $record): ?string
    {
        return $this->trimToNull($record->handle ?? null);
    }

    private function shopifyIdFor(Model $record): ?string
    {
        return $this->trimToNull($record->shopify_id ?? null);
    }

    private function trimToNull(mixed $value): ?string
    {
        $trimmed = trim((string) ($value ?? ''));

        return $trimmed === '' ? null : $trimmed;
    }

    private function log(DeletionRequest $request, string $field, int $changedBy, array $payload): void
    {
        ChangeLog::create([
            'import_id' => $request->import_id,
            'product_id' => $request->entity_type === 'product' ? (int) $request->deletable_id : null,
            'changed_by' => $changedBy,
            'model_type' => $request->deletable_type,
            'model_id' => $request->deletable_id,
            'field' => $field,
            'old_value' => null,
            'new_value' => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    private function notifyApprovers(DeletionRequest $request, Model $record, int $requestedBy): void
    {
        $recipientIds = $this->approverUserIdsFor($request, $record, $requestedBy);
        if ($recipientIds === []) {
            return;
        }

        $label = $request->entity_title ?: $request->entity_handle ?: 'record';

        foreach ($recipientIds as $userId) {
            AdminNotification::sendToUserId(
                Notification::make()
                    ->title('Delete approval requested')
                    ->body(ucfirst($request->entity_type) . " delete requested for {$label}. Open the relevant section to review and approve.")
                    ->warning(),
                $userId
            );
        }
    }

    private function notifyRequesterOfRejection(DeletionRequest $request): void
    {
        $requesterId = (int) ($request->requested_by ?? 0);
        if ($requesterId <= 0) {
            return;
        }

        $label = $request->entity_title ?: $request->entity_handle ?: 'record';
        $body = ucfirst($request->entity_type) . " delete request for {$label} was rejected.";

        if ($request->rejection_reason) {
            $body .= " Reason: {$request->rejection_reason}";
        }

        AdminNotification::sendToUserId(
            Notification::make()
                ->title('Delete request rejected')
                ->body($body)
                ->danger(),
            $requesterId
        );
    }

    /**
     * @return array<int>
     */
    private function approverUserIdsFor(
        DeletionRequest $request,
        Model $record,
        int $requestedBy,
    ): array
    {
        $targetApproverId = (int) $request->target_approver_id;

        if ($targetApproverId > 0) {
            return [$targetApproverId];
        }

        return $this->eligibleApproversQuery($record, $requestedBy)
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    public function eligibleApproversQuery(Model $record, ?int $excludeUserId = null): Builder
    {
        $roles = match (true) {
            $record instanceof NewProductDraft => [RolesEnum::SuperAdmin->value, RolesEnum::Admin->value],
            $record instanceof Product => [RolesEnum::SuperAdmin->value, RolesEnum::Admin->value],
            $record instanceof ShopifyCollection => [RolesEnum::SuperAdmin->value],
            default => [],
        };

        if ($roles === []) {
            return User::query()->whereRaw('1 = 0');
        }

        $query = User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $roles))
            ->orderBy('name');

        if ($excludeUserId !== null && $excludeUserId > 0) {
            $query->whereKeyNot($excludeUserId);
        }

        return $query;
    }
}
