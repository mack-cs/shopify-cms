<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Jobs\RebuildDependentStackImagesJob;
use App\Jobs\SynchronizeAuthorizedStackImagesJob;
use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Services\ComponentStackPropagation;
use App\Services\ImageOwnership;
use App\Services\StackImageSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/shopify-image-import.php';

function phase5Flags(bool $propagation = false, bool $local = false, bool $blind = false): void
{
    config([
        'images.component_stack_propagation' => $propagation,
        'images.component_stack_local_rebuild' => $local,
        'images.component_stack_blind_publication' => $blind,
    ]);
}

function phase5Stack(string $sku, array $componentIds): Product
{
    $stack = createImageImportProduct($sku, ['is_bundle' => true, 'status' => 'active']);
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create([
        'sku' => $sku,
        'shopify_id' => $stack->shopify_id,
        'handle' => $stack->handle,
        'title' => $sku,
        'status' => 'active',
        'bundle_product_ids' => $componentIds,
    ]));

    return $stack;
}

function phase5Component(string $sku, string $bytes, string $alt): array
{
    Storage::fake('public');
    $product = createImageImportProduct($sku);
    $asset = createImportTestAsset($bytes, 'jpg', $sku.'.jpg');
    $image = Image::withoutEvents(fn () => Image::create([
        'product_id' => $product->id,
        'position' => 1,
        'image_asset_id' => $asset->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'alt_text' => $alt,
        'needs_shopify_image_sync' => false,
    ]));

    return [$product, $image->fresh('imageAsset')];
}

final class Phase5Gateway implements ShopifyGraphqlGateway
{
    public int $calls = 0;

    public int $creates = 0;

    public function install(): void
    {
        config([
            'services.shopify.shop' => 'stack-flag-guard.myshopify.com',
            'services.shopify.admin_access_token' => 'test-token',
            'services.shopify.api_version' => '2026-01',
        ]);
        app()->instance(ShopifyGraphqlGateway::class, $this);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if (strtoupper($request->method()) === 'GET') {
                return Http::response('photograph-bytes');
            }
            $body = $request->data();
            $query = is_array($body) ? (string) ($body['query'] ?? '') : '';
            $variables = is_array($body) ? ($body['variables'] ?? []) : [];

            return Http::response(['data' => $this->graphql($query, is_array($variables) ? $variables : (array) $variables)]);
        });
    }

    public function graphql(string $query, array $variables = []): array
    {
        $this->calls++;
        if (str_contains($query, 'productCreateMedia')) {
            $this->creates++;

            return ['productCreateMedia' => ['media' => [['id' => 'gid://shopify/MediaImage/9901']], 'mediaUserErrors' => []]];
        }

        return ['product' => [
            'media' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
            'images' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
            'variants' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
        ]];
    }
}

it('does not run a queued local rebuild after that flag is disabled', function (): void {
    phase5Flags(local: true);
    Bus::fake();
    [$component] = phase5Component('LOCAL-Q', 'local-bytes', 'Local');
    $stack = phase5Stack('LOCAL-Q-STACK', [$component->id]);
    app(StackImageSync::class)->queueLocalRebuild((int) $component->id);
    $queued = Bus::dispatched(RebuildDependentStackImagesJob::class);
    expect($queued)->toHaveCount(1);

    phase5Flags();
    $queued[0]->handle(app(StackImageSync::class));

    expect(Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->count())->toBe(0);
    app(ComponentStackPropagation::class)->stageStack($stack->fresh());
    expect(Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->active()->count())->toBe(1);
});

it('does not publish a queued synchronization after automatic flags are disabled', function (): void {
    phase5Flags(blind: true);
    Bus::fake();
    Http::preventStrayRequests();
    [$component, $image] = phase5Component('SYNC-Q', 'sync-bytes', 'Sync sentence');
    $stack = phase5Stack('SYNC-Q-STACK', [$component->id]);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    Image::withoutEvents(fn () => $copy->forceFill([
        'needs_shopify_image_sync' => true,
        'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
        'shopify_media_create_uncertain' => true,
    ])->save());
    DB::table('component_image_authorizations')->insert([
        'product_id' => $component->id,
        'image_id' => $image->id,
        'image_asset_id' => $image->image_asset_id,
        'asset_sha256' => $image->imageAsset->sha256,
        'photograph_version' => max(1, (int) $image->fresh()->photograph_version),
        'alt_text_version' => max(1, (int) $image->fresh()->alt_text_version),
        'alt_text' => 'Sync sentence',
        'scope' => 'images',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    foreach (range(1, 2) as $ignored) {
        $stack->approvals()->create([
            'user_id' => \App\Models\User::factory()->create()->id,
            'approval_version' => $stack->approval_version ?: 1,
        ]);
    }
    app(StackImageSync::class)->queueBlindPublication($component, 321);
    $queued = Bus::dispatched(SynchronizeAuthorizedStackImagesJob::class);
    expect($queued)->toHaveCount(1);
    $gateway = new Phase5Gateway;
    app()->instance(ShopifyGraphqlGateway::class, $gateway);
    $authorizations = DB::table('component_image_authorizations')->count();

    phase5Flags();
    $queued[0]->handle(app(StackImageSync::class));

    $cursor = DB::table('stack_image_sync_cursors')->where('stack_product_id', $stack->id)->first();
    expect($gateway->calls)->toBe(0)
        ->and(Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->count())->toBe(1)
        ->and($copy->fresh()->shopify_id)->toBeNull()
        ->and($copy->fresh()->shopify_media_create_uncertain)->toBeTrue()
        ->and(DB::table('component_image_authorizations')->count())->toBe($authorizations)
        ->and($cursor->status)->toBe('failed')
        ->and((int) $cursor->desired_generation)->toBe(1)
        ->and((int) $cursor->approval_request_id)->toBe(321)
        ->and(in_array($cursor->status, ['queued', 'running'], true))->toBeFalse();

    phase5Flags(blind: true);
    Bus::fake();
    app(StackImageSync::class)->queueBlindPublication($component->fresh(), 322);
    expect(Bus::dispatched(SynchronizeAuthorizedStackImagesJob::class))->toHaveCount(1)
        ->and(DB::table('stack_image_sync_cursors')->where('stack_product_id', $stack->id)->value('status'))->toBe('queued');
});

it('requires both automatic flags before a queued synchronization can reach Shopify', function (): void {
    foreach ([
        'prop-off' => [false, true],
        'blind-off' => [true, false],
        'both-off' => [false, false],
    ] as $label => [$propagation, $blind]) {
        phase5Flags(propagation: true, blind: true);
        Bus::fake();
        [$component, $image] = phase5Component('BOTH-'.$label, 'sync-'.$label, 'Sync sentence');
        $stack = phase5Stack('BOTH-'.$label.'-STACK', [$component->id]);
        app(ComponentStackPropagation::class)->stageStack($stack);
        $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
        Image::withoutEvents(fn () => $copy->forceFill([
            'needs_shopify_image_sync' => true,
            'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
            'shopify_media_create_uncertain' => true,
        ])->save());
        DB::table('component_image_authorizations')->insert([
            'product_id' => $component->id,
            'image_id' => $image->id,
            'image_asset_id' => $image->image_asset_id,
            'asset_sha256' => $image->imageAsset->sha256,
            'photograph_version' => max(1, (int) $image->fresh()->photograph_version),
            'alt_text_version' => max(1, (int) $image->fresh()->alt_text_version),
            'alt_text' => 'Sync sentence',
            'scope' => 'images',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('inherited_media_retirements')->insert([
            'stack_product_id' => $stack->id,
            'image_id' => $copy->id,
            'old_media_id' => 'gid://shopify/MediaImage/9940',
            'status' => 'mutating',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        foreach (range(1, 2) as $ignored) {
            DB::table('approvals')->insert([
                'product_id' => $stack->id,
                'user_id' => \App\Models\User::factory()->create()->id,
                'approval_version' => $stack->fresh()->approval_version ?? 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
        app(StackImageSync::class)->queueBlindPublication($component, 321);
        $job = Bus::dispatched(SynchronizeAuthorizedStackImagesJob::class)[0];
        $gateway = new Phase5Gateway;
        $gateway->install();

        phase5Flags(propagation: $propagation, blind: $blind);
        $job->handle(app(StackImageSync::class));

        $cursor = DB::table('stack_image_sync_cursors')->where('stack_product_id', $stack->id)->first();
        expect($gateway->creates)->toBe(0)
            ->and($cursor->status)->toBe('failed')
            ->and((int) $cursor->desired_generation)->toBe(1)
            ->and((int) $cursor->working_generation)->toBe(0)
            ->and((int) $cursor->approval_request_id)->toBe(321)
            ->and($copy->fresh()->shopify_media_create_uncertain)->toBeTrue()
            ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->value('status'))->toBe('mutating');
    }

    phase5Flags(propagation: true, blind: true);
    Bus::fake();
    app(StackImageSync::class)->queueBlindPublication($component->fresh(), 322);
    expect(Bus::dispatched(SynchronizeAuthorizedStackImagesJob::class))->toHaveCount(1)
        ->and(DB::table('stack_image_sync_cursors')->where('stack_product_id', $stack->id)->value('status'))->toBe('queued')
        ->and((int) DB::table('stack_image_sync_cursors')->where('stack_product_id', $stack->id)->value('desired_generation'))->toBe(2)
        ->and((int) DB::table('stack_image_sync_cursors')->where('stack_product_id', $stack->id)->value('approval_request_id'))->toBe(322);

    phase5Flags(propagation: true, blind: true);
    Bus::fake();
    [$readyComponent, $readyImage] = phase5Component('BOTH-ON', 'sync-bytes', 'Sync sentence');
    $readyStack = phase5Stack('BOTH-ON-STACK', [$readyComponent->id]);
    app(ComponentStackPropagation::class)->stageStack($readyStack);
    $readyCopy = Image::query()->where('product_id', $readyStack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    Image::withoutEvents(fn () => $readyCopy->forceFill([
        'needs_shopify_image_sync' => true,
        'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
    ])->save());
    DB::table('component_image_authorizations')->insert([
        'product_id' => $readyComponent->id,
        'image_id' => $readyImage->id,
        'image_asset_id' => $readyImage->image_asset_id,
        'asset_sha256' => $readyImage->imageAsset->sha256,
        'photograph_version' => max(1, (int) $readyImage->fresh()->photograph_version),
        'alt_text_version' => max(1, (int) $readyImage->fresh()->alt_text_version),
        'alt_text' => 'Sync sentence',
        'scope' => 'images',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    app(StackImageSync::class)->queueBlindPublication($readyComponent, 400);
    $readyJob = Bus::dispatched(SynchronizeAuthorizedStackImagesJob::class)[0];
    $gateway = new Phase5Gateway;
    $gateway->install();
    $readyJob->handle(app(StackImageSync::class));

    expect($gateway->creates)->toBe(1);
});

it('does not retire a shared photograph when one source component is removed', function (): void {
    phase5Flags();
    Http::preventStrayRequests();
    $bytes = 'shared-photo-bytes';
    [$first, $firstImage] = phase5Component('SHARE-A', $bytes, 'Shared sentence');
    $second = createImageImportProduct('SHARE-B');
    $secondImage = Image::withoutEvents(fn () => Image::create([
        'product_id' => $second->id,
        'position' => 1,
        'image_asset_id' => $firstImage->image_asset_id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'alt_text' => 'Shared sentence',
        'needs_shopify_image_sync' => false,
    ]));
    $stack = phase5Stack('SHARE-STACK', [$first->id, $second->id]);
    $service = app(ComponentStackPropagation::class);
    $service->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $removedImageId = (int) $copy->source_image_id;
    $removedProductId = (int) Image::query()->find($removedImageId)->product_id;
    $survivor = $removedImageId === (int) $firstImage->id ? $secondImage : $firstImage;
    Image::withoutEvents(fn () => $copy->forceFill([
        'shopify_id' => 'gid://shopify/MediaImage/910',
        'needs_shopify_image_sync' => false,
        'sync_state' => Image::SYNC_STATE_SYNCED,
    ])->save());
    $draft = NewProductDraft::query()->where('shopify_id', $stack->shopify_id)->first();
    NewProductDraft::withoutEvents(fn () => $draft->forceFill([
        'bundle_product_ids' => [$removedProductId === (int) $first->id ? $second->id : $first->id],
    ])->save());

    $service->stageStack($stack->fresh());
    $plan = app(StackImageSync::class)->plan($stack->fresh());

    $links = DB::table('inherited_image_sources')->where('image_id', $copy->id)->orderBy('component_image_id')->get();
    expect((int) $copy->fresh()->source_image_id)->toBe((int) $survivor->id)
        ->and($copy->fresh()->sync_state)->toBe(Image::SYNC_STATE_SYNCED)
        ->and($copy->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/910')
        ->and($plan['retire'])->toBe([])
        ->and($links)->toHaveCount(2)
        ->and($links->firstWhere('component_image_id', $removedImageId)->removed_at)->not->toBeNull()
        ->and($links->firstWhere('component_image_id', $survivor->id)->removed_at)->toBeNull();
    Http::assertNothingSent();
});

it('keeps the shared photograph when its original source is replaced and the other component still uses it', function (): void {
    phase5Flags();
    Http::preventStrayRequests();
    $bytes = 'replace-shared-bytes';
    [$first, $firstImage] = phase5Component('REPL-A', $bytes, 'Shared sentence');
    $second = createImageImportProduct('REPL-B');
    $secondImage = Image::withoutEvents(fn () => Image::create([
        'product_id' => $second->id,
        'position' => 1,
        'image_asset_id' => $firstImage->image_asset_id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'alt_text' => 'Shared sentence',
    ]));
    $stack = phase5Stack('REPL-STACK', [$first->id, $second->id]);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    Image::withoutEvents(fn () => $copy->forceFill(['shopify_id' => 'gid://shopify/MediaImage/911'])->save());
    $replacement = createImportTestAsset('replacement-bytes', 'jpg', 'REPL-A-new.jpg');
    Image::withoutEvents(fn () => $firstImage->forceFill([
        'image_asset_id' => $replacement->id,
        'photograph_version' => max(1, (int) $firstImage->photograph_version),
    ])->save());

    app(ComponentStackPropagation::class)->stageStack($stack->fresh());
    $plan = app(StackImageSync::class)->plan($stack->fresh());
    $original = Image::query()->where('product_id', $stack->id)->where('image_asset_id', $secondImage->image_asset_id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();

    expect($original)->not->toBeNull()
        ->and((int) $original->source_image_id)->toBe((int) $secondImage->id)
        ->and($original->sync_state)->not->toBe(Image::SYNC_STATE_LOCAL_DELETED)
        ->and($plan['retire'])->not->toContain((int) $original->id)
        ->and(Image::query()->where('product_id', $stack->id)->where('image_asset_id', $replacement->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->active()->exists())->toBeTrue();
    Http::assertNothingSent();
});

it('retires the shared photograph only after every current component has stopped using it', function (): void {
    phase5Flags();
    [$first, $firstImage] = phase5Component('GONE-A', 'gone-bytes', 'Gone');
    $second = createImageImportProduct('GONE-B');
    Image::withoutEvents(fn () => Image::create([
        'product_id' => $second->id,
        'position' => 1,
        'image_asset_id' => $firstImage->image_asset_id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'alt_text' => 'Gone',
    ]));
    $stack = phase5Stack('GONE-STACK', [$first->id, $second->id]);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    Image::withoutEvents(fn () => $copy->forceFill(['shopify_id' => 'gid://shopify/MediaImage/912'])->save());
    $draft = NewProductDraft::query()->where('shopify_id', $stack->shopify_id)->first();
    NewProductDraft::withoutEvents(fn () => $draft->forceFill(['bundle_product_ids' => [$second->id]])->save());
    $stillThere = app(StackImageSync::class)->plan($stack->fresh());
    NewProductDraft::withoutEvents(fn () => $draft->forceFill(['bundle_product_ids' => []])->save());

    $plan = app(StackImageSync::class)->plan($stack->fresh());

    expect($stillThere['retire'])->toBe([])
        ->and($plan['retire'])->toContain((int) $copy->id);
});

it('does not replay an uncertain retirement of a photograph another component still needs', function (): void {
    phase5Flags();
    Http::preventStrayRequests();
    [$first, $firstImage] = phase5Component('SAFE-A', 'safe-bytes', 'Safe');
    $second = createImageImportProduct('SAFE-B');
    Image::withoutEvents(fn () => Image::create([
        'product_id' => $second->id,
        'position' => 1,
        'image_asset_id' => $firstImage->image_asset_id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'alt_text' => 'Safe',
    ]));
    $stack = phase5Stack('SAFE-STACK', [$first->id, $second->id]);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    Image::withoutEvents(fn () => $copy->forceFill([
        'shopify_id' => 'gid://shopify/MediaImage/913',
        'shopify_media_create_uncertain' => true,
    ])->save());
    DB::table('inherited_media_retirements')->insert([
        'stack_product_id' => $stack->id,
        'image_id' => $copy->id,
        'old_media_id' => 'gid://shopify/MediaImage/913',
        'status' => 'mutating',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $draft = NewProductDraft::query()->where('shopify_id', $stack->shopify_id)->first();
    $removedProductId = (int) Image::query()->find((int) $copy->source_image_id)->product_id;
    NewProductDraft::withoutEvents(fn () => $draft->forceFill([
        'bundle_product_ids' => [$removedProductId === (int) $first->id ? $second->id : $first->id],
    ])->save());

    $status = app(ComponentStackPropagation::class)->retirePlannedAssociation($stack->fresh(), $copy->fresh());

    expect($status)->toBe('preserved')
        ->and($copy->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/913')
        ->and($copy->fresh()->shopify_media_create_uncertain)->toBeTrue()
        ->and($copy->fresh()->sync_state)->not->toBe(Image::SYNC_STATE_LOCAL_DELETED)
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->value('status'))->toBe('mutating');
    Http::assertNothingSent();
});
