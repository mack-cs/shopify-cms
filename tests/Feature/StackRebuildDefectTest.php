<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Jobs\RebuildShopifyStackImagesJob;
use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyImageImportBatch;
use App\Services\ImageOwnership;
use App\Services\ProductShopifyUpdater;
use App\Services\ShopifyImageImportService;
use App\Services\StackRebuildLockStore;
use Illuminate\Bus\UniqueLock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/shopify-image-import.php';

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('shopify_product_images');
    Storage::fake('public');
    config()->set('shopify_image_import.disk', 'shopify_product_images');
    config([
        'images.component_stack_propagation' => false,
        'images.component_stack_local_rebuild' => false,
        'images.component_stack_blind_publication' => false,
    ]);
    Http::preventStrayRequests();
    $this->gateway = new StackRebuildDefectGateway;
    app()->instance(ShopifyGraphqlGateway::class, $this->gateway);
});

it('stages a valid managed backup and does not stage a corrupt one', function (): void {
    [$stack, $component] = defectRebuildBundle('DEFSTACK', ['DEFCOMP']);
    $valid = createImportTestAsset('valid-backup', 'jpg', 'DEFCOMP.jpg');
    $source = stackDefectPhotograph($component, [
        'image_asset_id' => $valid->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]);
    $batch = defectRebuildBatch();

    $ready = app(ShopifyImageImportService::class)->rebuildStacksForBatch($batch, [$stack->id]);
    $inherited = $stack->allImages()->active()->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();

    expect($ready['rebuilt'])->toBe(1)
        ->and($batch->fresh()->rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_COMPLETED)
        ->and($inherited->image_asset_id)->toBe($valid->id)
        ->and($inherited->source_image_id)->toBe($source->id)
        ->and($this->gateway->calls)->toBe([])
        ->and(config('images.component_stack_local_rebuild'))->toBeFalse()
        ->and(config('images.component_stack_propagation'))->toBeFalse()
        ->and(config('images.component_stack_blind_publication'))->toBeFalse();

    Storage::disk('public')->put($valid->storage_path, 'tampered-bytes');
    Storage::disk('public')->put('component-photos/other.jpg', 'unrelated-local-bytes');
    $source->forceFill([
        'image_path' => 'component-photos/other.jpg',
        'shopify_id' => 'gid://shopify/MediaImage/7701',
    ])->saveQuietly();
    $this->gateway->nodes = [defectMediaNode('gid://shopify/MediaImage/7701')];

    $rejected = app(ShopifyImageImportService::class)->rebuildStacksForBatch($batch->fresh(), [$stack->id]);
    $stack->refresh();

    expect($rejected['rebuilt'])->toBe(0)
        ->and($batch->fresh()->rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_INCOMPLETE)
        ->and($stack->image_rebuild_message)->toContain('does not match its stored SHA-256')
        ->and($stack->allImages()->active()->where('ownership', ImageOwnership::COMPONENT_INHERITED)->where('image_asset_id', $valid->id)->count())->toBe(1)
        ->and($source->fresh()->image_asset_id)->toBe($valid->id)
        ->and($this->gateway->calls)->toBe([]);
    Http::assertNothingSent();
});

it('keeps the previous inherited photograph when a replacement backup fails verification', function (): void {
    [$stack, $component] = defectRebuildBundle('REPSTACK', ['REPCOMP']);
    $original = createImportTestAsset('original-bytes', 'jpg', 'original.jpg');
    $source = stackDefectPhotograph($component, [
        'image_asset_id' => $original->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]);
    $batch = defectRebuildBatch();
    app(ShopifyImageImportService::class)->rebuildStacksForBatch($batch, [$stack->id]);
    $kept = $stack->allImages()->active()->where('source_image_id', $source->id)->first();

    $replacement = createImportTestAsset('replacement-good', 'jpg', 'replacement.jpg');
    Storage::disk('public')->put($replacement->storage_path, 'tampered-replacement');
    Image::withoutEvents(fn () => $source->forceFill([
        'image_asset_id' => $replacement->id,
        'photograph_version' => 4,
    ])->save());

    app(ShopifyImageImportService::class)->rebuildStacksForBatch($batch->fresh(), [$stack->id]);
    $active = $stack->allImages()->active()->where('ownership', ImageOwnership::COMPONENT_INHERITED)->get();

    $stack->refresh();

    expect($active)->toHaveCount(1)
        ->and($active->first()->id)->toBe($kept->id)
        ->and($active->first()->image_asset_id)->toBe($original->id)
        ->and($stack->image_rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_INCOMPLETE)
        ->and($stack->image_rebuild_message)->toContain('does not match its stored SHA-256');

    Storage::disk('public')->put($replacement->storage_path, 'replacement-good');
    app(ShopifyImageImportService::class)->rebuildStacksForBatch($batch->fresh(), [$stack->id]);
    $replaced = $stack->allImages()->active()->where('ownership', ImageOwnership::COMPONENT_INHERITED)->get();

    expect($stack->fresh()->image_rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_COMPLETED)
        ->and($replaced)->toHaveCount(1)
        ->and($replaced->first()->image_asset_id)->toBe($replacement->id)
        ->and($replaced->first()->source_image_id)->toBe($source->id)
        ->and($kept->fresh()->sync_state)->toBe(Image::SYNC_STATE_LOCAL_DELETED);
});

it('falls back to a verified Shopify file when the local photograph cannot be read', function (): void {
    [$stack, $component] = defectRebuildBundle('LOCSTACK', ['LOCCOMP']);
    $source = stackDefectPhotograph($component, [
        'image_path' => 'component-photos/missing.jpg',
        'shopify_id' => 'gid://shopify/MediaImage/7702',
        'shopify_media_create_uncertain' => false,
    ]);
    $this->gateway->nodes = [defectMediaNode('gid://shopify/MediaImage/7702')];
    Http::fake(fn () => Http::response('shopify-fallback-bytes', 200));
    $batch = defectRebuildBatch();

    app(ShopifyImageImportService::class)->rebuildStacksForBatch($batch, [$stack->id]);
    $source->refresh();
    $inherited = $stack->allImages()->active()->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();

    expect($batch->fresh()->rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_COMPLETED)
        ->and($source->imageAsset->sha256)->toBe(hash('sha256', 'shopify-fallback-bytes'))
        ->and($inherited->source_image_id)->toBe($source->id)
        ->and(collect($this->gateway->calls)->pluck('query')->contains(
            fn (string $query): bool => preg_match('/\bmutation\b/i', $query) === 1
        ))->toBeFalse();
    expect(Http::recorded())->toHaveCount(1);
    [$request] = Http::recorded()[0];
    expect($request->method())->toBe('GET');
});

it('rejects an uncertain Shopify attachment and an unresolved upload for the same photograph', function (): void {
    $uncertainComponent = createImageImportProduct('UNCCOMP');
    $operationComponent = createImageImportProduct('OPCOMP');
    [$stack] = defectRebuildBundle('UNCSTACK', [], [$uncertainComponent, $operationComponent]);
    $uncertain = stackDefectPhotograph($uncertainComponent, [
        'shopify_id' => 'gid://shopify/MediaImage/7703',
        'shopify_media_create_uncertain' => true,
    ]);
    $operationAsset = createImportTestAsset('operation-bytes', 'jpg', 'OPCOMP.jpg');
    Storage::disk('public')->delete($operationAsset->storage_path);
    $operationImage = stackDefectPhotograph($operationComponent, [
        'shopify_id' => 'gid://shopify/MediaImage/7704',
        'shopify_media_create_uncertain' => false,
        'image_asset_id' => $operationAsset->id,
    ]);
    DB::table('image_upload_operations')->insert([
        'id' => (string) Str::uuid(),
        'image_id' => $operationImage->id,
        'active_image_id' => $operationImage->id,
        'asset_key' => hash('sha256', 'operation-'.$operationImage->id),
        'image_asset_id' => $operationAsset->id,
        'asset_sha256' => $operationAsset->sha256,
        'product_gid' => $operationComponent->shopify_id,
        'source_url' => 'https://cdn.shopify.com/s/files/1/original.jpg',
        'before_media_ids' => json_encode([]),
        'status' => 'submitting',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $this->gateway->nodes = [
        defectMediaNode('gid://shopify/MediaImage/7703'),
        defectMediaNode('gid://shopify/MediaImage/7704', 'https://cdn.shopify.com/s/files/1/operation.jpg'),
    ];
    $batch = defectRebuildBatch();

    app(ShopifyImageImportService::class)->rebuildStacksForBatch($batch, [$stack->id]);
    $stack->refresh();

    expect($batch->fresh()->rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_INCOMPLETE)
        ->and($stack->image_rebuild_message)->toContain('media create has not been reconciled')
        ->and($stack->image_rebuild_message)->toContain('unresolved Shopify upload')
        ->and($uncertain->fresh()->image_asset_id)->toBeNull()
        ->and($operationImage->fresh()->image_asset_id)->toBe($operationAsset->id)
        ->and($stack->allImages()->active()->where('ownership', ImageOwnership::COMPONENT_INHERITED)->count())->toBe(0)
        ->and($this->gateway->calls)->toBe([]);
    Http::assertNothingSent();
});

it('still rejects a Shopify file from another product or a stale photograph', function (): void {
    [$stack, $component, $other] = defectRebuildBundle('BADSTACK', ['BADCOMP', 'OTHERCOMP']);
    $previous = createImportTestAsset('previous-bytes', 'jpg', 'previous.jpg');
    stackDefectPhotograph($component, [
        'position' => 1,
        'shopify_id' => 'gid://shopify/MediaImage/7710',
        'last_shopify_synced_image_asset_id' => $previous->id,
    ]);
    stackDefectPhotograph($other, [
        'position' => 1,
        'shopify_id' => 'gid://shopify/MediaImage/7711',
    ]);
    $this->gateway->nodes = [defectMediaNode('gid://shopify/MediaImage/7710')];
    $batch = defectRebuildBatch();

    app(ShopifyImageImportService::class)->rebuildStacksForBatch($batch, [$stack->id]);

    $stack->refresh();

    expect($stack->image_rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_INCOMPLETE)
        ->and($stack->image_rebuild_message)->toContain('different photograph')
        ->and($stack->image_rebuild_message)->toContain('not attached to this component product')
        ->and($stack->allImages()->active()->where('ownership', ImageOwnership::COMPONENT_INHERITED)->count())->toBe(0);
    Http::assertNothingSent();
});

it('blocks a duplicate rebuild after the old one-hour lock would have expired', function (): void {
    Queue::fake();
    $batch = defectRebuildBatch();
    RebuildShopifyStackImagesJob::dispatch($batch->id, [8, 3]);
    $this->travel(3601)->seconds();
    RebuildShopifyStackImagesJob::dispatch($batch->id, [3, 8]);
    $later = defectRebuildBatch();
    RebuildShopifyStackImagesJob::dispatch($later->id, [8, 3]);

    Queue::assertPushed(RebuildShopifyStackImagesJob::class, 2);

    Queue::pushed(RebuildShopifyStackImagesJob::class)->first()->releaseClaim();
    RebuildShopifyStackImagesJob::dispatch($batch->id, [8, 3]);

    Queue::assertPushed(RebuildShopifyStackImagesJob::class, 3);
});

it('lets one recovery take an abandoned claim and does not rebuild while the stack lock is held', function (): void {
    [$stack, $component] = defectRebuildBundle('ABNSTACK', ['ABNCOMP']);
    $asset = createImportTestAsset('abandoned-bytes', 'jpg', 'ABNCOMP.jpg');
    stackDefectPhotograph($component, ['image_asset_id' => $asset->id]);
    $batch = defectRebuildBatch();
    Queue::fake();
    RebuildShopifyStackImagesJob::dispatch($batch->id, [$stack->id]);
    $key = UniqueLock::getKey(new RebuildShopifyStackImagesJob($batch->id, [$stack->id]));
    DB::table('stack_rebuild_claims')->where('claim_key', $key)->update([
        'started_at' => now()->subHours(3),
        'heartbeat_at' => now()->subHours(3),
    ]);

    $first = app(StackRebuildLockStore::class)->lock($key);
    $second = app(StackRebuildLockStore::class)->lock($key);

    expect($first->get())->toBeTrue()
        ->and($second->get())->toBeFalse();

    $gallery = Cache::lock(ProductShopifyUpdater::IMAGE_SYNC_LOCK_PREFIX.$stack->id, 3600);
    expect($gallery->get())->toBeTrue();
    (new RebuildShopifyStackImagesJob($batch->id, [$stack->id]))->handle(app(ShopifyImageImportService::class));
    $gallery->release();

    expect($stack->allImages()->count())->toBe(0)
        ->and($stack->fresh()->image_rebuild_message)->toContain('Another image operation is running');
});

it('updates pending products owned by a failed worker and leaves a newer batch alone', function (): void {
    $owned = createImageImportProduct('OWNEDSTACK', ['type' => 'Bundle', 'is_bundle' => true]);
    $newer = createImageImportProduct('NEWERSTACK', ['type' => 'Bundle', 'is_bundle' => true]);
    $batch = defectRebuildBatch();
    $newerBatch = defectRebuildBatch();
    $batch->forceFill([
        'status' => ShopifyImageImportBatch::STATUS_COMPLETED,
        'rebuild_status' => ShopifyImageImportBatch::REBUILD_PENDING,
        'rebuild_stack_product_ids' => [$owned->id],
    ])->save();
    Product::withoutEvents(function () use ($owned, $newer, $batch, $newerBatch): void {
        $owned->forceFill([
            'image_import_status' => 'updated',
            'image_rebuild_status' => ShopifyImageImportBatch::REBUILD_PENDING,
            'image_rebuild_batch_id' => $batch->id,
        ])->save();
        $newer->forceFill([
            'image_import_status' => 'updated',
            'image_rebuild_status' => ShopifyImageImportBatch::REBUILD_PENDING,
            'image_rebuild_batch_id' => $newerBatch->id,
        ])->save();
    });

    (new RebuildShopifyStackImagesJob($batch->id, [$owned->id, $newer->id]))
        ->failed(new RuntimeException('The worker stopped before staging finished.'));

    $owned->refresh();
    $newer->refresh();
    $batch->refresh();

    expect($batch->status)->toBe(ShopifyImageImportBatch::STATUS_COMPLETED)
        ->and($batch->rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_FAILED)
        ->and($batch->rebuild_message)->toContain('worker stopped')
        ->and($owned->image_import_status)->toBe('updated')
        ->and($owned->image_rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_FAILED)
        ->and($owned->image_rebuild_message)->toContain('worker stopped')
        ->and($newer->image_rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_PENDING)
        ->and($newer->image_rebuild_batch_id)->toBe($newerBatch->id);
});

it('restores a retryable failure when dispatch throws and a later success clears it', function (): void {
    [$stack, $component] = defectRebuildBundle('RETSTACK', ['RETCOMP']);
    $asset = createImportTestAsset('retry-bytes', 'jpg', 'RETCOMP.jpg');
    stackDefectPhotograph($component, [
        'image_asset_id' => $asset->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]);
    $batch = defectRebuildBatch();
    $batch->forceFill([
        'status' => ShopifyImageImportBatch::STATUS_COMPLETED,
        'rebuild_status' => ShopifyImageImportBatch::REBUILD_PENDING,
        'rebuild_message' => null,
        'rebuild_stack_product_ids' => [$stack->id],
    ])->save();
    Product::withoutEvents(fn () => $stack->forceFill([
        'image_import_status' => 'updated',
        'image_rebuild_status' => ShopifyImageImportBatch::REBUILD_PENDING,
        'image_rebuild_message' => null,
        'image_rebuild_batch_id' => $batch->id,
    ])->save());

    RebuildShopifyStackImagesJob::markDispatchFailed(
        $batch->fresh(),
        [$stack->id],
        new RuntimeException('The queue refused the rebuild.'),
    );
    $stack->refresh();
    $batch->refresh();

    expect($batch->status)->toBe(ShopifyImageImportBatch::STATUS_COMPLETED)
        ->and($batch->rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_FAILED)
        ->and($batch->rebuild_message)->toContain('queue refused')
        ->and($stack->image_import_status)->toBe('updated')
        ->and($stack->image_rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_FAILED)
        ->and($stack->image_rebuild_message)->toContain('queue refused');

    (new RebuildShopifyStackImagesJob($batch->id, [$stack->id]))->handle(app(ShopifyImageImportService::class));
    $stack->refresh();
    $batch->refresh();

    expect($batch->status)->toBe(ShopifyImageImportBatch::STATUS_COMPLETED)
        ->and($batch->rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_COMPLETED)
        ->and($batch->rebuild_message)->not->toContain('queue refused')
        ->and($stack->image_import_status)->toBe('updated')
        ->and($stack->image_rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_COMPLETED)
        ->and($stack->image_rebuild_message)->not->toContain('queue refused')
        ->and($stack->allImages()->active()->where('ownership', ImageOwnership::COMPONENT_INHERITED)->count())->toBe(1);
});

function defectRebuildBundle(string $stackSku, array $componentSkus, array $components = []): array
{
    foreach ($componentSkus as $sku) {
        $components[] = createImageImportProduct($sku);
    }
    $stack = createImageImportProduct($stackSku, [
        'type' => 'Bundle',
        'is_bundle' => true,
    ]);
    NewProductDraft::withoutEvents(fn (): NewProductDraft => NewProductDraft::create([
        'sku' => $stackSku,
        'shopify_id' => $stack->shopify_id,
        'handle' => $stack->handle,
        'title' => $stack->title,
        'type' => 'Bundle',
        'tags' => 'bundles',
        'status' => 'active',
        'bundle_product_ids' => array_map(fn (Product $component): int => (int) $component->id, $components),
    ]));

    return [$stack, ...$components];
}

function stackDefectPhotograph(Product $product, array $overrides = []): Image
{
    return Image::withoutEvents(fn (): Image => Image::create(array_merge([
        'product_id' => $product->id,
        'position' => 1,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'local_dirty' => false,
        'needs_shopify_image_sync' => false,
        'photograph_version' => 3,
        'alt_text_version' => 1,
        'alt_text' => 'Component sentence',
        'alt_text_status' => Image::ALT_APPROVED,
    ], $overrides)));
}

function defectRebuildBatch(): ShopifyImageImportBatch
{
    return ShopifyImageImportBatch::create([
        's3_prefix' => 'incoming/defect-'.Str::lower(Str::random(8)),
        'status' => ShopifyImageImportBatch::STATUS_PENDING,
    ]);
}

function defectMediaNode(string $id, string $original = 'https://cdn.shopify.com/s/files/1/original.jpg'): array
{
    return [
        'id' => $id,
        'status' => 'READY',
        'fileStatus' => 'READY',
        'image' => ['url' => 'https://cdn.shopify.com/s/files/1/preview.jpg'],
        'originalSource' => ['url' => $original],
    ];
}

class StackRebuildDefectGateway implements ShopifyGraphqlGateway
{
    public array $calls = [];

    public array $nodes = [];

    public function graphql(string $query, array $variables = []): array
    {
        if (preg_match('/\bmutation\b/i', $query) === 1) {
            throw new RuntimeException('Shopify mutation during local rebuild.');
        }

        $this->calls[] = ['query' => $query, 'variables' => $variables];
        $field = str_contains($query, 'images(') ? 'images' : 'media';

        return [
            'product' => [
                $field => [
                    'nodes' => $field === 'media' ? $this->nodes : [],
                    'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                ],
            ],
        ];
    }
}
