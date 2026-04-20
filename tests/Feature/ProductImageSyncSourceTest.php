<?php

use App\Models\Image;
use App\Models\Import;
use App\Models\Product;
use App\Models\Variant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

it('builds a sync source url for local uploaded images without requiring a backup', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('product-images/test/local-upload.jpg', 'local-image-body');

    $image = createTestImage([
        'image_path' => 'product-images/test/local-upload.jpg',
        'src' => 'http://shopify-editor.test/storage/product-images/test/local-upload.jpg',
        'approved_filename' => 'approved-local-name.jpg',
        'backup_status' => Image::BACKUP_STATUS_PENDING,
        'image_asset_id' => null,
    ]);

    expect($image->backupReady())->toBeFalse();
    expect($image->localUploadReady())->toBeTrue();
    expect($image->desiredSyncSourceUrl())->toBe(route('product-image-backups.show', [
        'image' => $image,
        'filename' => 'approved-local-name.jpg',
    ]));
});

it('still requires a backup-backed source for shopify-origin images without a local upload', function (): void {
    $image = createTestImage([
        'shopify_id' => 'gid://shopify/MediaImage/1299',
        'src' => 'https://cdn.shopify.com/s/files/1/test-image.jpg',
        'image_path' => null,
        'approved_filename' => 'shopify-renamed.jpg',
        'backup_status' => Image::BACKUP_STATUS_PENDING,
        'image_asset_id' => null,
    ]);

    expect($image->localUploadReady())->toBeFalse();
    expect($image->desiredSyncSourceUrl())->toBeNull();
});

it('streams a local uploaded image through the managed source route with the approved filename', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('product-images/test/streamable-image.jpg', 'streamed-local-image');

    $image = createTestImage([
        'image_path' => 'product-images/test/streamable-image.jpg',
        'src' => 'http://shopify-editor.test/storage/product-images/test/streamable-image.jpg',
        'approved_filename' => 'approved-stream-name.jpg',
        'backup_status' => Image::BACKUP_STATUS_PENDING,
        'image_asset_id' => null,
    ]);

    $response = $this->get(route('product-image-backups.show', [
        'image' => $image,
        'filename' => $image->preferredFilename(),
    ]));

    $response->assertOk();
    $response->assertHeader('content-disposition', 'inline; filename="approved-stream-name.jpg"');
    expect($response->streamedContent())->toBe('streamed-local-image');
});

it('auto assigns the product-title filename pattern to newly uploaded images after image rename approval has run', function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('product-images/test/fresh-upload.png', 'fresh-local-image');

    $user = User::factory()->create();

    $import = Import::create([
        'filename' => 'test-import.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
    ]);

    $product = Product::create([
        'import_id' => $import->id,
        'handle' => 'renamed-product',
        'title' => 'Renamed Product',
        'product_category' => 'Apparel & Accessories > Jewelry > Bracelets',
        'type' => 'Bracelets',
        'first_image_auto_rename_completed_at' => now(),
        'first_image_auto_rename_approval_version' => 1,
    ]);

    Image::create([
        'product_id' => $product->id,
        'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
        'local_dirty' => true,
        'src' => 'http://shopify-editor.test/storage/product-images/test/existing-upload.png',
        'image_path' => 'product-images/test/existing-upload.png',
        'backup_status' => Image::BACKUP_STATUS_PENDING,
        'position' => 1,
        'approved_filename' => 'renamed-product-01.png',
        'filename_mode' => Image::FILENAME_MODE_AUTO,
    ]);

    $image = Image::create([
        'product_id' => $product->id,
        'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
        'local_dirty' => true,
        'src' => 'http://shopify-editor.test/storage/product-images/test/fresh-upload.png',
        'image_path' => 'product-images/test/fresh-upload.png',
        'backup_status' => Image::BACKUP_STATUS_PENDING,
        'position' => 2,
    ]);

    expect($image->fresh()->approved_filename)->toBe('renamed-product-02.png');
    expect($image->fresh()->filename_mode)->toBe(Image::FILENAME_MODE_AUTO);
});

it('clears a variant image link when the shared product image is deleted', function (): void {
    $image = createTestImage([
        'src' => 'http://shopify-editor.test/storage/product-images/test/linked-image.jpg',
        'position' => 1,
    ]);

    $variant = Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $image->product_id,
        'image_id' => $image->id,
        'sku' => 'LINKED-SKU',
        'sync_state' => Variant::SYNC_STATE_LOCAL_NEW,
        'local_dirty' => true,
    ]));

    Image::withoutEvents(fn () => $image->delete());

    expect($variant->fresh()->image_id)->toBeNull();
});

function createTestImage(array $overrides = []): Image
{
    $user = User::factory()->create();

    $import = Import::create([
        'filename' => 'test-import.csv',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
    ]);

    $product = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id,
        'handle' => 'test-product',
        'title' => 'Test Product',
        'product_category' => 'Apparel & Accessories > Jewelry > Bracelets',
        'type' => 'Bracelets',
    ]));

    return Image::withoutEvents(fn (): Image => Image::create(array_merge([
        'product_id' => $product->id,
        'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
        'local_dirty' => true,
        'src' => null,
        'image_path' => null,
        'backup_status' => Image::BACKUP_STATUS_PENDING,
        'position' => 1,
    ], $overrides)));
}
