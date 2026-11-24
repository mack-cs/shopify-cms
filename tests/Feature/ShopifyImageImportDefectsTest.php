<?php

use App\Jobs\ImportShopifyProductImagesJob;
use App\Services\ComponentStackPropagation;
use App\Jobs\RebuildShopifyStackImagesJob;
use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\ShopifyImageImportBatch;
use App\Models\ShopifyImageImportItem;
use App\Models\Variant;
use App\Services\ImageAltTextBackfillService;
use App\Services\ImageOwnership;
use App\Services\ShopifyImageImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Support/shopify-image-import.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('shopify_product_images');
    Storage::fake('public');
    config()->set('shopify_image_import.disk', 'shopify_product_images');
    Http::preventStrayRequests();
});

it('automatically advances the filename version for an unversioned replacement', function (): void {
    $product = createImageImportProduct('VER100');
    $asset = createImportTestAsset('current-photo', 'png', 'VER100-v3.png');
    $image = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'position' => 1,
        'image_asset_id' => $asset->id,
        'approved_filename' => 'VER100-v3.png',
        'filename_version' => 3,
        'alt_text' => 'Current sentence',
        'alt_text_status' => Image::ALT_APPROVED,
        'photograph_version' => 4,
        'alt_text_version' => 2,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_id' => 'gid://shopify/MediaImage/100',
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]));
    Storage::disk('shopify_product_images')->put('incoming/older/VER100.png', 'older-photo');

    $result = importDefectPrefix('incoming/older');
    $image->refresh();
    $item = ShopifyImageImportItem::query()->where('s3_key', 'incoming/older/VER100.png')->first();

    expect($result['updated_count'])->toBe(1)
        ->and($result['failed_count'])->toBe(0)
        ->and($image->filename_version)->toBe(4)
        ->and($image->approved_filename)->toBe('ver100-1-v4.png')
        ->and($image->s3_filename)->toBe('VER100.png')
        ->and($image->photograph_version)->toBe(5)
        ->and($image->shopify_id)->toBeNull()
        ->and($product->allImages()->active()->count())->toBe(1);

    importDefectPrefix('incoming/older');
    expect($image->fresh()->filename_version)->toBe(4)
        ->and($image->fresh()->photograph_version)->toBe(5)
        ->and($product->allImages()->active()->count())->toBe(1);
});

it('initializes a legacy row and replaces it on a same-filename upload', function (): void {
    $product = createImageImportProduct('LEGACY1');
    $asset = createImportTestAsset('legacy-photo', 'png', 'LEGACY1.png');
    $image = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'position' => 1,
        'image_asset_id' => $asset->id,
        'approved_filename' => 'LEGACY1.png',
        'alt_text' => 'Legacy sentence',
        'alt_text_status' => Image::ALT_APPROVED,
        'photograph_version' => 3,
        'alt_text_version' => 2,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_id' => 'gid://shopify/MediaImage/101',
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]));
    expect($image->filename_version)->toBeNull();
    Storage::disk('shopify_product_images')->put('incoming/legacy-same/LEGACY1.png', 'legacy-photo');

    importDefectPrefix('incoming/legacy-same');
    $image->refresh();

    expect($image->filename_version)->toBe(1)
        ->and($image->image_asset_id)->toBe($asset->id)
        ->and($image->photograph_version)->toBe(3)
        ->and($image->alt_text_version)->toBe(2)
        ->and($image->alt_text)->toBe('Legacy sentence')
        ->and($image->alt_text_status)->toBe(Image::ALT_APPROVED);

    Storage::disk('shopify_product_images')->put('incoming/legacy-bytes/LEGACY1.png', 'different-photo');
    $replaced = importDefectPrefix('incoming/legacy-bytes');
    $image->refresh();

    expect($replaced['failed_count'])->toBe(0)
        ->and($replaced['updated_count'])->toBe(1)
        ->and($image->image_asset_id)->not->toBe($asset->id)
        ->and($image->filename_version)->toBe(2)
        ->and($image->photograph_version)->toBe(4);
});

it('does not apply a description after a newer photograph has replaced the imported one', function (): void {
    $product = createImageImportProduct('ALT100');
    Storage::disk('shopify_product_images')->put('incoming/alt-current/ALT100.png', 'first-photo');
    Storage::disk('shopify_product_images')->put('incoming/alt-current/alt-text.csv', "filename,alt_text\nALT100.png,First description\n");

    $result = importDefectPrefix('incoming/alt-current');
    $image = $product->allImages()->first();

    $importedAssetId = (int) $image->image_asset_id;
    $importedPhotographVersion = (int) $image->photograph_version;

    expect($result['alt_text_applied'])->toBe(1)
        ->and($image->alt_text)->toBe('First description')
        ->and($image->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW)
        ->and($image->filename_version)->toBe(1)
        ->and($importedPhotographVersion)->toBe(1)
        ->and($image->alt_text_version)->toBe(2);

    $replacement = createImportTestAsset('newer-photo', 'png', 'ALT100-v2.png');
    $image->forceFill([
        'image_asset_id' => $replacement->id,
        'alt_text' => 'Description of the newer photograph',
        'alt_text_status' => Image::ALT_APPROVED,
    ])->save();
    $image->refresh();
    $newerVersion = (int) $image->photograph_version;

    expect(fn () => app(ImageAltTextBackfillService::class)->stageImportedAlt($image, 'First description', $importedAssetId, $importedPhotographVersion))
        ->toThrow(RuntimeException::class, 'older photograph');

    $image->refresh();
    expect($image->alt_text)->toBe('Description of the newer photograph')
        ->and($image->alt_text_status)->toBe(Image::ALT_APPROVED)
        ->and($image->image_asset_id)->toBe($replacement->id)
        ->and($image->photograph_version)->toBe($newerVersion);
});

it('places a stack upload beside an inherited photograph and still replaces a stack owned photograph', function (): void {
    $stack = createImageImportProduct('STACK100', [
        'type' => 'Bundle',
        'is_bundle' => true,
    ]);
    $component = createImageImportProduct('COMP100');
    $source = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $component->id,
        'position' => 1,
        'image_asset_id' => createImportTestAsset('component-photo', 'jpg', 'COMP100.jpg')->id,
        'ownership' => null,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]));
    $inherited = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $stack->id,
        'position' => 2,
        'image_asset_id' => $source->image_asset_id,
        'ownership' => ImageOwnership::COMPONENT_INHERITED,
        'source_image_id' => $source->id,
        'approved_filename' => 'COMP100.jpg',
        'alt_text' => 'Inherited sentence',
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]));
    $ownedAsset = createImportTestAsset('stack-front', 'png', 'STACK100.png');
    $owned = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $stack->id,
        'position' => 1,
        'image_asset_id' => $ownedAsset->id,
        'ownership' => ImageOwnership::STACK_OWNED,
        'source_image_id' => null,
        'approved_filename' => 'STACK100.png',
        'filename_version' => 1,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]));
    Storage::disk('shopify_product_images')->put('incoming/inherited/STACK100-1.jpg', 'direct-upload');
    Storage::disk('shopify_product_images')->put('incoming/inherited/STACK100.png', 'new-stack-front');

    $result = importDefectPrefix('incoming/inherited');
    $inherited->refresh();
    $owned->refresh();

    $imported = $stack->allImages()->active()->where('position', 2)->first();

    expect($result['failed_count'])->toBe(0)
        ->and($result['updated_count'])->toBe(2)
        ->and($inherited->position)->toBe(3)
        ->and($inherited->ownership)->toBe(ImageOwnership::COMPONENT_INHERITED)
        ->and($inherited->source_image_id)->toBe($source->id)
        ->and($inherited->image_asset_id)->toBe($source->image_asset_id)
        ->and($inherited->alt_text)->toBe('Inherited sentence')
        ->and($inherited->approved_filename)->toBe('COMP100.jpg')
        ->and($inherited->s3_filename)->toBeNull()
        ->and($owned->ownership)->toBe(ImageOwnership::STACK_OWNED)
        ->and($owned->approved_filename)->toBe('stack100-1-v2.png')
        ->and($owned->s3_filename)->toBe('STACK100.png')
        ->and($owned->source_image_id)->toBeNull()
        ->and($owned->filename_version)->toBe(2)
        ->and($owned->imageAsset?->original_filename)->toBe('STACK100.png')
        ->and($imported?->ownership)->toBe(ImageOwnership::STACK_OWNED)
        ->and($imported?->source_image_id)->toBeNull()
        ->and($imported?->approved_filename)->toBe('stack100-2-v1.jpg')
        ->and($imported?->s3_filename)->toBe('STACK100-1.jpg');
});

it('imports a stack photograph at position 1 without replacing the inherited component photograph', function (): void {
    $component = createImageImportProduct('COMP23');
    $stack = createImageImportProduct('EBB23TEST', [
        'handle' => 'the-test-escape-bracelet-test-stack',
        'type' => 'Bundle',
        'is_bundle' => true,
    ]);
    $source = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $component->id,
        'position' => 1,
        'image_asset_id' => createImportTestAsset('component-photo', 'jpg', 'COMP23.jpg')->id,
        'alt_text' => 'Component sentence',
        'alt_text_status' => Image::ALT_APPROVED,
        'photograph_version' => 4,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]));
    $inherited = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $stack->id,
        'position' => 1,
        'image_asset_id' => $source->image_asset_id,
        'ownership' => ImageOwnership::COMPONENT_INHERITED,
        'source_image_id' => $source->id,
        'approved_filename' => 'COMP23.jpg',
        'alt_text' => 'Component sentence',
        'alt_text_status' => Image::ALT_APPROVED,
        'photograph_version' => 4,
        'shopify_id' => 'gid://shopify/MediaImage/10396',
        'shopify_cdn_url' => 'https://cdn.shopify.com/s/files/1/inherited.jpg',
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'needs_shopify_image_sync' => false,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]));
    NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'sku' => 'EBB23TEST',
        'shopify_id' => $stack->shopify_id,
        'handle' => $stack->handle,
        'title' => $stack->title,
        'type' => 'Bundle',
        'tags' => 'bundles',
        'status' => 'draft',
        'bundle_product_ids' => [$component->id],
    ]));
    Storage::disk('shopify_product_images')->put('incoming/Test-Stack/EBB23TEST.jpg', 'stack-lifestyle');
    Storage::disk('shopify_product_images')->put(
        'incoming/Test-Stack/alt-text.csv',
        "filename,alt_text\nEBB23TEST.jpg,The test escape stack\n",
    );

    $first = importDefectPrefix('incoming/Test-Stack');
    $second = importDefectPrefix('incoming/Test-Stack');
    $inherited->refresh();
    $owned = $stack->allImages()->active()->where('ownership', ImageOwnership::STACK_OWNED)->first();

    expect($first['failed_count'])->toBe(0)
        ->and($first['updated_count'])->toBe(1)
        ->and($first['alt_text_applied'])->toBe(1)
        ->and($second['failed_count'])->toBe(0)
        ->and($stack->allImages()->active()->count())->toBe(2)
        ->and($owned?->position)->toBe(1)
        ->and($owned?->filename_version)->toBe(1)
        ->and($owned?->approved_filename)->toBe('the-test-escape-bracelet-test-stack-1-v1.jpg')
        ->and($owned?->s3_filename)->toBe('EBB23TEST.jpg')
        ->and($owned?->alt_text)->toBe('The test escape stack')
        ->and($owned?->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW)
        ->and($owned?->needs_shopify_image_sync)->toBeTrue()
        ->and($owned?->source_image_id)->toBeNull()
        ->and($inherited->position)->toBe(2)
        ->and($inherited->ownership)->toBe(ImageOwnership::COMPONENT_INHERITED)
        ->and($inherited->source_image_id)->toBe($source->id)
        ->and($inherited->shopify_id)->toBe('gid://shopify/MediaImage/10396')
        ->and($inherited->alt_text)->toBe('Component sentence')
        ->and($inherited->alt_text_status)->toBe(Image::ALT_APPROVED)
        ->and($inherited->photograph_version)->toBe(4)
        ->and($inherited->needs_shopify_image_sync)->toBeFalse();
    Http::assertNothingSent();

    $batch = ShopifyImageImportBatch::query()->latest('id')->first();
    $rebuilt = app(ShopifyImageImportService::class)->rebuildStacksForBatch($batch, [$stack->id]);
    $again = app(ComponentStackPropagation::class)->stageStack($stack->fresh());
    $images = $stack->allImages()->active()->orderBy('position')->get();

    expect($rebuilt['rebuilt'])->toBe(1)
        ->and($again['rebuilt'])->toBeTrue()
        ->and($images)->toHaveCount(2)
        ->and($images[0]->id)->toBe($owned?->id)
        ->and($images[0]->ownership)->toBe(ImageOwnership::STACK_OWNED)
        ->and($images[0]->alt_text)->toBe('The test escape stack')
        ->and($images[1]->id)->toBe($inherited->id)
        ->and($images[1]->ownership)->toBe(ImageOwnership::COMPONENT_INHERITED)
        ->and($images[1]->source_image_id)->toBe($source->id)
        ->and($images[1]->shopify_id)->toBe('gid://shopify/MediaImage/10396');
    Http::assertNothingSent();
});

it('keeps several stack owned photographs ahead of inherited component photographs', function (): void {
    $firstComponent = createImageImportProduct('COMPA');
    $secondComponent = createImageImportProduct('COMPB');
    $stack = createImageImportProduct('EBBMULTI', [
        'handle' => 'multi-stack',
        'type' => 'Bundle',
        'is_bundle' => true,
    ]);
    $firstSource = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $firstComponent->id,
        'position' => 1,
        'image_asset_id' => createImportTestAsset('component-a', 'jpg', 'COMPA.jpg')->id,
        'alt_text' => 'Component A',
        'alt_text_status' => Image::ALT_APPROVED,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]));
    $secondSource = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $secondComponent->id,
        'position' => 1,
        'image_asset_id' => createImportTestAsset('component-b', 'jpg', 'COMPB.jpg')->id,
        'alt_text' => 'Component B',
        'alt_text_status' => Image::ALT_APPROVED,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]));
    $firstInherited = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $stack->id,
        'position' => 1,
        'image_asset_id' => $firstSource->image_asset_id,
        'ownership' => ImageOwnership::COMPONENT_INHERITED,
        'source_image_id' => $firstSource->id,
        'alt_text' => 'Component A',
        'alt_text_status' => Image::ALT_APPROVED,
        'shopify_id' => 'gid://shopify/MediaImage/501',
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]));
    $secondInherited = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $stack->id,
        'position' => 2,
        'image_asset_id' => $secondSource->image_asset_id,
        'ownership' => ImageOwnership::COMPONENT_INHERITED,
        'source_image_id' => $secondSource->id,
        'alt_text' => 'Component B',
        'alt_text_status' => Image::ALT_APPROVED,
        'shopify_id' => 'gid://shopify/MediaImage/502',
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]));
    NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'sku' => 'EBBMULTI',
        'shopify_id' => $stack->shopify_id,
        'handle' => $stack->handle,
        'title' => $stack->title,
        'type' => 'Bundle',
        'tags' => 'bundles',
        'status' => 'draft',
        'bundle_product_ids' => [$firstComponent->id, $secondComponent->id],
    ]));
    Storage::disk('shopify_product_images')->put('incoming/multi-stack/EBBMULTI.jpg', 'owned-front');
    Storage::disk('shopify_product_images')->put('incoming/multi-stack/EBBMULTI-1.jpg', 'owned-second');
    Storage::disk('shopify_product_images')->put(
        'incoming/multi-stack/alt-text.csv',
        "filename,alt_text\nEBBMULTI.jpg,Owned front\nEBBMULTI-1.jpg,Owned second\n",
    );

    importDefectPrefix('incoming/multi-stack');
    $batch = ShopifyImageImportBatch::query()->latest('id')->first();
    app(ShopifyImageImportService::class)->rebuildStacksForBatch($batch, [$stack->id]);
    importDefectPrefix('incoming/multi-stack');
    app(ComponentStackPropagation::class)->stageStack($stack->fresh());
    $images = $stack->allImages()->active()->orderBy('position')->get();

    expect($images)->toHaveCount(4)
        ->and($images->pluck('ownership')->all())->toBe([
            ImageOwnership::STACK_OWNED,
            ImageOwnership::STACK_OWNED,
            ImageOwnership::COMPONENT_INHERITED,
            ImageOwnership::COMPONENT_INHERITED,
        ])
        ->and($images[0]->s3_filename)->toBe('EBBMULTI.jpg')
        ->and($images[0]->alt_text)->toBe('Owned front')
        ->and($images[0]->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW)
        ->and($images[1]->s3_filename)->toBe('EBBMULTI-1.jpg')
        ->and($images[1]->approved_filename)->toBe('multi-stack-2-v1.jpg')
        ->and($images[2]->id)->toBe($firstInherited->id)
        ->and($images[2]->source_image_id)->toBe($firstSource->id)
        ->and($images[2]->shopify_id)->toBe('gid://shopify/MediaImage/501')
        ->and($images[3]->id)->toBe($secondInherited->id)
        ->and($images[3]->source_image_id)->toBe($secondSource->id)
        ->and($images[3]->shopify_id)->toBe('gid://shopify/MediaImage/502');
    Http::assertNothingSent();
});

it('rejects duplicate filename versions for one product slot even when the filenames use different skus', function (): void {
    $product = createImageImportProduct('SLOT1');
    Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $product->id,
        'sku' => 'SLOT1B',
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'local_dirty' => false,
    ]));
    Storage::disk('shopify_product_images')->put('incoming/same-slot/SLOT1.png', 'front-a');
    Storage::disk('shopify_product_images')->put('incoming/same-slot/SLOT1B.png', 'front-b');

    $conflict = importDefectPrefix('incoming/same-slot');

    expect($conflict['updated_count'])->toBe(0)
        ->and($conflict['failed_count'])->toBe(2)
        ->and($product->allImages()->count())->toBe(0)
        ->and(ShopifyImageImportItem::query()->where('status', ShopifyImageImportItem::STATUS_UPDATED)->count())->toBe(0);

    Storage::disk('shopify_product_images')->put('incoming/highest-slot/SLOT1.png', 'front-old');
    Storage::disk('shopify_product_images')->put('incoming/highest-slot/SLOT1B-v2.png', 'front-new');
    $winner = importDefectPrefix('incoming/highest-slot');

    expect($winner['updated_count'])->toBe(1)
        ->and($winner['failed_count'])->toBe(1)
        ->and(defectPositionFilenames($product))->toBe([1 => 'SLOT1B-v2.png']);

    $other = createImageImportProduct('SLOT2');
    Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $other->id,
        'sku' => 'SLOT2B',
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'local_dirty' => false,
    ]));
    Storage::disk('shopify_product_images')->put('incoming/two-slots/SLOT2.png', 'front');
    Storage::disk('shopify_product_images')->put('incoming/two-slots/SLOT2B-1.jpg', 'second');
    $separate = importDefectPrefix('incoming/two-slots');

    expect($separate['failed_count'])->toBe(0)
        ->and($separate['updated_count'])->toBe(2)
        ->and(defectPositionFilenames($other))->toBe([
            1 => 'SLOT2.png',
            2 => 'SLOT2B-1.jpg',
        ]);
});

it('imports the same photograph at another position and accepts a repeated import', function (): void {
    $product = createImageImportProduct('DUP100');
    $asset = createImportTestAsset('shared-photo', 'jpg', 'DUP100.jpg');
    Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'position' => 1,
        'image_asset_id' => $asset->id,
        'approved_filename' => 'DUP100.jpg',
        'filename_version' => 1,
        'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]));
    Storage::disk('shopify_product_images')->put('incoming/same-bytes/DUP100-1.jpg', 'shared-photo');

    $first = importDefectPrefix('incoming/same-bytes');
    $second = importDefectPrefix('incoming/same-bytes');

    expect($first['failed_count'])->toBe(0)
        ->and($first['updated_count'])->toBe(1)
        ->and($second['failed_count'])->toBe(0)
        ->and($second['updated_count'])->toBe(1)
        ->and($product->allImages()->count())->toBe(2)
        ->and($product->allImages()->orderBy('position')->pluck('image_asset_id')->all())->toBe([$asset->id, $asset->id]);
});

it('queues a dependent stack rebuild with Shopify propagation disabled', function (): void {
    expect(config('images.component_stack_propagation'))->toBeFalse()
        ->and(config('images.component_stack_local_rebuild'))->toBeFalse()
        ->and(config('images.component_stack_blind_publication'))->toBeFalse();

    Bus::fake([RebuildShopifyStackImagesJob::class]);
    $component = createImageImportProduct('COMP200');
    $stack = createImageImportProduct('STACK200', [
        'type' => 'Bundle',
        'is_bundle' => true,
    ]);
    NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'sku' => 'STACK200',
        'shopify_id' => $stack->shopify_id,
        'handle' => $stack->handle,
        'title' => $stack->title,
        'type' => 'Bundle',
        'tags' => 'bundles',
        'status' => 'active',
        'bundle_product_ids' => [$component->id],
    ]));
    $stackImage = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $stack->id,
        'position' => 1,
        'image_asset_id' => createImportTestAsset('stack-gallery', 'png', 'stack.png')->id,
        'ownership' => ImageOwnership::STACK_OWNED,
        'src' => 'https://cdn.shopify.com/s/files/1/stack-gallery.png',
        'shopify_cdn_url' => 'https://cdn.shopify.com/s/files/1/stack-gallery.png',
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]));
    Storage::disk('shopify_product_images')->put('incoming/no-rebuild/COMP200.jpg', 'component-photo');
    $batch = ShopifyImageImportBatch::create([
        's3_prefix' => 'incoming/no-rebuild',
        'status' => ShopifyImageImportBatch::STATUS_PENDING,
    ]);

    app(ImportShopifyProductImagesJob::class, [
        'batchId' => $batch->id,
    ])->handle(app(ShopifyImageImportService::class));
    $stackImage->refresh();

    Bus::assertDispatched(RebuildShopifyStackImagesJob::class);
    expect($component->allImages()->count())->toBe(1)
        ->and($stack->allImages()->count())->toBe(1)
        ->and($stackImage->src)->toBe('https://cdn.shopify.com/s/files/1/stack-gallery.png')
        ->and($stackImage->shopify_cdn_url)->toBe('https://cdn.shopify.com/s/files/1/stack-gallery.png')
        ->and(config('images.component_stack_propagation'))->toBeFalse()
        ->and(config('images.component_stack_local_rebuild'))->toBeFalse()
        ->and(config('images.component_stack_blind_publication'))->toBeFalse();

    $manual = ShopifyImageImportBatch::create([
        's3_prefix' => 'incoming/manual-rebuild',
        'status' => ShopifyImageImportBatch::STATUS_PENDING,
    ]);
    (new RebuildShopifyStackImagesJob($manual->id, [], null, true))
        ->handle(app(ShopifyImageImportService::class));

    expect($manual->fresh()->status)->toBe(ShopifyImageImportBatch::STATUS_COMPLETED)
        ->and(config('images.component_stack_local_rebuild'))->toBeFalse();
});

it('leaves the other gallery position urls unchanged when a later position is imported', function (): void {
    $product = createImageImportProduct('URL100');
    $front = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'position' => 1,
        'image_asset_id' => createImportTestAsset('front-photo', 'png', 'URL100.png')->id,
        'approved_filename' => 'URL100.png',
        'filename_version' => 1,
        'src' => 'https://cdn.shopify.com/s/files/1/keep-position-one.jpg',
        'shopify_cdn_url' => 'https://cdn.shopify.com/s/files/1/keep-position-one.jpg',
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_id' => 'gid://shopify/MediaImage/1001',
        'needs_shopify_image_sync' => false,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]));
    Storage::disk('shopify_product_images')->put('incoming/position-two/URL100-1.jpg', 'second-photo');

    importDefectPrefix('incoming/position-two');
    $front->refresh();
    $second = $product->allImages()->where('position', 2)->first();

    expect($front->src)->toBe('https://cdn.shopify.com/s/files/1/keep-position-one.jpg')
        ->and($front->shopify_cdn_url)->toBe('https://cdn.shopify.com/s/files/1/keep-position-one.jpg')
        ->and($front->shopify_id)->toBe('gid://shopify/MediaImage/1001')
        ->and($front->needs_shopify_image_sync)->toBeFalse()
        ->and($second->src)->toContain('product-image-backups')
        ->and($second->photograph_version)->toBe(1)
        ->and($second->filename_version)->toBe(1);
});

it('clears stale approval evidence when a higher filename version replaces the photograph', function (): void {
    $product = createImageImportProduct('REPL100');
    Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'position' => 1,
        'image_asset_id' => createImportTestAsset('old-photo', 'png', 'REPL100.png')->id,
        'approved_filename' => 'REPL100.png',
        'filename_version' => 1,
        'alt_text' => 'Old sentence',
        'alt_text_status' => Image::ALT_APPROVED,
        'photograph_version' => 4,
        'alt_text_version' => 2,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_id' => 'gid://shopify/MediaImage/900',
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]));
    $before = $product->allImages()->first();
    $upload = app(\App\Services\ImageUploadOperations::class)->claim($before, $product->shopify_id, []);
    expect($upload)->not->toBeNull();
    Storage::disk('shopify_product_images')->put('incoming/replace-bytes/REPL100-v2.png', 'new-photo');

    importDefectPrefix('incoming/replace-bytes');
    $image = $product->allImages()->first();

    expect($image->filename_version)->toBe(2)
        ->and($image->photograph_version)->toBe(5)
        ->and($image->alt_text)->toBe('Old sentence')
        ->and($image->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW)
        ->and($image->shopify_id)->toBeNull()
        ->and($image->needs_shopify_image_sync)->toBeTrue()
        ->and($image->shopify_media_create_uncertain)->toBeTrue()
        ->and(app(\App\Services\ImageUploadOperations::class)->active($image)->id)->toBe($upload->id)
        ->and($image->alt_text_version)->not->toBe(2);
});

it('preserves identical legacy local bytes and replaces changed bytes at the same filename', function (): void {
    $product = createImageImportProduct('LOCAL1');
    Storage::disk('public')->put('product-images/local1.png', 'legacy-local-bytes');
    $image = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'position' => 1,
        'image_path' => 'product-images/local1.png',
        'alt_text' => 'Keep local sentence',
        'alt_text_status' => Image::ALT_APPROVED,
        'photograph_version' => 4,
        'alt_text_version' => 2,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_id' => 'gid://shopify/MediaImage/501',
        'src' => 'https://cdn.shopify.com/s/files/1/local-keep.jpg',
        'shopify_cdn_url' => 'https://cdn.shopify.com/s/files/1/local-keep.jpg',
        'needs_shopify_image_sync' => false,
    ]));
    $imageId = $image->id;
    Storage::disk('shopify_product_images')->put('incoming/local-same/LOCAL1.png', 'legacy-local-bytes');
    $same = importDefectPrefix('incoming/local-same');
    $image->refresh();

    expect($same['failed_count'])->toBe(0)
        ->and($same['updated_count'])->toBe(1)
        ->and($image->id)->toBe($imageId)
        ->and($image->filename_version)->toBe(1)
        ->and($image->alt_text)->toBe('Keep local sentence')
        ->and($image->photograph_version)->toBe(4)
        ->and($image->alt_text_version)->toBe(2)
        ->and($image->shopify_id)->toBe('gid://shopify/MediaImage/501');

    Storage::disk('shopify_product_images')->put('incoming/local-higher/LOCAL1.png', 'replacement-upload');
    $replaced = importDefectPrefix('incoming/local-higher');
    $image->refresh();

    expect($replaced['updated_count'])->toBe(1)
        ->and($replaced['failed_count'])->toBe(0)
        ->and($image->id)->toBe($imageId)
        ->and($image->filename_version)->toBe(2)
        ->and($image->imageAsset?->original_filename)->toBe('LOCAL1.png')
        ->and($image->photograph_version)->toBe(5)
        ->and($image->shopify_id)->toBeNull();
});

it('rejects an equal-version replacement when the legacy photograph cannot be read', function (): void {
    $product = createImageImportProduct('UNREAD1');
    $image = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'position' => 1,
        'image_path' => 'product-images/missing-local.png',
        'alt_text' => 'Unreadable sentence',
        'alt_text_status' => Image::ALT_APPROVED,
        'photograph_version' => 3,
        'alt_text_version' => 2,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_id' => 'gid://shopify/MediaImage/502',
        'needs_shopify_image_sync' => false,
    ]));
    Storage::disk('shopify_product_images')->put('incoming/unreadable/UNREAD1.png', 'new-bytes');

    $result = importDefectPrefix('incoming/unreadable');
    $image->refresh();

    expect($result['updated_count'])->toBe(0)
        ->and($result['failed_count'])->toBe(1)
        ->and(ShopifyImageImportItem::query()->first()->message)->toContain('could not be read')
        ->and($image->image_asset_id)->toBeNull()
        ->and($image->image_path)->toBe('product-images/missing-local.png')
        ->and($image->alt_text)->toBe('Unreadable sentence')
        ->and($image->photograph_version)->toBe(3)
        ->and($image->alt_text_version)->toBe(2)
        ->and($image->shopify_id)->toBe('gid://shopify/MediaImage/502');
});

it('rejects a whole slot when any filename version is claimed more than once', function (): void {
    $product = createImageImportProduct('MULTI1');
    Storage::disk('shopify_product_images')->put('incoming/multi-version/MULTI1.png', 'version-one-a');
    Storage::disk('shopify_product_images')->put('incoming/multi-version/MULTI1.jpg', 'version-one-b');
    Storage::disk('shopify_product_images')->put('incoming/multi-version/MULTI1-v2.png', 'version-two');

    $conflict = importDefectPrefix('incoming/multi-version');

    expect($conflict['updated_count'])->toBe(0)
        ->and($conflict['failed_count'])->toBe(3)
        ->and($product->allImages()->count())->toBe(0)
        ->and(ShopifyImageImportItem::query()->where('status', ShopifyImageImportItem::STATUS_UPDATED)->count())->toBe(0)
        ->and(ShopifyImageImportItem::query()->orderBy('s3_key')->pluck('message')->all())->each->toContain('None were imported');

    Storage::disk('shopify_product_images')->put('incoming/unique-version/MULTI1.png', 'version-one');
    Storage::disk('shopify_product_images')->put('incoming/unique-version/MULTI1-v2.png', 'version-two');
    $winner = importDefectPrefix('incoming/unique-version');

    expect($winner['updated_count'])->toBe(1)
        ->and($winner['failed_count'])->toBe(1)
        ->and(defectPositionFilenames($product))->toBe([1 => 'MULTI1-v2.png']);
});

it('stops the batch before changing an image when the filename version column is missing', function (): void {
    $product = createImageImportProduct('MIGRATE1');
    $image = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'position' => 1,
        'image_asset_id' => createImportTestAsset('existing-photo', 'png', 'MIGRATE1.png')->id,
        'alt_text' => 'Stay put',
        'photograph_version' => 2,
        'alt_text_version' => 2,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_id' => 'gid://shopify/MediaImage/503',
    ]));
    Storage::disk('shopify_product_images')->put('incoming/missing-column/MIGRATE1-v2.png', 'replacement');
    Schema::table('images', function ($table): void {
        $table->dropColumn('filename_version');
    });

    try {
        expect(fn () => importDefectPrefix('incoming/missing-column'))
            ->toThrow(RuntimeException::class, 'filename_version');
        $image->refresh();

        expect(ShopifyImageImportItem::query()->where('status', ShopifyImageImportItem::STATUS_UPDATED)->count())->toBe(0)
            ->and($image->alt_text)->toBe('Stay put')
            ->and($image->photograph_version)->toBe(2)
            ->and($image->shopify_id)->toBe('gid://shopify/MediaImage/503')
            ->and($product->allImages()->count())->toBe(1);
    } finally {
        if (! Schema::hasColumn('images', 'filename_version')) {
            Schema::table('images', function ($table): void {
                $table->unsignedInteger('filename_version')->nullable();
            });
        }
    }
});

it('stores the exact s3 basename and matches alt text without renaming the object', function (): void {
    $product = createImageImportProduct('SKU SPACE');
    Storage::disk('shopify_product_images')->put('incoming/spaced/SKU SPACE-v2.png', 'spaced-photo');
    Storage::disk('shopify_product_images')->put(
        'incoming/spaced/alt-text.csv',
        "filename,alt_text\nSKU SPACE-v2.png,Spaced description\n",
    );

    $result = importDefectPrefix('incoming/spaced');
    $image = $product->allImages()->first();

    expect($result['failed_count'])->toBe(0)
        ->and($result['alt_text_applied'])->toBe(1)
        ->and(Storage::disk('shopify_product_images')->exists('incoming/spaced/SKU SPACE-v2.png'))->toBeTrue()
        ->and($image->s3_filename)->toBe('SKU SPACE-v2.png')
        ->and($image->approved_filename)->toBe('sku-space-1-v2.png')
        ->and($image->filename_version)->toBe(2)
        ->and($image->alt_text)->toBe('Spaced description')
        ->and($image->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW);
});

it('does not treat identical bytes as a new photograph when the filename version increases', function (): void {
    $managed = createImageImportProduct('MANAGED1');
    $managedAsset = createImportTestAsset('managed-bytes', 'png', 'MANAGED1.png');
    $managedImage = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $managed->id,
        'position' => 1,
        'image_asset_id' => $managedAsset->id,
        'approved_filename' => 'manually-approved.png',
        'filename_mode' => Image::FILENAME_MODE_MANUAL,
        'filename_version' => 1,
        'alt_text' => 'Managed sentence',
        'alt_text_status' => Image::ALT_APPROVED,
        'photograph_version' => 4,
        'alt_text_version' => 2,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_id' => 'gid://shopify/MediaImage/701',
        'needs_shopify_image_sync' => false,
        'alt_confirmation_media_id' => 'gid://shopify/MediaImage/701',
        'alt_confirmation_text' => 'Managed sentence',
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]));
    Storage::disk('shopify_product_images')->put('incoming/same-managed/MANAGED1-v3.png', 'managed-bytes');

    importDefectPrefix('incoming/same-managed');
    $managedImage->refresh();

    expect($managedImage->filename_version)->toBe(3)
        ->and($managedImage->s3_filename)->toBe('MANAGED1-v3.png')
        ->and($managedImage->approved_filename)->toBe('manually-approved.png')
        ->and($managedImage->filename_mode)->toBe(Image::FILENAME_MODE_MANUAL)
        ->and($managedImage->image_asset_id)->toBe($managedAsset->id)
        ->and($managedImage->photograph_version)->toBe(4)
        ->and($managedImage->alt_text_version)->toBe(2)
        ->and($managedImage->alt_text)->toBe('Managed sentence')
        ->and($managedImage->alt_text_status)->toBe(Image::ALT_APPROVED)
        ->and($managedImage->shopify_id)->toBe('gid://shopify/MediaImage/701')
        ->and($managedImage->alt_confirmation_media_id)->toBe('gid://shopify/MediaImage/701')
        ->and($managedImage->needs_shopify_image_sync)->toBeFalse();

    $legacy = createImageImportProduct('LEGACYPATH');
    Storage::disk('public')->put('product-images/legacy-path.png', 'legacy-bytes');
    $legacyImage = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $legacy->id,
        'position' => 1,
        'image_path' => 'product-images/legacy-path.png',
        'approved_filename' => 'manually-approved.png',
        'filename_mode' => Image::FILENAME_MODE_MANUAL,
        'filename_version' => 1,
        'alt_text' => 'Legacy sentence',
        'alt_text_status' => Image::ALT_APPROVED,
        'photograph_version' => 4,
        'alt_text_version' => 2,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_id' => 'gid://shopify/MediaImage/702',
        'needs_shopify_image_sync' => false,
    ]));
    Storage::disk('shopify_product_images')->put('incoming/same-legacy/LEGACYPATH-v2.png', 'legacy-bytes');

    importDefectPrefix('incoming/same-legacy');
    $legacyImage->refresh();

    expect($legacyImage->filename_version)->toBe(2)
        ->and($legacyImage->image_path)->toBe('product-images/legacy-path.png')
        ->and($legacyImage->image_asset_id)->toBeNull()
        ->and($legacyImage->approved_filename)->toBe('manually-approved.png')
        ->and($legacyImage->photograph_version)->toBe(4)
        ->and($legacyImage->shopify_id)->toBe('gid://shopify/MediaImage/702')
        ->and($legacyImage->needs_shopify_image_sync)->toBeFalse();

    $both = createImageImportProduct('BOTHREF');
    $bothAsset = createImportTestAsset('both-bytes', 'png', 'BOTHREF.png');
    Storage::disk('public')->put('product-images/both-ref.png', 'both-bytes');
    $bothImage = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $both->id,
        'position' => 1,
        'image_asset_id' => $bothAsset->id,
        'image_path' => 'product-images/both-ref.png',
        'approved_filename' => 'manually-approved.png',
        'filename_mode' => Image::FILENAME_MODE_MANUAL,
        'filename_version' => 1,
        'alt_text' => 'Both sentence',
        'alt_text_status' => Image::ALT_APPROVED,
        'photograph_version' => 6,
        'alt_text_version' => 3,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_id' => 'gid://shopify/MediaImage/703',
        'needs_shopify_image_sync' => false,
    ]));
    Storage::disk('shopify_product_images')->put('incoming/same-both/BOTHREF-v4.png', 'both-bytes');

    importDefectPrefix('incoming/same-both');
    $bothImage->refresh();

    expect($bothImage->filename_version)->toBe(4)
        ->and($bothImage->image_asset_id)->toBe($bothAsset->id)
        ->and($bothImage->image_path)->toBe('product-images/both-ref.png')
        ->and($bothImage->approved_filename)->toBe('manually-approved.png')
        ->and($bothImage->photograph_version)->toBe(6)
        ->and($bothImage->alt_text_version)->toBe(3)
        ->and($bothImage->shopify_id)->toBe('gid://shopify/MediaImage/703');

    $disagree = createImageImportProduct('DISAGREE');
    $disagreeAsset = createImportTestAsset('asset-bytes', 'png', 'DISAGREE.png');
    Storage::disk('public')->put('product-images/disagree.png', 'path-bytes');
    $disagreeImage = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $disagree->id,
        'position' => 1,
        'image_asset_id' => $disagreeAsset->id,
        'image_path' => 'product-images/disagree.png',
        'approved_filename' => 'manually-approved.png',
        'filename_version' => 1,
        'photograph_version' => 2,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_id' => 'gid://shopify/MediaImage/704',
    ]));
    Storage::disk('shopify_product_images')->put('incoming/disagree/DISAGREE-v2.png', 'asset-bytes');

    $unverified = importDefectPrefix('incoming/disagree');
    $disagreeImage->refresh();

    expect($unverified['failed_count'])->toBe(1)
        ->and(ShopifyImageImportItem::query()->where('s3_key', 'incoming/disagree/DISAGREE-v2.png')->first()->message)->toContain('could not be read')
        ->and($disagreeImage->filename_version)->toBe(1)
        ->and($disagreeImage->image_path)->toBe('product-images/disagree.png')
        ->and($disagreeImage->photograph_version)->toBe(2)
        ->and($disagreeImage->shopify_id)->toBe('gid://shopify/MediaImage/704');

    $missing = createImageImportProduct('UNREAD2');
    $missingImage = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $missing->id,
        'position' => 1,
        'shopify_id' => 'gid://shopify/MediaImage/705',
        'approved_filename' => 'manually-approved.png',
        'filename_version' => 1,
        'photograph_version' => 3,
        'alt_text' => 'Remote only',
        'alt_text_status' => Image::ALT_APPROVED,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'needs_shopify_image_sync' => false,
    ]));
    Storage::disk('shopify_product_images')->put('incoming/unreadable-higher/UNREAD2-v2.png', 'new-bytes');

    $closed = importDefectPrefix('incoming/unreadable-higher');
    $missingImage->refresh();

    expect($closed['failed_count'])->toBe(1)
        ->and($missingImage->filename_version)->toBe(1)
        ->and($missingImage->photograph_version)->toBe(3)
        ->and($missingImage->shopify_id)->toBe('gid://shopify/MediaImage/705')
        ->and($missingImage->approved_filename)->toBe('manually-approved.png');
});

it('keeps a manual filename on an unchanged reimport and replaces it only for a new photograph', function (): void {
    $product = createImageImportProduct('KEEPNAME');
    $asset = createImportTestAsset('kept-bytes', 'png', 'KEEPNAME.png');
    $image = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'position' => 1,
        'image_asset_id' => $asset->id,
        'approved_filename' => 'manually-approved.png',
        'filename_mode' => Image::FILENAME_MODE_MANUAL,
        'filename_version' => 1,
        'alt_text' => 'Approved sentence',
        'alt_text_status' => Image::ALT_APPROVED,
        'photograph_version' => 4,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_id' => 'gid://shopify/MediaImage/801',
        'last_shopify_synced_image_asset_id' => $asset->id,
        'needs_shopify_image_sync' => false,
    ]));
    Storage::disk('shopify_product_images')->put('incoming/keep-name/KEEPNAME.png', 'kept-bytes');

    importDefectPrefix('incoming/keep-name');
    $image->refresh();

    expect($image->approved_filename)->toBe('manually-approved.png')
        ->and($image->s3_filename)->toBe('KEEPNAME.png')
        ->and($image->photograph_version)->toBe(4)
        ->and($image->needs_shopify_image_sync)->toBeFalse()
        ->and($image->shopify_id)->toBe('gid://shopify/MediaImage/801');

    Storage::disk('shopify_product_images')->put('incoming/replace-name/KEEPNAME-v2.png', 'new-photograph');
    importDefectPrefix('incoming/replace-name');
    $image->refresh();

    expect($image->approved_filename)->toBe('keepname-1-v2.png')
        ->and($image->s3_filename)->toBe('KEEPNAME-v2.png')
        ->and($image->filename_version)->toBe(2)
        ->and($image->photograph_version)->toBe(5)
        ->and($image->shopify_id)->toBeNull()
        ->and($image->alt_text)->toBe('Approved sentence')
        ->and($image->needs_shopify_image_sync)->toBeTrue();
});

it('does not advance the photograph version when a spare local path is cleared', function (): void {
    $product = createImageImportProduct('SPAREPATH');
    $asset = createImportTestAsset('spare-bytes', 'png', 'SPAREPATH.png');
    Storage::disk('public')->put('product-images/spare.png', 'spare-bytes');
    $image = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'position' => 1,
        'image_asset_id' => $asset->id,
        'image_path' => 'product-images/spare.png',
        'photograph_version' => 4,
        'sync_state' => Image::SYNC_STATE_SYNCED,
    ]));

    Image::withoutEvents(fn () => $image->forceFill(['image_path' => null])->save());
    $image->refresh();

    expect($image->image_path)->toBeNull()
        ->and($image->image_asset_id)->toBe($asset->id)
        ->and($image->photograph_version)->toBe(4);

    Storage::disk('public')->put('product-images/other.png', 'other-bytes');
    Image::withoutEvents(fn () => $image->forceFill(['image_path' => 'product-images/other.png'])->save());
    $image->refresh();

    expect($image->photograph_version)->toBe(5);
});

function importDefectPrefix(string $prefix): array
{
    $batch = ShopifyImageImportBatch::create([
        's3_prefix' => $prefix,
        'status' => ShopifyImageImportBatch::STATUS_PENDING,
    ]);

    return app(ShopifyImageImportService::class)->runBatch($batch);
}

function defectPositionFilenames($product): array
{
    return $product->allImages()->orderBy('position')->get()
        ->mapWithKeys(fn (Image $image): array => [(int) $image->position => $image->imageAsset?->original_filename])
        ->all();
}
