<?php

use App\Models\CollectionApprovalRequest;
use App\Models\Import;
use App\Models\ShopifyCollection;
use App\Models\User;
use App\Services\CollectionApprovalRequestService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates collection approval requests and skips duplicate pending requests', function () {
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $import = Import::create([
        'filename' => 'shopify-collections',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $requester->id,
        'is_current' => true,
        'is_valid' => true,
    ]);

    $collection = ShopifyCollection::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Collection/201',
        'handle' => 'approval-request',
        'title' => 'Approval Request',
        'approval_version' => 1,
    ]);

    $service = app(CollectionApprovalRequestService::class);

    $first = $service->request(collect([$collection]), $requester->id, $approver->id, 'Please review');
    $second = $service->request(collect([$collection]), $requester->id, $approver->id, 'Please review');

    expect($first['requested'])->toBe(1)
        ->and($second['requested'])->toBe(0)
        ->and($second['skipped_existing'])->toBe(1);
});

it('approves a pending collection approval request and records the approval', function () {
    $requester = User::factory()->create();
    $approver = User::factory()->create();
    $import = Import::create([
        'filename' => 'shopify-collections',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $requester->id,
        'is_current' => true,
        'is_valid' => true,
    ]);

    $collection = ShopifyCollection::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Collection/202',
        'handle' => 'approval-approve',
        'title' => 'Approval Approve',
        'approval_version' => 1,
    ]);

    $request = CollectionApprovalRequest::create([
        'collection_id' => $collection->id,
        'approval_version' => 1,
        'requested_by' => $requester->id,
        'target_approver_id' => $approver->id,
        'status' => CollectionApprovalRequest::STATUS_PENDING,
    ]);

    $summary = app(CollectionApprovalRequestService::class)->approveRequests(collect([$request]), $approver->id);

    $request->refresh();
    $collection->refresh();

    expect($summary['approved'])->toBe(1)
        ->and($request->status)->toBe(CollectionApprovalRequest::STATUS_APPROVED)
        ->and($request->approved_by)->toBe($approver->id)
        ->and($collection->approvalsForCurrentVersionCount())->toBe(1);
});
