<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\User;
use App\Services\ComponentStackPropagation;
use App\Services\ProductShopifyUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/shopify-image-import.php';

/**
 * Records the same Shopify calls the independent audit used, and separates
 * association removal from any other file update.
 */
final class CriticalDefectRemote implements ShopifyGraphqlGateway
{
    public array $nodes = [];

    public array $bodies = [];

    public int $creates = 0;

    public int $removals = 0;

    public array $removedIds = [];

    public int $appends = 0;

    public array $variantIds = [];

    public array $variantMediaIds = [];

    public bool $processing = false;

    public bool $keepRemovedMedia = false;

    public ?\Closure $onMediaRead = null;

    public ?\Closure $onVariantRead = null;

    public function __construct(public string $bytes) {}

    public function node(string $id): array
    {
        $url = 'https://cdn.shopify.com/s/files/critical-defect-'.preg_replace('/\D/', '', $id).'.jpg';

        return [
            'id' => $id,
            'status' => 'READY',
            'fileStatus' => 'READY',
            'alt' => null,
            'image' => ['url' => $url],
            'originalSource' => ['url' => $url],
        ];
    }

    public function graphql(string $query, array $variables = []): array
    {
        if (str_contains($query, 'productCreateMedia')) {
            $this->creates++;
            $id = 'gid://shopify/MediaImage/'.(9700 + $this->creates);
            $this->nodes[] = $this->node($id);

            return ['productCreateMedia' => ['media' => [['id' => $id]], 'mediaUserErrors' => []]];
        }
        if (str_contains($query, 'fileUpdate')) {
            $file = $variables['files'][0] ?? [];
            $id = (string) ($file['id'] ?? '');
            if (array_key_exists('referencesToRemove', $file)) {
                $this->removals++;
                $this->removedIds[] = $id;
                if (! $this->keepRemovedMedia) {
                    $this->nodes = array_values(array_filter($this->nodes, fn (array $node): bool => $node['id'] !== $id));
                }
            }

            return ['fileUpdate' => ['files' => [['id' => $id]], 'userErrors' => []]];
        }
        if (str_contains($query, 'productReorderMedia')) {
            return ['productReorderMedia' => ['mediaUserErrors' => []]];
        }
        if (str_contains($query, 'productVariantAppendMedia')) {
            $this->appends++;
            foreach ((array) ($variables['variantMedia'][0]['mediaIds'] ?? []) as $id) {
                $this->variantMediaIds[] = (string) $id;
            }

            return ['productVariantAppendMedia' => ['userErrors' => []]];
        }
        if (str_contains($query, 'productVariantDetachMedia')) {
            $removed = (array) ($variables['variantMedia'][0]['mediaIds'] ?? []);
            $this->variantMediaIds = array_values(array_diff($this->variantMediaIds, $removed));

            return ['productVariantDetachMedia' => ['userErrors' => []]];
        }
        if (str_contains($query, 'StackRetirementVariantMedia')) {
            if ($this->onVariantRead instanceof \Closure) {
                ($this->onVariantRead)();
            }

            return ['productVariant' => ['media' => [
                'nodes' => array_map(fn (string $id): array => ['id' => $id], $this->variantMediaIds),
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            ]]];
        }
        if (str_contains($query, 'StackRetirementMedia') && $this->onMediaRead instanceof \Closure) {
            ($this->onMediaRead)();
        }

        $nodes = $this->nodes;
        if ($this->processing) {
            $nodes = array_map(function (array $node): array {
                $node['status'] = 'PROCESSING';
                $node['fileStatus'] = 'PROCESSING';

                return $node;
            }, $nodes);
        }

        return ['product' => [
            'id' => $variables['id'] ?? $variables['productId'] ?? null,
            'media' => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
            'images' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
            'variants' => [
                'nodes' => array_map(fn (string $id): array => ['id' => $id], $this->variantIds),
                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
            ],
        ]];
    }

    public function install(): void
    {
        config([
            'services.shopify.shop' => 'critical-defect.myshopify.com',
            'services.shopify.admin_access_token' => 'test-token',
            'services.shopify.api_version' => '2026-01',
        ]);
        app()->instance(ShopifyGraphqlGateway::class, $this);
        Http::fake(fn ($request) => $request->method() === 'GET'
            ? Http::response($this->bodies[$request->url()] ?? $this->bytes)
            : Http::response(['data' => $this->graphql($request->data()['query'] ?? '', $request->data()['variables'] ?? [])]));
    }
}

function criticalDefectPair(int $count = 1): array
{
    $component = createImageImportProduct('CRITICAL-'.bin2hex(random_bytes(3)));
    $sources = [];
    foreach (range(1, $count) as $position) {
        $asset = createImportTestAsset('critical-'.$component->id.'-'.$position, 'jpg', 'image-'.$position.'.jpg');
        $sources[] = Image::withoutEvents(fn () => Image::create([
            'product_id' => $component->id,
            'position' => $position,
            'image_asset_id' => $asset->id,
            'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
            'alt_text' => 'Sentence '.$position,
            'sync_state' => Image::SYNC_STATE_SYNCED,
            'needs_shopify_image_sync' => false,
        ]));
    }
    $stack = createImageImportProduct($component->handle.'-STACK', ['is_bundle' => true]);
    $draft = NewProductDraft::withoutEvents(fn () => NewProductDraft::create([
        'sku' => 'STACK-'.$stack->id,
        'handle' => $stack->handle,
        'shopify_id' => $stack->shopify_id,
        'title' => 'Stack',
        'bundle_product_ids' => [$component->id],
    ]));

    return [$component, $sources, $stack, $draft];
}

beforeEach(function () {
    Storage::fake('public');
    Bus::fake();
    config([
        'images.component_stack_local_rebuild' => false,
        'images.component_stack_blind_publication' => false,
        'images.component_stack_propagation' => false,
    ]);
});

it('reconciles a draft stack removal locally and does not remove its Shopify association', function () {
    [$component, $sources, $stack, $draft] = criticalDefectPair();
    $source = $sources[0];
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = $stack->allImages()->first();
    Image::withoutEvents(fn () => $copy->forceFill([
        'shopify_id' => 'gid://shopify/MediaImage/9910',
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'needs_shopify_image_sync' => false,
    ])->save());
    Product::withoutEvents(fn () => $stack->forceFill(['status' => 'draft'])->save());
    NewProductDraft::withoutEvents(fn () => $draft->forceFill(['bundle_product_ids' => []])->save());
    $generation = DB::table('component_photograph_generations')->insertGetId([
        'component_product_id' => $component->id,
        'component_image_id' => $source->id,
        'image_asset_id' => $source->image_asset_id,
        'asset_sha256' => $source->imageAsset->sha256,
        'selection_revision' => 1,
        'status' => 'confirmed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $task = DB::table('stack_image_sync_tasks')->insertGetId([
        'stack_product_id' => $stack->id,
        'component_product_id' => $component->id,
        'component_image_id' => $source->id,
        'generation_id' => $generation,
        'purpose' => 'remove',
        'phase' => 'pending',
        'attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $remote = new CriticalDefectRemote(Storage::disk('public')->get($source->imageAsset->storage_path));
    $remote->nodes = [$remote->node($copy->shopify_id)];
    $remote->install();
    config(['images.component_stack_propagation' => true]);

    app(ComponentStackPropagation::class)->synchronizeTask($task);

    $recorded = DB::table('stack_image_sync_tasks')->find($task);
    expect($stack->fresh()->status)->toBe('draft')
        ->and($remote->removals)->toBe(0)
        ->and($remote->removedIds)->toBe([])
        ->and($remote->nodes[0]['id'])->toBe('gid://shopify/MediaImage/9910')
        ->and($copy->fresh()->sync_state)->toBe(Image::SYNC_STATE_LOCAL_DELETED)
        ->and($recorded->phase)->toBe('blocked')
        ->and($recorded->phase)->not->toBe('verified')
        ->and($recorded->last_error)->toContain('were not removed from Shopify');
    $intent = DB::table('inherited_media_retirements')->where('image_id', $copy->id)->first();
    $evidence = json_decode((string) $intent->membership_evidence, true);
    expect($intent->status)->toBe('pending')
        ->and($intent->old_media_id)->toBe('gid://shopify/MediaImage/9910')
        ->and((int) $intent->stack_product_id)->toBe($stack->id)
        ->and((int) $intent->component_product_id)->toBe($component->id)
        ->and((int) $intent->component_image_id)->toBe($source->id)
        ->and((int) $intent->generation_id)->toBe($generation)
        ->and((int) $intent->image_asset_id)->toBe($source->image_asset_id)
        ->and($intent->asset_sha256)->toBe($source->imageAsset->sha256)
        ->and($intent->deferral_reason)->toContain('Shopify association removal was deferred')
        ->and($evidence['component_ids'])->toBe([]);
});

it('refuses a direct Shopify retirement when the stack is no longer active', function () {
    [, , $stack] = criticalDefectPair();
    Product::withoutEvents(fn () => $stack->forceFill(['status' => 'draft'])->save());
    $remote = new CriticalDefectRemote('draft-bytes');
    $remote->nodes = [$remote->node('gid://shopify/MediaImage/9910')];
    $remote->install();

    expect(fn () => app(ProductShopifyUpdater::class)->retireStackProductMedia(
        $stack->fresh(),
        'gid://shopify/MediaImage/9910',
        '',
    ))->toThrow(RuntimeException::class, 'Stack is not active. Shopify media was not removed.');
    expect($remote->removals)->toBe(0)
        ->and($stack->fresh()->status)->toBe('draft');
});

it('still removes the Shopify association when an active stack loses the component', function () {
    [$component, $sources, $stack, $draft] = criticalDefectPair();
    $source = $sources[0];
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = $stack->allImages()->first();
    Image::withoutEvents(fn () => $copy->forceFill([
        'shopify_id' => 'gid://shopify/MediaImage/9911',
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'needs_shopify_image_sync' => false,
    ])->save());
    NewProductDraft::withoutEvents(fn () => $draft->forceFill(['bundle_product_ids' => []])->save());
    $generation = DB::table('component_photograph_generations')->insertGetId([
        'component_product_id' => $component->id,
        'component_image_id' => $source->id,
        'image_asset_id' => $source->image_asset_id,
        'asset_sha256' => $source->imageAsset->sha256,
        'selection_revision' => 1,
        'status' => 'confirmed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $task = DB::table('stack_image_sync_tasks')->insertGetId([
        'stack_product_id' => $stack->id,
        'component_product_id' => $component->id,
        'component_image_id' => $source->id,
        'generation_id' => $generation,
        'purpose' => 'remove',
        'phase' => 'pending',
        'attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $remote = new CriticalDefectRemote(Storage::disk('public')->get($source->imageAsset->storage_path));
    $remote->nodes = [$remote->node($copy->shopify_id)];
    $remote->install();
    config(['images.component_stack_propagation' => true]);

    app(ComponentStackPropagation::class)->synchronizeTask($task);

    expect($stack->fresh()->status)->toBe('active')
        ->and($remote->removals)->toBe(1)
        ->and($remote->removedIds)->toBe(['gid://shopify/MediaImage/9911'])
        ->and($copy->fresh()->sync_state)->toBe(Image::SYNC_STATE_LOCAL_DELETED)
        ->and(DB::table('stack_image_sync_tasks')->find($task)->phase)->toBe('verified');
});

it('keeps a current secondary photograph when legacy primary publication runs', function () {
    [$component, $sources, $stack] = criticalDefectPair(2);
    $a = $sources[0];
    $b = $sources[1];
    config(['images.component_stack_propagation' => true, 'images.component_stack_local_rebuild' => true]);
    foreach (range(1, 2) as $ignored) {
        App\Models\Approval::withoutEvents(fn () => App\Models\Approval::create([
            'product_id' => $component->id,
            'user_id' => User::factory()->create()->id,
            'approval_version' => $component->fresh()->approval_version,
        ]));
    }
    Image::withoutEvents(fn () => $a->forceFill([
        'shopify_id' => 'gid://shopify/MediaImage/810',
        'last_shopify_synced_image_asset_id' => $a->image_asset_id,
    ])->save());
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copyA = $stack->allImages()->where('source_image_id', $a->id)->first();
    $copyB = $stack->allImages()->where('source_image_id', $b->id)->first();
    foreach ([[$copyA, 'gid://shopify/MediaImage/811'], [$copyB, 'gid://shopify/MediaImage/812']] as [$copy, $gid]) {
        Image::withoutEvents(fn () => $copy->forceFill([
            'shopify_id' => $gid,
            'last_shopify_synced_image_asset_id' => $copy->image_asset_id,
            'needs_shopify_image_sync' => false,
            'sync_state' => Image::SYNC_STATE_SYNCED,
        ])->save());
    }
    $generation = DB::table('component_photograph_generations')->insertGetId([
        'component_product_id' => $component->id,
        'component_image_id' => $a->id,
        'image_asset_id' => $a->image_asset_id,
        'asset_sha256' => $a->imageAsset->sha256,
        'shopify_media_id' => $a->shopify_id,
        'selection_revision' => 1,
        'status' => 'confirmed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $task = DB::table('stack_image_sync_tasks')->insertGetId([
        'stack_product_id' => $stack->id,
        'component_product_id' => $component->id,
        'component_image_id' => $a->id,
        'generation_id' => $generation,
        'purpose' => 'publish',
        'phase' => 'pending',
        'attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $remote = new CriticalDefectRemote(Storage::disk('public')->get($a->imageAsset->storage_path));
    $remote->nodes = [$remote->node($copyA->shopify_id), $remote->node($copyB->shopify_id)];
    $remote->bodies[$remote->node($copyB->shopify_id)['originalSource']['url']] = Storage::disk('public')->get($b->imageAsset->storage_path);
    $remote->install();

    app(ComponentStackPropagation::class)->synchronizeTask($task);

    expect(DB::table('stack_image_sync_tasks')->find($task)->phase)->toBe('verified')
        ->and($copyB->fresh()->sync_state)->toBe(Image::SYNC_STATE_SYNCED)
        ->and($copyB->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/812')
        ->and($b->fresh()->sync_state)->toBe(Image::SYNC_STATE_SYNCED)
        ->and($copyA->fresh()->sync_state)->not->toBe(Image::SYNC_STATE_LOCAL_DELETED)
        ->and($remote->removals)->toBe(0)
        ->and($remote->removedIds)->not->toContain('gid://shopify/MediaImage/812')
        ->and(collect($remote->nodes)->pluck('id')->all())->toContain('gid://shopify/MediaImage/812');
});

function criticalOperator(): \App\Models\User
{
    $operator = \App\Models\User::factory()->create(['is_active' => true]);
    \Spatie\Permission\Models\Role::findOrCreate(\App\Enums\RolesEnum::Admin->value);
    $operator->assignRole(\App\Enums\RolesEnum::Admin->value);

    return $operator;
}

function criticalRemovalFixture(): array
{
    [$component, $sources, $stack, $draft] = criticalDefectPair();
    $source = $sources[0];
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = $stack->allImages()->first();
    Image::withoutEvents(fn () => $copy->forceFill([
        'shopify_id' => 'gid://shopify/MediaImage/9910',
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'needs_shopify_image_sync' => false,
    ])->save());
    Product::withoutEvents(fn () => $stack->forceFill(['status' => 'draft'])->save());
    NewProductDraft::withoutEvents(fn () => $draft->forceFill(['bundle_product_ids' => []])->save());
    $generation = DB::table('component_photograph_generations')->insertGetId([
        'component_product_id' => $component->id,
        'component_image_id' => $source->id,
        'image_asset_id' => $source->image_asset_id,
        'asset_sha256' => $source->imageAsset->sha256,
        'selection_revision' => 1,
        'status' => 'confirmed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $task = DB::table('stack_image_sync_tasks')->insertGetId([
        'stack_product_id' => $stack->id,
        'component_product_id' => $component->id,
        'component_image_id' => $source->id,
        'generation_id' => $generation,
        'purpose' => 'remove',
        'phase' => 'pending',
        'attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $remote = new CriticalDefectRemote(Storage::disk('public')->get($source->imageAsset->storage_path));
    $remote->nodes = [$remote->node($copy->shopify_id)];
    $remote->install();
    config(['images.component_stack_propagation' => true]);

    return [$component, $source, $stack, $draft, $copy, $task, $remote];
}

it('processes a deferred draft retirement after the stack becomes active', function () {
    [, , $stack, , $copy, $task, $remote] = criticalRemovalFixture();
    app(ComponentStackPropagation::class)->synchronizeTask($task);
    expect($remote->removals)->toBe(0)
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->value('status'))->toBe('pending');

    Product::withoutEvents(fn () => $stack->forceFill(['status' => 'active'])->save());
    app(ComponentStackPropagation::class)->synchronizeTask($task);

    expect($stack->fresh()->status)->toBe('active')
        ->and($remote->removals)->toBe(1)
        ->and($remote->removedIds)->toBe(['gid://shopify/MediaImage/9910'])
        ->and($remote->nodes)->toBe([])
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->value('status'))->toBe('confirmed')
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->value('resolution'))->toBe('shopify_absent')
        ->and(DB::table('stack_image_sync_tasks')->find($task)->phase)->toBe('verified')
        ->and($copy->fresh()->sync_state)->toBe(Image::SYNC_STATE_LOCAL_DELETED);
});

it('does not verify a reactivated removal while the remote association is still present', function () {
    [, , $stack, , $copy, $task, $remote] = criticalRemovalFixture();
    app(ComponentStackPropagation::class)->synchronizeTask($task);
    $remote->keepRemovedMedia = true;
    Product::withoutEvents(fn () => $stack->forceFill(['status' => 'active'])->save());

    app(ComponentStackPropagation::class)->synchronizeTask($task);
    app(ComponentStackPropagation::class)->synchronizeTask($task);

    $intent = DB::table('inherited_media_retirements')->where('image_id', $copy->id)->first();
    expect(DB::table('stack_image_sync_tasks')->find($task)->phase)->not->toBe('verified')
        ->and($intent->status)->toBe('mutating')
        ->and($remote->nodes[0]['id'])->toBe('gid://shopify/MediaImage/9910')
        ->and($remote->removals)->toBe(1);
});

it('keeps a failed retirement recoverable without replaying an uncertain mutation', function () {
    [, , $stack, , $copy, $task, $remote] = criticalRemovalFixture();
    app(ComponentStackPropagation::class)->synchronizeTask($task);
    Product::withoutEvents(fn () => $stack->forceFill(['status' => 'active'])->save());
    $remote->processing = true;

    app(ComponentStackPropagation::class)->synchronizeTask($task);

    expect(DB::table('stack_image_sync_tasks')->find($task)->phase)->toBe('failed')
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->value('status'))->toBe('pending')
        ->and($remote->removals)->toBe(0)
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->value('last_error'))->toContain('not READY');

    $remote->processing = false;
    app(ComponentStackPropagation::class)->synchronizeTask($task);
    expect(DB::table('stack_image_sync_tasks')->find($task)->phase)->toBe('verified')
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->value('status'))->toBe('confirmed')
        ->and($remote->removals)->toBe(1);

    app(ComponentStackPropagation::class)->synchronizeTask($task);
    expect($remote->removals)->toBe(1)
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->count())->toBe(1);
});

it('confirms an uncertain retirement from a later absence read without sending another mutation', function () {
    [, , $stack, , $copy, $task, $remote] = criticalRemovalFixture();
    app(ComponentStackPropagation::class)->synchronizeTask($task);
    $remote->keepRemovedMedia = true;
    Product::withoutEvents(fn () => $stack->forceFill(['status' => 'active'])->save());
    app(ComponentStackPropagation::class)->synchronizeTask($task);
    expect(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->value('status'))->toBe('mutating')
        ->and($remote->removals)->toBe(1);

    $remote->nodes = [];
    $operator = criticalOperator();
    $intentId = DB::table('inherited_media_retirements')->where('image_id', $copy->id)->value('id');
    test()->artisan('images:resolve-media-retirement', [
        'retirement' => $intentId,
        '--operator' => $operator->id,
        '--reason' => 'Observed the association is gone and confirmed the recorded retirement.',
    ])->assertSuccessful();

    app(ComponentStackPropagation::class)->synchronizeTask($task);

    expect(DB::table('inherited_media_retirements')->find($intentId)->status)->toBe('confirmed')
        ->and(DB::table('stack_image_sync_tasks')->find($task)->phase)->toBe('verified')
        ->and($remote->removals)->toBe(1);
});

it('repeats a draft removal without a second intent or a Shopify mutation', function () {
    [, , , , $copy, $task, $remote] = criticalRemovalFixture();
    app(ComponentStackPropagation::class)->synchronizeTask($task);
    app(ComponentStackPropagation::class)->synchronizeTask($task);

    expect($remote->removals)->toBe(0)
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->count())->toBe(1)
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->value('status'))->toBe('pending')
        ->and(DB::table('stack_image_sync_tasks')->find($task)->phase)->toBe('blocked');
});

it('preserves a current photograph when another component takes that asset before restaging', function () {
    [$componentA, $sourcesA, $stack, $draft] = criticalDefectPair();
    $imageA = $sourcesA[0];
    $assetX = $imageA->imageAsset;
    $componentB = createImageImportProduct('CRITICAL-B-'.bin2hex(random_bytes(2)));
    $assetZ = createImportTestAsset('asset-z-'.$componentB->id, 'jpg', 'z.jpg');
    $imageB = Image::withoutEvents(fn () => Image::create([
        'product_id' => $componentB->id,
        'position' => 1,
        'image_asset_id' => $assetZ->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'alt_text' => 'Sentence Z',
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'needs_shopify_image_sync' => false,
    ]));
    NewProductDraft::withoutEvents(fn () => $draft->forceFill(['bundle_product_ids' => [$componentA->id, $componentB->id]])->save());
    config(['images.component_stack_propagation' => true]);
    foreach (range(1, 2) as $ignored) {
        \App\Models\Approval::withoutEvents(fn () => \App\Models\Approval::create([
            'product_id' => $componentA->id,
            'user_id' => \App\Models\User::factory()->create()->id,
            'approval_version' => $componentA->fresh()->approval_version,
        ]));
    }
    app(ComponentStackPropagation::class)->stageStack($stack->fresh());
    $copyX = $stack->allImages()->where('source_image_id', $imageA->id)->first();
    Image::withoutEvents(fn () => $copyX->forceFill([
        'shopify_id' => 'gid://shopify/MediaImage/701',
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'needs_shopify_image_sync' => false,
    ])->save());
    $assetY = createImportTestAsset('asset-y-'.$componentA->id, 'jpg', 'y.jpg');
    Image::withoutEvents(fn () => $imageA->forceFill([
        'image_asset_id' => $assetY->id,
        'shopify_id' => 'gid://shopify/MediaImage/710',
        'last_shopify_synced_image_asset_id' => $assetY->id,
        'needs_shopify_image_sync' => false,
    ])->save());
    Image::withoutEvents(fn () => $imageB->forceFill(['image_asset_id' => $assetX->id])->save());
    $generation = DB::table('component_photograph_generations')->insertGetId([
        'component_product_id' => $componentA->id,
        'component_image_id' => $imageA->id,
        'image_asset_id' => $assetY->id,
        'asset_sha256' => $assetY->sha256,
        'shopify_media_id' => 'gid://shopify/MediaImage/710',
        'selection_revision' => 1,
        'status' => 'confirmed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $task = DB::table('stack_image_sync_tasks')->insertGetId([
        'stack_product_id' => $stack->id,
        'component_product_id' => $componentA->id,
        'component_image_id' => $imageA->id,
        'generation_id' => $generation,
        'purpose' => 'publish',
        'phase' => 'pending',
        'attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $remote = new CriticalDefectRemote(Storage::disk('public')->get($assetY->storage_path));
    $remote->nodes = [$remote->node('gid://shopify/MediaImage/701')];
    $remote->bodies[$remote->node('gid://shopify/MediaImage/701')['originalSource']['url']] = Storage::disk('public')->get($assetX->storage_path);
    $remote->install();

    app(ComponentStackPropagation::class)->synchronizeTask($task);

    $linkB = DB::table('inherited_image_sources')->where('image_id', $copyX->id)->where('component_image_id', $imageB->id)->whereNull('removed_at')->first();
    $linkA = DB::table('inherited_image_sources')->where('image_id', $copyX->id)->where('component_image_id', $imageA->id)->first();
    expect(DB::table('stack_image_sync_tasks')->find($task)->phase)->toBe('verified')
        ->and($copyX->fresh()->sync_state)->toBe(Image::SYNC_STATE_SYNCED)
        ->and($copyX->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/701')
        ->and((int) $copyX->fresh()->source_image_id)->toBe($imageB->id)
        ->and($linkB)->not->toBeNull()
        ->and($linkA->removed_at)->not->toBeNull()
        ->and($remote->removedIds)->not->toContain('gid://shopify/MediaImage/701');
});

it('still retires a superseded photograph when no current source uses that asset', function () {
    [$component, $sources, $stack] = criticalDefectPair();
    $source = $sources[0];
    $assetX = $source->imageAsset;
    config(['images.component_stack_propagation' => true]);
    foreach (range(1, 2) as $ignored) {
        \App\Models\Approval::withoutEvents(fn () => \App\Models\Approval::create([
            'product_id' => $component->id,
            'user_id' => \App\Models\User::factory()->create()->id,
            'approval_version' => $component->fresh()->approval_version,
        ]));
    }
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copyX = $stack->allImages()->first();
    Image::withoutEvents(fn () => $copyX->forceFill([
        'shopify_id' => 'gid://shopify/MediaImage/721',
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'needs_shopify_image_sync' => false,
    ])->save());
    $assetY = createImportTestAsset('replacement-y-'.$component->id, 'jpg', 'y.jpg');
    Image::withoutEvents(fn () => $source->forceFill([
        'image_asset_id' => $assetY->id,
        'shopify_id' => 'gid://shopify/MediaImage/722',
        'last_shopify_synced_image_asset_id' => $assetY->id,
        'needs_shopify_image_sync' => false,
    ])->save());
    $generation = DB::table('component_photograph_generations')->insertGetId([
        'component_product_id' => $component->id,
        'component_image_id' => $source->id,
        'image_asset_id' => $assetY->id,
        'asset_sha256' => $assetY->sha256,
        'shopify_media_id' => 'gid://shopify/MediaImage/722',
        'selection_revision' => 1,
        'status' => 'confirmed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $task = DB::table('stack_image_sync_tasks')->insertGetId([
        'stack_product_id' => $stack->id,
        'component_product_id' => $component->id,
        'component_image_id' => $source->id,
        'generation_id' => $generation,
        'purpose' => 'publish',
        'phase' => 'pending',
        'attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $remote = new CriticalDefectRemote(Storage::disk('public')->get($assetY->storage_path));
    $remote->nodes = [$remote->node('gid://shopify/MediaImage/721')];
    $remote->bodies[$remote->node('gid://shopify/MediaImage/721')['originalSource']['url']] = Storage::disk('public')->get($assetX->storage_path);
    $remote->install();

    app(ComponentStackPropagation::class)->synchronizeTask($task);

    expect(DB::table('stack_image_sync_tasks')->find($task)->phase)->toBe('verified')
        ->and($copyX->fresh()->sync_state)->toBe(Image::SYNC_STATE_LOCAL_DELETED)
        ->and($remote->removedIds)->toContain('gid://shopify/MediaImage/721');
});

it('stops variant replacement when the stack becomes inactive before the append', function () {
    $stack = createImageImportProduct('VARIANT-GUARD', ['is_bundle' => true]);
    $asset = createImportTestAsset('variant-guard-bytes', 'jpg', 'guard.jpg');
    $image = Image::withoutEvents(fn () => Image::create([
        'product_id' => $stack->id,
        'ownership' => \App\Services\ImageOwnership::COMPONENT_INHERITED,
        'image_asset_id' => $asset->id,
        'shopify_id' => 'gid://shopify/MediaImage/730',
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
    ]));
    DB::table('inherited_media_retirements')->insert([
        'stack_product_id' => $stack->id,
        'image_id' => $image->id,
        'generation_id' => 1,
        'old_media_id' => $image->shopify_id,
        'replacement_media_id' => 'gid://shopify/MediaImage/731',
        'status' => 'pending',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $remote = new CriticalDefectRemote(Storage::disk('public')->get($asset->storage_path));
    $remote->nodes = [$remote->node($image->shopify_id), $remote->node('gid://shopify/MediaImage/731')];
    $remote->variantIds = ['gid://shopify/ProductVariant/73'];
    $remote->variantMediaIds = [$image->shopify_id];
    $remote->onVariantRead = function () use ($stack): void {
        Product::withoutEvents(fn () => $stack->forceFill(['status' => 'draft'])->save());
    };
    $remote->install();

    expect(fn () => app(\App\Services\ProductShopifyUpdater::class)->retireStackProductMedia(
        $stack,
        $image->shopify_id,
        'gid://shopify/MediaImage/731',
        null,
        $image->id,
    ))->toThrow(RuntimeException::class, 'Stack is not active. Shopify media was not removed.');
    expect($remote->appends)->toBe(0)
        ->and($remote->removals)->toBe(0)
        ->and($stack->fresh()->status)->toBe('draft');
});

it('does not retire when membership changes before the Shopify mutation', function () {
    [$component, $sources, $stack, $draft] = criticalDefectPair();
    $source = $sources[0];
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = $stack->allImages()->first();
    Image::withoutEvents(fn () => $copy->forceFill([
        'shopify_id' => 'gid://shopify/MediaImage/740',
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'needs_shopify_image_sync' => false,
    ])->save());
    NewProductDraft::withoutEvents(fn () => $draft->forceFill(['bundle_product_ids' => []])->save());
    $generation = DB::table('component_photograph_generations')->insertGetId([
        'component_product_id' => $component->id,
        'component_image_id' => $source->id,
        'image_asset_id' => $source->image_asset_id,
        'asset_sha256' => $source->imageAsset->sha256,
        'selection_revision' => 1,
        'status' => 'confirmed',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $task = DB::table('stack_image_sync_tasks')->insertGetId([
        'stack_product_id' => $stack->id,
        'component_product_id' => $component->id,
        'component_image_id' => $source->id,
        'generation_id' => $generation,
        'purpose' => 'remove',
        'phase' => 'pending',
        'attempts' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $remote = new CriticalDefectRemote(Storage::disk('public')->get($source->imageAsset->storage_path));
    $remote->nodes = [$remote->node($copy->shopify_id)];
    $remote->onMediaRead = function () use ($draft, $component): void {
        NewProductDraft::withoutEvents(fn () => $draft->fresh()->forceFill(['bundle_product_ids' => [$component->id]])->save());
    };
    $remote->install();
    config(['images.component_stack_propagation' => true]);

    app(ComponentStackPropagation::class)->synchronizeTask($task);

    expect($remote->removals)->toBe(0)
        ->and($copy->fresh()->sync_state)->toBe(Image::SYNC_STATE_SYNCED)
        ->and($copy->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/740')
        ->and(DB::table('stack_image_sync_tasks')->find($task)->phase)->toBe('superseded')
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->value('status'))->toBe('retained');
});

it('schedules blocked retirement when the stack becomes active and verifies remote absence once', function () {
    [, , $stack, , $copy, $task, $remote] = criticalRemovalFixture();
    app(ComponentStackPropagation::class)->synchronizeTask($task);
    expect(DB::table('inherited_image_sources')->where('image_id', $copy->id)->whereNull('removed_at')->count())->toBe(0)
        ->and($copy->fresh()->sync_state)->toBe(Image::SYNC_STATE_LOCAL_DELETED);

    $stack->forceFill(['status' => 'active'])->save();

    $jobs = Bus::dispatched(\App\Jobs\SynchronizeStackInheritedImagesJob::class);
    expect($jobs)->toHaveCount(1)->and($jobs[0]->taskId)->toBe($task);
    $jobs[0]->handle(app(ComponentStackPropagation::class));
    $jobs[0]->handle(app(ComponentStackPropagation::class));

    expect($remote->removals)->toBe(1)
        ->and($remote->nodes)->toBe([])
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->value('status'))->toBe('confirmed')
        ->and(DB::table('stack_image_sync_tasks')->find($task)->phase)->toBe('verified')
        ->and(DB::table('stack_image_sync_tasks')->find($task)->attempts)->toBe(0);
});

it('lets scheduled recovery find a blocked retirement without opening provenance again', function () {
    [, , $stack, , $copy, $task, $remote] = criticalRemovalFixture();
    app(ComponentStackPropagation::class)->synchronizeTask($task);
    Product::withoutEvents(fn () => $stack->forceFill(['status' => 'active'])->save());
    DB::table('stack_image_sync_tasks')->where('id', $task)->update(['available_at' => now()->subMinute()]);

    expect(app(ComponentStackPropagation::class)->dispatchEligibleBlockedRetirements())->toBe(1)
        ->and(app(ComponentStackPropagation::class)->dispatchEligibleBlockedRetirements())->toBe(0);
    $jobs = Bus::dispatched(\App\Jobs\SynchronizeStackInheritedImagesJob::class);
    expect($jobs)->toHaveCount(1);
    $jobs[0]->handle(app(ComponentStackPropagation::class));

    expect($remote->removals)->toBe(1)
        ->and(DB::table('inherited_image_sources')->where('image_id', $copy->id)->whereNull('removed_at')->count())->toBe(0)
        ->and(DB::table('stack_image_sync_tasks')->find($task)->phase)->toBe('verified');
});

it('keeps a blocked retirement claimed until the job window expires and then allows one retry', function () {
    [, , $stack, , $copy, $task] = criticalRemovalFixture();
    app(ComponentStackPropagation::class)->synchronizeTask($task);
    Product::withoutEvents(fn () => $stack->forceFill(['status' => 'active'])->save());
    $before = DB::table('stack_image_sync_tasks')->find($task);

    expect(app(ComponentStackPropagation::class)->dispatchEligibleBlockedRetirements((int) $stack->id))->toBe(1)
        ->and(app(ComponentStackPropagation::class)->dispatchEligibleBlockedRetirements((int) $stack->id))->toBe(0);
    $claimed = DB::table('stack_image_sync_tasks')->find($task);
    expect($claimed->phase)->toBe('blocked')
        ->and($claimed->attempts)->toBe($before->attempts)
        ->and($claimed->last_error)->toBe($before->last_error)
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->value('status'))->toBe('pending')
        ->and(now()->diffInSeconds($claimed->available_at, false))->toBeGreaterThan(500);

    DB::table('stack_image_sync_tasks')->where('id', $task)->update(['available_at' => now()->subSecond()]);
    expect(app(ComponentStackPropagation::class)->dispatchEligibleBlockedRetirements((int) $stack->id))->toBe(1)
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->where('status', 'pending')->count())->toBe(1)
        ->and(Bus::dispatched(\App\Jobs\SynchronizeStackInheritedImagesJob::class))->toHaveCount(2);
});

it('does not schedule recovery for an inactive stack or an uncertain retirement', function () {
    [, , $stack, , $copy, $task] = criticalRemovalFixture();
    app(ComponentStackPropagation::class)->synchronizeTask($task);

    expect(app(ComponentStackPropagation::class)->dispatchEligibleBlockedRetirements((int) $stack->id))->toBe(0)
        ->and(DB::table('stack_image_sync_tasks')->find($task)->phase)->toBe('blocked');

    Product::withoutEvents(fn () => $stack->forceFill(['status' => 'active'])->save());
    DB::table('inherited_media_retirements')->where('image_id', $copy->id)->update(['status' => 'mutating', 'last_operation' => 'delete']);
    expect(app(ComponentStackPropagation::class)->dispatchEligibleBlockedRetirements((int) $stack->id))->toBe(0)
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->value('status'))->toBe('mutating');
});

it('does not retire when recovery runs after the component was restored', function () {
    [$component, , $stack, $draft, $copy, $task, $remote] = criticalRemovalFixture();
    app(ComponentStackPropagation::class)->synchronizeTask($task);
    $draft->forceFill(['bundle_product_ids' => [$component->id]])->save();
    Product::withoutEvents(fn () => $stack->forceFill(['status' => 'active'])->save());

    expect(app(ComponentStackPropagation::class)->dispatchEligibleBlockedRetirements((int) $stack->id))->toBe(1);
    Bus::dispatched(\App\Jobs\SynchronizeStackInheritedImagesJob::class)[0]->handle(app(ComponentStackPropagation::class));

    expect($remote->removals)->toBe(0)
        ->and($copy->fresh()->sync_state)->toBe(Image::SYNC_STATE_SYNCED)
        ->and(DB::table('stack_image_sync_tasks')->find($task)->phase)->toBe('superseded')
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->value('status'))->toBe('retained');
});
