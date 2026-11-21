<?php

use App\Jobs\SynchronizeAuthorizedStackImagesJob;
use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ProductPartialApprovalRequest;
use App\Models\User;
use App\Services\ComponentStackPropagation;
use App\Services\ImageOwnership;
use App\Services\ImageSyncMutex;
use App\Services\InheritedAltResolution;
use App\Services\StackImageSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobQueueing;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/shopify-image-import.php';

function phase4Flags(): void
{
    config([
        'images.component_stack_propagation' => false,
        'images.component_stack_local_rebuild' => false,
        'images.component_stack_blind_publication' => false,
    ]);
}

function phase4AuthorizeRecovery(): void
{
    config([
        'images.component_stack_propagation' => true,
        'images.component_stack_blind_publication' => true,
    ]);
}

function phase4RunningCursor(Product $stack, int $desired = 4, int $working = 2): void
{
    DB::table('stack_image_sync_cursors')->insert([
        'stack_product_id' => $stack->id,
        'desired_generation' => $desired,
        'working_generation' => $working,
        'status' => 'running',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function phase4Stack(string $sku, array $componentIds): Product
{
    $stack = createImageImportProduct($sku, ['is_bundle' => true, 'status' => 'active']);
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create([
        'sku' => $sku,
        'shopify_id' => $stack->shopify_id,
        'handle' => $stack->handle,
        'title' => $sku,
        'status' => 'active',
        'bundle_product_ids' => $componentIds,
    ]));

    return $stack;
}

function phase4Component(string $sku, string $bytes, string $alt): array
{
    Storage::fake('public');
    $product = createImageImportProduct($sku);
    $asset = createImportTestAsset($bytes, 'jpg', $sku.'.jpg');
    $image = Image::withoutEvents(fn () => Image::create([
        'product_id' => $product->id,
        'position' => 1,
        'image_asset_id' => $asset->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'alt_text' => $alt,
        'needs_shopify_image_sync' => false,
    ]));

    return [$product, $image->fresh('imageAsset')];
}

function phase4ApproveAlt(Product $product, Image $image, string $sentence): void
{
    $operator = User::factory()->create();
    ProductPartialApprovalRequest::withoutEvents(fn () => ProductPartialApprovalRequest::create([
        'product_id' => $product->id,
        'approval_version' => $product->approval_version ?? 1,
        'requested_by' => $operator->id,
        'status' => ProductPartialApprovalRequest::STATUS_APPROVED,
        'scopes' => ['image_alt'],
        'core_fields' => [],
        'request_batch_id' => (string) Str::uuid(),
        'image_alt_snapshot' => ['images' => [[
            'image_id' => $image->id,
            'image_asset_id' => $image->image_asset_id,
            'proposed_alt' => $sentence,
            'changed' => true,
            'photograph_version' => max(1, (int) $image->fresh()->photograph_version),
            'alt_text_version' => max(1, (int) $image->fresh()->alt_text_version),
        ]]],
        'approved_at' => now(),
    ]));
}

it('releases a running cursor when staging throws and a later approval can run', function (): void {
    phase4Flags();
    Bus::fake();
    Http::preventStrayRequests();
    [$component] = phase4Component('CURSOR', 'cursor-bytes', 'Cursor');
    $stack = phase4Stack('CURSOR-STACK', [$component->id]);
    DB::table('stack_image_sync_cursors')->insert([
        'stack_product_id' => $stack->id,
        'desired_generation' => 1,
        'working_generation' => 0,
        'status' => 'queued',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $propagation = Mockery::mock(app(ComponentStackPropagation::class))->makePartial();
    $propagation->shouldReceive('stageStack')->andThrow(new RuntimeException('storage failed'));
    app()->instance(ComponentStackPropagation::class, $propagation);

    expect(fn () => app(StackImageSync::class)->synchronizeStack((int) $stack->id))->toThrow(RuntimeException::class);
    expect(DB::table('stack_image_sync_cursors')->where('stack_product_id', $stack->id)->value('status'))->not->toBe('running');

    app(StackImageSync::class)->queueStack((int) $stack->id);

    expect(DB::table('stack_image_sync_cursors')->where('stack_product_id', $stack->id)->value('status'))->toBe('queued')
        ->and(DB::table('stack_image_sync_cursors')->where('stack_product_id', $stack->id)->value('desired_generation'))->toBe(2);
    Bus::assertDispatched(SynchronizeAuthorizedStackImagesJob::class);
});

it('does not retry an uncertain shopify operation when the worker dies', function (): void {
    phase4Flags();
    Bus::fake();
    [$component, $image] = phase4Component('UNSAFE', 'unsafe-bytes', 'Unsafe');
    $stack = phase4Stack('UNSAFE-STACK', [$component->id]);
    $copy = Image::withoutEvents(fn () => Image::create([
        'product_id' => $stack->id,
        'position' => 1,
        'ownership' => ImageOwnership::COMPONENT_INHERITED,
        'source_image_id' => $image->id,
        'image_asset_id' => $image->image_asset_id,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_id' => 'gid://shopify/MediaImage/8601',
        'shopify_media_create_uncertain' => true,
    ]));
    DB::table('inherited_media_retirements')->insert([
        'stack_product_id' => $stack->id,
        'image_id' => $copy->id,
        'old_media_id' => 'gid://shopify/MediaImage/8601',
        'status' => 'mutating',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('stack_image_sync_cursors')->insert([
        'stack_product_id' => $stack->id,
        'desired_generation' => 1,
        'working_generation' => 1,
        'status' => 'running',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    (new SynchronizeAuthorizedStackImagesJob((int) $stack->id))->failed(new RuntimeException('Worker interrupted'));
    $recovered = app(StackImageSync::class)->recoverAbandonedCursors();

    expect(DB::table('stack_image_sync_cursors')->where('stack_product_id', $stack->id)->value('status'))->toBe('failed')
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->value('status'))->toBe('mutating')
        ->and($copy->fresh()->shopify_media_create_uncertain)->toBeTrue()
        ->and($recovered)->toBe(0);
    Bus::assertNotDispatched(SynchronizeAuthorizedStackImagesJob::class);
});

it('does not dispatch recovery while automatic propagation and publication are disabled', function (): void {
    phase4Flags();
    Bus::fake();
    [$component] = phase4Component('FLAG', 'flag-bytes', 'Flag');
    $stack = phase4Stack('FLAG-STACK', [$component->id]);
    phase4RunningCursor($stack);

    $this->artisan('images:recover-component-stack-propagation')
        ->expectsOutput('Component-to-stack propagation is disabled. No tasks were dispatched.')
        ->doesntExpectOutputToContain('Requeued')
        ->assertSuccessful();
    expect(app(StackImageSync::class)->recoverAbandonedCursors())->toBe(0);

    foreach ([
        ['images.component_stack_propagation' => true],
        ['images.component_stack_local_rebuild' => true],
        ['images.component_stack_blind_publication' => true],
    ] as $oneFlag) {
        phase4Flags();
        config($oneFlag);
        expect(app(StackImageSync::class)->recoverAbandonedCursors())->toBe(0);
    }

    $cursor = DB::table('stack_image_sync_cursors')->where('stack_product_id', $stack->id)->first();
    expect($cursor->status)->toBe('running')
        ->and((int) $cursor->desired_generation)->toBe(4)
        ->and((int) $cursor->working_generation)->toBe(2);
    Bus::assertNotDispatched(SynchronizeAuthorizedStackImagesJob::class);
});

it('leaves an uncertain cursor unchanged when automatic recovery is disabled', function (): void {
    phase4Flags();
    Bus::fake();
    [$component, $image] = phase4Component('FROZEN', 'frozen-bytes', 'Frozen');
    $stack = phase4Stack('FROZEN-STACK', [$component->id]);
    $copy = Image::withoutEvents(fn () => Image::create([
        'product_id' => $stack->id,
        'position' => 1,
        'ownership' => ImageOwnership::COMPONENT_INHERITED,
        'source_image_id' => $image->id,
        'image_asset_id' => $image->image_asset_id,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_id' => 'gid://shopify/MediaImage/8321',
        'shopify_media_create_uncertain' => true,
    ]));
    DB::table('inherited_media_retirements')->insert([
        'stack_product_id' => $stack->id,
        'image_id' => $copy->id,
        'old_media_id' => 'gid://shopify/MediaImage/8321',
        'status' => 'mutating',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('stack_image_sync_cursors')->insert([
        'stack_product_id' => $stack->id,
        'desired_generation' => 4,
        'working_generation' => 2,
        'status' => 'running',
        'approval_request_id' => 321,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $before = (array) DB::table('stack_image_sync_cursors')->where('stack_product_id', $stack->id)->first();

    expect(app(StackImageSync::class)->recoverAbandonedCursors())->toBe(0);

    $after = (array) DB::table('stack_image_sync_cursors')->where('stack_product_id', $stack->id)->first();
    expect($after)->toBe($before)
        ->and($copy->fresh()->shopify_media_create_uncertain)->toBeTrue()
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->value('status'))->toBe('mutating');
    Bus::assertNotDispatched(SynchronizeAuthorizedStackImagesJob::class);
});

it('marks uncertain shopify evidence failed without dispatch when automatic recovery is enabled', function (): void {
    phase4Flags();
    phase4AuthorizeRecovery();
    Bus::fake();
    [$component, $image] = phase4Component('UNSAFE-ON', 'unsafe-on-bytes', 'Unsafe on');
    $stack = phase4Stack('UNSAFE-ON-STACK', [$component->id]);
    $copy = Image::withoutEvents(fn () => Image::create([
        'product_id' => $stack->id,
        'position' => 1,
        'ownership' => ImageOwnership::COMPONENT_INHERITED,
        'source_image_id' => $image->id,
        'image_asset_id' => $image->image_asset_id,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_id' => 'gid://shopify/MediaImage/8322',
        'shopify_media_create_uncertain' => true,
    ]));
    DB::table('stack_image_sync_cursors')->insert([
        'stack_product_id' => $stack->id,
        'desired_generation' => 4,
        'working_generation' => 2,
        'status' => 'running',
        'approval_request_id' => 321,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(app(StackImageSync::class)->recoverAbandonedCursors())->toBe(0);

    $cursor = DB::table('stack_image_sync_cursors')->where('stack_product_id', $stack->id)->first();
    expect($cursor->status)->toBe('failed')
        ->and((int) $cursor->desired_generation)->toBe(4)
        ->and((int) $cursor->working_generation)->toBe(2)
        ->and((int) $cursor->approval_request_id)->toBe(321)
        ->and($copy->fresh()->shopify_media_create_uncertain)->toBeTrue();
    Bus::assertNotDispatched(SynchronizeAuthorizedStackImagesJob::class);
});

it('requeues an abandoned running cursor when automatic propagation and publication are enabled', function (): void {
    phase4Flags();
    phase4AuthorizeRecovery();
    Bus::fake();
    [$component] = phase4Component('ABANDON', 'abandon-bytes', 'Abandon');
    $stack = phase4Stack('ABANDON-STACK', [$component->id]);
    phase4RunningCursor($stack, 4, 4);

    $recovered = app(StackImageSync::class)->recoverAbandonedCursors();

    $cursor = DB::table('stack_image_sync_cursors')->where('stack_product_id', $stack->id)->first();
    expect($recovered)->toBe(1)
        ->and($cursor->status)->toBe('queued')
        ->and((int) $cursor->desired_generation)->toBe(4)
        ->and((int) $cursor->working_generation)->toBe(4);
    Bus::assertDispatchedTimes(SynchronizeAuthorizedStackImagesJob::class, 1);

    expect(app(StackImageSync::class)->recoverAbandonedCursors())->toBe(0);
    Bus::assertDispatchedTimes(SynchronizeAuthorizedStackImagesJob::class, 1);
});

it('does not recover a cursor whose stack lock is still held', function (): void {
    phase4Flags();
    phase4AuthorizeRecovery();
    Bus::fake();
    [$component] = phase4Component('HELD', 'held-bytes', 'Held');
    $stack = phase4Stack('HELD-STACK', [$component->id]);
    phase4RunningCursor($stack);
    $mutex = new ImageSyncMutex;
    expect($mutex->acquire((int) $stack->id))->toBeTrue();

    try {
        expect(app(StackImageSync::class)->recoverAbandonedCursors())->toBe(0);
        expect(DB::table('stack_image_sync_cursors')->where('stack_product_id', $stack->id)->value('status'))->toBe('running');
        Bus::assertNotDispatched(SynchronizeAuthorizedStackImagesJob::class);
    } finally {
        $mutex->release();
    }
});

it('retries a definite queue rejection and does not dispatch a second job', function (): void {
    phase4Flags();
    phase4AuthorizeRecovery();
    config(['queue.default' => 'database']);
    [$component] = phase4Component('REJECT', 'reject-bytes', 'Reject');
    $stack = phase4Stack('REJECT-STACK', [$component->id]);
    phase4RunningCursor($stack);
    Event::listen(JobQueueing::class, function (): void {
        throw new RuntimeException('queue connection refused');
    });

    try {
        expect(app(StackImageSync::class)->recoverAbandonedCursors())->toBe(0);
    } finally {
        Event::forget(JobQueueing::class);
    }

    $cursor = DB::table('stack_image_sync_cursors')->where('stack_product_id', $stack->id)->first();
    expect($cursor->status)->toBe('running')
        ->and((int) $cursor->desired_generation)->toBe(4)
        ->and((int) $cursor->working_generation)->toBe(2)
        ->and(DB::table('jobs')->where('payload', 'like', '%SynchronizeAuthorizedStackImagesJob%')->count())->toBe(0);

    expect(app(StackImageSync::class)->recoverAbandonedCursors())->toBe(1);
    expect(app(StackImageSync::class)->recoverAbandonedCursors())->toBe(0);
    $cursor = DB::table('stack_image_sync_cursors')->where('stack_product_id', $stack->id)->first();
    expect($cursor->status)->toBe('queued')
        ->and((int) $cursor->desired_generation)->toBe(4)
        ->and((int) $cursor->working_generation)->toBe(2)
        ->and(DB::table('jobs')->where('payload', 'like', '%SynchronizeAuthorizedStackImagesJob%')->count())->toBe(1);
});

it('does not dispatch again when queue acceptance is uncertain', function (): void {
    phase4Flags();
    phase4AuthorizeRecovery();
    config(['queue.default' => 'database']);
    [$component] = phase4Component('UNSURE', 'unsure-bytes', 'Unsure');
    $stack = phase4Stack('UNSURE-STACK', [$component->id]);
    phase4RunningCursor($stack);
    Event::listen(JobQueued::class, function (): void {
        throw new RuntimeException('the queue accepted the job before the caller returned');
    });

    try {
        expect(app(StackImageSync::class)->recoverAbandonedCursors())->toBe(1);
    } finally {
        Event::forget(JobQueued::class);
    }

    $cursor = DB::table('stack_image_sync_cursors')->where('stack_product_id', $stack->id)->first();
    expect($cursor->status)->toBe('queued')
        ->and((int) $cursor->desired_generation)->toBe(4)
        ->and((int) $cursor->working_generation)->toBe(2)
        ->and(DB::table('jobs')->where('payload', 'like', '%SynchronizeAuthorizedStackImagesJob%')->count())->toBe(1);

    expect(app(StackImageSync::class)->recoverAbandonedCursors())->toBe(0)
        ->and(DB::table('jobs')->where('payload', 'like', '%SynchronizeAuthorizedStackImagesJob%')->count())->toBe(1);
});

it('keeps an unchanged rebuild from touching timestamps, resolutions, jobs, or shopify', function (): void {
    phase4Flags();
    Bus::fake();
    Http::preventStrayRequests();
    $bytes = 'noop-bytes';
    [$first, $firstImage] = phase4Component('NOOP-A', $bytes, 'First approved sentence');
    $second = createImageImportProduct('NOOP-B');
    $secondImage = Image::withoutEvents(fn () => Image::create([
        'product_id' => $second->id,
        'position' => 1,
        'image_asset_id' => $firstImage->image_asset_id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'alt_text' => 'Second approved sentence',
        'sync_state' => Image::SYNC_STATE_SYNCED,
    ]));
    phase4ApproveAlt($first, $firstImage, 'First approved sentence');
    phase4ApproveAlt($second, $secondImage->fresh(), 'Second approved sentence');
    $stack = phase4Stack('NOOP-STACK', [$first->id, $second->id]);
    $service = app(ComponentStackPropagation::class);
    $service->stageStack($stack);
    $existing = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $taskId = DB::table('stack_image_sync_tasks')->insertGetId([
        'stack_product_id' => $stack->id,
        'component_product_id' => $first->id,
        'component_image_id' => $firstImage->id,
        'generation_id' => (int) DB::table('component_photograph_generations')->insertGetId([
            'component_product_id' => $first->id,
            'component_image_id' => $firstImage->id,
            'image_asset_id' => $firstImage->image_asset_id,
            'asset_sha256' => $firstImage->imageAsset->sha256,
            'selection_revision' => 1,
            'status' => 'confirmed',
            'created_at' => now(),
            'updated_at' => now(),
        ]),
        'purpose' => 'publish',
        'phase' => 'alt_conflict',
        'attempts' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $operator = User::factory()->create(['email' => 'operator-'.Str::lower(Str::random(8)).'@leighavenue.co.za', 'is_active' => true]);
    \Spatie\Permission\Models\Role::findOrCreate(\App\Enums\RolesEnum::Admin->value);
    $operator->assignRole(\App\Enums\RolesEnum::Admin->value);
    $service->resolveInheritedAlt($taskId, 'First approved sentence', 'Operator selected the first approved source description.', $operator->id);
    $existing->refresh();
    $imageUpdatedAt = (string) $existing->updated_at;
    $sourceUpdatedAt = (string) DB::table('inherited_image_sources')->where('image_id', $existing->id)->max('updated_at');
    $revisions = DB::table('image_revisions')->where('image_id', $existing->id)->count();
    $sourceEvents = DB::table('inherited_image_source_events')->where('image_id', $existing->id)->count();

    Carbon::setTestNow(now()->addSeconds(2));
    try {
        $service->stageStack($stack->fresh());
    } finally {
        Carbon::setTestNow();
    }

    expect(app(InheritedAltResolution::class)->current($existing->fresh()))->toBe('First approved sentence')
        ->and($existing->fresh()->alt_text)->toBe('First approved sentence')
        ->and((string) $existing->fresh()->updated_at)->toBe($imageUpdatedAt)
        ->and((string) DB::table('inherited_image_sources')->where('image_id', $existing->id)->max('updated_at'))->toBe($sourceUpdatedAt)
        ->and(DB::table('image_revisions')->where('image_id', $existing->id)->count())->toBe($revisions)
        ->and(DB::table('inherited_image_source_events')->where('image_id', $existing->id)->count())->toBe($sourceEvents)
        ->and(Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->count())->toBe(1);
    Bus::assertNothingDispatched();
    Http::assertNothingSent();
});
