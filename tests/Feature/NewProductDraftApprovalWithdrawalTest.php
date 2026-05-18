<?php

use App\Filament\Resources\NewProductDraftResource;
use App\Models\ChangeLog;
use App\Models\NewProductDraft;
use App\Models\NewProductDraftApproval;
use App\Models\Product;
use App\Models\ProductPartialApprovalRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('withdraws a pending draft approval and logs the user who withdrew it', function (): void {
    $requester = User::factory()->create();
    $withdrawer = User::factory()->create();

    $draft = NewProductDraft::create([
        'title' => 'Pending Approval Draft',
        'approval_version' => 4,
        'status' => 'active',
        'created_by' => $requester->id,
    ]);

    $approval = NewProductDraftApproval::create([
        'new_product_draft_id' => $draft->id,
        'user_id' => $requester->id,
        'approval_version' => $draft->approval_version,
    ]);

    expect($draft->fresh()->isPendingApproval())->toBeTrue();

    $result = NewProductDraftResource::withdrawDraftFromApproval($draft->fresh(), $withdrawer->id);

    $draft->refresh();

    expect($result['removed'])->toBe(1)
        ->and($draft->isPendingApproval())->toBeFalse()
        ->and(NewProductDraftApproval::query()
            ->where('new_product_draft_id', $draft->id)
            ->where('approval_version', $draft->approval_version)
            ->count())->toBe(0);

    $log = ChangeLog::query()
        ->where('model_type', NewProductDraft::class)
        ->where('model_id', $draft->id)
        ->where('field', 'approval_withdrawn')
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and((int) $log->changed_by)->toBe($withdrawer->id);

    $payload = json_decode((string) $log->new_value, true);

    expect($payload)->toMatchArray([
        'status' => 'withdrawn',
        'approval_version' => $draft->approval_version,
        'handle' => $draft->handle,
        'withdrawn_by' => $withdrawer->id,
    ])
        ->and($payload['removed_approvals'][0]['id'] ?? null)->toBe($approval->id)
        ->and($payload['removed_approvals'][0]['user_id'] ?? null)->toBe($requester->id);
});

it('treats drafts with handles as pending when the linked product has a pending partial approval request', function (): void {
    $requester = User::factory()->create();
    $withdrawer = User::factory()->create();

    $product = Product::create([
        'title' => 'Handled Product',
        'handle' => 'handled-draft',
        'status' => 'active',
        'approval_version' => 2,
    ]);

    $draft = NewProductDraft::create([
        'title' => 'Handled Draft',
        'handle' => $product->handle,
        'approval_version' => 2,
        'status' => 'active',
        'created_by' => $requester->id,
    ]);

    $request = ProductPartialApprovalRequest::create([
        'product_id' => $product->id,
        'approval_version' => $product->approval_version,
        'requested_by' => $requester->id,
        'request_batch_id' => 'handled-draft-request',
        'target_approver_id' => null,
        'status' => ProductPartialApprovalRequest::STATUS_PENDING,
        'scopes' => ['product'],
        'core_fields' => ['title'],
    ]);

    expect($draft->fresh()->isPendingApproval())->toBeTrue();

    $result = NewProductDraftResource::withdrawDraftFromApproval($draft->fresh(), $withdrawer->id);

    expect($result['removed'])->toBe(1)
        ->and(ProductPartialApprovalRequest::query()->whereKey($request->id)->exists())->toBeFalse();
});
