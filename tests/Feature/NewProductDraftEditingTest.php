<?php

use App\Filament\Resources\NewProductDraftResource;
use App\Models\NewProductDraft;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lets a user acquire refresh and release a draft edit lock', function (): void {
    $user = User::factory()->create();

    $draft = NewProductDraft::create([
        'title' => 'Lock Test Draft',
        'status' => 'draft',
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
        'approval_version' => 1,
    ]);

    expect($draft->acquireEditLock($user->id, 15))->toBeTrue();

    $draft->refresh();

    expect((int) $draft->editing_user_id)->toBe($user->id);
    expect($draft->editing_started_at)->not->toBeNull();
    expect($draft->editing_expires_at)->not->toBeNull();
    expect($draft->isActivelyEditedByAnotherUser($user->id, 15))->toBeFalse();

    expect($draft->refreshEditLock($user->id, 15))->toBeTrue();
    expect($draft->releaseEditLock($user->id))->toBeTrue();

    $draft->refresh();

    expect($draft->editing_user_id)->toBeNull();
    expect($draft->editing_started_at)->toBeNull();
    expect($draft->editing_expires_at)->toBeNull();
});

it('prevents another user from acquiring an active draft edit lock until it expires', function (): void {
    $firstUser = User::factory()->create();
    $secondUser = User::factory()->create();

    $draft = NewProductDraft::create([
        'title' => 'Locked Draft',
        'status' => 'draft',
        'origin' => NewProductDraft::ORIGIN_DRAFT_TOOL,
        'approval_version' => 1,
    ]);

    expect($draft->acquireEditLock($firstUser->id, 15))->toBeTrue();

    $draft->refresh();

    expect($draft->isActivelyEditedByAnotherUser($secondUser->id, 15))->toBeTrue();
    expect($draft->acquireEditLock($secondUser->id, 15))->toBeFalse();

    $draft->forceFill([
        'editing_expires_at' => now()->subMinute(),
    ])->save();

    $draft->refresh();

    expect($draft->acquireEditLock($secondUser->id, 15))->toBeTrue();
});

it('allows draft form data mutation without forcing required product-completeness fields', function (): void {
    $data = NewProductDraftResource::mutateDraftFormData([
        'title' => '',
        'status' => null,
        'published' => null,
        'extra_shopify_fields' => [],
    ]);

    expect($data['title'])->toBe('');
    expect($data['status'] ?? null)->toBeNull();
    expect($data['published'] ?? null)->toBeNull();
    expect($data['siblings_collection_name'])->toBeNull();
    expect($data['payload'])->toBeNull();
});
