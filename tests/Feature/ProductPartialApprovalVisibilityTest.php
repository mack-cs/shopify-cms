<?php

use App\Models\Product;
use App\Models\ProductPartialApprovalRequest;
use App\Models\User;
use App\Services\ProductPartialApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('shows pending partial approval requests to all users while keeping individual requests read only for non-target users', function (): void {
    $requester = User::factory()->create();
    $targetApprover = User::factory()->create();
    $otherUser = User::factory()->create();

    $product = Product::create([
        'title' => 'Partial Approval Product',
        'handle' => 'partial-approval-product',
        'status' => 'active',
        'approval_version' => 3,
    ]);

    $request = ProductPartialApprovalRequest::create([
        'product_id' => $product->id,
        'approval_version' => $product->approval_version,
        'requested_by' => $requester->id,
        'request_batch_id' => 'batch-visibility-test',
        'target_approver_id' => $targetApprover->id,
        'status' => ProductPartialApprovalRequest::STATUS_PENDING,
        'scopes' => ['product'],
        'core_fields' => ['title'],
    ]);

    $service = app(ProductPartialApprovalService::class);

    expect($service->visiblePendingRequestsQuery()->pluck('id')->all())->toContain($request->id)
        ->and($service->actionableRequestsQuery($requester->id)->pluck('id')->all())->not->toContain($request->id)
        ->and($service->actionableRequestsQuery($targetApprover->id)->pluck('id')->all())->toContain($request->id)
        ->and($service->actionableRequestsQuery($otherUser->id)->pluck('id')->all())->not->toContain($request->id)
        ->and($service->canApproveRequest($request->fresh('product'), $requester->id))->toBeFalse()
        ->and($service->canApproveRequest($request->fresh('product'), $targetApprover->id))->toBeTrue()
        ->and($service->canApproveRequest($request->fresh('product'), $otherUser->id))->toBeFalse();
});

it('shows open partial approval requests to the sender as well as any-reviewer requests to other users', function (): void {
    $requester = User::factory()->create();
    $otherUser = User::factory()->create();

    $product = Product::create([
        'title' => 'Open Queue Product',
        'handle' => 'open-queue-product',
        'status' => 'active',
        'approval_version' => 1,
    ]);

    $request = ProductPartialApprovalRequest::create([
        'product_id' => $product->id,
        'approval_version' => $product->approval_version,
        'requested_by' => $requester->id,
        'request_batch_id' => 'batch-open-queue',
        'target_approver_id' => null,
        'status' => ProductPartialApprovalRequest::STATUS_PENDING,
        'scopes' => ['product'],
        'core_fields' => ['title'],
    ]);

    $service = app(ProductPartialApprovalService::class);

    expect($service->visiblePendingRequestsQuery()->pluck('id')->all())->toContain($request->id)
        ->and($service->actionableRequestsQuery($requester->id)->pluck('id')->all())->not->toContain($request->id)
        ->and($service->actionableRequestsQuery($otherUser->id)->pluck('id')->all())->toContain($request->id)
        ->and($service->canApproveRequest($request->fresh('product'), $otherUser->id))->toBeTrue();
});
