<?php

namespace App\Http\Controllers;

use App\Enums\RolesEnum;
use App\Models\DeletionRequest;
use App\Services\DeletionRequestWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DeleteApprovalAlertController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['requests' => []]);
        }

        [$canApproveDrafts, $canApproveProducts] = $this->approvalFlags($request);

        if (!$canApproveDrafts && !$canApproveProducts) {
            return response()->json(['requests' => []]);
        }

        $requests = DeletionRequest::query()
            ->with(['requester:id,name'])
            ->withCount('approvals')
            ->where('status', DeletionRequest::STATUS_PENDING)
            ->where('requested_by', '!=', $user->id)
            ->whereDoesntHave('approvals', fn ($query) => $query->where('user_id', $user->id))
            ->where(function ($query) use ($canApproveDrafts, $canApproveProducts): void {
                if ($canApproveDrafts) {
                    $query->orWhere('entity_type', 'draft');
                }

                if ($canApproveProducts) {
                    $query->orWhereIn('entity_type', ['product', 'collection']);
                }
            })
            ->latest('id')
            ->get()
            ->map(fn (DeletionRequest $deletionRequest): array => [
                'id' => $deletionRequest->id,
                'entity_type' => $deletionRequest->entity_type,
                'title' => $deletionRequest->entity_title,
                'handle' => $deletionRequest->entity_handle,
                'reason' => $deletionRequest->reason,
                'requested_by' => $deletionRequest->requester?->name ?: 'Unknown user',
                'approval_count' => (int) $deletionRequest->approvals_count,
                'created_at' => $deletionRequest->created_at?->toDateTimeString(),
            ])
            ->values()
            ->all();

        return response()->json(['requests' => $requests]);
    }

    public function approve(Request $request, DeletionRequest $deletionRequest, DeletionRequestWorkflowService $workflow): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        [$canApproveDrafts, $canApproveProducts] = $this->approvalFlags($request);

        $isAllowed = match ($deletionRequest->entity_type) {
            'draft' => $canApproveDrafts,
            'product', 'collection' => $canApproveProducts,
            default => false,
        };

        if (!$isAllowed) {
            abort(403);
        }

        if ((int) $deletionRequest->requested_by === (int) $user->id) {
            return response()->json([
                'message' => 'You cannot approve your own delete request.',
            ], 422);
        }

        try {
            $result = $workflow->approveRequest($deletionRequest, (int) $user->id);

            return response()->json([
                'message' => ($result['queued'] ?? false)
                    ? 'Delete approved and queued.'
                    : 'Delete approval recorded.',
                'queued' => (bool) ($result['queued'] ?? false),
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    public function reject(Request $request, DeletionRequest $deletionRequest, DeletionRequestWorkflowService $workflow): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        [$canApproveDrafts, $canApproveProducts] = $this->approvalFlags($request);

        $isAllowed = match ($deletionRequest->entity_type) {
            'draft' => $canApproveDrafts,
            'product', 'collection' => $canApproveProducts,
            default => false,
        };

        if (!$isAllowed) {
            abort(403);
        }

        if ((int) $deletionRequest->requested_by === (int) $user->id) {
            return response()->json([
                'message' => 'You cannot reject your own delete request.',
            ], 422);
        }

        try {
            $rejected = $workflow->rejectRequest(
                $deletionRequest,
                (int) $user->id,
                trim((string) $request->input('reason', '')) ?: null
            );

            return response()->json([
                'message' => 'Delete request rejected.',
                'reason' => $rejected->rejection_reason,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * @return array{bool,bool}
     */
    private function approvalFlags(Request $request): array
    {
        $user = $request->user();

        $canApproveDrafts = $user?->hasAnyRole([RolesEnum::SuperAdmin->value, RolesEnum::Admin->value]) ?? false;
        $canApproveProducts = $user?->hasRole(RolesEnum::SuperAdmin->value) ?? false;

        return [$canApproveDrafts, $canApproveProducts];
    }
}
