<?php

use App\Models\CollectionApprovalRequest;
use App\Models\ShopifyCollection;
use App\Models\User;
use App\Services\CollectionApprovalRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows pending collection approval requests to all users while keeping targeted requests read only for non-target users', function (): void {
    $requester = User::factory()->create();
    $targetApprover = User::factory()->create();
    $otherUser = User::factory()->create();

    $collection = ShopifyCollection::create([
        'title' => 'Visibility Collection',
        'handle' => 'visibility-collection',
        'approval_version' => 2,
    ]);

    $request = CollectionApprovalRequest::create([
        'collection_id' => $collection->id,
        'approval_version' => $collection->approval_version,
        'requested_by' => $requester->id,
        'request_batch_id' => 'collection-visibility-batch',
        'target_approver_id' => $targetApprover->id,
        'status' => CollectionApprovalRequest::STATUS_PENDING,
    ]);

    $service = app(CollectionApprovalRequestService::class);

    expect($service->visiblePendingRequestsQuery()->pluck('id')->all())->toContain($request->id)
        ->and($service->actionableRequestsQuery($requester->id)->pluck('id')->all())->not->toContain($request->id)
        ->and($service->actionableRequestsQuery($targetApprover->id)->pluck('id')->all())->toContain($request->id)
        ->and($service->actionableRequestsQuery($otherUser->id)->pluck('id')->all())->not->toContain($request->id)
        ->and($service->canApproveRequest($request->fresh('collection'), $requester->id))->toBeFalse()
        ->and($service->canApproveRequest($request->fresh('collection'), $targetApprover->id))->toBeTrue()
        ->and($service->canApproveRequest($request->fresh('collection'), $otherUser->id))->toBeFalse();
});

it('shows open collection approval requests to the sender and other users', function (): void {
    $requester = User::factory()->create();
    $otherUser = User::factory()->create();

    $collection = ShopifyCollection::create([
        'title' => 'Open Collection Queue',
        'handle' => 'open-collection-queue',
        'approval_version' => 1,
    ]);

    $request = CollectionApprovalRequest::create([
        'collection_id' => $collection->id,
        'approval_version' => $collection->approval_version,
        'requested_by' => $requester->id,
        'request_batch_id' => 'collection-open-batch',
        'target_approver_id' => null,
        'status' => CollectionApprovalRequest::STATUS_PENDING,
    ]);

    $service = app(CollectionApprovalRequestService::class);

    expect($service->visiblePendingRequestsQuery()->pluck('id')->all())->toContain($request->id)
        ->and($service->actionableRequestsQuery($requester->id)->pluck('id')->all())->not->toContain($request->id)
        ->and($service->actionableRequestsQuery($otherUser->id)->pluck('id')->all())->toContain($request->id)
        ->and($service->canApproveRequest($request->fresh('collection'), $otherUser->id))->toBeTrue();
});
