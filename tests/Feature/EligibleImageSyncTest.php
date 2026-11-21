<?php

use App\Models\Approval;
use App\Models\Image;
use App\Models\Product;
use App\Models\User;
use App\Services\ProductShopifyUpdater;
use App\Services\ShopifyImageImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/shopify-image-import.php';

/**
 * @param  array<int, array<string, mixed>>  $nodes
 */
function fakeEligibleImageShopify(Product $product, array &$nodes, array $bytesBySha = [], array $bytesByUrl = []): object
{
    $state = (object) ['creates' => 0, 'reorders' => 0];
    config()->set('services.shopify.shop', 'test-shop.myshopify.com');
    config()->set('services.shopify.admin_access_token', 'test-token');
    config()->set('services.shopify.api_version', '2026-01');

    Http::fake(function ($request) use ($product, &$nodes, &$bytesByUrl, $bytesBySha, &$state) {
        if ($request->method() === 'GET') {
            $url = strtok((string) $request->url(), '?') ?: '';

            return Http::response($bytesByUrl[$url] ?? 'unrelated-bytes');
        }
        $payload = $request->data();
        $query = (string) ($payload['query'] ?? '');
        $variables = $payload['variables'] ?? [];
        if (is_object($variables)) {
            $variables = get_object_vars($variables);
        }
        if (str_contains($query, 'productCreateMedia')) {
            $state->creates++;
            $source = (string) ($variables['media'][0]['originalSource'] ?? '');
            preg_match('/asset=([a-f0-9]{64})/', $source, $matches);
            $sha = $matches[1] ?? '';
            $bytes = $bytesBySha[$sha] ?? 'created-bytes';
            $url = 'https://cdn.shopify.com/s/files/1/created-'.$sha.'.jpg';
            $bytesByUrl[$url] = $bytes;
            $id = 'gid://shopify/MediaImage/'.(7000 + $state->creates);
            $nodes[] = [
                'id' => $id,
                'status' => 'READY',
                'fileStatus' => 'READY',
                'image' => ['url' => $url],
                'originalSource' => ['url' => $url],
            ];

            return Http::response(['data' => ['productCreateMedia' => ['media' => [['id' => $id]], 'mediaUserErrors' => []]]]);
        }
        if (str_contains($query, 'productReorderMedia')) {
            $state->reorders++;

            return Http::response(['data' => ['productReorderMedia' => ['mediaUserErrors' => []]]]);
        }
        $productPayload = [
            'id' => $product->shopify_id,
            'options' => [],
            'variants' => ['nodes' => []],
            'media' => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
            'images' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
        ];
        if (str_contains($query, 'productByHandle')) {
            return Http::response(['data' => ['productByHandle' => $productPayload]]);
        }

        return Http::response(['data' => ['product' => $productPayload]]);
    });

    return $state;
}

function approveEligibleProduct(Product $product): Product
{
    if ((int) ($product->approval_version ?? 0) < 1) {
        Product::withoutEvents(fn () => $product->forceFill(['approval_version' => 1])->save());
        $product->refresh();
    }
    foreach (range(1, 2) as $ignored) {
        Approval::create([
            'product_id' => $product->id,
            'user_id' => User::factory()->create()->id,
            'approval_version' => $product->approval_version,
        ]);
    }

    return $product->fresh();
}

it('uploads every backed-up image and does not upload the same photographs again', function () {
    Storage::fake('public');
    $product = createImageImportProduct('FIVE-UPLOAD');
    $nodes = [];
    $bytesBySha = [];
    $images = [];
    for ($position = 1; $position <= 5; $position++) {
        $asset = createImportTestAsset('upload-bytes-'.$position, 'jpg', "upload-{$position}.jpg");
        $bytesBySha[$asset->sha256] = 'upload-bytes-'.$position;
        $images[] = Image::withoutEvents(fn () => Image::create([
            'product_id' => $product->id,
            'position' => $position,
            'image_asset_id' => $asset->id,
            'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
            'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
            'needs_shopify_image_sync' => true,
            'approved_filename' => "upload-{$position}.jpg",
        ]));
    }
    $product = approveEligibleProduct($product->fresh());
    $state = fakeEligibleImageShopify($product, $nodes, $bytesBySha);

    $first = app(ProductShopifyUpdater::class)->syncProductImages(collect([$product->fresh()]));
    expect($first['synced'])->toBe(1)
        ->and($first['failed'])->toBe(0)
        ->and($state->creates)->toBe(5);
    $ids = [];
    foreach ($images as $image) {
        $fresh = $image->fresh();
        expect($fresh->shopify_id)->not->toBeNull()
            ->and($fresh->needs_shopify_image_sync)->toBeFalse();
        $ids[] = $fresh->shopify_id;
    }
    expect(array_unique($ids))->toHaveCount(5);

    $second = app(ProductShopifyUpdater::class)->syncProductImages(collect([$product->fresh()]));
    expect($second['synced'])->toBe(1)
        ->and($state->creates)->toBe(5);
});

it('imports five photographs and keeps a remote-deleted position as history', function () {
    Storage::fake('public');
    $product = createImageImportProduct('FIVE-IMPORT');
    $historical = Image::withoutEvents(fn () => Image::create([
        'product_id' => $product->id,
        'position' => 1,
        'shopify_id' => 'gid://shopify/MediaImage/1',
        'sync_state' => Image::SYNC_STATE_REMOTE_DELETED,
        'needs_shopify_image_sync' => false,
    ]));
    $service = app(ShopifyImageImportService::class);
    $method = new ReflectionMethod($service, 'prepareImageForImport');
    for ($position = 1; $position <= 5; $position++) {
        $method->invoke($service, $product->fresh(), createImportTestAsset('import-'.$position, 'jpg', "five-{$position}.jpg"), "five-{$position}.jpg", $position);
    }

    $active = $product->allImages()->active()->orderBy('position')->get();
    expect($active)->toHaveCount(5)
        ->and($active->pluck('position')->all())->toBe([1, 2, 3, 4, 5])
        ->and($historical->fresh()->sync_state)->toBe(Image::SYNC_STATE_REMOTE_DELETED)
        ->and($historical->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/1')
        ->and($product->allImages()->where('position', 1)->count())->toBe(2);
});

it('synchronizes all five eligible images and does not upload them again', function () {
    $product = approveEligibleProduct(createImageImportProduct('FIVE-SYNC'));
    $nodes = [];
    $images = [];
    for ($position = 1; $position <= 5; $position++) {
        $id = 'gid://shopify/MediaImage/5'.$position;
        $images[] = Image::withoutEvents(fn () => Image::create([
            'product_id' => $product->id,
            'position' => $position,
            'shopify_id' => $id,
            'src' => "https://cdn.shopify.com/s/files/1/five-{$position}.jpg",
            'sync_state' => Image::SYNC_STATE_SYNCED,
            'needs_shopify_image_sync' => false,
            'backup_status' => Image::BACKUP_STATUS_PENDING,
        ]));
        $nodes[] = [
            'id' => $id,
            'status' => 'READY',
            'fileStatus' => 'READY',
            'image' => ['url' => "https://cdn.shopify.com/s/files/1/five-{$position}.jpg"],
        ];
    }
    $nodes = array_reverse($nodes);
    $state = fakeEligibleImageShopify($product, $nodes);

    $first = app(ProductShopifyUpdater::class)->syncProductImages(collect([$product->fresh()]));
    expect($first['synced'])->toBe(1)
        ->and($first['failed'])->toBe(0)
        ->and($state->creates)->toBe(0);
    foreach ($images as $position => $image) {
        expect($image->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/5'.($position + 1));
    }

    $second = app(ProductShopifyUpdater::class)->syncProductImages(collect([$product->fresh()]));
    expect($second['synced'])->toBe(1)
        ->and($state->creates)->toBe(0);

    Image::withoutEvents(function () use ($images): void {
        $images[0]->forceFill(['position' => 5])->save();
        $images[4]->forceFill(['position' => 1])->save();
    });
    $reordered = app(ProductShopifyUpdater::class)->syncProductImages(collect([$product->fresh()]));
    expect($reordered['synced'])->toBe(1)
        ->and($reordered['failed'])->toBe(0)
        ->and($state->creates)->toBe(0)
        ->and($state->reorders)->toBeGreaterThan(0)
        ->and($images[0]->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/51');
});

it('does not upload a photograph replaced after approval and preserves the published file', function () {
    Storage::fake('public');
    $product = createImageImportProduct('FIVE-REPLACE');
    $nodes = [];
    $bytesBySha = [];
    $kept = [];
    for ($position = 1; $position <= 5; $position++) {
        $id = 'gid://shopify/MediaImage/4'.$position;
        $asset = null;
        if ($position === 3) {
            $asset = createImportTestAsset('original-three', 'jpg', 'three.jpg');
            $bytesBySha[$asset->sha256] = 'original-three';
        }
        $image = Image::withoutEvents(fn () => Image::create([
            'product_id' => $product->id,
            'position' => $position,
            'shopify_id' => $id,
            'src' => "https://cdn.shopify.com/s/files/1/keep-{$position}.jpg",
            'image_asset_id' => $asset?->id,
            'last_shopify_synced_image_asset_id' => $asset?->id,
            'backup_status' => $asset ? Image::BACKUP_STATUS_BACKED_UP : Image::BACKUP_STATUS_PENDING,
            'sync_state' => Image::SYNC_STATE_SYNCED,
            'needs_shopify_image_sync' => false,
        ]));
        $kept[$position] = $image;
        $nodes[] = [
            'id' => $id,
            'status' => 'READY',
            'fileStatus' => 'READY',
            'image' => ['url' => "https://cdn.shopify.com/s/files/1/keep-{$position}.jpg"],
            'originalSource' => ['url' => "https://cdn.shopify.com/s/files/1/keep-{$position}.jpg"],
        ];
    }
    $product = approveEligibleProduct($product->fresh());
    $replacement = createImportTestAsset('replacement-three', 'jpg', 'three-new.jpg');
    $bytesBySha[$replacement->sha256] = 'replacement-three';
    $state = fakeEligibleImageShopify($product, $nodes, $bytesBySha);
    $kept[3]->forceFill([
        'image_asset_id' => $replacement->id,
        'needs_shopify_image_sync' => true,
    ])->save();

    $result = app(ProductShopifyUpdater::class)->syncProductImages(collect([$product->fresh()]));

    expect($result['synced'])->toBe(0)
        ->and($result['failed'])->toBe(1)
        ->and($state->creates)->toBe(0)
        ->and($kept[3]->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/43')
        ->and($kept[3]->fresh()->needs_shopify_image_sync)->toBeTrue()
        ->and($kept[1]->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/41')
        ->and($kept[2]->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/42')
        ->and($kept[4]->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/44')
        ->and($kept[5]->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/45')
        ->and(collect($result['warnings'])->pluck('warning')->implode(' '))->toContain('not an authorized photograph');

    $missing = approveEligibleProduct(createImageImportProduct('FIVE-MISSING'));
    $onlyFirst = [[
        'id' => 'gid://shopify/MediaImage/31',
        'status' => 'READY',
        'fileStatus' => 'READY',
        'image' => ['url' => 'https://cdn.shopify.com/s/files/1/only-first.jpg'],
    ]];
    Image::withoutEvents(fn () => Image::create([
        'product_id' => $missing->id, 'position' => 1, 'shopify_id' => 'gid://shopify/MediaImage/31',
        'src' => 'https://cdn.shopify.com/s/files/1/only-first.jpg', 'sync_state' => Image::SYNC_STATE_SYNCED,
        'needs_shopify_image_sync' => false,
    ]));
    for ($position = 2; $position <= 5; $position++) {
        Image::withoutEvents(fn () => Image::create([
            'product_id' => $missing->id, 'position' => $position, 'shopify_id' => 'gid://shopify/MediaImage/3'.$position,
            'sync_state' => Image::SYNC_STATE_LOCAL_UPDATED, 'needs_shopify_image_sync' => true,
            'backup_status' => Image::BACKUP_STATUS_PENDING,
        ]));
    }
    $missingState = fakeEligibleImageShopify($missing, $onlyFirst);
    $missingResult = app(ProductShopifyUpdater::class)->syncProductImages(collect([$missing->fresh()]));
    expect($missingResult['synced'])->toBe(0)
        ->and($missingResult['failed'])->toBe(1)
        ->and($missingState->creates)->toBe(0)
        ->and(collect($missingResult['warnings'])->pluck('warning')->implode(' '))->toContain('media ID is not available');
});

it('reuses a lost upload response and does not repeat an uncertain create', function () {
    Storage::fake('public');
    $product = createImageImportProduct('LOST-UPLOAD');
    $asset = createImportTestAsset('lost-bytes', 'jpg', 'lost.jpg');
    $image = Image::withoutEvents(fn () => Image::create([
        'product_id' => $product->id,
        'position' => 1,
        'image_asset_id' => $asset->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
        'needs_shopify_image_sync' => true,
        'shopify_media_create_uncertain' => true,
    ]));
    $mediaId = 'gid://shopify/MediaImage/880';
    $url = 'https://cdn.shopify.com/s/files/1/lost.jpg';
    DB::table('image_upload_operations')->insert([
        'id' => (string) Str::uuid(),
        'image_id' => $image->id,
        'active_image_id' => $image->id,
        'asset_key' => hash('sha256', $product->shopify_id.':'.$asset->sha256),
        'image_asset_id' => $asset->id,
        'asset_sha256' => $asset->sha256,
        'product_gid' => $product->shopify_id,
        'source_url' => $url,
        'before_media_ids' => json_encode([]),
        'status' => 'processing',
        'media_id' => $mediaId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $nodes = [[
        'id' => $mediaId,
        'status' => 'READY',
        'fileStatus' => 'READY',
        'image' => ['url' => $url],
        'originalSource' => ['url' => $url],
    ]];
    $state = fakeEligibleImageShopify($product, $nodes, [$asset->sha256 => 'lost-bytes'], [$url => 'lost-bytes']);
    $product = approveEligibleProduct($product->fresh());

    $recovered = app(ProductShopifyUpdater::class)->syncProductImages(collect([$product->fresh()]));
    expect($recovered['synced'])->toBe(1)
        ->and($state->creates)->toBe(0)
        ->and($image->fresh()->shopify_id)->toBe($mediaId)
        ->and($image->fresh()->shopify_media_create_uncertain)->toBeFalse();

    $uncertain = Image::withoutEvents(fn () => Image::create([
        'product_id' => $product->id,
        'position' => 2,
        'image_asset_id' => createImportTestAsset('uncertain-bytes', 'jpg', 'uncertain.jpg')->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
        'needs_shopify_image_sync' => true,
        'shopify_media_create_uncertain' => true,
    ]));
    $again = app(ProductShopifyUpdater::class)->syncProductImages(collect([$product->fresh()]));
    expect($again['synced'])->toBe(0)
        ->and($again['failed'])->toBe(1)
        ->and($state->creates)->toBe(0)
        ->and($uncertain->fresh()->shopify_id)->toBeNull()
        ->and(collect($again['warnings'])->pluck('warning')->implode(' '))->toContain('uncertain');
});

it('keeps duplicate active positions from creating another Shopify file', function () {
    $product = approveEligibleProduct(createImageImportProduct('DUP-POSITION'));
    $nodes = [];
    foreach ([1, 1] as $index => $position) {
        $id = 'gid://shopify/MediaImage/6'.($index + 1);
        Image::withoutEvents(fn () => Image::create([
            'product_id' => $product->id,
            'position' => $position,
            'shopify_id' => $id,
            'src' => "https://cdn.shopify.com/s/files/1/dup-{$index}.jpg",
            'sync_state' => Image::SYNC_STATE_SYNCED,
            'needs_shopify_image_sync' => false,
        ]));
        $nodes[] = [
            'id' => $id,
            'status' => 'READY',
            'fileStatus' => 'READY',
            'image' => ['url' => "https://cdn.shopify.com/s/files/1/dup-{$index}.jpg"],
        ];
    }
    Image::withoutEvents(fn () => Image::create([
        'product_id' => $product->id,
        'position' => 1,
        'shopify_id' => 'gid://shopify/MediaImage/69',
        'sync_state' => Image::SYNC_STATE_REMOTE_DELETED,
        'needs_shopify_image_sync' => false,
    ]));
    $state = fakeEligibleImageShopify($product, $nodes);
    $result = app(ProductShopifyUpdater::class)->syncProductImages(collect([$product->fresh()]));

    expect($result['synced'])->toBe(0)
        ->and($result['failed'])->toBe(1)
        ->and($state->creates)->toBe(0)
        ->and(collect($result['warnings'])->pluck('warning')->implode(' '))->toContain('shares position 1')
        ->and(Image::query()->where('product_id', $product->id)->where('sync_state', Image::SYNC_STATE_REMOTE_DELETED)->count())->toBe(1)
        ->and(Image::query()->where('product_id', $product->id)->count())->toBe(3);
});
