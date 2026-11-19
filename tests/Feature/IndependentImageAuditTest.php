<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\RolesEnum;
use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\User;
use App\Services\AltTextAwaitingConfirmation;
use App\Services\AltTextReconciliationReport;
use App\Services\ImageAltTextBackfillService;
use App\Services\ImageAltTextShopifyWriter;
use App\Services\ImageSyncMutex;
use App\Services\ImageUploadOperations;
use App\Services\ProductShopifyUpdater;
use App\Services\ShopifyApiClient;
use App\Services\ShopifyImageImportService;
use App\Services\ShopifyMediaImageResolver;
use App\Services\ShopifyProductMediaReader;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/shopify-image-import.php';

final class LifecycleShopifyFake implements ShopifyGraphqlGateway
{
    public array $nodes = [];

    public array $legacy = [];

    public array $bodies = [];

    public int $creates = 0;

    public int $updates = 0;

    public int $reads = 0;

    public int $pageSize = 250;

    public bool $loseCreateResponse = false;

    public bool $processing = false;

    public int $delay = 0;

    public ?array $pending = null;

    public ?Closure $onCreate = null;

    public function node(string $id, string $bytes, string $alt = ''): array
    {
        $url = 'https://cdn.shopify.com/audit/'.basename($id).'.jpg';
        $this->bodies[$url] = $bytes;

        return ['id' => $id, 'alt' => $alt, 'status' => 'READY', 'fileStatus' => 'READY', 'image' => ['url' => $url]];
    }

    public function graphql(string $query, array $variables = []): array
    {
        if (str_contains($query, 'productCreateMedia')) {
            $this->creates++;
            ($this->onCreate ?? fn () => null)($variables);
            $node = $this->node('gid://shopify/MediaImage/'.(100 + $this->creates), 'upload bytes');
            if ($this->processing) {
                $node['status'] = 'PROCESSING';
                $node['fileStatus'] = 'PROCESSING';
            }
            $this->nodes[] = $node;
            if ($this->loseCreateResponse) {
                throw new ConnectionException('response lost after acceptance');
            }

            return ['productCreateMedia' => ['media' => [['id' => $node['id']]], 'mediaUserErrors' => []]];
        }
        if (str_contains($query, 'fileUpdate')) {
            $this->updates++;
            $this->pending = $variables['files'][0];

            return ['fileUpdate' => ['files' => [$this->pending], 'userErrors' => []]];
        }
        if (str_contains($query, 'productReorderMedia')) {
            return ['productReorderMedia' => ['mediaUserErrors' => []]];
        }
        $this->reads++;
        if ($this->pending && str_contains($query, 'ConfirmAttachedImageAlt') && $this->delay-- <= 0) {
            foreach ($this->nodes as &$node) {
                if ($node['id'] === $this->pending['id']) {
                    $node['alt'] = $this->pending['alt'];
                }
            }
            unset($node);
        }
        $field = str_contains($query, 'images(first:') ? 'images' : 'media';
        $all = $field === 'images' ? $this->legacy : $this->nodes;
        $offset = (int) ($variables['after'] ?? 0);
        $page = array_slice($all, $offset, $this->pageSize);
        $end = $offset + count($page);

        return ['product' => [$field => ['nodes' => $page, 'pageInfo' => ['hasNextPage' => $end < count($all), 'endCursor' => (string) $end]]]];
    }
}

function lifecycleHttp(LifecycleShopifyFake $remote): void
{
    Http::fake(function ($request) use ($remote) {
        if ($request->method() === 'GET') {
            return Http::response($remote->bodies[$request->url()] ?? 'unrelated bytes');
        }

        return Http::response(['data' => $remote->graphql($request->data()['query'] ?? '', $request->data()['variables'] ?? [])]);
    });
}

function lifecycleImage(string $sku = 'AUDIT', string $bytes = 'upload bytes'): Image
{
    Storage::fake('public');
    $p = createImageImportProduct($sku);
    $a = createImportTestAsset($bytes, 'jpg', 'photo.jpg');

    return Image::withoutEvents(fn () => Image::create(['product_id' => $p->id, 'position' => 1, 'sync_state' => Image::SYNC_STATE_LOCAL_NEW, 'image_asset_id' => $a->id, 'backup_status' => Image::BACKUP_STATUS_BACKED_UP, 'needs_shopify_image_sync' => true]));
}

function lifecycleSync(Image $image, array $stale = []): array
{
    return (new ReflectionMethod(ProductShopifyUpdater::class, 'updateImages'))->invoke(app(ProductShopifyUpdater::class), $image->product, $image->product->shopify_id, [], $stale, null, false);
}

it('preserves approved different photographs at the same position and their relationships', function () {
    $i = lifecycleImage();
    $keep = Image::withoutEvents(fn () => Image::create(['product_id' => $i->product_id, 'position' => 1, 'sync_state' => Image::SYNC_STATE_SYNCED, 'shopify_id' => 'gid://shopify/MediaImage/1']));
    $i->forceFill(['alt_text' => 'Approved B', 'alt_text_status' => Image::ALT_APPROVED, 'source_image_id' => $keep->id])->save();
    (new ReflectionMethod(ShopifyImageImportService::class, 'cleanupDuplicatePositionsForPipeline'))->invoke(app(ShopifyImageImportService::class), $i->product);
    (new ReflectionMethod(ShopifyImageImportService::class, 'removeExtraActiveImagesAtPosition'))->invoke(app(ShopifyImageImportService::class), $i->product, $keep, 1);
    expect($i->fresh()->alt_text)->toBe('Approved B')->and($i->fresh()->source_image_id)->toBe($keep->id)->and($i->fresh()->is_duplicate_hidden)->toBeFalse();
});

it('rejects stale historical URL evidence when current Shopify bytes differ', function () {
    $i = lifecycleImage(bytes: 'replacement photograph');
    $remote = new LifecycleShopifyFake;
    $remote->nodes[] = $remote->node('gid://shopify/MediaImage/9', 'old photograph');
    $i->imageAsset->update(['source_url' => $remote->nodes[0]['image']['url']]);
    lifecycleHttp($remote);
    expect((new ShopifyMediaImageResolver($remote))->diagnose($i)['classification'])->toBe('unresolved_provenance');
});

it('requires explicit independent file adoption after a lost creation response and never creates twice', function () {
    $i = lifecycleImage();
    $remote = new LifecycleShopifyFake;
    $remote->loseCreateResponse = true;
    $remote->onCreate = function ($variables) use ($i) {
        expect(DB::table('image_upload_operations')->where('active_image_id', $i->id)->value('status'))->toBe('submitting');
        expect($variables['media'][0]['originalSource'])->toContain('?asset='.$i->imageAsset->sha256);
    };
    lifecycleHttp($remote);
    lifecycleSync($i);
    expect($i->fresh()->shopify_media_create_uncertain)->toBeTrue();
    lifecycleSync($i->fresh());
    expect($remote->creates)->toBe(1)->and($i->fresh()->shopify_id)->toBeNull()->and(DB::table('image_upload_operations')->value('status'))->toBe('needs_review');
    $operator = User::factory()->create(['is_active' => true]);
    Role::findOrCreate(RolesEnum::Admin->value);
    $operator->assignRole(RolesEnum::Admin->value);
    app()->instance(ShopifyGraphqlGateway::class, $remote);
    $this->artisan('images:resolve-upload', ['operation' => DB::table('image_upload_operations')->value('id'), '--adopt-media-id' => 'gid://shopify/MediaImage/101',
        '--operator' => $operator->id, '--reason' => 'Reviewed Shopify upload history and this exact independent file.'])->assertSuccessful();
    expect($i->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/101')->and($remote->creates)->toBe(1)
        ->and(DB::table('image_upload_operations')->value('resolved_by'))->toBe($operator->id);
});

it('invalidates old media without an asset baseline and archives the approved state', function () {
    $i = lifecycleImage();
    Image::withoutEvents(fn () => $i->forceFill(['image_asset_id' => null, 'shopify_id' => 'gid://shopify/MediaImage/11', 'src' => 'https://cdn.shopify.com/old.jpg', 'alt_text' => 'Approved old photo', 'alt_text_status' => Image::ALT_APPROVED])->save());
    $asset = createImportTestAsset('replacement', 'jpg', 'new.jpg');
    (new ReflectionMethod(ShopifyImageImportService::class, 'prepareImageForImport'))->invoke(app(ShopifyImageImportService::class), $i->product, $asset, 'new.jpg', 1);
    $snapshot = json_decode(DB::table('image_revisions')->where('image_id', $i->id)->value('snapshot'), true);
    expect($i->fresh()->shopify_id)->toBeNull()->and($i->fresh()->shopify_identity_unresolved)->toBeTrue()->and($i->fresh()->alt_text)->toBeNull()->and($snapshot['alt_text'])->toBe('Approved old photo');
});

it('rejects a stack copy of an obsolete component photograph', function () {
    $source = lifecycleImage();
    $stack = createImageImportProduct('STACK', ['is_bundle' => true]);
    $old = createImportTestAsset('old bytes', 'jpg', 'old.jpg');
    $copy = Image::withoutEvents(fn () => Image::create(['product_id' => $stack->id, 'position' => 2, 'sync_state' => Image::SYNC_STATE_SYNCED, 'image_asset_id' => $old->id, 'source_image_id' => $source->id]));
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['sku' => 'STACK', 'shopify_id' => $stack->shopify_id, 'handle' => $stack->handle, 'title' => 'Stack', 'status' => 'active', 'bundle_product_ids' => [$source->product_id]]));
    $method = new ReflectionMethod(ImageAltTextBackfillService::class, 'copyHasCurrentSource');
    expect($method->invoke(app(ImageAltTextBackfillService::class), $copy))->toBeFalse();
});

it('refreshes media inside the mutex rather than trusting prelock snapshots', function () {
    $i = lifecycleImage();
    $remote = new LifecycleShopifyFake;
    lifecycleHttp($remote);
    lifecycleSync($i, ['media' => ['nodes' => []]]);
    lifecycleSync($i->fresh(), ['media' => ['nodes' => []]]);
    expect($remote->creates)->toBe(1)->and($i->fresh()->needs_shopify_image_sync)->toBeFalse();
});

it('exhausts media and legacy image pagination before matching', function () {
    $i = lifecycleImage();
    $remote = new LifecycleShopifyFake;
    $remote->pageSize = 1;
    $remote->nodes[] = $remote->node('gid://shopify/MediaImage/1', 'unrelated');
    $remote->nodes[] = $remote->node('gid://shopify/MediaImage/2', 'upload bytes');
    $remote->legacy = [['id' => 'gid://shopify/ProductImage/1', 'url' => 'https://cdn.shopify.com/1.jpg'], ['id' => 'gid://shopify/ProductImage/2', 'url' => $remote->nodes[1]['image']['url']]];
    Image::withoutEvents(fn () => $i->forceFill(['shopify_id' => 'gid://shopify/ProductImage/2'])->save());
    lifecycleHttp($remote);
    expect((new ShopifyMediaImageResolver($remote))->resolve($i))->toBe('gid://shopify/MediaImage/2')->and($remote->reads)->toBe(4);
});

it('retains a killed workers durable claim and never replays creation without evidence', function () {
    $i = lifecycleImage();
    $remote = new LifecycleShopifyFake;
    lifecycleHttp($remote);
    $operation = app(ImageUploadOperations::class)->claim($i, $i->product->shopify_id, []);
    // A new service/job instance sees only the durable state, as after process death.
    lifecycleSync($i->fresh());
    expect($remote->creates)->toBe(0)->and(DB::table('image_upload_operations')->where('id', $operation->id)->value('status'))->toBe('needs_review');
});

it('does not mark processing media synced or upload it again', function () {
    $i = lifecycleImage();
    $remote = new LifecycleShopifyFake;
    $remote->processing = true;
    lifecycleHttp($remote);
    lifecycleSync($i);
    lifecycleSync($i->fresh());
    expect($remote->creates)->toBe(1)->and($i->fresh()->needs_shopify_image_sync)->toBeTrue()->and($i->fresh()->shopify_id)->toBeNull();
});

it('detects lock expiry and ownership loss before another request', function () {
    $i = lifecycleImage();
    $mutex = new ImageSyncMutex;
    expect($mutex->acquire($i->product_id))->toBeTrue();
    Cache::lock(ProductShopifyUpdater::IMAGE_SYNC_LOCK_PREFIX.$i->product_id)->forceRelease();
    expect(fn () => $mutex->assertOwned())->toThrow(RuntimeException::class, 'ownership was lost');
});

it('retries delayed readback without resending fileUpdate and skips already current alt', function () {
    $remote = new LifecycleShopifyFake;
    $remote->nodes[] = $remote->node('gid://shopify/MediaImage/1', 'bytes', 'old');
    $remote->delay = 2;
    $writer = new ImageAltTextShopifyWriter($remote);
    $writer->update('gid://shopify/MediaImage/1', 'approved', 'gid://shopify/Product/1');
    $writer->update('gid://shopify/MediaImage/1', 'approved', 'gid://shopify/Product/1');
    expect($remote->updates)->toBe(1)->and($remote->nodes[0]['alt'])->toBe('approved');
});

it('keeps uncertain alt updates awaiting confirmation without replay', function () {
    $remote = new LifecycleShopifyFake;
    $remote->nodes[] = $remote->node('gid://shopify/MediaImage/1', 'bytes', 'old');
    $remote->delay = 100;
    $writer = new ImageAltTextShopifyWriter($remote);
    expect(fn () => $writer->update('gid://shopify/MediaImage/1', 'approved', 'gid://shopify/Product/1'))->toThrow(AltTextAwaitingConfirmation::class);
    expect(fn () => $writer->update('gid://shopify/MediaImage/1', 'approved', 'gid://shopify/Product/1', true))->toThrow(AltTextAwaitingConfirmation::class);
    expect($remote->updates)->toBe(1);
});

it('does not publish alt text to a processing file', function () {
    $remote = new LifecycleShopifyFake;
    $remote->nodes[] = $remote->node('gid://shopify/MediaImage/1', 'bytes');
    $remote->nodes[0]['fileStatus'] = 'PROCESSING';
    expect(fn () => (new ImageAltTextShopifyWriter($remote))->update('gid://shopify/MediaImage/1', 'approved', 'gid://shopify/Product/1'))->toThrow(RuntimeException::class, 'not READY');
    expect($remote->updates)->toBe(0);
});

it('reports API failures as unknown without modifying CMS rows', function () {
    $i = lifecycleImage();
    $before = $i->fresh()->getAttributes();
    $gateway = new class implements ShopifyGraphqlGateway
    {
        public function graphql(string $query, array $variables = []): array
        {
            throw new RuntimeException('unavailable');
        }
    };
    expect((new ShopifyMediaImageResolver($gateway))->diagnose($i)['classification'])->toBe('api_error')->and($i->fresh()->getAttributes())->toBe($before);
});

it('serializes duplicate claims with a database unique active operation', function () {
    $i = lifecycleImage();
    $service = new ImageUploadOperations;
    expect($service->claim($i, $i->product->shopify_id, []))->not->toBeNull();
    expect((new ImageUploadOperations)->claim($i->fresh(), $i->product->shopify_id, []))->toBeNull()->and(DB::table('image_upload_operations')->count())->toBe(1);
});

it('rejects conflicting historical candidates instead of choosing a URL', function () {
    $i = lifecycleImage();
    $remote = new LifecycleShopifyFake;
    $remote->nodes[] = $remote->node('gid://shopify/MediaImage/1', 'upload bytes');
    $remote->nodes[] = $remote->node('gid://shopify/MediaImage/2', 'other photograph');
    Image::withoutEvents(fn () => $i->forceFill(['shopify_cdn_url' => $remote->nodes[0]['image']['url'], 'src' => $remote->nodes[1]['image']['url']])->save());
    lifecycleHttp($remote);
    expect((new ShopifyMediaImageResolver($remote))->diagnose($i)['classification'])->toBe('ambiguous');
});

it('blocks a concurrent import and then reuses the same photograph across reordered positions', function () {
    $i = lifecycleImage();
    $mutex = new ImageSyncMutex;
    expect($mutex->acquire($i->product_id))->toBeTrue();
    $method = new ReflectionMethod(ShopifyImageImportService::class, 'prepareImageForImport');
    expect(fn () => $method->invoke(app(ShopifyImageImportService::class), $i->product, $i->imageAsset, 'renamed.jpg', 2))
        ->toThrow(RuntimeException::class, 'operation is running');
    $mutex->release();
    $method->invoke(app(ShopifyImageImportService::class), $i->product, $i->imageAsset, 'renamed.jpg', 2);
    $method->invoke(app(ShopifyImageImportService::class), $i->product, $i->imageAsset, 'renamed-again.jpg', 3);
    expect($i->product->allImages()->count())->toBe(1)->and($i->fresh()->position)->toBe(3);
});

it('does not create separate operations for two rows containing the same product photograph', function () {
    $i = lifecycleImage();
    $copy = Image::withoutEvents(fn () => Image::create(['product_id' => $i->product_id, 'image_asset_id' => $i->image_asset_id, 'position' => 2, 'backup_status' => Image::BACKUP_STATUS_BACKED_UP]));
    $service = new ImageUploadOperations;
    expect($service->claim($i, $i->product->shopify_id, []))->not->toBeNull();
    expect($service->claim($copy, $i->product->shopify_id, []))->toBeNull();
    expect(DB::table('image_upload_operations')->count())->toBe(1);
});

it('keeps the upload source pinned to the archived asset after an image changes', function () {
    $i = lifecycleImage();
    $operation = (new ImageUploadOperations)->claim($i, $i->product->shopify_id, []);
    $asset = createImportTestAsset('different photograph', 'jpg', 'new.jpg');
    Image::withoutEvents(fn () => $i->forceFill(['image_asset_id' => $asset->id])->save());
    $this->get($operation->source_url)->assertOk()->assertStreamedContent('upload bytes');
    expect(Storage::disk('public')->exists($asset->storage_path))->toBeTrue();
});

it('writes upload reports without changing operation or image records or calling Shopify', function () {
    $i = lifecycleImage();
    (new ImageUploadOperations)->claim($i, $i->product->shopify_id, []);
    $before = DB::table('image_upload_operations')->first();
    $imageBefore = $i->fresh()->getAttributes();
    $path = storage_path('app/upload-report-regression.csv');
    try {
        $this->artisan('images:upload-reconciliation-report', ['--output' => $path])->assertSuccessful();
        expect(file_get_contents($path))->toContain('grants no authorization');
        expect(DB::table('image_upload_operations')->first())->toEqual($before);
        expect($i->fresh()->getAttributes())->toBe($imageBefore);
        Http::assertNothingSent();
    } finally {
        if (is_file($path)) {
            unlink($path);
        }
    }
});

it('requires a recorded operator decision before releasing an unresolved create', function () {
    $i = lifecycleImage();
    $operation = (new ImageUploadOperations)->claim($i, $i->product->shopify_id, []);
    $operator = User::factory()->create();
    $this->artisan('images:resolve-upload', ['operation' => $operation->id])->assertFailed();
    expect(DB::table('image_upload_operations')->value('active_image_id'))->toBe($i->id);
    $operator->forceFill(['is_active' => true])->save();
    Role::findOrCreate(RolesEnum::Admin->value);
    $operator->assignRole(RolesEnum::Admin->value);
    $this->artisan('images:resolve-upload', ['operation' => $operation->id, '--confirmed-not-created' => true,
        '--operator' => $operator->id, '--reason' => 'Reviewed all media and the remote upload never executed.'])->assertSuccessful();
    expect(DB::table('image_upload_operations')->value('status'))->toBe('resolved_absent');
    expect(DB::table('image_upload_operations')->value('resolved_by'))->toBe($operator->id);
    expect((new ImageUploadOperations)->claim($i->fresh(), $i->product->shopify_id, []))->not->toBeNull();
    Http::assertNothingSent();
});

it('will not release a returned file as a nonexistent upload or supersede the same photograph', function () {
    $i = lifecycleImage();
    $operation = (new ImageUploadOperations)->claim($i, $i->product->shopify_id, []);
    (new ImageUploadOperations)->record($operation, 'processing', 'gid://shopify/MediaImage/1');
    $operator = User::factory()->create();
    $arguments = ['operation' => $operation->id, '--operator' => $operator->id, '--reason' => 'Reviewed operation and kept the existing media evidence.'];
    $this->artisan('images:resolve-upload', $arguments + ['--confirmed-not-created' => true])->assertFailed();
    $this->artisan('images:resolve-upload', $arguments + ['--superseded' => true])->assertFailed();
    expect(DB::table('image_upload_operations')->value('active_image_id'))->toBe($i->id);
});

it('fails closed on malformed pagination instead of declaring media missing', function () {
    $gateway = new class implements ShopifyGraphqlGateway
    {
        public function graphql(string $query, array $variables = []): array
        {
            return ['product' => ['media' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => true, 'endCursor' => null]]]];
        }
    };
    expect(fn () => (new ShopifyProductMediaReader($gateway))->media('gid://shopify/Product/1'))->toThrow(RuntimeException::class, 'did not advance');
});
it('verifies original source bytes rather than a recompressed preview', function () {
    $i = lifecycleImage();
    $remote = new LifecycleShopifyFake;
    $node = $remote->node('gid://shopify/MediaImage/7', 'recompressed preview');
    $original = 'https://cdn.shopify.com/audit/original.jpg?signature=current';
    $node['originalSource'] = ['url' => $original];
    $remote->bodies[$original] = 'upload bytes';
    $remote->nodes[] = $node;
    Image::withoutEvents(fn () => $i->forceFill(['shopify_id' => $node['id']])->save());
    lifecycleHttp($remote);
    expect((new ShopifyMediaImageResolver($remote))->diagnose($i)['classification'])->toBe('verified_current_media');
});

it('rejects changed original bytes even when the old preview and MediaImage id persist', function () {
    $i = lifecycleImage();
    $remote = new LifecycleShopifyFake;
    $node = $remote->node('gid://shopify/MediaImage/7', 'upload bytes');
    $original = 'https://cdn.shopify.com/audit/original.jpg?signature=current';
    $node['originalSource'] = ['url' => $original];
    $remote->bodies[$original] = 'replacement photograph';
    $remote->nodes[] = $node;
    Image::withoutEvents(fn () => $i->forceFill(['shopify_id' => $node['id']])->save());
    lifecycleHttp($remote);
    expect((new ShopifyMediaImageResolver($remote))->diagnose($i)['classification'])->toBe('unresolved_provenance');
});

it('blocks a duplicate existing photograph from implicit import cleanup', function () {
    $i = lifecycleImage();
    Image::withoutEvents(fn () => Image::create(['product_id' => $i->product_id, 'position' => 1, 'image_asset_id' => $i->image_asset_id, 'sync_state' => Image::SYNC_STATE_SYNCED, 'alt_text' => 'Other approved text', 'alt_text_status' => Image::ALT_APPROVED]));
    $before = $i->product->allImages()->orderBy('id')->get()->toArray();
    expect(fn () => (new ReflectionMethod(ShopifyImageImportService::class, 'prepareImageForImport'))->invoke(app(ShopifyImageImportService::class), $i->product, $i->imageAsset, 'new-name.jpg', 1))
        ->toThrow(RuntimeException::class, 'More than one CMS row');
    expect($i->product->allImages()->orderBy('id')->get()->toArray())->toBe($before);
});
it('reuses a renamed Shopify file by verified original bytes without another upload', function () {
    $i = lifecycleImage();
    Image::withoutEvents(fn () => $i->forceFill(['src' => 'https://cdn.shopify.com/old-filename.jpg', 'shopify_cdn_url' => 'https://cdn.shopify.com/old-filename.jpg', 'shopify_id' => 'gid://shopify/ProductImage/999'])->save());
    $remote = new LifecycleShopifyFake;
    $remote->nodes[] = $remote->node('gid://shopify/MediaImage/77', 'upload bytes');
    lifecycleHttp($remote);
    lifecycleSync($i);
    expect($remote->creates)->toBe(0)->and($i->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/77');
});

it('includes blank failed descriptions in the read-only report without requesting Shopify', function () {
    $i = lifecycleImage();
    Image::withoutEvents(fn () => $i->forceFill(['alt_text' => null, 'alt_text_status' => Image::ALT_FAILED])->save());
    $rows = app(AltTextReconciliationReport::class)->rows();
    expect($rows)->toHaveCount(1)->and($rows[0]['classification'])->toBe('invalid_alt_text');
    Http::assertNothingSent();
});

it('does not internally replay a throttled image mutation', function (string $field) {
    $posts = 0;
    $arguments = $field === 'fileUpdate' ? 'files: []' : 'productId: "gid://shopify/Product/1", media: []';
    Http::fake(function () use (&$posts) {
        $posts++;

        return Http::response(['errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]]]);
    });
    expect(fn () => app(ShopifyApiClient::class)->graphql('mutation ImageWrite { '.$field.'('.$arguments.') { __typename } }'))
        ->toThrow(RuntimeException::class, 'automatic replay was blocked');
    expect($posts)->toBe(1);
})->with(['productCreateMedia', 'fileUpdate']);
