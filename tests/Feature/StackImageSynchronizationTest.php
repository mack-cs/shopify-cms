<?php

use App\Jobs\RebuildDependentStackImagesJob;
use App\Jobs\SynchronizeAuthorizedStackImagesJob;
use App\Mail\ImagePublicationReminderMail;
use App\Models\Image;
use App\Models\ImageAsset;
use App\Models\Product;
use App\Models\User;
use App\Services\ComponentStackPropagation;
use App\Services\ImageOwnership;
use App\Services\ImagePublicationReminderService;
use App\Services\SkuListFilterService;
use App\Services\StackImageSync;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(Illuminate\Foundation\Testing\RefreshDatabase::class);
require_once __DIR__.'/../Support/shopify-image-import.php';

function stackSyncFlags(bool $local = false, bool $blind = false): void
{
    config([
        'images.component_stack_propagation' => false,
        'images.component_stack_local_rebuild' => $local,
        'images.component_stack_blind_publication' => $blind,
    ]);
}

function stackSyncPair(string $sku, array $photos): array
{
    Storage::fake('public');
    $component = createImageImportProduct($sku);
    $images = [];
    foreach ($photos as $index => $photo) {
        $asset = createImportTestAsset($photo['bytes'], 'jpg', $photo['filename']);
        $images[] = Image::withoutEvents(fn () => Image::create([
            'product_id' => $component->id,
            'position' => $index + 1,
            'image_asset_id' => $asset->id,
            'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
            'backup_completed_at' => now(),
            'sync_state' => Image::SYNC_STATE_SYNCED,
            'alt_text' => $photo['alt'],
            'photograph_version' => $photo['photo_version'] ?? 1,
            'alt_text_version' => $photo['alt_version'] ?? 1,
            'needs_shopify_image_sync' => false,
            'shopify_id' => $photo['shopify_id'] ?? null,
        ]));
    }
    $stack = createImageImportProduct($sku.'-STACK', ['is_bundle' => true, 'status' => $photo['stack_status'] ?? 'active']);
    \App\Models\NewProductDraft::withoutEvents(fn () => \App\Models\NewProductDraft::create([
        'sku' => $sku.'-STACK',
        'shopify_id' => $stack->shopify_id,
        'handle' => $stack->handle,
        'title' => $stack->title,
        'status' => 'active',
        'bundle_product_ids' => [$component->id],
    ]));

    return [$component, $images, $stack];
}

it('rebuilds every current component photograph locally without shopify publication', function () {
    stackSyncFlags();
    Http::preventStrayRequests();
    [$component, $images, $stack] = stackSyncPair('LOCAL', [
        ['bytes' => 'front-bytes', 'filename' => 'LOCAL.jpg', 'alt' => 'Front of the bracelet'],
        ['bytes' => 'back-bytes', 'filename' => 'LOCAL-1.jpg', 'alt' => 'Back of the bracelet'],
    ]);
    $owned = Image::withoutEvents(fn () => Image::create([
        'product_id' => $stack->id, 'position' => 1, 'ownership' => ImageOwnership::STACK_OWNED,
        'alt_text' => 'Stack lifestyle', 'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_id' => 'gid://shopify/MediaImage/50', 'needs_shopify_image_sync' => false,
    ]));

    $result = app(ComponentStackPropagation::class)->stageStack($stack);

    $copies = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->orderBy('source_image_id')->get();
    expect($result['rebuilt'])->toBeTrue()
        ->and($result['message'])->not->toContain('confirmed component publication')
        ->and($copies)->toHaveCount(2)
        ->and($copies->pluck('alt_text')->sort()->values()->all())->toBe(['Back of the bracelet', 'Front of the bracelet'])
        ->and($copies->pluck('shopify_id')->filter()->all())->toBe([])
        ->and($copies->pluck('source_image_id')->sort()->values()->all())->toBe(collect($images)->pluck('id')->sort()->values()->all())
        ->and($owned->fresh()->alt_text)->toBe('Stack lifestyle')
        ->and($owned->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/50')
        ->and($images[0]->fresh()->shopify_id)->toBeNull();

    app(ComponentStackPropagation::class)->stageStack($stack->fresh());
    expect(Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->active()->count())->toBe(2)
        ->and(app(StackImageSync::class)->describe($stack->fresh())['phase'])->toBe('local_cms_rebuild_completed');
    $component->refresh();
});

it('reports a component photograph that has no readable file and still copies the others', function () {
    stackSyncFlags();
    [$component, $images, $stack] = stackSyncPair('MISSING', [
        ['bytes' => 'present-bytes', 'filename' => 'MISSING.jpg', 'alt' => 'Present sentence'],
    ]);
    $broken = Image::withoutEvents(fn () => Image::create([
        'product_id' => $component->id, 'position' => 2, 'alt_text' => 'Missing file',
        'sync_state' => Image::SYNC_STATE_SYNCED,
    ]));
    $result = app(ComponentStackPropagation::class)->stageStack($stack);
    expect($result['rebuilt'])->toBeTrue()
        ->and(implode(' ', $result['unavailable']))->toContain('Image '.$broken->id)
        ->and(Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->active()->count())->toBe(1)
        ->and($images[0]->id)->not->toBe($broken->id);
});

it('replaces one inherited photograph and updates one alt without a new stack record or upload flag', function () {
    stackSyncFlags();
    [, $images, $stack] = stackSyncPair('EDIT', [
        ['bytes' => 'keep-bytes', 'filename' => 'EDIT.jpg', 'alt' => 'Keep this sentence'],
        ['bytes' => 'old-bytes', 'filename' => 'EDIT-1.jpg', 'alt' => 'Old angle'],
    ]);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $kept = Image::query()->where('product_id', $stack->id)->where('source_image_id', $images[0]->id)->first();
    $changed = Image::query()->where('product_id', $stack->id)->where('source_image_id', $images[1]->id)->first();
    Image::withoutEvents(fn () => $kept->forceFill(['needs_shopify_image_sync' => false])->save());
    Image::withoutEvents(fn () => $changed->forceFill(['needs_shopify_image_sync' => false, 'shopify_id' => null])->save());
    $replacement = createImportTestAsset('new-bytes', 'jpg', 'EDIT-1.jpg');
    Image::withoutEvents(fn () => $images[1]->forceFill([
        'image_asset_id' => $replacement->id, 'photograph_version' => 2,
    ])->save());
    Image::withoutEvents(fn () => $images[0]->forceFill(['alt_text' => 'Updated angle sentence', 'alt_text_version' => 4])->save());

    app(ComponentStackPropagation::class)->stageStack($stack->fresh());

    expect(Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->active()->count())->toBe(2)
        ->and($kept->fresh()->alt_text)->toBe('Updated angle sentence')
        ->and($kept->fresh()->needs_shopify_image_sync)->toBeFalse()
        ->and((int) Image::query()->where('product_id', $stack->id)->where('source_image_id', $images[1]->id)->active()->first()->image_asset_id)->toBe($replacement->id)
        ->and($changed->fresh()->sync_state)->toBe(Image::SYNC_STATE_LOCAL_DELETED);
});

it('removes an unpublished inherited copy without deleting the component or another stack owned image', function () {
    stackSyncFlags();
    [, $images, $stack] = stackSyncPair('DROP', [
        ['bytes' => 'stay-bytes', 'filename' => 'DROP.jpg', 'alt' => 'Stays'],
        ['bytes' => 'gone-bytes', 'filename' => 'DROP-1.jpg', 'alt' => 'Goes'],
    ]);
    $other = createImageImportProduct('DROP-OTHER', ['is_bundle' => true]);
    $owned = Image::withoutEvents(fn () => Image::create([
        'product_id' => $other->id, 'ownership' => ImageOwnership::STACK_OWNED,
        'image_asset_id' => $images[1]->image_asset_id, 'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_id' => 'gid://shopify/MediaImage/77',
    ]));
    app(ComponentStackPropagation::class)->stageStack($stack);
    Image::withoutEvents(fn () => $images[1]->forceFill(['sync_state' => Image::SYNC_STATE_LOCAL_DELETED])->save());
    app(ComponentStackPropagation::class)->stageStack($stack->fresh());

    expect(Image::query()->where('product_id', $stack->id)->where('source_image_id', $images[1]->id)->active()->exists())->toBeFalse()
        ->and(Image::query()->where('product_id', $stack->id)->where('source_image_id', $images[0]->id)->active()->exists())->toBeTrue()
        ->and(ImageAsset::query()->find($images[1]->image_asset_id))->not->toBeNull()
        ->and($owned->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/77');
});

it('does not queue shopify work while automatic propagation and blind publication are disabled', function () {
    stackSyncFlags();
    Bus::fake();
    [$component] = stackSyncPair('QUIET', [
        ['bytes' => 'quiet-bytes', 'filename' => 'QUIET.jpg', 'alt' => 'Quiet sentence'],
    ]);
    foreach (range(1, 2) as $ignored) {
        $component->approvals()->create(['user_id' => User::factory()->create()->id, 'approval_version' => $component->approval_version ?: 1]);
    }
    app(StackImageSync::class)->queueLocalRebuild((int) $component->id);
    expect(Bus::dispatched(SynchronizeAuthorizedStackImagesJob::class))->toHaveCount(0)
        ->and(Bus::dispatched(RebuildDependentStackImagesJob::class))->toHaveCount(0);
});

it('queues one consolidated shopify sync for an active stack and does not mutate a draft stack', function () {
    stackSyncFlags(false, true);
    Bus::fake();
    Http::preventStrayRequests();
    [, , $active] = stackSyncPair('ACTIVE', [
        ['bytes' => 'active-bytes', 'filename' => 'ACTIVE.jpg', 'alt' => 'Active sentence'],
    ]);
    [, , $draft] = stackSyncPair('DRAFT', [
        ['bytes' => 'draft-bytes', 'filename' => 'DRAFT.jpg', 'alt' => 'Draft sentence', 'stack_status' => 'draft'],
    ]);
    app(StackImageSync::class)->queueStack((int) $active->id, 11);
    app(StackImageSync::class)->queueStack((int) $active->id, 12);
    app(StackImageSync::class)->queueStack((int) $active->id, 13);
    expect(Bus::dispatched(SynchronizeAuthorizedStackImagesJob::class))->toHaveCount(1)
        ->and((int) DB::table('stack_image_sync_cursors')->where('stack_product_id', $active->id)->value('desired_generation'))->toBe(3);

    $plan = app(StackImageSync::class)->synchronizeStack((int) $draft->id);
    expect($plan['outcome'])->toBe('deferred_inactive')
        ->and($plan['uploads'])->toBe([])
        ->and(app(StackImageSync::class)->describe($draft->fresh())['phase'])->toBe('shopify_update_pending_authorization');
});

it('skips shopify when the authorized versions are already verified', function () {
    stackSyncFlags(false, true);
    Http::preventStrayRequests();
    [, $images, $stack] = stackSyncPair('NOOP', [
        ['bytes' => 'noop-bytes', 'filename' => 'NOOP.jpg', 'alt' => 'Verified sentence'],
    ]);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    Image::withoutEvents(fn () => $copy->forceFill([
        'shopify_id' => 'gid://shopify/MediaImage/90',
        'needs_shopify_image_sync' => false,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_alt_text' => 'Verified sentence',
    ])->save());
    DB::table('component_image_authorizations')->insert([
        'product_id' => $images[0]->product_id, 'image_id' => $images[0]->id,
        'image_asset_id' => $images[0]->image_asset_id, 'asset_sha256' => $images[0]->imageAsset->sha256,
        'photograph_version' => 1, 'alt_text_version' => 1, 'alt_text' => 'Verified sentence',
        'scope' => 'full', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('stack_image_sync_ledger')->insert([
        'stack_product_id' => $stack->id, 'image_id' => $copy->id, 'source_image_id' => $images[0]->id,
        'verified_photograph_version' => 1, 'verified_alt_version' => 1, 'outcome' => 'verified',
        'shopify_media_id' => 'gid://shopify/MediaImage/90', 'verified_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $plan = app(StackImageSync::class)->plan($stack->fresh());
    expect($plan['outcome'])->toBe('no_changes_required')
        ->and($plan['uploads'])->toBe([])
        ->and($plan['alt_only'])->toBe([])
        ->and($copy->fresh()->shopify_id)->not->toBe($images[0]->shopify_id);
});

it('sends one reminder after 90 minutes and one follow-up a day later', function () {
    while (DB::transactionLevel() > 0) {
        DB::rollBack();
    }
    Mail::fake();
    config(['services.slack.missing_alt_recipient_email' => 'nick@leighavenue.co.za']);
    $product = createImageImportProduct('REMIND');
    $image = Image::withoutEvents(fn () => Image::create([
        'product_id' => $product->id, 'position' => 1, 'alt_text' => 'Needs a push',
        'needs_shopify_image_sync' => true, 'shopify_alt_pending' => true,
        'image_attention_since' => now()->subMinutes(89), 'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
    ]));
    expect(app(ImagePublicationReminderService::class)->sendDue()['sent'])->toBeFalse();
    Image::withoutEvents(fn () => $image->forceFill(['image_attention_since' => now()->subMinutes(91)])->save());
    $first = app(ImagePublicationReminderService::class)->sendDue();
    $second = app(ImagePublicationReminderService::class)->sendDue();
    expect($first['sent'])->toBeTrue()->and($first['kind'])->toBe('initial')->and($second['sent'])->toBeFalse();
    Mail::assertSent(ImagePublicationReminderMail::class, function (ImagePublicationReminderMail $mail): bool {
        $html = $mail->render();

        return str_contains($html, 'REMIND')
            && str_contains($html, 'Open CMS')
            && str_contains($html, 'paste the listed SKUs');
    });
    $this->travel(25)->hours();
    $follow = app(ImagePublicationReminderService::class)->sendDue();
    expect($follow['sent'])->toBeTrue()->and($follow['kind'])->toBe('follow_up')
        ->and(DB::table('image_publication_reminders')->where('status', 'accepted')->count())->toBe(2);
});

it('keeps the original attention time when the same image is edited again', function () {
    $product = createImageImportProduct('TIMER');
    $image = Image::withoutEvents(fn () => Image::create([
        'product_id' => $product->id, 'position' => 1, 'alt_text' => 'First',
        'needs_shopify_image_sync' => true, 'image_attention_since' => now()->subHours(3),
        'sync_state' => Image::SYNC_STATE_LOCAL_UPDATED, 'shopify_id' => 'gid://shopify/MediaImage/3',
    ]));
    $image->alt_text = 'Second edit';
    $image->save();
    expect($image->fresh()->image_attention_since->equalTo($image->image_attention_since) || $image->fresh()->image_attention_since->eq(now()->subHours(3)))->toBeTrue();
    expect($image->fresh()->image_attention_since->gt(now()->subMinutes(5)))->toBeFalse();
});

it('filters products by several pasted skus', function () {
    $first = createImageImportProduct('SKU-ONE');
    $second = createImageImportProduct('SKU-TWO');
    createImageImportProduct('SKU-THREE');
    $ids = app(SkuListFilterService::class)
        ->applyToProducts(Product::query(), "SKU-ONE, SKU-TWO\nSKU-TWO")
        ->pluck('id')->all();
    expect($ids)->toContain($first->id)->toContain($second->id)->toHaveCount(2);
});

it('does not mark a legacy stack verified without shopify read-back', function () {
    stackSyncFlags();
    [, , $stack] = stackSyncPair('LEGACY', [
        ['bytes' => 'legacy-bytes', 'filename' => 'LEGACY.jpg', 'alt' => 'Legacy sentence'],
    ]);
    $this->artisan('images:rebuild-stack-baseline', ['stack' => $stack->id])->assertSuccessful();
    expect(DB::table('stack_image_sync_ledger')->where('stack_product_id', $stack->id)->where('outcome', 'baseline_verified')->exists())->toBeFalse()
        ->and(DB::table('stack_image_sync_ledger')->where('stack_product_id', $stack->id)->where('outcome', 'local_rebuilt')->exists())->toBeTrue();
});

it('rebuilds dependent stacks locally when that switch is enabled and does not authorize shopify', function () {
    stackSyncFlags(true, false);
    Http::preventStrayRequests();
    [, $images, $stack] = stackSyncPair('AUTO', [
        ['bytes' => 'auto-bytes', 'filename' => 'AUTO.jpg', 'alt' => 'Automatic sentence'],
    ]);
    expect(app(StackImageSync::class)->rebuildDependents((int) $images[0]->product_id))->toBe(1)
        ->and(Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->count())->toBe(1)
        ->and(DB::table('stack_image_sync_tasks')->count())->toBe(0);
});
