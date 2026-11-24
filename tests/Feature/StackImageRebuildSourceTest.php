<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Jobs\ImportShopifyProductImagesJob;
use App\Jobs\RebuildShopifyStackImagesJob;
use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyImageImportBatch;
use App\Services\ImageOwnership;
use App\Services\ShopifyImageImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

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
    $this->gateway = new StackRebuildGateway;
    app()->instance(ShopifyGraphqlGateway::class, $this->gateway);
});

it('rebuilds a directly imported stack while all three stack flags are disabled', function (): void {
    [$stack, $component] = stackRebuildBundle('STACKREB', ['COMPREB']);
    $asset = createImportTestAsset('managed-component', 'jpg', 'COMPREB.jpg');
    $source = stackComponentPhotograph($component, [
        'image_asset_id' => $asset->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
        'photograph_version' => 3,
    ]);
    Product::withoutEvents(fn () => $stack->forceFill(['approval_version' => 4])->save());
    Storage::disk('shopify_product_images')->put('incoming/stack-flags/STACKREB.png', 'stack-owned-bytes');

    $batch = runStackImageImport('incoming/stack-flags');
    $stack->refresh();
    $source->refresh();
    $images = $stack->allImages()->active()->orderBy('position')->get();

    expect($batch->status)->toBe(ShopifyImageImportBatch::STATUS_COMPLETED)
        ->and($batch->rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_COMPLETED)
        ->and($stack->image_import_status)->toBe('updated')
        ->and($stack->image_rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_COMPLETED)
        ->and($stack->approval_version)->toBe(4)
        ->and($images)->toHaveCount(2)
        ->and($images[0]->ownership)->toBe(ImageOwnership::STACK_OWNED)
        ->and($images[0]->position)->toBe(1)
        ->and($images[0]->source_image_id)->toBeNull()
        ->and($images[1]->ownership)->toBe(ImageOwnership::COMPONENT_INHERITED)
        ->and($images[1]->position)->toBe(2)
        ->and($images[1]->source_image_id)->toBe($source->id)
        ->and($images[1]->image_asset_id)->toBe($asset->id)
        ->and($source->image_asset_id)->toBe($asset->id)
        ->and($source->photograph_version)->toBe(3)
        ->and($this->gateway->calls)->toBe([])
        ->and(config('images.component_stack_propagation'))->toBeFalse()
        ->and(config('images.component_stack_local_rebuild'))->toBeFalse()
        ->and(config('images.component_stack_blind_publication'))->toBeFalse();
    Http::assertNothingSent();
});

it('reuses an existing managed backup without reading Shopify', function (): void {
    [$stack, $component] = stackRebuildBundle('STACKBAK', ['COMPBAK']);
    $asset = createImportTestAsset('backup-bytes', 'png', 'COMPBAK.png');
    $source = stackComponentPhotograph($component, [
        'image_asset_id' => $asset->id,
        'src' => 'https://cdn.shopify.com/s/files/1/do-not-fetch.jpg',
        'shopify_cdn_url' => 'https://cdn.shopify.com/s/files/1/do-not-fetch.jpg',
        'shopify_id' => 'gid://shopify/MediaImage/42',
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
        'photograph_version' => 6,
        'alt_text_status' => Image::ALT_APPROVED,
    ]);
    Storage::disk('shopify_product_images')->put('incoming/stack-backup/STACKBAK.png', 'owned');

    runStackImageImport('incoming/stack-backup');
    $source->refresh();

    expect($source->image_asset_id)->toBe($asset->id)
        ->and($source->photograph_version)->toBe(6)
        ->and($source->alt_text_status)->toBe(Image::ALT_APPROVED)
        ->and($source->shopify_id)->toBe('gid://shopify/MediaImage/42')
        ->and($stack->allImages()->active()->where('image_asset_id', $asset->id)->count())->toBe(1)
        ->and($this->gateway->calls)->toBe([]);
    Http::assertNothingSent();
});

it('stores a readable local photograph as the managed backup', function (): void {
    [$stack, $component] = stackRebuildBundle('STACKLOC', ['COMPLOC']);
    Storage::disk('public')->put('component-photos/COMPLOC.jpg', 'local-only-bytes');
    $source = stackComponentPhotograph($component, [
        'image_path' => 'component-photos/COMPLOC.jpg',
        'photograph_version' => 2,
        'alt_text_status' => Image::ALT_APPROVED,
        'shopify_media_create_uncertain' => false,
    ]);
    Storage::disk('shopify_product_images')->put('incoming/stack-local/STACKLOC.png', 'owned');

    runStackImageImport('incoming/stack-local');
    $source->refresh();
    $inherited = $stack->allImages()->active()->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();

    expect($source->image_asset_id)->not->toBeNull()
        ->and($source->imageAsset->sha256)->toBe(hash('sha256', 'local-only-bytes'))
        ->and($source->photograph_version)->toBe(2)
        ->and($source->alt_text_status)->toBe(Image::ALT_APPROVED)
        ->and($source->needs_shopify_image_sync)->toBeFalse()
        ->and($source->shopify_media_create_uncertain)->toBeFalse()
        ->and($inherited)->not->toBeNull()
        ->and($inherited->image_asset_id)->toBe($source->image_asset_id)
        ->and($inherited->source_image_id)->toBe($source->id)
        ->and($this->gateway->calls)->toBe([]);
    Http::assertNothingSent();
});

it('downloads a Shopify file only when its MediaImage is attached to that component', function (): void {
    [$stack, $component] = stackRebuildBundle('STACKSHOP', ['COMPSHOP']);
    $source = stackComponentPhotograph($component, [
        'shopify_id' => 'gid://shopify/MediaImage/8801',
        'src' => 'https://cdn.shopify.com/s/files/1/unverified-preview.jpg',
        'photograph_version' => 5,
        'alt_text_status' => Image::ALT_APPROVED,
        'shopify_media_create_uncertain' => false,
    ]);
    $this->gateway->nodes = [stackRebuildMediaNode('gid://shopify/MediaImage/8801')];
    Http::fake(fn () => Http::response('verified-shopify-bytes', 200));
    Storage::disk('shopify_product_images')->put('incoming/stack-shopify/STACKSHOP.png', 'owned');

    runStackImageImport('incoming/stack-shopify');
    $source->refresh();
    $inherited = $stack->allImages()->active()->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $queries = array_column($this->gateway->calls, 'query');

    expect($source->imageAsset->sha256)->toBe(hash('sha256', 'verified-shopify-bytes'))
        ->and($source->photograph_version)->toBe(5)
        ->and($source->alt_text_status)->toBe(Image::ALT_APPROVED)
        ->and($source->needs_shopify_image_sync)->toBeFalse()
        ->and($source->shopify_media_create_uncertain)->toBeFalse()
        ->and($source->shopify_id)->toBe('gid://shopify/MediaImage/8801')
        ->and($inherited->image_asset_id)->toBe($source->image_asset_id)
        ->and($inherited->source_image_id)->toBe($source->id)
        ->and($queries)->not->toBeEmpty()
        ->and(collect($queries)->contains(fn (string $query): bool => preg_match('/\bmutation\b/i', $query) === 1))->toBeFalse()
        ->and($this->gateway->calls[0]['variables']['id'])->toBe($component->shopify_id);
    expect(Http::recorded())->toHaveCount(1);
    [$request] = Http::recorded()[0];
    expect($request->method())->toBe('GET')
        ->and($request->url())->toBe('https://cdn.shopify.com/s/files/1/original.jpg');
});

it('stages mixed managed, local, and verified Shopify photographs in component order', function (): void {
    $first = createImageImportProduct('MIXA');
    $second = createImageImportProduct('MIXB');
    $third = createImageImportProduct('MIXC');
    $stack = stackRebuildBundle('STACKMIX', [], [$first, $second, $third]);
    $managed = createImportTestAsset('mix-managed', 'jpg', 'MIXA.jpg');
    $firstImage = stackComponentPhotograph($first, [
        'image_asset_id' => $managed->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]);
    Storage::disk('public')->put('component-photos/MIXB.jpg', 'mix-local');
    $secondImage = stackComponentPhotograph($second, [
        'image_path' => 'component-photos/MIXB.jpg',
    ]);
    $thirdImage = stackComponentPhotograph($third, [
        'shopify_id' => 'gid://shopify/MediaImage/8802',
    ]);
    $this->gateway->nodes = [stackRebuildMediaNode('gid://shopify/MediaImage/8802', 'https://cdn.shopify.com/s/files/1/mix-original.jpg')];
    Http::fake(fn () => Http::response('mix-shopify', 200));
    Storage::disk('shopify_product_images')->put('incoming/stack-mix/STACKMIX.png', 'owned-front');

    runStackImageImport('incoming/stack-mix');
    $images = $stack->allImages()->active()->orderBy('position')->get();

    expect($images)->toHaveCount(4)
        ->and($images->pluck('ownership')->all())->toBe([
            ImageOwnership::STACK_OWNED,
            ImageOwnership::COMPONENT_INHERITED,
            ImageOwnership::COMPONENT_INHERITED,
            ImageOwnership::COMPONENT_INHERITED,
        ])
        ->and($images->pluck('position')->all())->toBe([1, 2, 3, 4])
        ->and($images[0]->source_image_id)->toBeNull()
        ->and($images[1]->source_image_id)->toBe($firstImage->id)
        ->and($images[1]->image_asset_id)->toBe($managed->id)
        ->and($images[2]->source_image_id)->toBe($secondImage->id)
        ->and($images[2]->imageAsset->sha256)->toBe(hash('sha256', 'mix-local'))
        ->and($images[3]->source_image_id)->toBe($thirdImage->id)
        ->and($images[3]->imageAsset->sha256)->toBe(hash('sha256', 'mix-shopify'));
    expect(collect($this->gateway->calls)->pluck('query')->contains(
        fn (string $query): bool => preg_match('/\bmutation\b/i', $query) === 1
    ))->toBeFalse();
});

it('rejects an untrusted URL, stale media, and media attached to another product', function (): void {
    [$stack, $component, $stale, $unattached] = stackRebuildBundle('STACKBAD', ['COMPBAD', 'COMPSTALE', 'COMPOTHER']);
    stackComponentPhotograph($component, [
        'position' => 1,
        'src' => 'https://example.com/url-only.jpg',
    ]);
    $previous = createImportTestAsset('previous-photograph', 'jpg', 'previous.jpg');
    stackComponentPhotograph($stale, [
        'shopify_id' => 'gid://shopify/MediaImage/8803',
        'last_shopify_synced_image_asset_id' => $previous->id,
    ]);
    stackComponentPhotograph($unattached, [
        'shopify_id' => 'gid://shopify/MediaImage/9999',
    ]);
    $this->gateway->nodes = [stackRebuildMediaNode('gid://shopify/MediaImage/8803')];
    Storage::disk('shopify_product_images')->put('incoming/stack-bad/STACKBAD.png', 'owned');

    $batch = runStackImageImport('incoming/stack-bad');
    $stack->refresh();

    expect($batch->status)->toBe(ShopifyImageImportBatch::STATUS_COMPLETED)
        ->and($batch->rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_INCOMPLETE)
        ->and($stack->image_import_status)->toBe('updated')
        ->and($stack->image_rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_INCOMPLETE)
        ->and($stack->image_rebuild_message)->toContain('could not be downloaded')
        ->and($stack->image_rebuild_message)->toContain('different photograph')
        ->and($stack->image_rebuild_message)->toContain('not attached to this component product')
        ->and($stack->allImages()->active()->where('ownership', ImageOwnership::COMPONENT_INHERITED)->count())->toBe(0);
    Http::assertNothingSent();
});

it('uses a verified Shopify attachment when the local photograph cannot be read', function (): void {
    [$stack, $component] = stackRebuildBundle('STACKMISS', ['COMPMISS']);
    $source = stackComponentPhotograph($component, [
        'image_path' => 'component-photos/missing.jpg',
        'shopify_id' => 'gid://shopify/MediaImage/8804',
    ]);
    $this->gateway->nodes = [stackRebuildMediaNode('gid://shopify/MediaImage/8804')];
    Http::fake(fn () => Http::response('recovered-from-shopify', 200));
    Storage::disk('shopify_product_images')->put('incoming/stack-missing/STACKMISS.png', 'owned');

    $batch = runStackImageImport('incoming/stack-missing');
    $source->refresh();
    $inherited = $stack->allImages()->active()->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();

    expect($batch->rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_COMPLETED)
        ->and($source->imageAsset->sha256)->toBe(hash('sha256', 'recovered-from-shopify'))
        ->and($inherited->source_image_id)->toBe($source->id)
        ->and(collect($this->gateway->calls)->pluck('query')->contains(
            fn (string $query): bool => preg_match('/\bmutation\b/i', $query) === 1
        ))->toBeFalse();
});

it('keeps the stack owned photograph at its requested position', function (): void {
    [$stack, $component] = stackRebuildBundle('STACKPOS', ['COMPPOS']);
    $source = stackComponentPhotograph($component, [
        'image_asset_id' => createImportTestAsset('position-component', 'jpg', 'COMPPOS.jpg')->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]);
    Storage::disk('shopify_product_images')->put('incoming/stack-position/STACKPOS.png', 'front-owned');

    runStackImageImport('incoming/stack-position');
    $images = $stack->allImages()->active()->orderBy('position')->get();

    expect($images)->toHaveCount(2)
        ->and($images[0]->position)->toBe(1)
        ->and($images[0]->ownership)->toBe(ImageOwnership::STACK_OWNED)
        ->and($images[0]->s3_filename)->toBe('STACKPOS.png')
        ->and($images[0]->source_image_id)->toBeNull()
        ->and($images[1]->position)->toBe(2)
        ->and($images[1]->ownership)->toBe(ImageOwnership::COMPONENT_INHERITED)
        ->and($images[1]->source_image_id)->toBe($source->id);
});

it('places only component primaries in component order after two stack owned images', function (): void {
    $first = createImageImportProduct('ORDA');
    $second = createImageImportProduct('ORDB');
    $stack = stackRebuildBundle('STACKORD', [], [$first, $second]);
    $firstFront = stackComponentPhotograph($first, [
        'position' => 1,
        'image_asset_id' => createImportTestAsset('order-a1', 'jpg', 'ORDA.jpg')->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]);
    stackComponentPhotograph($first, [
        'position' => 2,
        'image_asset_id' => createImportTestAsset('order-a2', 'jpg', 'ORDA-1.jpg')->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]);
    $secondFront = stackComponentPhotograph($second, [
        'position' => 1,
        'image_asset_id' => createImportTestAsset('order-b1', 'jpg', 'ORDB.jpg')->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]);
    Storage::disk('shopify_product_images')->put('incoming/stack-order/STACKORD.png', 'owned-order');
    Storage::disk('shopify_product_images')->put('incoming/stack-order/STACKORD-1.png', 'second-owned-order');

    $batch = runStackImageImport('incoming/stack-order');
    $images = $stack->allImages()->active()->orderBy('position')->get();
    $ids = $images->pluck('id')->all();
    (new RebuildShopifyStackImagesJob($batch->id, [$stack->id]))->handle(app(ShopifyImageImportService::class));

    expect($images->pluck('ownership')->all())->toBe([
        ImageOwnership::STACK_OWNED,
        ImageOwnership::STACK_OWNED,
        ImageOwnership::COMPONENT_INHERITED,
        ImageOwnership::COMPONENT_INHERITED,
    ])
        ->and($images->pluck('position')->all())->toBe([1, 2, 3, 4])
        ->and($images->pluck('source_image_id')->all())->toBe([null, null, $firstFront->id, $secondFront->id])
        ->and($stack->allImages()->active()->orderBy('position')->pluck('id')->all())->toBe($ids);
});

it('reuses a legacy primary with a Shopify URL and gives new inherited rows a display URL', function (): void {
    [$stack, $fromUrl, $fromBackup, $withoutPrimary] = stackRebuildBundle('STACKLEGACY', ['URLPRIMARY', 'BACKUPPRIMARY', 'NOSUBSTITUTE']);
    $url = 'https://cdn.shopify.com/s/files/1/primary.png';
    $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+p2wAAAABJRU5ErkJggg==');
    Http::fake([$url => Http::response($bytes)]);
    $source = stackComponentPhotograph($fromUrl, ['src' => $url, 'alt_text' => 'URL primary']);
    $legacy = stackComponentPhotograph($stack, [
        'position' => 2, 'src' => $url, 'shopify_id' => 'gid://shopify/MediaImage/555',
    ]);
    $backup = stackComponentPhotograph($fromBackup, [
        'image_asset_id' => createImportTestAsset('another photograph', 'jpg', 'primary.jpg')->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP, 'alt_text' => 'Backup primary',
    ]);
    stackComponentPhotograph($withoutPrimary, [
        'position' => 2, 'image_asset_id' => createImportTestAsset('lifestyle', 'jpg', 'second.jpg')->id,
    ]);
    Storage::disk('shopify_product_images')->put('incoming/legacy-primary/STACKLEGACY.png', 'stack lifestyle');

    $batch = runStackImageImport('incoming/legacy-primary');
    $images = $stack->images()->orderBy('position')->get();
    expect($batch->rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_INCOMPLETE)
        ->and($images->pluck('source_image_id')->all())->toBe([null, $source->id, $backup->id])
        ->and($images[1]->id)->toBe($legacy->id)
        ->and($images[1]->shopify_id)->toBe('gid://shopify/MediaImage/555')
        ->and($images[1]->alt_text)->toBe('URL primary')
        ->and($images[2]->src)->toContain('/product-image-backups/')
        ->and($images[2]->alt_text)->toBe('Backup primary');

    runStackImageImport('incoming/legacy-primary');
    expect($stack->images()->orderBy('position')->pluck('id')->all())->toBe($images->pluck('id')->all())
        ->and($this->gateway->calls)->toBe([]);
    Http::assertSentCount(1);
});

it('does not queue a second rebuild for the same batch and stack revision', function (): void {
    Queue::fake();
    $batch = ShopifyImageImportBatch::create([
        's3_prefix' => 'incoming/unique-rebuild',
        'status' => ShopifyImageImportBatch::STATUS_COMPLETED,
    ]);
    RebuildShopifyStackImagesJob::dispatch($batch->id, [10, 4]);
    RebuildShopifyStackImagesJob::dispatch($batch->id, [4, 10]);
    $later = ShopifyImageImportBatch::create([
        's3_prefix' => 'incoming/unique-rebuild-later',
        'status' => ShopifyImageImportBatch::STATUS_COMPLETED,
    ]);
    RebuildShopifyStackImagesJob::dispatch($later->id, [10, 4]);

    Queue::assertPushed(RebuildShopifyStackImagesJob::class, 2);
});

it('does not import an ordinary product into a stack rebuild', function (): void {
    $product = createImageImportProduct('ORDINARY');
    Storage::disk('shopify_product_images')->put('incoming/ordinary/ORDINARY.png', 'plain-photo');

    $batch = runStackImageImport('incoming/ordinary');
    $product->refresh();

    expect($batch->status)->toBe(ShopifyImageImportBatch::STATUS_COMPLETED)
        ->and($batch->rebuild_status)->toBeNull()
        ->and($product->image_import_status)->toBe('updated')
        ->and($product->image_rebuild_status)->toBeNull()
        ->and($product->allImages()->count())->toBe(1);
});

it('replaces an inherited photograph when the component photograph changes', function (): void {
    [$stack, $component] = stackRebuildBundle('STACKNEW', ['COMPNEW']);
    $original = createImportTestAsset('original-component', 'jpg', 'COMPNEW.jpg');
    $source = stackComponentPhotograph($component, [
        'image_asset_id' => $original->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
        'photograph_version' => 3,
    ]);
    Storage::disk('shopify_product_images')->put('incoming/stack-replace/STACKNEW.png', 'owned');

    $batch = runStackImageImport('incoming/stack-replace');
    $inherited = $stack->allImages()->active()->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    expect($inherited->approved_filename)->toBe($stack->handle.'-2-v1.jpg');
    $replacement = createImportTestAsset('replacement-component', 'jpg', 'COMPNEW-v2.jpg');
    Image::withoutEvents(fn () => $source->forceFill([
        'image_asset_id' => $replacement->id,
        'photograph_version' => 4,
    ])->save());

    (new RebuildShopifyStackImagesJob($batch->id, [$stack->id]))->handle(app(ShopifyImageImportService::class));
    $inherited->refresh();
    $active = $stack->allImages()->active()->where('ownership', ImageOwnership::COMPONENT_INHERITED)->get();

    expect($active)->toHaveCount(1)
        ->and($active->first()->id)->not->toBe($inherited->id)
        ->and($active->first()->image_asset_id)->toBe($replacement->id)
        ->and($active->first()->source_image_id)->toBe($source->id)
        ->and($active->first()->approved_filename)->toBe($stack->handle.'-2-v2.jpg')
        ->and($active->first()->filename_version)->toBe(2)
        ->and($active->first()->photograph_version)->toBe(4)
        ->and($inherited->sync_state)->toBe(Image::SYNC_STATE_LOCAL_DELETED)
        ->and($stack->allImages()->active()->where('ownership', ImageOwnership::STACK_OWNED)->first()->position)->toBe(1);

    app(\App\Services\ComponentStackPropagation::class)->stageStack($stack->fresh());
    $copies = $stack->allImages()->active()->where('ownership', ImageOwnership::COMPONENT_INHERITED)->get();
    expect($copies->modelKeys())->toBe($active->modelKeys())
        ->and($copies->first()->approved_filename)->toBe($stack->handle.'-2-v2.jpg');

    Storage::disk('shopify_product_images')->put('incoming/stack-second/STACKNEW-1.png', 'second-owned');
    runStackImageImport('incoming/stack-second');
    $secondOwned = $stack->allImages()->active()->where('position', 2)->first();
    expect($secondOwned->ownership)->toBe(ImageOwnership::STACK_OWNED)
        ->and($secondOwned->approved_filename)->toBe($stack->handle.'-2-v3.png')
        ->and($copies->first()->fresh()->position)->toBe(3)
        ->and($copies->first()->fresh()->approved_filename)->toBe($stack->handle.'-2-v2.jpg');
});

it('keeps photographs that were staged when another component source is missing', function (): void {
    $ready = createImageImportProduct('PARTA');
    $missing = createImageImportProduct('PARTB');
    $stack = stackRebuildBundle('STACKPART', [], [$ready, $missing]);
    $asset = createImportTestAsset('partial-ready', 'jpg', 'PARTA.jpg');
    $readyImage = stackComponentPhotograph($ready, [
        'image_asset_id' => $asset->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
    ]);
    $missingImage = stackComponentPhotograph($missing, [
        'image_path' => 'component-photos/part-missing.jpg',
    ]);
    Storage::disk('shopify_product_images')->put('incoming/stack-partial/STACKPART.png', 'owned');

    $batch = runStackImageImport('incoming/stack-partial');
    $stack->refresh();
    $kept = $stack->allImages()->active()->where('source_image_id', $readyImage->id)->first();

    expect($batch->status)->toBe(ShopifyImageImportBatch::STATUS_COMPLETED)
        ->and($batch->rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_INCOMPLETE)
        ->and($stack->image_import_status)->toBe('updated')
        ->and($stack->image_rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_INCOMPLETE)
        ->and($kept)->not->toBeNull()
        ->and($kept->image_asset_id)->toBe($asset->id)
        ->and($this->gateway->calls)->toBe([]);

    Storage::disk('public')->put('component-photos/part-missing.jpg', 'recovered-bytes');
    (new RebuildShopifyStackImagesJob($batch->id, $batch->rebuild_stack_product_ids))->handle(app(ShopifyImageImportService::class));
    $stack->refresh();
    $batch->refresh();
    $inherited = $stack->allImages()->active()->where('ownership', ImageOwnership::COMPONENT_INHERITED)->orderBy('position')->get();

    expect($batch->status)->toBe(ShopifyImageImportBatch::STATUS_COMPLETED)
        ->and($batch->rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_COMPLETED)
        ->and($stack->image_import_status)->toBe('updated')
        ->and($stack->image_rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_COMPLETED)
        ->and($inherited)->toHaveCount(2)
        ->and($inherited[0]->id)->toBe($kept->id)
        ->and($inherited[1]->source_image_id)->toBe($missingImage->id)
        ->and($inherited[1]->imageAsset->sha256)->toBe(hash('sha256', 'recovered-bytes'))
        ->and($this->gateway->calls)->toBe([]);
    Http::assertNothingSent();
});

it('reports a failed rebuild separately when component membership is unknown', function (): void {
    $stack = createImageImportProduct('STACKUNK', [
        'type' => 'Bundle',
        'is_bundle' => true,
    ]);
    Storage::disk('shopify_product_images')->put('incoming/stack-unknown/STACKUNK.png', 'owned-without-draft');

    $batch = runStackImageImport('incoming/stack-unknown');
    $stack->refresh();

    expect($batch->status)->toBe(ShopifyImageImportBatch::STATUS_COMPLETED)
        ->and($batch->rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_FAILED)
        ->and($batch->rebuild_message)->toContain('component membership is unknown')
        ->and($stack->image_import_status)->toBe('updated')
        ->and($stack->image_rebuild_status)->toBe(ShopifyImageImportBatch::REBUILD_FAILED)
        ->and($stack->image_rebuild_message)->toContain('component membership is unknown')
        ->and($stack->allImages()->active()->count())->toBe(1)
        ->and($stack->allImages()->active()->first()->ownership)->toBe(ImageOwnership::STACK_OWNED);
});

it('does not rebuild a parent stack from a component import while local rebuild is disabled', function (): void {
    [$stack, $component] = stackRebuildBundle('STACKDEP', ['COMPDEP']);
    Storage::disk('shopify_product_images')->put('incoming/stack-dependent/COMPDEP.jpg', 'component-only');

    $batch = runStackImageImport('incoming/stack-dependent');

    expect($batch->status)->toBe(ShopifyImageImportBatch::STATUS_COMPLETED)
        ->and($batch->rebuild_status)->toBeNull()
        ->and($stack->fresh()->image_rebuild_status)->toBeNull()
        ->and($stack->allImages()->count())->toBe(0)
        ->and($component->allImages()->count())->toBe(1)
        ->and(config('images.component_stack_local_rebuild'))->toBeFalse();
});

/**
 * @param  array<int, Product>  $components
 */
function stackRebuildBundle(string $stackSku, array $componentSkus = [], array $components = []): array|Product
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
        'bundle_product_ids' => array_map(fn (Product $component): int => $component->id, $components),
    ]));

    return $componentSkus === [] && count($components) !== 1 ? $stack : [$stack, ...$components];
}

function stackComponentPhotograph(Product $product, array $overrides = []): Image
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

function runStackImageImport(string $prefix): ShopifyImageImportBatch
{
    $batch = ShopifyImageImportBatch::create([
        's3_prefix' => $prefix,
        'status' => ShopifyImageImportBatch::STATUS_PENDING,
    ]);
    app(ImportShopifyProductImagesJob::class, [
        'batchId' => $batch->id,
    ])->handle(app(ShopifyImageImportService::class));

    return $batch->fresh();
}

function stackRebuildMediaNode(string $id, string $original = 'https://cdn.shopify.com/s/files/1/original.jpg'): array
{
    return [
        'id' => $id,
        'status' => 'READY',
        'fileStatus' => 'READY',
        'image' => ['url' => 'https://cdn.shopify.com/s/files/1/preview.jpg'],
        'originalSource' => ['url' => $original],
    ];
}

class StackRebuildGateway implements ShopifyGraphqlGateway
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
