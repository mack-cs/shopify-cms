<?php

use App\Filament\Resources\ProductResource;
use App\Models\Image;
use App\Models\ImageAsset;
use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyImageImportBatch;
use App\Models\ShopifyImageImportItem;
use App\Models\User;
use App\Models\Variant;
use App\Services\ShopifyImageImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('normalizes date and incoming folder inputs to the incoming S3 prefix', function (): void {
    $service = app(ShopifyImageImportService::class);

    expect($service->normalizePrefix('2026-07-06'))->toBe('incoming/2026-07-06')
        ->and($service->normalizePrefix('/incoming/2026-07-06/'))->toBe('incoming/2026-07-06');
});

it('imports only direct image files in the normalized S3 folder and records the batch', function (): void {
    Storage::fake('shopify_product_images');
    Storage::fake('public');
    config()->set('shopify_image_import.disk', 'shopify_product_images');

    $bytes = 'fake-png-body';
    Storage::disk('shopify_product_images')->put('incoming/2026-07-06/LRB0001.png', $bytes);
    Storage::disk('shopify_product_images')->put('incoming/2026-07-06/readme.txt', 'ignore me');
    Storage::disk('shopify_product_images')->put('incoming/2026-07-06/nested/LRB0002.png', 'ignore nested');

    $asset = createImportTestAsset($bytes, 'png', 'LRB0001.png');
    $product = createImageImportProduct('LRB0001');

    $image = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/MediaImage/1001',
        'image_asset_id' => $asset->id,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'local_dirty' => false,
        'src' => 'https://cdn.shopify.com/s/files/old.png',
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
        'position' => 1,
        'approved_filename' => 'LRB0001.png',
        'filename_mode' => Image::FILENAME_MODE_MANUAL,
        'last_shopify_synced_image_asset_id' => $asset->id,
        'needs_shopify_image_sync' => false,
    ]));

    $batch = ShopifyImageImportBatch::create([
        's3_prefix' => '2026-07-06',
        'status' => ShopifyImageImportBatch::STATUS_PENDING,
    ]);

    $result = app(ShopifyImageImportService::class)->runBatch($batch);

    $batch->refresh();
    $product->refresh();
    $image->refresh();

    expect($result['total_files'])->toBe(1)
        ->and($result['matched_count'])->toBe(1)
        ->and($result['updated_count'])->toBe(1)
        ->and($result['failed_count'])->toBe(0)
        ->and($batch->s3_prefix)->toBe('incoming/2026-07-06')
        ->and($product->image_import_batch_id)->toBe($batch->id)
        ->and($product->image_import_status)->toBe('updated')
        ->and($image->src)->toBe(route('product-image-backups.show', [
            'image' => $image,
            'filename' => 'LRB0001.png',
        ]));

    $item = ShopifyImageImportItem::query()->firstOrFail();

    expect($item->sku)->toBe('LRB0001')
        ->and($item->s3_key)->toBe('incoming/2026-07-06/LRB0001.png')
        ->and($item->status)->toBe(ShopifyImageImportItem::STATUS_UPDATED);
});

it('filters products updated in the latest completed image import batch', function (): void {
    $older = ShopifyImageImportBatch::create([
        's3_prefix' => 'incoming/2026-07-05',
        'status' => ShopifyImageImportBatch::STATUS_COMPLETED,
        'completed_at' => now()->subDay(),
    ]);

    $latest = ShopifyImageImportBatch::create([
        's3_prefix' => 'incoming/2026-07-06',
        'status' => ShopifyImageImportBatch::STATUS_COMPLETED,
        'completed_at' => now(),
    ]);

    $olderProduct = createImageImportProduct('OLD-SKU', [
        'handle' => 'old-import-product',
        'image_import_batch_id' => $older->id,
        'image_import_status' => 'updated',
    ]);

    $latestProduct = createImageImportProduct('NEW-SKU', [
        'handle' => 'latest-import-product',
        'image_import_batch_id' => $latest->id,
        'image_import_status' => 'updated',
    ]);

    $failedLatestProduct = createImageImportProduct('FAIL-SKU', [
        'handle' => 'latest-failed-product',
        'image_import_batch_id' => $latest->id,
        'image_import_status' => 'failed',
    ]);

    $ids = ProductResource::applyLatestImageImportFilter(Product::query())
        ->pluck('id')
        ->all();

    expect($ids)->toContain($latestProduct->id)
        ->not->toContain($olderProduct->id)
        ->not->toContain($failedLatestProduct->id);
});

function createImageImportProduct(string $sku, array $productOverrides = []): Product
{
    $user = User::factory()->create();

    $import = Import::create([
        'filename' => 'image-import-test.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
    ]);

    $product = Product::withoutEvents(fn (): Product => Product::create(array_merge([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Product/' . abs(crc32($sku)),
        'handle' => strtolower(str_replace('_', '-', $sku)),
        'title' => $sku,
        'type' => 'Bracelets',
        'status' => 'active',
    ], $productOverrides)));

    Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $product->id,
        'sku' => $sku,
        'sync_state' => Variant::SYNC_STATE_SYNCED,
        'local_dirty' => false,
    ]));

    return $product;
}

function createImportTestAsset(string $bytes, string $extension, string $filename): ImageAsset
{
    $sha256 = hash('sha256', $bytes);
    $path = 'product-image-assets/' . substr($sha256, 0, 2) . '/' . substr($sha256, 2, 2) . "/{$sha256}.{$extension}";

    Storage::disk('public')->put($path, $bytes);

    return ImageAsset::create([
        'sha256' => $sha256,
        'storage_disk' => 'public',
        'storage_path' => $path,
        'original_filename' => $filename,
        'source_url' => 's3://leigh-product-images/incoming/2026-07-06/' . $filename,
        'mime_type' => 'image/png',
        'extension' => $extension,
        'file_size' => strlen($bytes),
        'downloaded_at' => now(),
        'last_verified_at' => now(),
        'status' => ImageAsset::STATUS_AVAILABLE,
    ]);
}
