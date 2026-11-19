<?php

use App\Models\Image;
use App\Models\NewProductDraft;
use App\Services\CatalogImageMatcher;
use App\Services\ComponentStackPropagation;
use App\Services\ImageUploadOperations;
use App\Services\StackComponentCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/shopify-image-import.php';

beforeEach(function (): void {
    Storage::fake('public');
    Bus::fake();
    config(['images.component_stack_propagation' => false]);
});

it('durably visits more than forty drafts and revisits old observer bypasses in subsequent passes', function (): void {
    $stack = createImageImportProduct('RECOVERY-OLD', ['tags' => 'stack']);
    $first = NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['sku' => 'SCAN-FIRST', 'shopify_id' => $stack->shopify_id, 'handle' => $stack->handle, 'title' => 'Stack', 'status' => 'active', 'bundle_product_ids' => []]));
    foreach (range(1, 84) as $n) {
        NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['sku' => 'SCAN-'.$n, 'handle' => 'unmatched-'.$n, 'title' => 'Draft', 'status' => 'draft']));
    }
    config(['images.component_stack_propagation' => true]);
    foreach (range(1, 3) as $n) {
        app(ComponentStackPropagation::class)->recoverPending();
    }
    $state = DB::table('image_relationship_scan_state')->where('name', 'draft_membership')->first();
    expect((int) $state->last_id)->toBe((int) NewProductDraft::max('id'))->and((int) $state->completed_passes)->toBe(1);
    DB::table('new_product_drafts')->where('id', $first->id)->update(['bundle_product_ids' => '[12345]']);
    app()->forgetInstance(ComponentStackPropagation::class); // A restarted worker uses persisted progress.
    app(ComponentStackPropagation::class)->recoverPending();
    expect(json_decode(DB::table('stack_membership_revisions')->where('stack_product_id', $stack->id)->orderByDesc('revision')->value('component_ids'), true))->toBe([12345])
        ->and(DB::table('image_relationship_scan_failures')->count())->toBe(0);
    $tasks = DB::table('stack_image_sync_tasks')->count();
    app(ComponentStackPropagation::class)->recoverPending();
    expect(DB::table('stack_image_sync_tasks')->count())->toBe($tasks);
});

it('persists a failed old relationship separately and resumes it without starving the scan', function (): void {
    $stack = createImageImportProduct('SCAN-FAIL', ['is_bundle' => true]);
    $draft = NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['sku' => 'SCAN-FAIL', 'shopify_id' => $stack->shopify_id, 'handle' => $stack->handle, 'title' => 'Stack', 'bundle_product_ids' => []]));
    $fail = true;
    DB::listen(function ($query) use (&$fail): void {
        if ($fail && str_starts_with(strtolower($query->sql), 'select') && str_contains($query->sql, 'stack_membership_revisions')) {
            throw new RuntimeException('Simulated interrupted relationship inspection');
        }
    });
    config(['images.component_stack_propagation' => true]);
    app(ComponentStackPropagation::class)->recoverPending();
    expect(DB::table('image_relationship_scan_failures')->where('draft_id', $draft->id)->count())->toBe(1);
    $fail = false;
    app(ComponentStackPropagation::class)->recoverPending();
    expect(DB::table('image_relationship_scan_failures')->count())->toBe(0)
        ->and(DB::table('stack_membership_revisions')->where('stack_product_id', $stack->id)->count())->toBe(1);
});

it('leaves scan progress untouched while propagation is disabled', function (): void {
    expect(app(ComponentStackPropagation::class)->recoverPending())->toBe(0)
        ->and(DB::table('image_relationship_scan_state')->count())->toBe(0);
    Bus::assertNothingDispatched();
    $this->artisan('images:recover-component-stack-propagation', ['--report' => true])->assertExitCode(0);
    expect(DB::table('image_relationship_scan_state')->count())->toBe(0);
    Http::assertNothingSent();
    Bus::assertNothingDispatched();
});

it('uses the latest empty dependency list rather than an older nonempty draft', function (): void {
    $component = createImageImportProduct('LATEST-COMP');
    $stack = createImageImportProduct('LATEST-STACK', ['is_bundle' => true]);
    foreach ([[$component->id], null] as $index => $members) {
        NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['sku' => 'LATEST-'.$index, 'shopify_id' => $stack->shopify_id, 'handle' => $stack->handle.'-'.$index, 'title' => 'Stack', 'status' => 'active', 'bundle_product_ids' => $members]));
    }
    expect(app(StackComponentCatalog::class)->componentIds($stack))->toBe([])
        ->and(app(StackComponentCatalog::class)->stackIdsForComponent($component->id))->toBe([]);
});

it('does no catalogue photograph downloads after entering the locking transaction and rejects stale asset evidence', function (): void {
    $p = createImageImportProduct('PREFETCH');
    $asset = createImportTestAsset('original bytes', 'jpg', 'prefetch.jpg');
    $url = 'https://cdn.shopify.com/s/files/prefetch.jpg';
    $image = Image::withoutEvents(fn () => Image::create(['product_id' => $p->id, 'src' => $url, 'image_asset_id' => $asset->id, 'sync_state' => Image::SYNC_STATE_SYNCED]));
    $baseLevel = DB::transactionLevel();
    Http::fake(function () use ($baseLevel) {
        expect(DB::transactionLevel())->toBe($baseLevel);

        return Http::response('original bytes');
    });
    $matcher = app(CatalogImageMatcher::class);
    $evidence = $matcher->prepareEvidence(collect([$image]), [$url]);
    DB::transaction(function () use ($matcher, $image, $url, $evidence): void {
        expect($matcher->match(collect([$image]), [], null, $url, $evidence)['image']?->id)->toBe($image->id);
        $replacement = createImportTestAsset('different bytes', 'jpg', 'replacement.jpg');
        Image::withoutEvents(fn () => $image->forceFill(['image_asset_id' => $replacement->id])->save());
        expect($matcher->match(collect([$image->fresh('imageAsset')]), [], null, $url, $evidence)['ambiguous'])->toBeTrue();
        expect($matcher->match(collect([$image]), [], null, $url)['ambiguous'])->toBeTrue();
    });
    Http::assertSentCount(1);
});

it('does not resurrect a remote deleted record when its old identifier is imported again', function (): void {
    $p = createImageImportProduct('HISTORY-SEPARATE');
    $old = Image::withoutEvents(fn () => Image::create(['product_id' => $p->id, 'shopify_id' => 'gid://shopify/MediaImage/12', 'src' => 'https://cdn.shopify.com/s/files/old.jpg', 'sync_state' => Image::SYNC_STATE_REMOTE_DELETED]));
    $decision = app(CatalogImageMatcher::class)->match(collect([$old]), [], $old->shopify_id, $old->src);
    expect($decision['image'])->toBeNull()->and($old->fresh()->sync_state)->toBe(Image::SYNC_STATE_REMOTE_DELETED);
    Http::assertNothingSent();
});

it('does not adopt a component or another stacks file when an upload response was lost', function (): void {
    $component = createImageImportProduct('UNCERTAIN-COMP');
    $stack = createImageImportProduct('UNCERTAIN-STACK', ['is_bundle' => true]);
    $asset = createImportTestAsset('same original photograph', 'jpg', 'uncertain.jpg');
    $source = Image::withoutEvents(fn () => Image::create(['product_id' => $component->id, 'shopify_id' => 'gid://shopify/MediaImage/771', 'image_asset_id' => $asset->id, 'sync_state' => Image::SYNC_STATE_SYNCED]));
    $copy = Image::withoutEvents(fn () => Image::create(['product_id' => $stack->id, 'ownership' => 'component_inherited', 'source_image_id' => $source->id,
        'image_asset_id' => $asset->id, 'backup_status' => Image::BACKUP_STATUS_BACKED_UP, 'sync_state' => Image::SYNC_STATE_LOCAL_NEW]));
    $operations = app(ImageUploadOperations::class);
    $operation = $operations->claim($copy, $stack->shopify_id, []);
    $operations->record($operation, 'processing', $source->shopify_id);
    $operation = $operations->active($copy);
    Http::fake(fn () => Http::response('same original photograph'));
    $node = ['id' => $source->shopify_id, 'status' => 'READY', 'fileStatus' => 'READY', 'originalSource' => ['url' => 'https://cdn.shopify.com/s/files/shared-uncertain.jpg']];
    expect($operations->reconcile($copy, $operation, [$node]))->toBeNull()
        ->and($copy->fresh()->shopify_id)->toBeNull()->and($operations->active($copy)->status)->toBe('needs_review');
    Http::assertNothingSent();
});

it('retries additive image migrations without discarding existing audit or ownership records', function (): void {
    $p = createImageImportProduct('MIGRATION-RETRY', ['is_bundle' => true]);
    $image = Image::withoutEvents(fn () => Image::create(['product_id' => $p->id, 'alt_text' => 'Keep legacy description', 'ownership' => null]));
    foreach (glob(database_path('migrations/2026_11_1[789]_*.php')) as $file) {
        (require $file)->up();
    }
    expect($image->fresh()->alt_text)->toBe('Keep legacy description')->and($image->fresh()->ownership)->toBeNull();
});
