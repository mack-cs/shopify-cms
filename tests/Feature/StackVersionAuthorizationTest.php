<?php

use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ProductPartialApprovalRequest;
use App\Models\ShopifyImageImportBatch;
use App\Models\User;
use App\Services\ComponentPhotographSelector;
use App\Services\ComponentStackPropagation;
use App\Services\ImageAltTextBackfillService;
use App\Services\ImageOwnership;
use App\Services\ProductPartialApprovalService;
use App\Services\ShopifyImageImportService;
use App\Services\StackImageSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/shopify-image-import.php';

function versionFlags(): void
{
    config([
        'images.component_stack_propagation' => false,
        'images.component_stack_local_rebuild' => false,
        'images.component_stack_blind_publication' => false,
    ]);
}

function versionPair(string $sku, string $bytes, string $alt): array
{
    Storage::fake('public');
    $component = createImageImportProduct($sku);
    $asset = createImportTestAsset($bytes, 'jpg', $sku.'.jpg');
    $image = Image::withoutEvents(fn () => Image::create([
        'product_id' => $component->id,
        'position' => 1,
        'image_asset_id' => $asset->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'alt_text' => $alt,
        'needs_shopify_image_sync' => false,
    ]));
    $stack = createImageImportProduct($sku.'-STACK', ['is_bundle' => true, 'status' => 'active']);
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create([
        'sku' => $sku.'-STACK',
        'shopify_id' => $stack->shopify_id,
        'handle' => $stack->handle,
        'title' => $stack->title,
        'status' => 'active',
        'bundle_product_ids' => [$component->id],
    ]));

    return [$component, $image->fresh(), $stack];
}

function approveScope(Product $product, string $scope): array
{
    Bus::fake();
    $service = app(ProductPartialApprovalService::class);
    $requested = $service->request(collect([$product->fresh()]), User::factory()->create()->id, [$scope], []);
    expect($requested['requested'])->toBe(1);
    $request = ProductPartialApprovalRequest::query()->where('product_id', $product->id)->where('status', ProductPartialApprovalRequest::STATUS_PENDING)->latest('id')->first();

    return $service->approveRequests(collect([$request]), User::factory()->create()->id);
}

it('does not reuse an earlier authorization when alt text returns to a previous sentence', function (): void {
    versionFlags();
    [, $image] = versionPair('ABA', 'aba-bytes', 'Sentence A');
    $writer = app(ImageAltTextBackfillService::class);
    $writer->markPendingReview($image->fresh(), 'Sentence A');
    expect(approveScope($image->product, 'image_alt')['approved'])->toBe(1);
    $first = (int) $image->fresh()->alt_text_version;
    $writer->markPendingReview($image->fresh(), 'Sentence B');
    $writer->markPendingReview($image->fresh(), 'Sentence A');
    $image->refresh();
    $sync = app(StackImageSync::class);

    expect($image->alt_text_version)->toBe($first + 2)
        ->and($image->alt_text)->toBe('Sentence A')
        ->and($sync->altAuthorized($image))->toBeFalse()
        ->and(app(ProductPartialApprovalService::class)->approvedAlt($image))->toBeNull();

    expect(approveScope($image->product, 'image_alt')['approved'])->toBe(1)
        ->and($sync->altAuthorized($image->fresh()))->toBeTrue()
        ->and(DB::table('component_image_authorizations')->where('image_id', $image->id)->where('scope', 'images')->count())->toBe(0);
});

it('does not authorize an alt sentence edited after the review snapshot', function (): void {
    versionFlags();
    [, $image] = versionPair('RACE', 'race-bytes', 'Reviewed sentence');
    $writer = app(ImageAltTextBackfillService::class);
    $writer->markPendingReview($image->fresh(), 'Reviewed sentence');
    Bus::fake();
    $service = app(ProductPartialApprovalService::class);
    $service->request(collect([$image->product->fresh()]), User::factory()->create()->id, ['image_alt'], []);
    $writer->markPendingReview($image->fresh(), 'Changed before approval');
    $request = ProductPartialApprovalRequest::query()->where('product_id', $image->product_id)->first();
    $result = $service->approveRequests(collect([$request]), User::factory()->create()->id);

    expect($request->fresh()->status)->toBe(ProductPartialApprovalRequest::STATUS_REJECTED)
        ->and($result['approved'])->toBe(0)
        ->and(DB::table('component_image_authorizations')->where('image_id', $image->id)->count())->toBe(0)
        ->and(app(StackImageSync::class)->altAuthorized($image->fresh()))->toBeFalse();
});

it('does not authorize photograph bytes replaced after the images review snapshot', function (): void {
    versionFlags();
    [, $image] = versionPair('PHOTO', 'original-bytes', 'Keep this sentence');
    $originalVersion = (int) $image->photograph_version;
    Bus::fake();
    $service = app(ProductPartialApprovalService::class);
    $service->request(collect([$image->product->fresh()]), User::factory()->create()->id, ['images'], []);
    $replacement = createImportTestAsset('replacement-bytes', 'jpg', 'PHOTO-new.jpg');
    $image->forceFill(['image_asset_id' => $replacement->id, 'shopify_identity_unresolved' => true, 'shopify_id' => null])->save();
    $request = ProductPartialApprovalRequest::query()->where('product_id', $image->product_id)->first();
    $result = $service->approveRequests(collect([$request]), User::factory()->create()->id);

    expect($result['approved'])->toBe(1)
        ->and($image->fresh()->photograph_version)->toBe($originalVersion + 1)
        ->and($image->fresh()->alt_text)->toBe('Keep this sentence')
        ->and(app(StackImageSync::class)->photographAuthorized($image->fresh()))->toBeFalse()
        ->and(DB::table('component_image_authorizations')->where('image_id', $image->id)->count())->toBe(0);
});

it('versions a real csv alt import and a real s3 photograph replacement', function (): void {
    versionFlags();
    Storage::fake('public');
    Storage::fake('shopify_product_images');
    config()->set('shopify_image_import.disk', 'shopify_product_images');
    Http::preventStrayRequests();
    $product = createImageImportProduct('CSVROW');
    $original = createImportTestAsset('original-csv-bytes', 'png', 'CSVROW.png');
    $image = Image::withoutEvents(fn () => Image::create([
        'product_id' => $product->id,
        'position' => 1,
        'image_asset_id' => $original->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_id' => 'gid://shopify/MediaImage/41',
        'alt_text' => 'Original sentence',
        'approved_filename' => 'CSVROW.png',
        'filename_mode' => Image::FILENAME_MODE_MANUAL,
        'needs_shopify_image_sync' => false,
    ]));
    $photoVersion = (int) $image->photograph_version;
    $altVersion = (int) $image->alt_text_version;
    Storage::disk('shopify_product_images')->put('incoming/2026-11-21/CSVROW.png', 'replacement-csv-bytes');
    Storage::disk('shopify_product_images')->put('incoming/2026-11-21/alt-text.csv', "filename,alt_text\nCSVROW.png,Imported bracelet clasp\n");
    $batch = ShopifyImageImportBatch::create([
        's3_prefix' => '2026-11-21',
        'status' => ShopifyImageImportBatch::STATUS_PENDING,
    ]);

    $result = app(ShopifyImageImportService::class)->runBatch($batch);
    $image->refresh();

    expect($result['updated_count'])->toBe(1)
        ->and($result['alt_text_applied'])->toBe(1)
        ->and($image->alt_text)->toBe('Imported bracelet clasp')
        ->and($image->photograph_version)->toBeGreaterThan($photoVersion)
        ->and($image->alt_text_version)->toBeGreaterThan($altVersion)
        ->and($image->shopify_identity_unresolved)->toBeTrue()
        ->and($image->shopify_id)->toBeNull();

    $afterImport = (int) $image->alt_text_version;
    Storage::disk('shopify_product_images')->put('incoming/2026-11-21/CSVROW.png', 'replacement-csv-bytes');
    $second = ShopifyImageImportBatch::create([
        's3_prefix' => '2026-11-21',
        'status' => ShopifyImageImportBatch::STATUS_PENDING,
    ]);
    $again = app(ShopifyImageImportService::class)->runBatch($second);

    expect($again['alt_text_unchanged'])->toBe(1)
        ->and($image->fresh()->alt_text_version)->toBe($afterImport)
        ->and($image->fresh()->alt_text)->toBe('Imported bracelet clasp');
});

it('leaves an approved sentence in place when the same alt text is saved again', function (): void {
    versionFlags();
    [, $image] = versionPair('SAME', 'same-bytes', 'Earlier sentence');
    app(ImageAltTextBackfillService::class)->markPendingReview($image->fresh(), 'Stable sentence');
    expect(approveScope($image->product, 'image_alt')['approved'])->toBe(1);
    $version = (int) $image->fresh()->alt_text_version;
    app(ImageAltTextBackfillService::class)->markPendingReview($image->fresh(), 'Stable sentence');
    $image->fresh()->forceFill(['alt_text' => 'Stable sentence'])->save();

    expect($image->fresh()->alt_text_version)->toBe($version)
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_APPROVED)
        ->and(app(StackImageSync::class)->altAuthorized($image->fresh()))->toBeTrue();
});

it('publishes a later approved source sentence without reuploading the photograph', function (): void {
    versionFlags();
    Http::preventStrayRequests();
    [, $image, $stack] = versionPair('FOLLOW', 'follow-bytes', 'Original sentence');
    $writer = app(ImageAltTextBackfillService::class);
    $writer->markPendingReview($image->fresh(), 'First approved sentence');
    expect(approveScope($image->product, 'image_alt')['approved'])->toBe(1);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $copy->forceFill([
        'shopify_id' => 'gid://shopify/MediaImage/90',
        'shopify_alt_text' => 'First approved sentence',
        'needs_shopify_image_sync' => false,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'last_shopify_synced_image_asset_id' => $copy->image_asset_id,
    ])->save();
    DB::table('stack_image_sync_ledger')->insert([
        'stack_product_id' => $stack->id, 'image_id' => $copy->id, 'source_image_id' => $image->id,
        'verified_photograph_version' => $image->fresh()->photograph_version, 'verified_alt_version' => 1,
        'outcome' => 'verified', 'shopify_media_id' => 'gid://shopify/MediaImage/90', 'verified_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);

    $writer->markPendingReview($image->fresh(), 'Second approved sentence');
    expect(approveScope($image->product, 'image_alt')['approved'])->toBe(1);
    app(ComponentStackPropagation::class)->stageStack($stack->fresh());
    $copy->refresh();
    $plan = app(StackImageSync::class)->plan($stack->fresh());

    expect($copy->alt_text)->toBe('Second approved sentence')
        ->and($copy->needs_shopify_image_sync)->toBeFalse()
        ->and($plan['outcome'])->toBe('alt_text_only')
        ->and($plan['uploads'])->toBe([])
        ->and($plan['alt_only'])->toBe([(int) $copy->id]);
});

it('keeps an approved independent stack description when the component sentence changes', function (): void {
    versionFlags();
    Http::preventStrayRequests();
    [, $image, $stack] = versionPair('OWN', 'own-bytes', 'Component sentence');
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    app(ImageAltTextBackfillService::class)->markPendingReview($copy->fresh(), 'Independent stack description');
    expect(approveScope($stack, 'image_alt')['approved'])->toBe(1);
    app(ImageAltTextBackfillService::class)->markPendingReview($image->fresh(), 'A later component sentence');
    expect(approveScope($image->product, 'image_alt')['approved'])->toBe(1);
    app(ComponentStackPropagation::class)->stageStack($stack->fresh());
    $copy->refresh();
    $copy->forceFill([
        'shopify_id' => 'gid://shopify/MediaImage/91',
        'shopify_alt_text' => 'Independent stack description',
        'needs_shopify_image_sync' => false,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'last_shopify_synced_image_asset_id' => $copy->image_asset_id,
    ])->save();
    DB::table('stack_image_sync_ledger')->insert([
        'stack_product_id' => $stack->id, 'image_id' => $copy->id, 'source_image_id' => $image->id,
        'verified_photograph_version' => $image->fresh()->photograph_version, 'outcome' => 'verified',
        'shopify_media_id' => 'gid://shopify/MediaImage/91', 'verified_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $plan = app(StackImageSync::class)->plan($stack->fresh());

    expect($copy->fresh()->alt_text)->toBe('Independent stack description')
        ->and($copy->alt_inheritance_mode)->toBe('independent')
        ->and($plan['outcome'])->toBe('no_changes_required')
        ->and($plan['alt_only'])->toBe([])
        ->and($plan['uploads'])->toBe([]);
});

it('does not publish an unrelated pending photograph when alt text is approved', function (): void {
    versionFlags();
    Http::preventStrayRequests();
    [$component, $image, $stack] = versionPair('SPLIT', 'split-bytes', 'Previous clasp');
    $otherAsset = createImportTestAsset('other-bytes', 'jpg', 'SPLIT-1.jpg');
    $other = Image::withoutEvents(fn () => Image::create([
        'product_id' => $component->id, 'position' => 2, 'image_asset_id' => $otherAsset->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP, 'backup_completed_at' => now(),
        'sync_state' => Image::SYNC_STATE_SYNCED, 'alt_text' => 'Other sentence',
    ]));
    $pendingAsset = createImportTestAsset('pending-bytes', 'jpg', 'pending.jpg');
    $other->forceFill(['image_asset_id' => $pendingAsset->id])->save();
    app(ImageAltTextBackfillService::class)->markPendingReview($image->fresh(), 'Approved clasp');
    expect(approveScope($component, 'image_alt')['approved'])->toBe(1);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $plan = app(StackImageSync::class)->plan($stack->fresh());

    expect(app(StackImageSync::class)->photographAuthorized($other->fresh()))->toBeFalse()
        ->and(app(StackImageSync::class)->altAuthorized($image->fresh()))->toBeTrue()
        ->and($plan['uploads'])->toBe([])
        ->and(DB::table('component_image_authorizations')->where('image_id', $other->id)->where('scope', 'images')->count())->toBe(0);
});

it('treats an approved unresolved component photograph as publishable on the stack', function (): void {
    versionFlags();
    Http::preventStrayRequests();
    [, $image, $stack] = versionPair('UNRESOLVED', 'unresolved-bytes', 'Current clasp');
    $image->forceFill([
        'shopify_id' => null,
        'shopify_identity_unresolved' => true,
        'needs_shopify_image_sync' => true,
        'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
    ])->save();
    expect(approveScope($image->product, 'images')['approved'])->toBe(1);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $plan = app(StackImageSync::class)->plan($stack->fresh());

    expect(app(ComponentPhotographSelector::class)->publicationAllowed($image->fresh()))->toBeFalse()
        ->and(app(StackImageSync::class)->photographAuthorized($image->fresh()))->toBeTrue()
        ->and($image->fresh()->shopify_id)->toBeNull()
        ->and((int) $copy->image_asset_id)->toBe((int) $image->image_asset_id)
        ->and($copy->shopify_id)->toBeNull()
        ->and($plan['uploads'])->toBe([(int) $copy->id]);
});

it('makes no shopify change when an authorized stack is already current', function (): void {
    versionFlags();
    Http::preventStrayRequests();
    [, $image, $stack] = versionPair('QUIET', 'quiet-bytes', 'Earlier quiet sentence');
    app(ImageAltTextBackfillService::class)->markPendingReview($image->fresh(), 'Quiet sentence');
    expect(approveScope($image->product, 'image_alt')['approved'])->toBe(1);
    expect(approveScope($image->product, 'images')['approved'])->toBe(1);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $copy->forceFill([
        'shopify_id' => 'gid://shopify/MediaImage/92',
        'shopify_alt_text' => 'Quiet sentence',
        'needs_shopify_image_sync' => false,
        'sync_state' => Image::SYNC_STATE_SYNCED,
    ])->save();
    DB::table('stack_image_sync_ledger')->insert([
        'stack_product_id' => $stack->id, 'image_id' => $copy->id, 'source_image_id' => $image->id,
        'verified_photograph_version' => $image->fresh()->photograph_version,
        'verified_alt_version' => $image->fresh()->alt_text_version,
        'outcome' => 'verified', 'shopify_media_id' => 'gid://shopify/MediaImage/92', 'verified_at' => now(),
        'created_at' => now(), 'updated_at' => now(),
    ]);
    $stack->refresh();
    foreach (range(1, 2) as $ignored) {
        $stack->approvals()->create([
            'user_id' => User::factory()->create()->id,
            'approval_version' => $stack->approval_version,
        ]);
    }
    DB::table('stack_image_sync_cursors')->insert([
        'stack_product_id' => $stack->id, 'desired_generation' => 1, 'working_generation' => 0,
        'status' => 'queued', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $first = app(StackImageSync::class)->synchronizeStack((int) $stack->id);
    $second = app(StackImageSync::class)->synchronizeStack((int) $stack->id);

    expect($first['outcome'])->toBe('no_changes_required')
        ->and($second['outcome'])->toBe('no_changes_required')
        ->and($copy->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/92')
        ->and($copy->fresh()->needs_shopify_image_sync)->toBeFalse();
});
