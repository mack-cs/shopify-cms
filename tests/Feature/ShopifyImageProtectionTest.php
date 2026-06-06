<?php

use App\Jobs\ProductImageBackupImagesJob;
use App\Models\Image;
use App\Models\ImageAsset;
use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyRow;
use App\Models\User;
use App\Models\Variant;
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

it('does not mark locally dirty variants as conflicted when imported money decimals are equivalent', function (): void {
    $import = createShopifyImport();
    $product = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id,
        'handle' => 'variant-decimal-formatting',
        'title' => 'Variant Decimal Formatting',
        'product_category' => 'Apparel & Accessories > Jewelry > Bracelets',
        'type' => 'Bracelets',
    ]));

    $variant = Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/5001',
        'sync_state' => Variant::SYNC_STATE_LOCAL_UPDATED,
        'local_dirty' => true,
        'sku' => 'SKU-5001',
        'barcode' => 'SKU-5001',
        'price' => '19.90',
        'compare_at_price' => '29.00',
        'weight' => '1.500',
        'weight_unit' => 'g',
        'inventory_tracked' => true,
        'inventory_qty' => 5,
        'position' => 1,
    ]));

    createPrimaryShopifyRow($import, 'variant-decimal-formatting', 1);
    createVariantShopifyRow($import, 'variant-decimal-formatting', 2, [
        HeaderStore::INTERNAL_VARIANT_SHOPIFY_ID => 'gid://shopify/ProductVariant/5001',
        HeaderStore::VARIANT_SKU => 'SKU-5001',
        HeaderStore::VARIANT_BARCODE => 'SKU-5001',
        HeaderStore::VARIANT_PRICE => '19.9',
        HeaderStore::VARIANT_COMPARE_AT => '29',
        HeaderStore::VARIANT_GRAMS => '1.5',
        HeaderStore::VARIANT_WEIGHT_UNIT => 'g',
        HeaderStore::INTERNAL_VARIANT_INVENTORY_TRACKED => 'true',
        HeaderStore::VARIANT_INVENTORY_QTY => '5',
    ]);

    app(Normalizer::class)->buildNormalizedTables($import);

    $variant->refresh();

    expect($variant->sync_state)->toBe(Variant::SYNC_STATE_LOCAL_UPDATED)
        ->and($variant->local_dirty)->toBeTrue();
});

it('still marks locally dirty variants as conflicted when imported money values differ', function (): void {
    $import = createShopifyImport();
    $product = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id,
        'handle' => 'variant-real-price-conflict',
        'title' => 'Variant Real Price Conflict',
        'product_category' => 'Apparel & Accessories > Jewelry > Bracelets',
        'type' => 'Bracelets',
    ]));

    $variant = Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductVariant/5002',
        'sync_state' => Variant::SYNC_STATE_LOCAL_UPDATED,
        'local_dirty' => true,
        'sku' => 'SKU-5002',
        'barcode' => 'SKU-5002',
        'price' => '18.90',
        'compare_at_price' => '29.00',
        'weight' => '1.500',
        'weight_unit' => 'g',
        'inventory_tracked' => true,
        'inventory_qty' => 5,
        'position' => 1,
    ]));

    createPrimaryShopifyRow($import, 'variant-real-price-conflict', 1);
    createVariantShopifyRow($import, 'variant-real-price-conflict', 2, [
        HeaderStore::INTERNAL_VARIANT_SHOPIFY_ID => 'gid://shopify/ProductVariant/5002',
        HeaderStore::VARIANT_SKU => 'SKU-5002',
        HeaderStore::VARIANT_BARCODE => 'SKU-5002',
        HeaderStore::VARIANT_PRICE => '19.90',
        HeaderStore::VARIANT_COMPARE_AT => '29.00',
        HeaderStore::VARIANT_GRAMS => '1.500',
        HeaderStore::VARIANT_WEIGHT_UNIT => 'g',
        HeaderStore::INTERNAL_VARIANT_INVENTORY_TRACKED => 'true',
        HeaderStore::VARIANT_INVENTORY_QTY => '5',
    ]);

    app(Normalizer::class)->buildNormalizedTables($import);

    expect($variant->fresh()->sync_state)->toBe(Variant::SYNC_STATE_CONFLICT);
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

function createVariantShopifyRow(Import $import, string $handle, int $rowIndex, array $data = []): ShopifyRow
{
    return ShopifyRow::create([
        'import_id' => $import->id,
        'row_index' => $rowIndex,
        'handle' => $handle,
        'row_type' => 'variant',
        'variant_key' => (string) ($data[HeaderStore::VARIANT_SKU] ?? 'variant-key'),
        'image_key' => null,
        'data' => $data,
    ]);
}
