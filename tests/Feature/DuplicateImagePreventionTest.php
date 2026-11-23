<?php

use App\Console\Commands\ReportDuplicateImagePositions;
use App\Contracts\ShopifyGraphqlGateway;
use App\Models\Approval;
use App\Models\Image;
use App\Models\User;
use App\Models\ImageAsset;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyImageImportBatch;
use App\Models\ShopifyRow;
use App\Services\DuplicateImagePositionReport;
use App\Services\HeaderStore;
use App\Services\Normalizer;
use App\Services\ProductImageFilenameService;
use App\Services\ProductShopifyUpdater;
use App\Services\ShopifyImageImportService;
use App\Services\ShopifyMediaImageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\artisan;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Support/shopify-image-import.php';

it('reconnects one catalogue image when only the Shopify cache buster changes', function (): void {
    $product = createImageImportProduct('BLUSH-HOUR');
    $image = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'src' => 'https://cdn.shopify.com/s/files/1/blush.jpg?v=111',
        'position' => 2,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_id' => 'gid://shopify/ProductImage/55',
        'alt_text' => 'Approved blush',
        'alt_text_status' => Image::ALT_APPROVED,
        'image_asset_id' => catalogueAsset('same-photo')->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
    ]));

    reconcileCatalogueImage($product, 'https://cdn.shopify.com/s/files/1/blush.jpg?v=222', 'gid://shopify/ProductImage/55', 'Shopify alt');
    reconcileCatalogueImage($product, 'https://cdn.shopify.com/s/files/1/blush.jpg?v=333', 'gid://shopify/ProductImage/55', 'Shopify alt again');

    expect($product->allImages()->count())->toBe(1)
        ->and($image->fresh()->shopify_id)->toBe('gid://shopify/ProductImage/55')
        ->and($image->fresh()->alt_text)->toBe('Approved blush')
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_APPROVED)
        ->and($image->fresh()->shopify_cdn_url)->toBe('https://cdn.shopify.com/s/files/1/blush.jpg?v=333')
        ->and($image->fresh()->image_asset_id)->not->toBeNull();
});

it('does not insert or choose a row when two catalogue images already share a canonical path', function (): void {
    $product = createImageImportProduct('MOONLIT');
    $older = catalogueImage($product, 'https://cdn.shopify.com/s/files/1/moon.jpg?v=1', null);
    $newer = catalogueImage($product, 'https://cdn.shopify.com/s/files/1/moon.jpg?v=2', 'gid://shopify/ProductImage/90');

    reconcileCatalogueImage($product, 'https://cdn.shopify.com/s/files/1/moon.jpg?v=3', 'gid://shopify/MediaImage/91', 'Incoming');

    expect($product->allImages()->count())->toBe(2)
        ->and($older->fresh()->shopify_id)->toBeNull()
        ->and($newer->fresh()->shopify_id)->toBe('gid://shopify/ProductImage/90')
        ->and($older->fresh()->src)->toContain('v=1')
        ->and($newer->fresh()->src)->toContain('v=2');
});

it('does not match or overwrite a local new image from a catalogue url', function (): void {
    $product = createImageImportProduct('LOCAL-NEW');
    $image = catalogueImage($product, 'https://cdn.shopify.com/s/files/1/local.jpg?v=1', null, Image::SYNC_STATE_LOCAL_NEW);

    reconcileCatalogueImage($product, 'https://cdn.shopify.com/s/files/1/local.jpg?v=2', 'gid://shopify/ProductImage/12', 'Incoming');

    expect($product->allImages()->count())->toBe(1)
        ->and($image->fresh()->sync_state)->toBe(Image::SYNC_STATE_LOCAL_NEW)
        ->and($image->fresh()->shopify_id)->toBeNull()
        ->and($image->fresh()->src)->toBe('https://cdn.shopify.com/s/files/1/local.jpg?v=1');
});

it('does not treat a shared asset hash as image identity', function (): void {
    $product = createImageImportProduct('SAME-HASH');
    $asset = catalogueAsset('shared-bytes');
    catalogueImage($product, 'https://cdn.shopify.com/s/files/1/left.jpg', null, Image::SYNC_STATE_SYNCED, $asset);
    catalogueImage($product, 'https://cdn.shopify.com/s/files/1/right.jpg', 'gid://shopify/MediaImage/8', Image::SYNC_STATE_SYNCED, $asset);

    reconcileCatalogueImage($product, 'https://cdn.shopify.com/s/files/1/third.jpg', 'gid://shopify/MediaImage/9', 'Third');

    expect($product->allImages()->count())->toBe(3);
});

it('does not request another Shopify upload when only the filename or position changes', function (): void {
    $product = createImageImportProduct('RENAME-ONLY', ['title' => 'Rename Only Bracelet']);
    $image = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/MediaImage/44',
        'src' => 'https://cdn.shopify.com/s/files/1/rename.jpg',
        'position' => 1,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'approved_filename' => 'old-name.jpg',
        'needs_shopify_image_sync' => false,
    ]));

    $updated = app(ProductImageFilenameService::class)->assignFromCurrentTitle($product);

    expect($updated)->toBe(1)
        ->and($image->fresh()->approved_filename)->not->toBe('old-name.jpg')
        ->and($image->fresh()->needs_shopify_image_sync)->toBeFalse()
        ->and($image->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/44');

    $image->fresh()->update(['position' => 4]);

    expect($image->fresh()->position)->toBe(4)
        ->and($image->fresh()->needs_shopify_image_sync)->toBeFalse()
        ->and($image->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/44');
});

it('clears the Shopify media id when the photograph changes and keeps it for a cache buster', function (): void {
    $product = createImageImportProduct('BYTES');
    $image = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/MediaImage/45',
        'shopify_cdn_url' => 'https://cdn.shopify.com/s/files/1/original.jpg?v=1',
        'src' => 'https://cdn.shopify.com/s/files/1/original.jpg?v=1',
        'position' => 1,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'needs_shopify_image_sync' => false,
    ]));

    $image->update(['src' => 'https://cdn.shopify.com/s/files/1/original.jpg?v=9']);

    expect($image->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/45')
        ->and($image->fresh()->shopify_cdn_url)->toBe('https://cdn.shopify.com/s/files/1/original.jpg?v=1')
        ->and($image->fresh()->needs_shopify_image_sync)->toBeFalse();

    $image->fresh()->update(['src' => 'https://cdn.shopify.com/s/files/1/replacement.jpg']);

    expect($image->fresh()->shopify_id)->toBeNull()
        ->and($image->fresh()->shopify_cdn_url)->toBeNull()
        ->and($image->fresh()->needs_shopify_image_sync)->toBeTrue();
});

it('imports the same S3 object twice without a second row or a filename republish', function (): void {
    Storage::fake('shopify_product_images');
    Storage::fake('public');
    config()->set('shopify_image_import.disk', 'shopify_product_images');
    Http::preventStrayRequests();

    $bytes = 'repeat-s3-bytes';
    Storage::disk('shopify_product_images')->put('incoming/2026-07-06/LRB0001.png', $bytes);
    $asset = createImportTestAsset($bytes, 'png', 'LRB0001.png');
    $product = createImageImportProduct('LRB0001');
    $image = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/MediaImage/1001',
        'shopify_cdn_url' => 'https://cdn.shopify.com/s/files/1/original.png?v=1',
        'image_asset_id' => $asset->id,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'src' => 'https://cdn.shopify.com/s/files/1/original.png?v=1',
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
        'position' => 1,
        'approved_filename' => 'LRB0001.png',
        'last_shopify_synced_image_asset_id' => $asset->id,
        'needs_shopify_image_sync' => false,
        'alt_text' => 'Approved import',
        'alt_text_status' => Image::ALT_APPROVED,
    ]));

    $service = app(ShopifyImageImportService::class);
    $first = ShopifyImageImportBatch::create(['s3_prefix' => '2026-07-06', 'status' => ShopifyImageImportBatch::STATUS_PENDING]);
    $service->runBatch($first);
    $second = ShopifyImageImportBatch::create(['s3_prefix' => '2026-07-06', 'status' => ShopifyImageImportBatch::STATUS_PENDING]);
    $service->runBatch($second);

    expect($product->allImages()->count())->toBe(1)
        ->and($image->fresh()->needs_shopify_image_sync)->toBeFalse()
        ->and($image->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/1001')
        ->and($image->fresh()->shopify_cdn_url)->toBe('https://cdn.shopify.com/s/files/1/original.png?v=1')
        ->and($image->fresh()->alt_text)->toBe('Approved import')
        ->and($image->fresh()->src)->toContain('/product-image-backups/');
});

it('clears the Shopify id when a repeated S3 import replaces the photograph', function (): void {
    Storage::fake('shopify_product_images');
    Storage::fake('public');
    config()->set('shopify_image_import.disk', 'shopify_product_images');
    Http::preventStrayRequests();

    $original = 'original-bytes';
    Storage::disk('shopify_product_images')->put('incoming/2026-07-06/LRB0009-v2.png', 'replacement-bytes');
    $asset = createImportTestAsset($original, 'png', 'LRB0009.png');
    $product = createImageImportProduct('LRB0009');
    $image = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/MediaImage/1009',
        'image_asset_id' => $asset->id,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'src' => 'https://cdn.shopify.com/s/files/1/original-9.png',
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
        'position' => 1,
        'approved_filename' => 'LRB0009.png',
        'last_shopify_synced_image_asset_id' => $asset->id,
        'needs_shopify_image_sync' => false,
        'alt_text' => 'Old photograph',
        'alt_text_status' => Image::ALT_APPROVED,
    ]));

    app(ShopifyImageImportService::class)->runBatch(ShopifyImageImportBatch::create([
        's3_prefix' => '2026-07-06',
        'status' => ShopifyImageImportBatch::STATUS_PENDING,
    ]));

    expect($product->allImages()->where('sync_state', '!=', Image::SYNC_STATE_LOCAL_DELETED)->count())->toBe(1)
        ->and($image->fresh()->shopify_id)->toBeNull()
        ->and($image->fresh()->needs_shopify_image_sync)->toBeTrue()
        ->and($image->fresh()->alt_text)->toBeNull()
        ->and($image->fresh()->image_asset_id)->not->toBe($asset->id);
});

it('rebuilds a stack twice without a second copy or losing approved alt text', function (): void {
    Storage::fake('public');
    Http::preventStrayRequests();
    $component = createImageImportProduct('COMP-1');
    $asset = catalogueAsset('component-photo', 'component.jpg');
    $componentImage = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $component->id,
        'shopify_id' => 'gid://shopify/MediaImage/501',
        'image_asset_id' => $asset->id,
        'src' => 'https://cdn.shopify.com/s/files/1/component.jpg',
        'position' => 1,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'alt_text' => 'Component front',
    ]));
    $stack = createImageImportProduct('STACK-1', ['type' => 'Bundle', 'title' => 'Stack One']);
    Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $stack->id,
        'shopify_id' => 'gid://shopify/MediaImage/500',
        'src' => 'https://cdn.shopify.com/s/files/1/lifestyle.jpg',
        'position' => 1,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'alt_text' => 'Lifestyle',
        'alt_text_status' => Image::ALT_APPROVED,
    ]));
    NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'sku' => 'STACK-1',
        'shopify_id' => $stack->shopify_id,
        'handle' => $stack->handle,
        'title' => 'Stack One',
        'type' => 'Bundle',
        'status' => 'active',
        'bundle_product_ids' => [$component->id],
    ]));

    $method = new ReflectionMethod(ShopifyImageImportService::class, 'rebuildStackImages');
    $method->invoke(app(ShopifyImageImportService::class), $stack);
    $copy = $stack->allImages()->where('source_image_id', $componentImage->id)->firstOrFail();
    $copy->forceFill([
        'shopify_id' => 'gid://shopify/MediaImage/502',
        'shopify_cdn_url' => 'https://cdn.shopify.com/s/files/1/stack-copy.jpg?v=1',
        'needs_shopify_image_sync' => false,
        'alt_text' => 'Approved stack copy',
        'alt_text_status' => Image::ALT_APPROVED,
    ])->save();

    $method->invoke(app(ShopifyImageImportService::class), $stack->fresh());

    expect($stack->allImages()->whereNotIn('sync_state', [Image::SYNC_STATE_LOCAL_DELETED, Image::SYNC_STATE_REMOTE_DELETED])->count())->toBe(2)
        ->and($copy->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/502')
        ->and($copy->fresh()->shopify_cdn_url)->toBe('https://cdn.shopify.com/s/files/1/stack-copy.jpg?v=1')
        ->and($copy->fresh()->needs_shopify_image_sync)->toBeFalse()
        ->and($copy->fresh()->alt_text)->toBe('Approved stack copy')
        ->and($copy->fresh()->source_image_id)->toBe($componentImage->id);
});

it('reuses Shopify media with a changed cache buster instead of uploading it again', function (): void {
    Storage::fake('public');
    $product = createImageImportProduct('REUSE-MEDIA');
    $asset = catalogueAsset('reuse-bytes', 'reuse.jpg');
    $image = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'src' => 'https://example.test/product-image-backups/1/reuse.jpg',
        'shopify_cdn_url' => 'https://cdn.shopify.com/s/files/1/reuse.jpg?v=1',
        'image_asset_id' => $asset->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'position' => 1,
        'sync_state' => Image::SYNC_STATE_LOCAL_UPDATED,
        'needs_shopify_image_sync' => true,
        'alt_text' => 'Approved reuse',
        'alt_text_status' => Image::ALT_APPROVED,
    ]));
    $creates = 0;
    Http::fake(function ($request) use (&$creates) {
        if ($request->method() === 'GET') {
            return Http::response('reuse-bytes');
        }
        $query = (string) ($request->data()['query'] ?? '');
        if (str_contains($query, 'ProductImageSyncMedia') || str_contains($query, 'ProductImageReorderMedia')) {
            return Http::response(['data' => ['product' => [
                'media' => ['nodes' => [['id' => 'gid://shopify/MediaImage/700', 'status' => 'READY', 'fileStatus' => 'READY', 'image' => ['url' => 'https://cdn.shopify.com/s/files/1/reuse.jpg?v=2']]], 'pageInfo' => ['hasNextPage' => false]],
                'images' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]],
            ]]]);
        }
        if (str_contains($query, 'productCreateMedia')) {
            $creates++;

            return Http::response(['data' => ['productCreateMedia' => ['media' => [['id' => 'gid://shopify/MediaImage/1']], 'mediaUserErrors' => []]]]);
        }

        return Http::response(['data' => ['productReorderMedia' => ['mediaUserErrors' => []]]]);
    });

    invokeImageSync($product, [
        'media' => ['nodes' => [[
            'id' => 'gid://shopify/MediaImage/700',
            'image' => ['url' => 'https://cdn.shopify.com/s/files/1/reuse.jpg?v=2'],
        ]]],
    ]);

    expect($creates)->toBe(0)
        ->and($image->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/700')
        ->and($image->fresh()->needs_shopify_image_sync)->toBeFalse()
        ->and($image->fresh()->alt_text)->toBe('Approved reuse');
});

it('does not upload again after Shopify accepts a create without a media id', function (): void {
    Storage::fake('public');
    $product = createImageImportProduct('UNCERTAIN');
    $asset = catalogueAsset('uncertain-bytes', 'uncertain.jpg');
    $image = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'src' => null,
        'image_asset_id' => $asset->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'position' => 1,
        'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
        'needs_shopify_image_sync' => true,
    ]));
    authorizeUncertainPhotograph($product);
    $creates = 0;
    Http::fake(function ($request) use (&$creates, &$details) {
        if ($request->method() === 'GET') {
            return Http::response(str_contains($request->url(), 'uncertain.jpg') ? 'uncertain-bytes' : 'unrelated');
        }
        $query = (string) ($request->data()['query'] ?? '');
        if (str_contains($query, 'ProductImageSyncMedia') || str_contains($query, 'ProductImageCreatedMedia') || str_contains($query, 'ProductImageReorderMedia')) {
            $nodes = array_map(fn ($node) => array_merge(['status' => 'READY', 'fileStatus' => 'READY'], $node), $details['media']['nodes'] ?? []);

            return Http::response(['data' => ['product' => ['media' => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => false]], 'images' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false]]]]]);
        }
        if (str_contains($query, 'productCreateMedia')) {
            $creates++;

            return Http::response(['data' => ['productCreateMedia' => ['media' => [], 'mediaUserErrors' => []]]]);
        }

        return Http::response(['data' => ['productReorderMedia' => ['mediaUserErrors' => []]]]);
    });

    $details = ['media' => ['nodes' => []]];
    invokeImageSync($product, $details);
    expect($image->fresh()->shopify_media_create_uncertain)->toBeTrue();

    $details['media']['nodes'][] = [
        'id' => 'gid://shopify/MediaImage/879',
        'image' => ['url' => 'https://cdn.shopify.com/s/files/1/unrelated.jpg'],
    ];
    invokeImageSync($product->fresh(), $details);

    expect($creates)->toBe(1)
        ->and($image->fresh()->shopify_id)->toBeNull()
        ->and($image->fresh()->shopify_media_create_uncertain)->toBeTrue();

    $asset->forceFill(['source_url' => 'https://cdn.shopify.com/s/files/1/uncertain.jpg?v=1'])->save();
    $details['media']['nodes'][] = [
        'id' => 'gid://shopify/MediaImage/880',
        'image' => ['url' => 'https://cdn.shopify.com/s/files/1/uncertain.jpg?v=9'],
    ];
    invokeImageSync($product->fresh(), $details);

    expect($creates)->toBe(1)
        ->and($image->fresh()->shopify_id)->toBeNull()
        ->and($image->fresh()->shopify_media_create_uncertain)->toBeTrue()
        ->and(\Illuminate\Support\Facades\DB::table('image_upload_operations')->where('active_image_id', $image->id)->value('status'))->toBe('needs_review');
});

it('skips image sync while another worker holds the product lock', function (): void {
    $product = createImageImportProduct('LOCKED');
    Http::preventStrayRequests();
    $lock = Cache::lock(ProductShopifyUpdater::IMAGE_SYNC_LOCK_PREFIX.$product->id, 30);
    expect($lock->get())->toBeTrue();

    $warnings = invokeImageSync($product, ['media' => ['nodes' => []]]);

    expect($warnings[0]['warning'] ?? '')->toContain('another sync is already running');
    $lock->release();
});

it('publishes alt text only after the Shopify media file is verified', function (): void {
    $product = createImageImportProduct('ALT-KEEP');
    $asset = catalogueAsset('alt-bytes', 'alt.jpg');
    $asset->forceFill(['source_url' => 'https://cdn.shopify.com/s/files/1/alt.jpg?v=1'])->save();
    $image = Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'shopify_id' => 'gid://shopify/ProductImage/55',
        'src' => 'https://example.test/product-image-backups/'.$product->id.'/alt.jpg',
        'image_asset_id' => $asset->id,
        'position' => 2,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'alt_text' => 'Approved sentence',
        'alt_text_status' => Image::ALT_APPROVED,
    ]));
    app()->instance(ShopifyGraphqlGateway::class, new class implements ShopifyGraphqlGateway
    {
        public function graphql(string $query, array $variables = []): array
        {
            return ['product' => [
                'media' => ['nodes' => [[
                    'id' => 'gid://shopify/MediaImage/7700', 'status' => 'READY', 'fileStatus' => 'READY',
                    'image' => ['url' => 'https://cdn.shopify.com/s/files/1/alt.jpg?v=9'],
                ]], 'pageInfo' => ['hasNextPage' => false]],
                'images' => ['nodes' => [[
                    'id' => 'gid://shopify/ProductImage/55',
                    'url' => 'https://cdn.shopify.com/s/files/1/alt.jpg?v=4',
                ]], 'pageInfo' => ['hasNextPage' => false]],
            ]];
        }
    });

    Http::fake(['https://cdn.shopify.com/*' => Http::response('alt-bytes')]);
    $resolved = app(ShopifyMediaImageResolver::class)->resolve($image->fresh());

    expect($resolved)->toBe('gid://shopify/MediaImage/7700')
        ->and($image->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/7700')
        ->and($image->fresh()->shopify_cdn_url)->toBe('https://cdn.shopify.com/s/files/1/alt.jpg?v=9')
        ->and($image->fresh()->alt_text)->toBe('Approved sentence')
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_APPROVED);
});

it('reports a same-path duplicate without hiding it and leaves different photographs for review', function (): void {
    $same = createImageImportProduct('SAME-PATH');
    $asset = catalogueAsset('report-bytes', 'report.jpg');
    $hide = catalogueImage($same, 'https://cdn.shopify.com/s/files/1/report.jpg?v=1', null, Image::SYNC_STATE_SYNCED, $asset);
    $hide->forceFill(['alt_text' => 'Approved on the old row', 'alt_text_status' => Image::ALT_APPROVED])->save();
    $keep = catalogueImage($same, 'https://cdn.shopify.com/s/files/1/report.jpg?v=2', 'gid://shopify/MediaImage/3', Image::SYNC_STATE_SYNCED, $asset);

    $different = createImageImportProduct('DIFF-PATH');
    catalogueImage($different, 'https://cdn.shopify.com/s/files/1/thula.jpg', null, Image::SYNC_STATE_SYNCED, catalogueAsset('thula', 'ThulaThula.jpg'));
    catalogueImage($different, 'https://cdn.shopify.com/s/files/1/fireball.jpg', 'gid://shopify/MediaImage/4', Image::SYNC_STATE_SYNCED, catalogueAsset('fireball', 'FireballFatale.jpg'));

    $rows = collect(app(DuplicateImagePositionReport::class)->rows())->keyBy('product');
    $sameRow = $rows['same-path'];
    $differentRow = $rows['diff-path'];

    expect($sameRow['keep_row_id'])->toBe((string) $keep->id)
        ->and($sameRow['proposed_hide_row_id'])->toBe((string) $hide->id)
        ->and($sameRow['canonical_url_comparison'])->toBe('same')
        ->and($sameRow['asset_hash_comparison'])->toBe('same')
        ->and($sameRow['proposed_action'])->toContain('hide_unlinked_duplicate')
        ->and($sameRow['proposed_action'])->toContain('copy alt_text')
        ->and($sameRow['proposed_action'])->toContain('must not be rewritten')
        ->and($differentRow['proposed_action'])->toContain('review')
        ->and($differentRow['keep_row_id'])->toBe('')
        ->and($hide->fresh()->sync_state)->toBe(Image::SYNC_STATE_SYNCED)
        ->and($keep->fresh()->alt_text_status)->toBeNull();

    $path = storage_path('app/duplicate-image-position-report-test.csv');
    artisan(ReportDuplicateImagePositions::class, ['--output' => $path])->assertSuccessful();
    expect(file_get_contents($path))->toContain('hide_unlinked_duplicate')
        ->and($hide->fresh()->is_duplicate_hidden)->toBeFalse();
    @unlink($path);
});

function catalogueAsset(string $bytes, string $filename = 'photo.jpg'): ImageAsset
{
    $sha = hash('sha256', $bytes);
    $path = 'product-image-assets/'.substr($sha, 0, 2).'/'.substr($sha, 2, 2)."/{$sha}.jpg";
    Storage::disk('public')->put($path, $bytes);

    return ImageAsset::create([
        'sha256' => $sha,
        'storage_disk' => 'public',
        'storage_path' => $path,
        'original_filename' => $filename,
        'status' => ImageAsset::STATUS_AVAILABLE,
    ]);
}

function catalogueImage(Product $product, string $src, ?string $shopifyId, string $syncState = Image::SYNC_STATE_SYNCED, ?ImageAsset $asset = null): Image
{
    return Image::withoutEvents(fn (): Image => Image::create([
        'product_id' => $product->id,
        'src' => $src,
        'position' => 2,
        'sync_state' => $syncState,
        'shopify_id' => $shopifyId,
        'image_asset_id' => $asset?->id,
        'local_dirty' => $syncState === Image::SYNC_STATE_LOCAL_NEW,
    ]));
}

function reconcileCatalogueImage(Product $product, string $src, string $shopifyId, string $alt): void
{
    $row = new ShopifyRow([
        'data' => [
            HeaderStore::IMAGE_SRC => $src,
            HeaderStore::IMAGE_POSITION => '2',
            HeaderStore::IMAGE_ALT_TEXT => $alt,
            HeaderStore::INTERNAL_IMAGE_SHOPIFY_ID => $shopifyId,
        ],
    ]);

    (new ReflectionMethod(Normalizer::class, 'reconcileImages'))
        ->invoke(app(Normalizer::class), $product->fresh(), collect([$row]), app(\App\Services\CatalogImageMatcher::class)->prepareEvidence($product->allImages()->with('imageAsset')->get(), [$src]));
}

function authorizeUncertainPhotograph(Product $product): void
{
    foreach ([1, 2] as $ignored) {
        Approval::create([
            'product_id' => $product->id,
            'user_id' => User::factory()->create()->id,
            'approval_version' => $product->fresh()->approval_version ?? 1,
        ]);
    }
}

/**
 * @param  array<string, mixed>  $details
 * @return array<int, array<string, mixed>>
 */
function invokeImageSync(Product $product, array $details): array
{
    return (new ReflectionMethod(ProductShopifyUpdater::class, 'updateImages'))
        ->invoke(
            app(ProductShopifyUpdater::class),
            $product,
            $product->shopify_id,
            [],
            $details,
            null,
            false,
        );
}
