<?php

use App\Models\Image;
use App\Models\ShopifyImageImportBatch;
use App\Models\ShopifyImageImportItem;
use App\Services\ShopifyImageImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Support/shopify-image-import.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('shopify_product_images');
    Storage::fake('public');
    config()->set('shopify_image_import.disk', 'shopify_product_images');
    Http::preventStrayRequests();
});

it('keeps both gallery positions from the batch 48 filenames', function (): void {
    $prefix = 'incoming/Test-Normal';
    Storage::disk('shopify_product_images')->put($prefix.'/LAB02TEST02V2.png', 'v2-position-1');
    Storage::disk('shopify_product_images')->put($prefix.'/LAB02TEST02V2-1.jpg', 'v2-position-2');
    Storage::disk('shopify_product_images')->put($prefix.'/LAB0TEST02V1.png', 'v1-position-1');
    Storage::disk('shopify_product_images')->put($prefix.'/LAB0TEST02V1-1.jpg', 'v1-position-2');
    $first = createImageImportProduct('LAB02TEST02V2');
    $second = createImageImportProduct('LAB0TEST02V1');

    $result = importPositionPrefix($prefix);

    expect($result['updated_count'])->toBe(4)
        ->and($result['failed_count'])->toBe(0)
        ->and(positionFilenames($first))->toBe([
            1 => 'LAB02TEST02V2.png',
            2 => 'LAB02TEST02V2-1.jpg',
        ])
        ->and(positionFilenames($second))->toBe([
            1 => 'LAB0TEST02V1.png',
            2 => 'LAB0TEST02V1-1.jpg',
        ])
        ->and($first->allImages()->count())->toBe(2)
        ->and($second->allImages()->count())->toBe(2);

    importPositionPrefix($prefix);

    expect($first->allImages()->count())->toBe(2)
        ->and($second->allImages()->count())->toBe(2)
        ->and(positionFilenames($first))->toBe([
            1 => 'LAB02TEST02V2.png',
            2 => 'LAB02TEST02V2-1.jpg',
        ]);
});

it('maps a bare sku to position 1 and each numeric suffix to the next position', function (): void {
    Storage::disk('shopify_product_images')->put('incoming/positions/SKU100.png', 'base');
    Storage::disk('shopify_product_images')->put('incoming/positions/SKU100-1.jpg', 'slot-2');
    Storage::disk('shopify_product_images')->put('incoming/positions/SKU100-2.jpg', 'slot-3');
    $product = createImageImportProduct('SKU100');

    importPositionPrefix('incoming/positions');

    expect(positionFilenames($product))->toBe([
        1 => 'SKU100.png',
        2 => 'SKU100-1.jpg',
        3 => 'SKU100-2.jpg',
    ]);
});

it('treats a filename version as metadata and does not move the gallery position', function (): void {
    Storage::disk('shopify_product_images')->put('incoming/versions/SKU200-v2.png', 'revised-front');
    Storage::disk('shopify_product_images')->put('incoming/versions/SKU200-1-v2.jpg', 'revised-second');
    Storage::disk('shopify_product_images')->put('incoming/versions/SKU200-2-v3.jpg', 'revised-third');
    $product = createImageImportProduct('SKU200');

    importPositionPrefix('incoming/versions');
    $images = $product->allImages()->orderBy('position')->get();

    expect(positionFilenames($product))->toBe([
        1 => 'SKU200-v2.png',
        2 => 'SKU200-1-v2.jpg',
        3 => 'SKU200-2-v3.jpg',
    ])->and($images->pluck('photograph_version')->all())->toBe([1, 1, 1])
        ->and($images->pluck('alt_text_version')->all())->toBe([1, 1, 1]);
});

it('replaces only the matching position and keeps the photograph version independent of the filename version', function (): void {
    $product = createImageImportProduct('SKU300');
    $front = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'position' => 1,
        'image_asset_id' => createImportTestAsset('front-bytes', 'png', 'SKU300.png')->id,
        'approved_filename' => 'SKU300.png',
        'alt_text' => 'Front sentence',
        'alt_text_status' => Image::ALT_APPROVED,
        'photograph_version' => 4,
        'alt_text_version' => 2,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_id' => 'gid://shopify/MediaImage/3001',
        'needs_shopify_image_sync' => false,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]));
    $second = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'position' => 2,
        'image_asset_id' => createImportTestAsset('second-bytes', 'jpg', 'SKU300-1.jpg')->id,
        'approved_filename' => 'SKU300-1.jpg',
        'alt_text' => 'Second sentence',
        'alt_text_status' => Image::ALT_APPROVED,
        'photograph_version' => 4,
        'alt_text_version' => 2,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_id' => 'gid://shopify/MediaImage/3002',
        'needs_shopify_image_sync' => false,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]));
    Storage::disk('shopify_product_images')->put('incoming/replace/SKU300-1-v7.jpg', 'replacement-bytes');
    $secondId = $second->id;

    importPositionPrefix('incoming/replace');
    $front->refresh();
    $second->refresh();

    expect($product->allImages()->count())->toBe(2)
        ->and($front->imageAsset?->original_filename)->toBe('SKU300.png')
        ->and($front->alt_text)->toBe('Front sentence')
        ->and($front->alt_text_status)->toBe(Image::ALT_APPROVED)
        ->and($front->photograph_version)->toBe(4)
        ->and($front->needs_shopify_image_sync)->toBeFalse()
        ->and($second->id)->toBe($secondId)
        ->and($second->imageAsset?->original_filename)->toBe('SKU300-1-v7.jpg')
        ->and($second->approved_filename)->toBe('SKU300-1-v7.jpg')
        ->and($second->photograph_version)->toBe(5)
        ->and($second->alt_text_version)->not->toBe(7)
        ->and($second->needs_shopify_image_sync)->toBeTrue()
        ->and($second->shopify_id)->toBeNull();
});

it('does not remove a valid image at another position', function (): void {
    $product = createImageImportProduct('SKU400');
    Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'position' => 4,
        'image_asset_id' => createImportTestAsset('kept-bytes', 'jpg', 'kept.jpg')->id,
        'approved_filename' => 'kept.jpg',
        'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]));
    Storage::disk('shopify_product_images')->put('incoming/keep/SKU400.png', 'new-front');
    Storage::disk('shopify_product_images')->put('incoming/keep/SKU400-1.jpg', 'new-second');

    importPositionPrefix('incoming/keep');

    expect(positionFilenames($product))->toBe([
        1 => 'SKU400.png',
        2 => 'SKU400-1.jpg',
        4 => 'kept.jpg',
    ]);
});

it('keeps the highest filename version and reports the older file', function (): void {
    Storage::disk('shopify_product_images')->put('incoming/choice/SKU500.png', 'version-one');
    Storage::disk('shopify_product_images')->put('incoming/choice/SKU500-v2.png', 'version-two');
    $product = createImageImportProduct('SKU500');

    $result = importPositionPrefix('incoming/choice');
    $older = ShopifyImageImportItem::query()->where('s3_key', 'incoming/choice/SKU500.png')->first();

    expect($result['updated_count'])->toBe(1)
        ->and($result['failed_count'])->toBe(1)
        ->and(positionFilenames($product))->toBe([1 => 'SKU500-v2.png'])
        ->and($older->status)->toBe(ShopifyImageImportItem::STATUS_FAILED)
        ->and($older->message)->toContain('filename version 2')
        ->and($older->message)->toContain('was not imported');
});

it('imports neither file when one position has two copies of the same filename version', function (): void {
    Storage::disk('shopify_product_images')->put('incoming/conflict/SKU600.png', 'png-copy');
    Storage::disk('shopify_product_images')->put('incoming/conflict/SKU600.jpg', 'jpg-copy');
    $product = createImageImportProduct('SKU600');

    $result = importPositionPrefix('incoming/conflict');

    expect($result['updated_count'])->toBe(0)
        ->and($result['failed_count'])->toBe(2)
        ->and($product->allImages()->count())->toBe(0)
        ->and(ShopifyImageImportItem::query()->where('status', ShopifyImageImportItem::STATUS_UPDATED)->count())->toBe(0)
        ->and(ShopifyImageImportItem::query()->first()->message)->toContain('None were imported');
});

function importPositionPrefix(string $prefix): array
{
    $batch = ShopifyImageImportBatch::create([
        's3_prefix' => $prefix,
        'status' => ShopifyImageImportBatch::STATUS_PENDING,
    ]);

    return app(ShopifyImageImportService::class)->runBatch($batch);
}

function positionFilenames($product): array
{
    return $product->allImages()->orderBy('position')->get()
        ->mapWithKeys(fn (Image $image): array => [(int) $image->position => $image->imageAsset?->original_filename])
        ->all();
}
