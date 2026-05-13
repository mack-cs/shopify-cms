<?php

use App\Jobs\ProductImageBackupImagesJob;
use App\Models\Image;
use App\Models\ImageAsset;
use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyRow;
use App\Models\User;
use App\Services\HeaderStore;
use App\Services\Normalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('marks previously synced shopify images as remote deleted instead of deleting them', function (): void {
    $import = createShopifyImport();
    $product = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id,
        'handle' => 'remote-deleted-product',
        'title' => 'Remote Deleted Product',
        'product_category' => 'Apparel & Accessories > Jewelry > Bracelets',
        'type' => 'Bracelets',
    ]));

    $image = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/MediaImage/2001',
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'local_dirty' => false,
        'src' => 'https://cdn.shopify.com/s/files/removed-image.jpg',
        'position' => 2,
        'backup_status' => Image::BACKUP_STATUS_PENDING,
    ]));

    createPrimaryShopifyRow($import, 'remote-deleted-product', 1);

    app(Normalizer::class)->buildNormalizedTables($import);

    expect($image->fresh()->sync_state)->toBe(Image::SYNC_STATE_REMOTE_DELETED);
    expect($image->fresh()->src)->toBe('https://cdn.shopify.com/s/files/removed-image.jpg');
});

it('queues targeted backup when a new shopify image is first seen', function (): void {
    Queue::fake();

    $import = createShopifyImport();
    createPrimaryShopifyRow($import, 'new-shopify-image', 1, [
        HeaderStore::TITLE => 'New Shopify Image',
        HeaderStore::IMAGE_SRC => 'https://cdn.shopify.com/s/files/new-image.jpg',
        HeaderStore::IMAGE_POSITION => '1',
        HeaderStore::INTERNAL_IMAGE_SHOPIFY_ID => 'gid://shopify/MediaImage/3001',
    ]);

    app(Normalizer::class)->buildNormalizedTables($import);

    $image = Image::query()->where('shopify_id', 'gid://shopify/MediaImage/3001')->firstOrFail();

    expect($image->backup_status)->toBe(Image::BACKUP_STATUS_PENDING);
    expect($image->image_asset_id)->toBeNull();

    Queue::assertPushed(ProductImageBackupImagesJob::class, function (ProductImageBackupImagesJob $job) use ($image): bool {
        return in_array($image->id, $job->imageIds, true)
            && $job->reason === 'Shopify image change backup';
    });
});

it('queues targeted backup and clears old asset when a shopify image source changes', function (): void {
    Queue::fake();

    $import = createShopifyImport();
    $product = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id,
        'handle' => 'shopify-image-changed',
        'title' => 'Shopify Image Changed',
        'product_category' => 'Apparel & Accessories > Jewelry > Bracelets',
        'type' => 'Bracelets',
    ]));

    $asset = ImageAsset::create([
        'sha256' => str_repeat('a', 64),
        'storage_disk' => 'public',
        'storage_path' => 'product-image-assets/aa/aa/' . str_repeat('a', 64) . '.jpg',
        'original_filename' => 'old.jpg',
        'source_url' => 'https://cdn.shopify.com/s/files/old-image.jpg',
        'mime_type' => 'image/jpeg',
        'extension' => 'jpg',
        'file_size' => 123,
        'downloaded_at' => now(),
        'last_verified_at' => now(),
        'status' => ImageAsset::STATUS_AVAILABLE,
    ]);

    $image = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/MediaImage/4001',
        'image_asset_id' => $asset->id,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'local_dirty' => false,
        'src' => 'https://cdn.shopify.com/s/files/old-image.jpg',
        'position' => 1,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]));

    createPrimaryShopifyRow($import, 'shopify-image-changed', 1, [
        HeaderStore::TITLE => 'Shopify Image Changed',
        HeaderStore::IMAGE_SRC => 'https://cdn.shopify.com/s/files/newer-image.jpg',
        HeaderStore::IMAGE_POSITION => '1',
        HeaderStore::INTERNAL_IMAGE_SHOPIFY_ID => 'gid://shopify/MediaImage/4001',
    ]);

    app(Normalizer::class)->buildNormalizedTables($import);

    $image->refresh();

    expect($image->src)->toBe('https://cdn.shopify.com/s/files/newer-image.jpg');
    expect($image->image_asset_id)->toBeNull();
    expect($image->backup_status)->toBe(Image::BACKUP_STATUS_PENDING);

    Queue::assertPushed(ProductImageBackupImagesJob::class, function (ProductImageBackupImagesJob $job) use ($image): bool {
        return in_array($image->id, $job->imageIds, true);
    });
});

function createShopifyImport(): Import
{
    $user = User::factory()->create();

    return Import::create([
        'filename' => 'shopify-api',
        'mode' => 'overwrite',
        'status' => 'processing',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);
}

function createPrimaryShopifyRow(Import $import, string $handle, int $rowIndex, array $data = []): ShopifyRow
{
    return ShopifyRow::create([
        'import_id' => $import->id,
        'row_index' => $rowIndex,
        'handle' => $handle,
        'row_type' => 'product_primary',
        'variant_key' => null,
        'image_key' => null,
        'data' => array_merge([
            HeaderStore::HANDLE => $handle,
            HeaderStore::TITLE => ucfirst(str_replace('-', ' ', $handle)),
            HeaderStore::BODY_HTML => '<p>Body</p>',
            HeaderStore::VENDOR => 'Vendor',
            HeaderStore::TAGS => 'tag-one',
            HeaderStore::TYPE => 'Bracelets',
            HeaderStore::STATUS => 'active',
            HeaderStore::PUBLISHED => 'true',
            HeaderStore::PRODUCT_CATEGORY => 'Apparel & Accessories > Jewelry > Bracelets',
        ], $data),
    ]);
}
