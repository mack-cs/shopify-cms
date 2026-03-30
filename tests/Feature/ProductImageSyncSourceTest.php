<?php

use App\Models\Image;
use App\Models\Import;
use App\Models\Product;
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
