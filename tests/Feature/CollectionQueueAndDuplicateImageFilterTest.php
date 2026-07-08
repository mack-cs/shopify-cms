<?php

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ShopifyCollectionResource;
use App\Models\Import;
use App\Models\Image;
use App\Models\Product;
use App\Models\ShopifyCollection;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('dismisses a collection from the current approval queue version', function () {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'shopify-collections',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);

    $collection = ShopifyCollection::create([
        'import_id' => $import->id,
        'shopify_id' => 'gid://shopify/Collection/21',
        'handle' => 'bright-brace',
        'title' => 'Bright Brace',
        'draft_title' => 'Bright Brace Updated',
        'approval_version' => 3,
    ]);

    $visibleBefore = ShopifyCollectionResource::applyApprovalQueueVisibilityFilter(
        ShopifyCollection::query()->whereKey($collection->id)
    )->exists();

    ShopifyCollectionResource::dismissFromApprovalQueue($collection);

    $collection->refresh();

    $visibleAfter = ShopifyCollectionResource::applyApprovalQueueVisibilityFilter(
        ShopifyCollection::query()->whereKey($collection->id)
    )->exists();

    expect($visibleBefore)->toBeTrue()
        ->and($collection->approval_queue_dismissed_version)->toBe(3)
        ->and($visibleAfter)->toBeFalse();
});

it('filters products with duplicate active image positions', function () {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'products',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);

    $duplicateProduct = Product::create([
        'import_id' => $import->id,
        'handle' => 'dup-product',
        'title' => 'Duplicate Product',
    ]);

    $cleanProduct = Product::create([
        'import_id' => $import->id,
        'handle' => 'clean-product',
        'title' => 'Clean Product',
    ]);

    Image::create([
        'product_id' => $duplicateProduct->id,
        'src' => 'https://example.com/1.jpg',
        'position' => 2,
        'sync_state' => Image::SYNC_STATE_SYNCED,
    ]);

    Image::create([
        'product_id' => $duplicateProduct->id,
        'src' => 'https://example.com/2.jpg',
        'position' => 2,
        'sync_state' => Image::SYNC_STATE_LOCAL_UPDATED,
    ]);

    Image::create([
        'product_id' => $cleanProduct->id,
        'src' => 'https://example.com/3.jpg',
        'position' => 1,
        'sync_state' => Image::SYNC_STATE_SYNCED,
    ]);

    Image::create([
        'product_id' => $cleanProduct->id,
        'src' => 'https://example.com/4.jpg',
        'position' => 2,
        'sync_state' => Image::SYNC_STATE_SYNCED,
    ]);

    $duplicateIds = ProductResource::applyDuplicateImagePositionsFilter(Product::query())
        ->pluck('id')
        ->all();

    $cleanIds = ProductResource::applyNoDuplicateImagePositionsFilter(Product::query())
        ->pluck('id')
        ->all();

    expect($duplicateIds)->toContain($duplicateProduct->id)
        ->not->toContain($cleanProduct->id);

    expect($cleanIds)->toContain($cleanProduct->id)
        ->not->toContain($duplicateProduct->id);
});

it('permanently deletes extra duplicate image rows for a product', function (): void {
    $user = User::factory()->create();
    $import = Import::create([
        'filename' => 'products',
        'mode' => 'overwrite',
        'status' => 'ready',
        'created_by' => $user->id,
        'is_current' => true,
        'is_valid' => true,
    ]);

    $product = Product::withoutEvents(fn (): Product => Product::create([
        'import_id' => $import->id,
        'handle' => 'forever-duplicate-product',
        'title' => 'Forever Duplicate Product',
    ]));

    $primary = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/MediaImage/8001',
        'src' => 'https://example.com/keep.jpg',
        'position' => 1,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
    ]));

    $duplicate = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/MediaImage/8002',
        'src' => 'https://example.com/delete.jpg',
        'position' => 1,
        'sync_state' => Image::SYNC_STATE_LOCAL_UPDATED,
        'backup_status' => Image::BACKUP_STATUS_PENDING,
    ]));

    $variant = Variant::withoutEvents(fn (): Variant => Variant::create([
        'product_id' => $product->id,
        'image_id' => $duplicate->id,
        'sku' => 'FOREVER-DUP',
    ]));

    $deleted = invokePrivateProductResourceMethod('deleteDuplicateImagesForProductForever', [$product]);

    expect($deleted)->toBe(1)
        ->and(Image::query()->whereKey($primary->id)->exists())->toBeTrue()
        ->and(Image::query()->whereKey($duplicate->id)->exists())->toBeFalse()
        ->and($variant->fresh()->image_id)->toBeNull()
        ->and(ProductResource::applyDuplicateImagePositionsFilter(Product::query())->pluck('id')->all())
        ->not->toContain($product->id);
});

function invokePrivateProductResourceMethod(string $method, array $arguments): mixed
{
    $reflection = new ReflectionClass(ProductResource::class);
    $method = $reflection->getMethod($method);
    $method->setAccessible(true);

    return $method->invokeArgs(null, $arguments);
}
