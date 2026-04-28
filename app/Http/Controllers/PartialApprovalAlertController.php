<?php

namespace App\Http\Controllers;

use App\Filament\Resources\ProductPartialApprovalRequestResource;
use App\Services\ProductPartialApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PartialApprovalAlertController extends Controller
{
    public function __invoke(Request $request, ProductPartialApprovalService $service): JsonResponse
    {
        $user = $request->user();
        if (!$user) {
            return response()->json(['requests' => []]);
        }

        $requests = $service->actionableRequestsQuery((int) $user->id)
            ->latest('id')
            ->get()
            ->map(function ($partialRequest) use ($service): array {
                return [
                    'id' => (int) $partialRequest->id,
                    'product_id' => (int) ($partialRequest->product_id ?? 0),
                    'title' => trim((string) ($partialRequest->product?->title ?? '')) ?: ('Product #' . $partialRequest->product_id),
                    'handle' => trim((string) ($partialRequest->product?->handle ?? '')),
                    'requested_by' => $partialRequest->requester?->name ?: 'Unknown user',
                    'requested_fields' => $service->requestFieldLabels(
                        is_array($partialRequest->scopes) ? $partialRequest->scopes : [],
                        is_array($partialRequest->core_fields) ? $partialRequest->core_fields : [],
                    ),
                    'queue_url' => ProductPartialApprovalRequestResource::getUrl('index'),
                ];
            })
            ->values()
            ->all();

        return response()->json(['requests' => $requests]);
    }
}
