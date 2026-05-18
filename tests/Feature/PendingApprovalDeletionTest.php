<?php

use App\Models\ChangeLog;
use App\Models\CollectionApprovalRequest;
use App\Models\Import;
use App\Models\Product;
use App\Models\ProductPartialApprovalRequest;
use App\Models\ShopifyCollection;
use App\Models\User;
use App\Services\CollectionApprovalRequestService;
use App\Services\ProductPartialApprovalService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('deletes pending product partial approval requests and logs the super admin who deleted them', function (): void {
    $requester = User::factory()->create();
    $superAdmin = User::factory()->create();

    $import = Import::create([
        'filename' => 'shopify-products',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $requester->id,
        'is_current' => true,
        'is_valid' => true,
    ]);

    $product = Product::create([
        'import_id' => $import->id,
        'title' => 'Approval Product',
        'handle' => 'approval-product',
        'status' => 'active',
        'approval_version' => 2,
    ]);

    $request = ProductPartialApprovalRequest::create([
        'product_id' => $product->id,
        'approval_version' => $product->approval_version,
        'requested_by' => $requester->id,
        'request_batch_id' => 'product-delete-batch',
        'target_approver_id' => null,
        'status' => ProductPartialApprovalRequest::STATUS_PENDING,
        'scopes' => ['product'],
        'core_fields' => ['title'],
    ]);

    $summary = app(ProductPartialApprovalService::class)->deletePendingRequests(collect([$request->fresh('product')]), $superAdmin->id);

    expect($summary['deleted'])->toBe(1)
        ->and(ProductPartialApprovalRequest::query()->whereKey($request->id)->exists())->toBeFalse();

    $log = ChangeLog::query()
        ->where('model_type', ProductPartialApprovalRequest::class)
        ->where('model_id', $request->id)
        ->where('field', 'pending_approval_deleted')
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and((int) $log->changed_by)->toBe($superAdmin->id);
});

it('deletes pending collection approval requests and logs the super admin who deleted them', function (): void {
    $requester = User::factory()->create();
    $superAdmin = User::factory()->create();

    $collection = ShopifyCollection::create([
        'title' => 'Approval Collection',
        'handle' => 'approval-collection',
        'approval_version' => 3,
    ]);

    $request = CollectionApprovalRequest::create([
        'collection_id' => $collection->id,
        'approval_version' => $collection->approval_version,
        'requested_by' => $requester->id,
        'request_batch_id' => 'collection-delete-batch',
        'target_approver_id' => null,
        'status' => CollectionApprovalRequest::STATUS_PENDING,
    ]);

    $summary = app(CollectionApprovalRequestService::class)->deletePendingRequests(collect([$request->fresh('collection')]), $superAdmin->id);

    expect($summary['deleted'])->toBe(1)
        ->and(CollectionApprovalRequest::query()->whereKey($request->id)->exists())->toBeFalse();

    $log = ChangeLog::query()
        ->where('model_type', CollectionApprovalRequest::class)
        ->where('model_id', $request->id)
        ->where('field', 'pending_approval_deleted')
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and((int) $log->changed_by)->toBe($superAdmin->id);
});
