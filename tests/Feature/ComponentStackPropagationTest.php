<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Jobs\DispatchComponentPublicationOutboxJob;
use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\User;
use App\Models\ProductPartialApprovalRequest;
use App\Services\ComponentPhotographSelector;
use App\Services\ComponentStackPropagation;
use App\Services\ImageAltTextBackfillService;
use App\Services\ImageOwnership;
use App\Services\ProductPartialApprovalService;
use App\Services\ProductShopifyUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/shopify-image-import.php';

function propagationOn(): void
{
    config(['images.component_stack_propagation' => true]);
}

function propagationOperator(): User
{
    $operator = User::factory()->create([
        'email' => 'operator-'.Str::lower(Str::random(8)).'@leighavenue.co.za',
        'is_active' => true,
    ]);
    \Spatie\Permission\Models\Role::findOrCreate(\App\Enums\RolesEnum::Admin->value);
    $operator->assignRole(\App\Enums\RolesEnum::Admin->value);
    return $operator;
}

function propagationStack(string $sku, array $componentIds): Product
{
    $stack = createImageImportProduct($sku, ['is_bundle' => true]);
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create([
        'sku' => $sku, 'shopify_id' => $stack->shopify_id, 'handle' => $stack->handle,
        'title' => $sku, 'status' => 'active', 'bundle_product_ids' => $componentIds,
    ]));

    return $stack;
}

function propagationComponent(string $sku, string $bytes, ?string $mediaId, bool $confirmed = true): array
{
    Storage::fake('public');
    $product = createImageImportProduct($sku);
    if ((int) ($product->approval_version ?? 0) < 1) {
        Product::withoutEvents(fn () => $product->forceFill(['approval_version' => 1])->save());
        $product->refresh();
    }
    foreach (range(1, 2) as $ignored) {
        $approver = User::factory()->create();
        $product->approvals()->create(['user_id' => $approver->id, 'approval_version' => $product->approval_version]);
    }
    $asset = createImportTestAsset($bytes, 'jpg', $sku.'.jpg');
    $image = Image::withoutEvents(fn () => Image::create([
        'product_id' => $product->id, 'position' => 1, 'image_asset_id' => $asset->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP, 'backup_completed_at' => now(),
        'sync_state' => Image::SYNC_STATE_SYNCED, 'shopify_id' => $mediaId,
        'last_shopify_synced_image_asset_id' => $confirmed ? $asset->id : null,
        'needs_shopify_image_sync' => ! $confirmed, 'alt_text' => 'Component sentence',
        'alt_text_status' => Image::ALT_APPROVED,
    ]));

    return [$product, $image->fresh('imageAsset'), $asset];
}

final class PropagationHttp
{
    public array $nodesByProduct = [];

    public array $seen = [];

    public int $creates = 0;

    public int $fileUpdates = 0;

    public array $fileUpdateIds = [];

    public int $deletes = 0;

    public array $deletedIds = [];

    public bool $failStack = false;

    public ?string $failProductId = null;

    public string $bytes = 'photo-bytes';

    public bool $processing = false;

    public bool $failDelete = false;

    public bool $variantError = false;

    public bool $incompleteVariants = false;

    public bool $trackVariants = false;

    public bool $loseNextAppend = false;

    public int $appends = 0;

    public array $variantMediaIds = [];

    public ?\Closure $onRetirementRead = null;

    public function install(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                $url = strtok((string) $request->url(), '?') ?: '';

                return Http::response($this->bytesByUrl[$url] ?? $this->bytes);
            }
            $payload = $request->data();
            if (! is_array($payload) || ! isset($payload['query'])) {
                $payload = json_decode((string) $request->body(), true) ?: [];
            }
            $query = (string) ($payload['query'] ?? '');
            $variables = $payload['variables'] ?? [];
            if (is_object($variables)) {
                $variables = get_object_vars($variables);
            }
            if (str_contains($query, 'productCreateMedia')) {
                $this->seen[] = $variables['productId'] ?? null;
                $productId = (string) ($variables['productId'] ?? '');
                $this->creates++;
                $id = 'gid://shopify/MediaImage/'.(200 + $this->creates);
                $this->nodesByProduct[$productId][] = $this->node($id);

                return Http::response(['data' => ['productCreateMedia' => ['media' => [['id' => $id]], 'mediaUserErrors' => []]]]);
            }
            if (str_contains($query, 'DetachInheritedStackMedia')) {
                if ($this->failDelete) {
                    throw new RuntimeException('Shopify association-removal response lost.');
                }
                $productId = (string) ($variables['files'][0]['referencesToRemove'][0] ?? '');
                $ids = array_column($variables['files'], 'id');
                $this->deletes++;
                $this->deletedIds = array_merge($this->deletedIds, $ids);
                $this->nodesByProduct[$productId] = array_values(array_filter(
                    $this->nodesByProduct[$productId] ?? [],
                    fn (array $node): bool => ! in_array($node['id'], $ids, true)
                ));

                return Http::response(['data' => ['fileUpdate' => ['files' => [['id' => $ids[0]]], 'userErrors' => []]]]);
            }
            if (str_contains($query, 'StackRetirementVariants')) {
                return Http::response(['data' => ['product' => [
                    'variants' => ['nodes' => $this->variantError || $this->incompleteVariants || $this->trackVariants ? [['id' => 'gid://shopify/ProductVariant/1']] : [], 'pageInfo' => ['hasNextPage' => $this->incompleteVariants, 'endCursor' => null]],
                ]]]);
            }
            if (str_contains($query, 'StackRetirementVariantMedia')) {
                $media = array_map(fn (string $id): array => ['id' => $id], $this->variantMediaIds);

                return Http::response(['data' => ['productVariant' => [
                    'media' => ['nodes' => $media, 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
                ]]]);
            }
            if (str_contains($query, 'productVariantAppendMedia')) {
                $this->appends++;
                if ($this->variantError) {
                    return Http::response(['data' => ['productVariantAppendMedia' => ['userErrors' => [['message' => 'Variant media was rejected']]]]]);
                }
                foreach ((array) ($variables['variantMedia'][0]['mediaIds'] ?? []) as $id) {
                    $this->variantMediaIds[] = (string) $id;
                }
                $this->variantMediaIds = array_values(array_unique($this->variantMediaIds));
                if ($this->loseNextAppend) {
                    $this->loseNextAppend = false;
                    throw new RuntimeException('Shopify append response was lost.');
                }

                return Http::response(['data' => ['productVariantAppendMedia' => ['userErrors' => []]]]);
            }
            if (str_contains($query, 'productVariantDetachMedia')) {
                $removed = (array) ($variables['variantMedia'][0]['mediaIds'] ?? []);
                $this->variantMediaIds = array_values(array_diff($this->variantMediaIds, $removed));

                return Http::response(['data' => ['productVariantDetachMedia' => ['userErrors' => []]]]);
            }
            if (str_contains($query, 'productReorderMedia')) {
                return Http::response(['data' => ['productReorderMedia' => ['mediaUserErrors' => []]]]);
            }
            if (str_contains($query, 'fileUpdate')) {
                $this->fileUpdates++;
                $file = $variables['files'][0] ?? [];
                $id = (string) ($file['id'] ?? '');
                $alt = $file['alt'] ?? null;
                foreach ($this->nodesByProduct as $productKey => $nodes) {
                    foreach ($nodes as $index => $node) {
                        if (($node['id'] ?? null) === $id) {
                            $this->nodesByProduct[$productKey][$index]['alt'] = $alt;
                            $this->fileUpdateIds[] = $id;
                        }
                    }
                }

                return Http::response(['data' => ['fileUpdate' => ['files' => [['id' => $id, 'alt' => $alt]], 'userErrors' => []]]]);
            }
            $productId = (string) ($variables['id'] ?? '');
            if ($this->failStack && $productId === $this->failProductId) {
                throw new RuntimeException('Shopify was unavailable for this stack.');
            }
            $nodes = $this->nodesByProduct[$productId] ?? [];
            if ($this->onRetirementRead instanceof \Closure && str_contains($query, 'StackRetirementMedia')) {
                ($this->onRetirementRead)();
            }
            if ($this->processing) {
                $nodes = array_map(function (array $node): array {
                    $node['status'] = 'PROCESSING';
                    $node['fileStatus'] = 'PROCESSING';

                    return $node;
                }, $nodes);
            }

            return Http::response(['data' => ['product' => [
                'media' => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
                'images' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
            ]]]);
        });
    }

    public array $bytesByUrl = [];

    public function node(string $id): array
    {
        $url = 'https://cdn.shopify.com/s/files/'.substr(sha1($id), 0, 12).'.jpg';
        $this->bytesByUrl[$url] = $this->bytes;

        return [
            'id' => $id, 'alt' => null, 'status' => 'READY', 'fileStatus' => 'READY',
            'image' => ['url' => $url],
            'originalSource' => ['url' => $url],
        ];
    }
}

it('allows explicit retention of uncertain retirement without replaying any Shopify mutation', function () {
    $stack = createImageImportProduct('RETAIN-OLD', ['is_bundle' => true]);
    $image = Image::withoutEvents(fn () => Image::create(['product_id' => $stack->id, 'ownership' => ImageOwnership::COMPONENT_INHERITED, 'shopify_id' => 'gid://shopify/MediaImage/501', 'alt_text' => 'Keep this description', 'sync_state' => Image::SYNC_STATE_SYNCED]));
    $intent = DB::table('inherited_media_retirements')->insertGetId(['stack_product_id' => $stack->id, 'image_id' => $image->id,
        'old_media_id' => $image->shopify_id, 'status' => 'mutating', 'last_operation' => 'delete',
        'last_operation_target' => $stack->shopify_id, 'created_at' => now(), 'updated_at' => now()]);
    $http = new PropagationHttp;
    $http->nodesByProduct[$stack->shopify_id] = [$http->node($image->shopify_id)];
    $http->install();
    $operator = propagationOperator();
    $this->artisan('images:resolve-media-retirement', ['retirement' => $intent, '--operator' => $operator->id, '--reason' => 'Reviewed the uncertain removal; keep the old photograph explicitly.'])->assertExitCode(1);
    expect(DB::table('inherited_media_retirements')->find($intent)->status)->toBe('mutating');
    $this->artisan('images:resolve-media-retirement', ['retirement' => $intent, '--operator' => $operator->id, '--reason' => 'Reviewed the uncertain removal; keep the old photograph explicitly.', '--keep' => true])->assertExitCode(0);
    expect(DB::table('inherited_media_retirements')->find($intent)->status)->toBe('retained')
        ->and($image->fresh()->alt_text)->toBe('Keep this description')->and($image->fresh()->sync_state)->toBe(Image::SYNC_STATE_SYNCED)
        ->and(DB::table('image_alt_review_events')->where('action', 'resolve_retirement')->count())->toBe(1)
        ->and($http->deletes)->toBe(0)->and($http->fileUpdates)->toBe(0)->and($http->appends)->toBe(0);
});

it('does nothing while automatic propagation is disabled', function () {
    [$component, $image] = propagationComponent('OFF', 'photo-bytes', 'gid://shopify/MediaImage/1');
    app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image);
    expect(DB::table('component_publication_outbox')->count())->toBe(0)
        ->and($component->isStackProduct())->toBeFalse();
});

it('publishes a confirmed component photograph to every dependent stack', function () {
    propagationOn();
    [$component, $image] = propagationComponent('COMP', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $first = propagationStack('STACK-A', [$component->id]);
    $second = propagationStack('STACK-B', [$component->id]);
    $http = new PropagationHttp;
    $http->bytes = 'photo-bytes';
    $http->install();
    app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    $tasks = DB::table('stack_image_sync_tasks')->get();
    expect($tasks)->toHaveCount(2)
        ->and($tasks->every(fn ($task) => $task->phase === 'verified'))->toBeTrue()
        ->and(Image::query()->where('product_id', $first->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->whereNotNull('shopify_id')->exists())->toBeTrue()
        ->and(Image::query()->where('product_id', $second->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->whereNotNull('shopify_id')->exists())->toBeTrue();
});

it('stages a component photograph before it has a shopify media id and does not upload it', function () {
    [$component, $image] = propagationComponent('STAGE', 'photo-bytes', null, false);
    $stack = propagationStack('STACK-STAGE', [$component->id]);
    $result = app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    expect($result['rebuilt'])->toBeTrue()
        ->and($image->shopify_id)->toBeNull()
        ->and($copy)->not->toBeNull()
        ->and($copy->shopify_id)->toBeNull()
        ->and($copy->needs_shopify_image_sync)->toBeTrue()
        ->and(DB::table('stack_image_sync_tasks')->count())->toBe(0);
});

it('selects the current primary photograph instead of an older shopify linked image', function () {
    $product = createImageImportProduct('PRIMARY');
    $oldAsset = createImportTestAsset('old-photo', 'jpg', 'old.jpg');
    $newAsset = createImportTestAsset('new-photo', 'jpg', 'new.jpg');
    Image::withoutEvents(fn () => Image::create([
        'product_id' => $product->id, 'position' => 2, 'image_asset_id' => $oldAsset->id,
        'shopify_id' => 'gid://shopify/MediaImage/9', 'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'sync_state' => Image::SYNC_STATE_SYNCED,
    ]));
    $primary = Image::withoutEvents(fn () => Image::create([
        'product_id' => $product->id, 'position' => 1, 'image_asset_id' => $newAsset->id,
        'shopify_id' => null, 'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'sync_state' => Image::SYNC_STATE_LOCAL_NEW, 'needs_shopify_image_sync' => true,
    ]));
    expect(app(ComponentPhotographSelector::class)->selected($product->fresh())->id)->toBe($primary->id);
});

it('keeps a failed stack recoverable while the other stack completes', function () {
    propagationOn();
    [$component, $image] = propagationComponent('SPLIT', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $ok = propagationStack('STACK-OK', [$component->id]);
    $bad = propagationStack('STACK-BAD', [$component->id]);
    $http = new PropagationHttp;
    $http->failStack = true;
    $http->failProductId = $bad->shopify_id;
    $http->install();
    try {
        app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    } catch (Throwable) {
    }
    $okTask = DB::table('stack_image_sync_tasks')->where('stack_product_id', $ok->id)->first();
    $badTask = DB::table('stack_image_sync_tasks')->where('stack_product_id', $bad->id)->first();
    expect($okTask->phase)->toBe('verified');
    expect($badTask->phase)->not->toBe('verified');
    $http->failStack = false;
    $http->install();
    app(ComponentStackPropagation::class)->synchronizeTask((int) $badTask->id);
    expect(DB::table('stack_image_sync_tasks')->where('id', $badTask->id)->value('phase'))->toBe('verified');
});

it('leaves stack owned images at every position independent of a component rebuild', function () {
    [$component] = propagationComponent('OWN', 'photo-bytes', null, false);
    $stack = propagationStack('STACK-OWNED', [$component->id]);
    $owned = [];
    foreach ([1, 2, 3] as $position) {
        $owned[$position] = Image::withoutEvents(fn () => Image::create([
            'product_id' => $stack->id, 'position' => $position, 'ownership' => ImageOwnership::STACK_OWNED,
            'alt_text' => 'Owned '.$position, 'alt_text_status' => Image::ALT_APPROVED,
            'sync_state' => Image::SYNC_STATE_SYNCED, 'shopify_id' => 'gid://shopify/MediaImage/8'.$position,
        ]));
    }
    app(ComponentStackPropagation::class)->stageStack($stack->fresh());
    foreach ($owned as $position => $image) {
        $fresh = $image->fresh();
        expect($fresh->ownership)->toBe(ImageOwnership::STACK_OWNED)
            ->and($fresh->position)->toBe($position)
            ->and($fresh->alt_text)->toBe('Owned '.$position)
            ->and($fresh->sync_state)->toBe(Image::SYNC_STATE_SYNCED);
    }
});

it('does not accumulate an active inherited photograph when the same generation is staged again', function () {
    [$component] = propagationComponent('REPEAT', 'photo-bytes', null, false);
    $stack = propagationStack('STACK-REPEAT', [$component->id]);
    app(ComponentStackPropagation::class)->stageStack($stack);
    app(ComponentStackPropagation::class)->stageStack($stack->fresh());
    expect(Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->active()->count())->toBe(1);
});

it('supersedes an in flight stack task when a newer component generation is confirmed', function () {
    propagationOn();
    [$component, $image, $asset] = propagationComponent('GEN', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $stack = propagationStack('STACK-GEN', [$component->id]);
    $service = app(ComponentStackPropagation::class);
    Bus::fake();
    $service->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    $service->fanOut((int) DB::table('component_publication_outbox')->value('id'));
    $first = DB::table('component_photograph_generations')->first();
    $task = DB::table('stack_image_sync_tasks')->where('generation_id', $first->id)->first();
    $replacement = createImportTestAsset('second-photo', 'jpg', 'second.jpg');
    Image::withoutEvents(fn () => $image->forceFill([
        'image_asset_id' => $replacement->id, 'last_shopify_synced_image_asset_id' => $replacement->id,
        'shopify_id' => 'gid://shopify/MediaImage/8',
    ])->save());
    $service->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    $service->synchronizeTask((int) $task->id);
    expect(DB::table('stack_image_sync_tasks')->where('id', $task->id)->value('phase'))->toBe('superseded')
        ->and(DB::table('stack_image_sync_tasks')->where('stack_product_id', $stack->id)->where('phase', '!=', 'superseded')->exists())->toBeTrue()
        ->and($asset->id)->not->toBe($replacement->id);
});

it('reconciles hidden deleted and reordered component selection without keeping the old primary', function () {
    propagationOn();
    Bus::fake();
    [$component, $first] = propagationComponent('ORDER', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $secondAsset = createImportTestAsset('other-photo', 'jpg', 'other.jpg');
    $second = Image::withoutEvents(fn () => Image::create([
        'product_id' => $component->id, 'position' => 2, 'image_asset_id' => $secondAsset->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP, 'shopify_id' => 'gid://shopify/MediaImage/8',
        'last_shopify_synced_image_asset_id' => $secondAsset->id, 'needs_shopify_image_sync' => false,
        'sync_state' => Image::SYNC_STATE_SYNCED,
    ]));
    $service = app(ComponentStackPropagation::class);
    $service->recordConfirmedPhotograph($first->fresh(['product', 'imageAsset']), true);
    Image::withoutEvents(fn () => $first->forceFill(['position' => 2])->save());
    Image::withoutEvents(fn () => $second->forceFill(['position' => 1])->save());
    $service->reconcileComponentSelection((int) $component->id);
    expect(DB::table('component_photograph_generations')->where('status', 'confirmed')->where('component_image_id', $first->id)->exists())->toBeFalse();
    $service->recordConfirmedPhotograph($second->fresh(['product', 'imageAsset']), true);
    $latest = DB::table('component_photograph_generations')->where('status', 'confirmed')->orderByDesc('id')->first();
    expect((int) $latest->component_image_id)->toBe($second->id);
    Image::withoutEvents(fn () => $second->forceFill(['is_duplicate_hidden' => true])->save());
    expect(app(ComponentPhotographSelector::class)->selected($component->fresh()))->toBeNull();
});

it('keeps a provenance row for every component that shares photograph bytes and does not choose between conflicting alt text', function () {
    $bytes = 'shared-photo';
    [$first, $firstImage] = propagationComponent('SHARE-A', $bytes, null, false);
    $second = createImageImportProduct('SHARE-B');
    $secondImage = Image::withoutEvents(fn () => Image::create([
        'product_id' => $second->id, 'position' => 1, 'image_asset_id' => $firstImage->image_asset_id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP, 'alt_text' => 'Different sentence',
        'sync_state' => Image::SYNC_STATE_SYNCED,
    ]));
    Image::withoutEvents(fn () => $firstImage->forceFill(['alt_text' => 'First sentence'])->save());
    $stack = propagationStack('STACK-SHARE', [$first->id, $second->id]);
    $result = app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    expect($result['rebuilt'])->toBeTrue()
        ->and(DB::table('inherited_image_sources')->where('image_id', $copy->id)->count())->toBe(2)
        ->and($copy->alt_text)->toBeNull()
        ->and($copy->source_image_id)->not->toBeNull()
        ->and($secondImage->id)->not->toBe($firstImage->id);
});

it('leaves an upload claim unresolved when the worker stops before shopify confirmation', function () {
    propagationOn();
    [$component, $image] = propagationComponent('CRASH', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $stack = propagationStack('STACK-CRASH', [$component->id]);
    Bus::fake();
    $service = app(ComponentStackPropagation::class);
    $service->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    $service->fanOut((int) DB::table('component_publication_outbox')->value('id'));
    $task = DB::table('stack_image_sync_tasks')->where('stack_product_id', $stack->id)->first();
    expect($task->phase)->toBe('pending')
        ->and($task->applied_generation_id)->toBeNull()
        ->and(Image::query()->where('product_id', $stack->id)->whereNotNull('shopify_id')->exists())->toBeFalse()
        ->and(DB::table('image_upload_operations')->count())->toBe(0);
});

it('does not mark processing media as synchronized', function () {
    propagationOn();
    [$component, $image] = propagationComponent('LOCK', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $stack = propagationStack('STACK-LOCK', [$component->id]);
    Bus::fake();
    $service = app(ComponentStackPropagation::class);
    $service->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    $service->fanOut((int) DB::table('component_publication_outbox')->value('id'));
    $taskId = (int) DB::table('stack_image_sync_tasks')->where('stack_product_id', $stack->id)->value('id');
    $http = new PropagationHttp;
    $http->processing = true;
    $http->install();
    try {
        $service->synchronizeTask($taskId);
    } catch (Throwable) {
    }
    expect(DB::table('stack_image_sync_tasks')->where('id', $taskId)->value('phase'))->not->toBe('verified');
});

it('does not replay an uncertain image mutation from the shopify client', function () {
    $calls = 0;
    Http::fake(function () use (&$calls) {
        $calls++;

        return Http::response(['errors' => [['message' => 'Throttled', 'extensions' => ['code' => 'THROTTLED']]], 'data' => ['productCreateMedia' => ['media' => [['id' => 'gid://shopify/MediaImage/1']]]]], 200);
    });
    $client = app(ShopifyGraphqlGateway::class);
    expect(fn () => $client->graphql('mutation productCreateMedia($productId: ID!) { productCreateMedia(productId: $productId, media: []) { media { id } } }', []))->toThrow(RuntimeException::class);
    expect($calls)->toBe(1);
});

it('retires only the superseded stack attachment and keeps a stack owned file', function () {
    propagationOn();
    [$component, $image, $asset] = propagationComponent('RETIRE', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $stack = propagationStack('STACK-RETIRE', [$component->id]);
    $owned = Image::withoutEvents(fn () => Image::create([
        'product_id' => $stack->id, 'position' => 1, 'ownership' => ImageOwnership::STACK_OWNED,
        'shopify_id' => 'gid://shopify/MediaImage/50', 'alt_text' => 'Keep owned',
        'sync_state' => Image::SYNC_STATE_SYNCED,
    ]));
    $http = new PropagationHttp;
    $http->install();
    app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    $firstCopy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $replacement = createImportTestAsset('replacement-photo', 'jpg', 'replacement.jpg');
    $http->bytes = 'replacement-photo';
    $http->install();
    Image::withoutEvents(fn () => $image->forceFill([
        'image_asset_id' => $replacement->id, 'last_shopify_synced_image_asset_id' => $replacement->id,
        'shopify_id' => 'gid://shopify/MediaImage/9',
    ])->save());
    app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    expect($owned->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/50')
        ->and($owned->fresh()->sync_state)->toBe(Image::SYNC_STATE_SYNCED)
        ->and($owned->fresh()->alt_text)->toBe('Keep owned')
        ->and($firstCopy->fresh()->sync_state)->toBe(Image::SYNC_STATE_LOCAL_DELETED)
        ->and($http->deletedIds)->toContain($firstCopy->shopify_id)
        ->and($http->deletedIds)->not->toContain('gid://shopify/MediaImage/50')
        ->and($asset->sha256)->not->toBe($replacement->sha256);
});

it('keeps approved stack owned alt text when an inherited photograph is staged', function () {
    [$component] = propagationComponent('ALT', 'photo-bytes', null, false);
    $stack = propagationStack('STACK-ALT', [$component->id]);
    $owned = Image::withoutEvents(fn () => Image::create([
        'product_id' => $stack->id, 'position' => 2, 'ownership' => ImageOwnership::STACK_OWNED,
        'alt_text' => 'Approved lifestyle', 'alt_text_status' => Image::ALT_APPROVED,
        'sync_state' => Image::SYNC_STATE_SYNCED,
    ]));
    app(ComponentStackPropagation::class)->stageStack($stack);
    expect($owned->fresh()->alt_text)->toBe('Approved lifestyle')
        ->and($owned->fresh()->alt_text_status)->toBe(Image::ALT_APPROVED);
});

it('does not treat an obsolete component approval as approval of the replacement photograph', function () {
    $operator = User::factory()->create();
    $product = createImageImportProduct('OBSOLETE');
    $old = createImportTestAsset('old-photo', 'jpg', 'old.jpg');
    $image = Image::withoutEvents(fn () => Image::create([
        'product_id' => $product->id, 'position' => 1, 'image_asset_id' => $old->id,
        'alt_text' => 'Old sentence', 'alt_text_status' => Image::ALT_APPROVED,
        'last_shopify_synced_image_asset_id' => $old->id, 'shopify_id' => 'gid://shopify/MediaImage/3',
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP, 'sync_state' => Image::SYNC_STATE_SYNCED,
    ]));
    $request = ProductPartialApprovalRequest::withoutEvents(fn () => ProductPartialApprovalRequest::create([
        'product_id' => $product->id, 'approval_version' => 1, 'requested_by' => $operator->id, 'status' => ProductPartialApprovalRequest::STATUS_APPROVED,
        'scopes' => ['image_alt'], 'core_fields' => [], 'request_batch_id' => (string) Str::uuid(),
        'image_alt_snapshot' => ['images' => [['image_id' => $image->id, 'image_asset_id' => $old->id, 'proposed_alt' => 'Old sentence', 'changed' => true]]],
        'approved_at' => now(),
    ]));
    $replacement = createImportTestAsset('new-photo', 'jpg', 'new.jpg');
    Image::withoutEvents(fn () => $image->forceFill([
        'image_asset_id' => $replacement->id, 'alt_text' => 'Old sentence', 'shopify_identity_unresolved' => true,
    ])->save());
    expect(app(\App\Services\ProductPartialApprovalService::class)->approvedAlt($image->fresh()))->toBeNull()
        ->and($request->status)->toBe(ProductPartialApprovalRequest::STATUS_APPROVED);
});

it('keeps the publication outbox when queue dispatch does not run', function () {
    propagationOn();
    Bus::fake();
    [$component, $image] = propagationComponent('OUTBOX', 'photo-bytes', 'gid://shopify/MediaImage/7');
    propagationStack('STACK-OUTBOX', [$component->id]);
    app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    $outbox = DB::table('component_publication_outbox')->first();
    expect($outbox->status)->toBe('pending')
        ->and(Bus::dispatched(DispatchComponentPublicationOutboxJob::class))->toHaveCount(1)
        ->and(DB::table('stack_image_sync_tasks')->count())->toBe(0);
    app(ComponentStackPropagation::class)->fanOut((int) $outbox->id);
    expect(DB::table('stack_image_sync_tasks')->count())->toBe(1)
        ->and(DB::table('component_publication_outbox')->where('id', $outbox->id)->value('status'))->toBe('dispatched');
});

it('still publishes a stack owned image through its own approval when no component changed', function () {
    $operator = User::factory()->create();
    $stack = createImageImportProduct('SOLO', ['is_bundle' => true]);
    $asset = createImportTestAsset('independent-stack-photograph', 'jpg', 'solo.jpg');
    $owned = Image::withoutEvents(fn () => Image::create([
        'product_id' => $stack->id, 'position' => 2, 'ownership' => ImageOwnership::STACK_OWNED,
        'image_asset_id' => $asset->id,
        'alt_text' => 'Independent sentence', 'alt_text_status' => Image::ALT_APPROVED,
        'shopify_id' => 'gid://shopify/MediaImage/77', 'sync_state' => Image::SYNC_STATE_SYNCED,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
    ]));
    $approvals = app(\App\Services\ProductPartialApprovalService::class);
    ProductPartialApprovalRequest::withoutEvents(fn () => ProductPartialApprovalRequest::create([
        'product_id' => $stack->id, 'approval_version' => $stack->approval_version ?? 1,
        'requested_by' => $operator->id, 'status' => ProductPartialApprovalRequest::STATUS_APPROVED,
        'scopes' => ['image_alt'], 'core_fields' => [], 'request_batch_id' => (string) Str::uuid(),
        'image_alt_snapshot' => $approvals->imageAltSnapshot($stack->fresh()), 'approved_at' => now(),
    ]));
    expect(ImageOwnership::isStackOwned($owned))->toBeTrue()
        ->and(ImageOwnership::isInherited($owned))->toBeFalse()
        ->and($approvals->approvedAlt($owned->fresh()))->toBe('Independent sentence')
        ->and(DB::table('component_publication_outbox')->count())->toBe(0);
});

it('reports unclassified stack images without assigning ownership', function () {
    $stack = createImageImportProduct('LEGACY', ['is_bundle' => true]);
    $image = Image::withoutEvents(fn () => Image::create([
        'product_id' => $stack->id, 'position' => 2, 'sync_state' => Image::SYNC_STATE_SYNCED,
    ]));
    $this->artisan('images:legacy-image-ownership-report')->assertSuccessful();
    expect($image->fresh()->ownership)->toBeNull()
        ->and(ImageOwnership::effective($image->fresh('product')))->toBe(ImageOwnership::UNRESOLVED);
});

it('does not confirm a component generation without shopify evidence or product approval', function () {
    propagationOn();
    [$component, $image] = propagationComponent('EVIDENCE', 'photo-bytes', 'gid://shopify/MediaImage/7');
    app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), false);
    expect(DB::table('component_photograph_generations')->count())->toBe(0);
    $unapproved = createImageImportProduct('UNAPPROVED');
    $asset = createImportTestAsset('unapproved-bytes', 'jpg', 'unapproved.jpg');
    $plain = Image::withoutEvents(fn () => Image::create([
        'product_id' => $unapproved->id, 'position' => 1, 'image_asset_id' => $asset->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP, 'shopify_id' => 'gid://shopify/MediaImage/8',
        'last_shopify_synced_image_asset_id' => $asset->id, 'needs_shopify_image_sync' => false,
        'sync_state' => Image::SYNC_STATE_SYNCED,
    ]));
    app(ComponentStackPropagation::class)->recordConfirmedPhotograph($plain->fresh(['product', 'imageAsset']), true);
    expect(DB::table('component_publication_outbox')->count())->toBe(0)
        ->and($component->isApprovedByTwo())->toBeTrue()
        ->and($unapproved->fresh()->isApprovedByTwo())->toBeFalse();
});

it('uploads an approved cms photograph to the stack when the component has no shopify identity', function () {
    config([
        'images.component_stack_propagation' => false,
        'images.component_stack_local_rebuild' => false,
        'images.component_stack_blind_publication' => false,
    ]);
    Storage::fake('public');
    Bus::fake();
    $component = createImageImportProduct('H2COMP');
    $asset = createImportTestAsset('approved-stack-bytes', 'jpg', 'H2COMP.jpg');
    $source = Image::withoutEvents(fn () => Image::create([
        'product_id' => $component->id, 'position' => 1, 'image_asset_id' => $asset->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP, 'backup_completed_at' => now(),
        'sync_state' => Image::SYNC_STATE_LOCAL_NEW, 'shopify_id' => null,
        'shopify_identity_unresolved' => true, 'needs_shopify_image_sync' => true,
        'alt_text' => 'Component sentence',
    ]));
    $stack = propagationStack('H2STACK', [$component->id]);
    $partial = app(\App\Services\ProductPartialApprovalService::class);
    $partial->request(collect([$component->fresh()]), User::factory()->create()->id, ['images'], []);
    $request = \App\Models\ProductPartialApprovalRequest::query()->where('product_id', $component->id)->first();
    expect($partial->approveRequests(collect([$request]), User::factory()->create()->id)['approved'])->toBe(1)
        ->and(app(ComponentPhotographSelector::class)->publicationAllowed($source->fresh()))->toBeFalse()
        ->and(app(\App\Services\StackImageSync::class)->photographAuthorized($source->fresh()))->toBeTrue();
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $http = new PropagationHttp;
    $http->bytes = 'approved-stack-bytes';
    $http->install();
    $published = app(\App\Services\ProductShopifyUpdater::class)->synchronizeInheritedStackImage($stack->fresh(), $copy->id);
    expect($published['confirmed'])->toBeTrue()
        ->and($http->creates)->toBeGreaterThan(0)
        ->and($copy->fresh()->shopify_id)->not->toBeNull()
        ->and($source->fresh()->shopify_id)->toBeNull()
        ->and((int) $copy->fresh()->image_asset_id)->toBe((int) $asset->id);

    $approvedMediaId = $copy->fresh()->shopify_id;
    $replacement = createImportTestAsset('unapproved-replacement', 'jpg', 'H2COMP-new.jpg');
    $source->fresh()->forceFill(['image_asset_id' => $replacement->id])->save();
    app(ComponentStackPropagation::class)->stageStack($stack->fresh());
    $unapproved = Image::query()->where('product_id', $stack->id)->where('image_asset_id', $replacement->id)->first();
    $http->creates = 0;
    $http->bytes = 'unapproved-replacement';
    $http->install();
    $blocked = app(\App\Services\ProductShopifyUpdater::class)->synchronizeInheritedStackImage($stack->fresh(), $unapproved->id);
    expect($blocked['confirmed'])->toBeFalse()
        ->and($http->creates)->toBe(0)
        ->and($copy->fresh()->shopify_id)->toBe($approvedMediaId)
        ->and(collect($blocked['warnings'])->pluck('warning')->implode(' '))->toContain('not approved and confirmed');
});

it('publishes a newly approved inherited sentence without uploading the photograph', function () {
    config([
        'images.component_stack_propagation' => false,
        'images.component_stack_local_rebuild' => false,
        'images.component_stack_blind_publication' => false,
    ]);
    Storage::fake('public');
    Bus::fake();
    $component = createImageImportProduct('ALTCOMP');
    $asset = createImportTestAsset('stable-photograph', 'jpg', 'ALTCOMP.jpg');
    $source = Image::withoutEvents(fn () => Image::create([
        'product_id' => $component->id, 'position' => 1, 'image_asset_id' => $asset->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP, 'backup_completed_at' => now(),
        'sync_state' => Image::SYNC_STATE_SYNCED, 'shopify_id' => 'gid://shopify/MediaImage/15',
        'last_shopify_synced_image_asset_id' => $asset->id, 'needs_shopify_image_sync' => false,
        'alt_text' => 'First sentence',
    ]));
    $stack = propagationStack('ALTSTACK', [$component->id]);
    app(ImageAltTextBackfillService::class)->markPendingReview($source->fresh(), 'Second sentence');
    $partial = app(ProductPartialApprovalService::class);
    $partial->request(collect([$component->fresh()]), User::factory()->create()->id, ['image_alt'], []);
    $request = ProductPartialApprovalRequest::query()->where('product_id', $component->id)->first();
    expect($partial->approveRequests(collect([$request]), User::factory()->create()->id)['approved'])->toBe(1);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $copy->forceFill([
        'shopify_id' => 'gid://shopify/MediaImage/77',
        'shopify_alt_text' => 'First sentence',
        'needs_shopify_image_sync' => false,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'last_shopify_synced_image_asset_id' => $asset->id,
        'alt_text_status' => Image::ALT_PUBLISHED,
    ])->save();
    $http = new PropagationHttp;
    $http->bytes = 'stable-photograph';
    $http->nodesByProduct[$stack->shopify_id] = [$http->node('gid://shopify/MediaImage/77')];
    $http->install();
    $result = app(ImageAltTextBackfillService::class)->publishInheritedCopy($copy->fresh());

    expect($result['published'])->toBe(1)
        ->and($result['failed'])->toBe([])
        ->and($http->creates)->toBe(0)
        ->and($http->fileUpdates)->toBe(1)
        ->and($copy->fresh()->shopify_alt_text)->toBe('Second sentence')
        ->and($copy->fresh()->needs_shopify_image_sync)->toBeFalse()
        ->and($copy->fresh()->image_asset_id)->toBe($asset->id);
});

it('does not upload an inherited stack photograph before its component generation is approved and confirmed', function () {
    $component = createImageImportProduct('PLAIN');
    $asset = createImportTestAsset('photo-bytes', 'jpg', 'plain.jpg');
    $source = Image::withoutEvents(fn () => Image::create([
        'product_id' => $component->id, 'position' => 1, 'image_asset_id' => $asset->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP, 'sync_state' => Image::SYNC_STATE_SYNCED,
    ]));
    $stack = propagationStack('STACK-GATE', [$component->id]);
    $copy = Image::withoutEvents(fn () => Image::create([
        'product_id' => $stack->id, 'position' => 2, 'ownership' => ImageOwnership::COMPONENT_INHERITED,
        'source_image_id' => $source->id, 'image_asset_id' => $asset->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP, 'needs_shopify_image_sync' => true,
        'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
    ]));
    (new PropagationHttp)->install();
    $result = app(\App\Services\ProductShopifyUpdater::class)->synchronizeInheritedStackImage($stack->fresh(), $copy->id);
    expect($result['confirmed'])->toBeFalse()
        ->and(collect($result['warnings'])->pluck('warning')->implode(' '))->toContain('not approved and confirmed');
});

it('never replays uncertain retirement and finishes only after fresh absence evidence', function () {
    propagationOn();
    [$component, $image] = propagationComponent('RETIRE-FAIL', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $stack = propagationStack('STACK-RETIRE-FAIL', [$component->id]);
    $http = new PropagationHttp;
    $http->install();
    app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    $first = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $replacement = createImportTestAsset('replacement-photo', 'jpg', 'replacement-fail.jpg');
    $http->bytes = 'replacement-photo';
    $http->failDelete = true;
    $http->install();
    Image::withoutEvents(fn () => $image->forceFill([
        'image_asset_id' => $replacement->id, 'last_shopify_synced_image_asset_id' => $replacement->id,
        'shopify_id' => 'gid://shopify/MediaImage/9',
    ])->save());
    try {
        app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    } catch (Throwable) {
    }
    expect($first->fresh()->sync_state)->toBe(Image::SYNC_STATE_SYNCED)
        ->and(DB::table('inherited_media_retirements')->whereIn('status', ['pending', 'mutating'])->count())->toBeGreaterThan(0);
    $http->failDelete = false;
    $http->install();
    $taskId = (int) DB::table('stack_image_sync_tasks')->where('phase', '!=', 'verified')->value('id');
    expect(fn () => app(ComponentStackPropagation::class)->synchronizeTask($taskId))->toThrow(RuntimeException::class, 'will not be replayed');
    expect($first->fresh()->sync_state)->toBe(Image::SYNC_STATE_SYNCED)->and($http->deletes)->toBe(0);
    // Simulate delayed visibility of an accepted association removal, not a retry.
    $http->nodesByProduct[$stack->shopify_id] = array_values(array_filter($http->nodesByProduct[$stack->shopify_id], fn ($node) => $node['id'] !== $first->shopify_id));
    app(ComponentStackPropagation::class)->synchronizeTask($taskId);
    expect($first->fresh()->sync_state)->toBe(Image::SYNC_STATE_LOCAL_DELETED)
        ->and($http->deletes)->toBe(0);
});

it('does not let an obsolete retirement delete the current photograph', function () {
    propagationOn();
    [$component, $image] = propagationComponent('STALE', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $stack = propagationStack('STACK-STALE', [$component->id]);
    $http = new PropagationHttp;
    $http->install();
    app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    $first = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $replacement = createImportTestAsset('replacement-photo', 'jpg', 'stale-next.jpg');
    $http->bytes = 'replacement-photo';
    $http->onRetirementRead = function () use ($component, $image, $replacement): void {
        DB::table('component_photograph_generations')->where('component_product_id', $component->id)->update(['status' => 'superseded']);
        DB::table('component_photograph_generations')->insert([
            'component_product_id' => $component->id, 'component_image_id' => $image->id,
            'image_asset_id' => $replacement->id, 'asset_sha256' => $replacement->sha256,
            'selection_revision' => 99, 'status' => 'confirmed', 'confirmed_at' => now(),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    };
    $http->install();
    Image::withoutEvents(fn () => $image->forceFill([
        'image_asset_id' => $replacement->id, 'last_shopify_synced_image_asset_id' => $replacement->id,
        'shopify_id' => 'gid://shopify/MediaImage/9',
    ])->save());
    try {
        app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    } catch (Throwable) {
    }
    expect($first->fresh()->sync_state)->toBe(Image::SYNC_STATE_SYNCED)
        ->and($http->deletedIds)->toBe([]);
});

it('stops retirement when a variant mutation is rejected or pagination is incomplete', function () {
    propagationOn();
    [$component, $image] = propagationComponent('VARIANT', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $stack = propagationStack('STACK-VARIANT', [$component->id]);
    $http = new PropagationHttp;
    $http->install();
    app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    $first = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $replacement = createImportTestAsset('replacement-photo', 'jpg', 'variant-next.jpg');
    $http->bytes = 'replacement-photo';
    $http->variantError = true;
    $http->variantMediaIds = [$first->shopify_id];
    $http->install();
    Image::withoutEvents(fn () => $image->forceFill([
        'image_asset_id' => $replacement->id, 'last_shopify_synced_image_asset_id' => $replacement->id,
        'shopify_id' => 'gid://shopify/MediaImage/9',
    ])->save());
    try {
        app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    } catch (Throwable) {
    }
    expect($http->deletes)->toBe(0)->and($first->fresh()->sync_state)->toBe(Image::SYNC_STATE_SYNCED);
});

it('does not select file status on the media interface', function () {
    $selection = \App\Services\ShopifyProductMediaReader::mediaNodeSelection();
    expect(\App\Services\ShopifyProductMediaReader::selectsFileStatusOnMediaInterface($selection))->toBeFalse()
        ->and(\App\Services\ShopifyProductMediaReader::selectsFileStatusOnMediaInterface('nodes { id status fileStatus }'))->toBeTrue();
});

it('reconciles a stack added or removed after the publication outbox was dispatched', function () {
    propagationOn();
    [$component, $image] = propagationComponent('MEMBER', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $stack = propagationStack('STACK-MEMBER', [$component->id]);
    $http = new PropagationHttp;
    $http->install();
    $service = app(ComponentStackPropagation::class);
    $service->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    expect(DB::table('component_publication_outbox')->value('status'))->toBe('dispatched');
    $added = propagationStack('STACK-ADDED', [$component->id]);
    $service->reconcileDraftMembership(NewProductDraft::query()->where('handle', $added->handle)->first());
    expect(DB::table('stack_image_sync_tasks')->where('stack_product_id', $added->id)->where('purpose', 'publish')->where('phase', 'verified')->exists())->toBeTrue();
    $original = NewProductDraft::query()->where('handle', $stack->handle)->first();
    NewProductDraft::withoutEvents(fn () => $original->forceFill(['bundle_product_ids' => []])->save());
    $service->reconcileDraftMembership($original->fresh());
    expect(DB::table('stack_image_sync_tasks')->where('stack_product_id', $stack->id)->where('purpose', 'remove')->exists())->toBeTrue();
});

it('uses the current stack draft and ignores an older draft that still lists the component', function () {
    propagationOn();
    Bus::fake();
    [$component, $image] = propagationComponent('HISTORY', 'history-bytes', 'gid://shopify/MediaImage/7');
    $stack = propagationStack('STACK-HISTORY', [$component->id]);
    $current = NewProductDraft::query()->where('handle', $stack->handle)->first();
    NewProductDraft::withoutEvents(fn () => $current->forceFill(['bundle_product_ids' => []])->save());
    $old = NewProductDraft::withoutEvents(fn () => NewProductDraft::create([
        'sku' => 'STACK-HISTORY-OLD', 'shopify_id' => $stack->shopify_id, 'handle' => 'stack-history-old',
        'title' => 'Old', 'status' => 'active', 'bundle_product_ids' => [$component->id],
    ]));
    DB::table('new_product_drafts')->where('id', $old->id)->update(['updated_at' => now()->subDay()]);
    app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    app(ComponentStackPropagation::class)->fanOut((int) DB::table('component_publication_outbox')->value('id'));
    expect(DB::table('stack_image_sync_tasks')->where('stack_product_id', $stack->id)->exists())->toBeFalse();
});

it('opens a new generation when a previous photograph is selected again', function () {
    propagationOn();
    Bus::fake();
    [$component, $image, $asset] = propagationComponent('CYCLE', 'photo-bytes', 'gid://shopify/MediaImage/7');
    propagationStack('STACK-CYCLE', [$component->id]);
    $service = app(ComponentStackPropagation::class);
    $service->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    $replacement = createImportTestAsset('second-photo', 'jpg', 'cycle-b.jpg');
    Image::withoutEvents(fn () => $image->forceFill([
        'image_asset_id' => $replacement->id, 'last_shopify_synced_image_asset_id' => $replacement->id,
        'shopify_id' => 'gid://shopify/MediaImage/8',
    ])->save());
    $service->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    Image::withoutEvents(fn () => $image->forceFill([
        'image_asset_id' => $asset->id, 'last_shopify_synced_image_asset_id' => $asset->id,
        'shopify_id' => 'gid://shopify/MediaImage/7',
    ])->save());
    $service->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    $generations = DB::table('component_photograph_generations')->orderBy('selection_revision')->get();
    expect($generations)->toHaveCount(3)
        ->and((int) $generations->last()->image_asset_id)->toBe($asset->id)
        ->and((int) $generations->last()->selection_revision)->toBe(3)
        ->and($generations->last()->status)->toBe('confirmed');
});

it('creates one task when two workers insert the same stack generation', function () {
    propagationOn();
    [$component, $image] = propagationComponent('RACE', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $stack = propagationStack('STACK-RACE', [$component->id]);
    Bus::fake();
    $service = app(ComponentStackPropagation::class);
    $service->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    $generationId = (int) DB::table('component_photograph_generations')->value('id');
    $first = $service->ensureStackTask($stack->id, $generationId);
    $second = $service->ensureStackTask($stack->id, $generationId);
    expect($first)->toBe($second)
        ->and(DB::table('stack_image_sync_tasks')->count())->toBe(1);
});

it('refuses to retry an exhausted task while a shopify upload is uncertain', function () {
    propagationOn();
    [$component, $image] = propagationComponent('EXHAUST', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $stack = propagationStack('STACK-EXHAUST', [$component->id]);
    Bus::fake();
    $service = app(ComponentStackPropagation::class);
    $service->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    $service->fanOut((int) DB::table('component_publication_outbox')->value('id'));
    $taskId = (int) DB::table('stack_image_sync_tasks')->value('id');
    DB::table('stack_image_sync_tasks')->where('id', $taskId)->update(['phase' => 'exhausted']);
    $copy = Image::withoutEvents(fn () => Image::create([
        'product_id' => $stack->id, 'ownership' => ImageOwnership::COMPONENT_INHERITED,
        'shopify_media_create_uncertain' => true, 'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
    ]));
    DB::table('inherited_image_sources')->insert([
        'image_id' => $copy->id, 'component_image_id' => $image->id, 'component_product_id' => $component->id,
        'image_asset_id' => $image->image_asset_id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $operator = propagationOperator();
    $this->artisan('images:recover-stack-image-task', ['task' => $taskId, '--reason' => 'Operator reviewed the exhausted stack task.', '--user' => $operator->id])
        ->assertFailed();
    Image::withoutEvents(fn () => $copy->forceFill(['shopify_media_create_uncertain' => false])->save());
    config(['images.component_stack_propagation' => false]);
    $this->artisan('images:recover-stack-image-task', ['task' => $taskId, '--reason' => 'Operator reviewed the exhausted stack task.', '--user' => $operator->id])
        ->assertSuccessful();
    expect(DB::table('stack_image_sync_tasks')->where('id', $taskId)->value('phase'))->toBe('pending')
        ->and((int) DB::table('stack_image_sync_task_audits')->where('task_id', $taskId)->where('action', 'reopen')->value('user_id'))->toBe($operator->id);
});

it('archives inherited alt text when approved source descriptions conflict', function () {
    $bytes = 'shared-conflict';
    [$first, $firstImage] = propagationComponent('ALT-A', $bytes, null, false);
    $second = createImageImportProduct('ALT-B');
    $secondImage = Image::withoutEvents(fn () => Image::create([
        'product_id' => $second->id, 'position' => 1, 'image_asset_id' => $firstImage->image_asset_id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP, 'alt_text' => 'Second approved sentence',
        'sync_state' => Image::SYNC_STATE_SYNCED,
    ]));
    Image::withoutEvents(fn () => $firstImage->forceFill(['alt_text' => 'First approved sentence'])->save());
    foreach ([[$first, $firstImage, 'First approved sentence'], [$second, $secondImage, 'Second approved sentence']] as [$product, $source, $sentence]) {
        $operator = User::factory()->create();
        ProductPartialApprovalRequest::withoutEvents(fn () => ProductPartialApprovalRequest::create([
            'product_id' => $product->id, 'approval_version' => $product->approval_version ?? 1,
            'requested_by' => $operator->id, 'status' => ProductPartialApprovalRequest::STATUS_APPROVED,
            'scopes' => ['image_alt'], 'core_fields' => [], 'request_batch_id' => (string) Str::uuid(),
            'image_alt_snapshot' => ['images' => [[
                'image_id' => $source->id, 'image_asset_id' => $source->image_asset_id,
                'proposed_alt' => $sentence, 'changed' => true,
                'photograph_version' => max(1, (int) $source->fresh()->photograph_version),
                'alt_text_version' => max(1, (int) $source->fresh()->alt_text_version),
            ]]],
            'approved_at' => now(),
        ]));
    }
    $stack = propagationStack('STACK-CONFLICT', [$first->id, $second->id]);
    $existing = Image::withoutEvents(fn () => Image::create([
        'product_id' => $stack->id, 'position' => 2, 'ownership' => ImageOwnership::COMPONENT_INHERITED,
        'image_asset_id' => $firstImage->image_asset_id, 'alt_text' => 'Historical inherited description',
        'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
    ]));
    app(ComponentStackPropagation::class)->stageStack($stack);
    expect($existing->fresh()->alt_text)->toBe('Historical inherited description')
        ->and(DB::table('image_revisions')->where('image_id', $existing->id)->exists())->toBeTrue()
        ->and(DB::table('inherited_image_sources')->where('image_id', $existing->id)->count())->toBe(2);
    $taskId = DB::table('stack_image_sync_tasks')->insertGetId([
        'stack_product_id' => $stack->id,
        'component_product_id' => $first->id,
        'component_image_id' => $firstImage->id,
        'generation_id' => DB::table('component_photograph_generations')->insertGetId([
            'component_product_id' => $first->id,
            'component_image_id' => $firstImage->id,
            'image_asset_id' => $firstImage->image_asset_id,
            'asset_sha256' => $firstImage->imageAsset->sha256,
            'selection_revision' => 1,
            'status' => 'confirmed',
            'created_at' => now(),
            'updated_at' => now(),
        ]),
        'purpose' => 'publish',
        'phase' => 'alt_conflict',
        'attempts' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    $operator = propagationOperator();
    expect(fn () => app(ComponentStackPropagation::class)->resolveInheritedAlt($taskId, 'Invented sentence', 'Operator reviewed the conflicting inherited description.', $operator->id))
        ->toThrow(RuntimeException::class, 'not an approved description');
    expect(fn () => app(ComponentStackPropagation::class)->resolveInheritedAlt($taskId, 'Invented sentence', 'Operator reviewed the conflicting inherited description.', null))
        ->toThrow(RuntimeException::class, 'authorized operator');
});

it('does not delete stack media when variant pagination does not advance', function () {
    $stack = createImageImportProduct('PAGE', ['is_bundle' => true]);
    $asset = createImportTestAsset('photo-bytes', 'jpg', 'obsolete.jpg');
    $image = Image::withoutEvents(fn () => Image::create(['product_id' => $stack->id, 'ownership' => ImageOwnership::COMPONENT_INHERITED, 'shopify_id' => 'gid://shopify/MediaImage/1', 'image_asset_id' => $asset->id, 'sync_state' => Image::SYNC_STATE_SYNCED]));
    $http = new PropagationHttp;
    $http->incompleteVariants = true;
    $http->install();
    $http->nodesByProduct[$stack->shopify_id] = [
        $http->node('gid://shopify/MediaImage/1'),
        $http->node('gid://shopify/MediaImage/2'),
    ];
    expect(fn () => app(ProductShopifyUpdater::class)->retireStackProductMedia(
        $stack,
        'gid://shopify/MediaImage/1',
        'gid://shopify/MediaImage/2',
        null,
        $image->id,
    ))->toThrow(RuntimeException::class, 'pagination did not advance');
    expect($http->deletes)->toBe(0);
});

it('keeps an already attached inherited photograph in place when its generation cannot be published', function () {
    config(['images.component_stack_propagation' => false]);
    $component = createImageImportProduct('HOLD-COMP');
    $stack = propagationStack('HOLD-STACK', [$component->id]);
    if ((int) ($stack->approval_version ?? 0) < 1) {
        Product::withoutEvents(fn () => $stack->forceFill(['approval_version' => 1])->save());
        $stack->refresh();
    }
    foreach (range(1, 2) as $ignored) {
        $approver = User::factory()->create();
        $stack->approvals()->create(['user_id' => $approver->id, 'approval_version' => $stack->approval_version]);
    }
    $inherited = Image::withoutEvents(fn () => Image::create([
        'product_id' => $stack->id, 'position' => 1, 'ownership' => ImageOwnership::COMPONENT_INHERITED,
        'shopify_id' => 'gid://shopify/MediaImage/1', 'sync_state' => Image::SYNC_STATE_SYNCED,
        'needs_shopify_image_sync' => false,
    ]));
    $owned = Image::withoutEvents(fn () => Image::create([
        'product_id' => $stack->id, 'position' => 2, 'ownership' => ImageOwnership::STACK_OWNED,
        'shopify_id' => 'gid://shopify/MediaImage/2', 'sync_state' => Image::SYNC_STATE_SYNCED,
        'needs_shopify_image_sync' => false,
    ]));
    $moves = null;
    Http::fake(function ($request) use (&$moves) {
        $payload = $request->data();
        $query = (string) ($payload['query'] ?? '');
        if (str_contains($query, 'productReorderMedia')) {
            $moves = $payload['variables']['moves'] ?? null;

            return Http::response(['data' => ['productReorderMedia' => ['mediaUserErrors' => []]]]);
        }
        if (str_contains($query, 'productCreateMedia')) {
            throw new RuntimeException('An already attached inherited photograph was uploaded.');
        }

        return Http::response(['data' => ['product' => [
            'media' => ['nodes' => [
                ['id' => 'gid://shopify/MediaImage/1', 'status' => 'READY', 'fileStatus' => 'READY', 'image' => ['url' => 'https://cdn.shopify.com/a.jpg'], 'originalSource' => ['url' => 'https://cdn.shopify.com/a.jpg']],
                ['id' => 'gid://shopify/MediaImage/2', 'status' => 'READY', 'fileStatus' => 'READY', 'image' => ['url' => 'https://cdn.shopify.com/b.jpg'], 'originalSource' => ['url' => 'https://cdn.shopify.com/b.jpg']],
            ], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
            'images' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
        ]]]);
    });
    $result = app(ProductShopifyUpdater::class)->syncProductImages(collect([$stack->fresh()]));
    expect($result['synced'])->toBe(1)
        ->and($result['failed'])->toBe(0)
        ->and($moves)->toBeNull()
        ->and($inherited->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/1')
        ->and($owned->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/2');
});

it('republishes a component that returns after its inherited photograph was removed', function () {
    propagationOn();
    [$component, $image] = propagationComponent('READD', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $stack = propagationStack('STACK-READD', [$component->id]);
    $http = new PropagationHttp;
    $http->install();
    $service = app(ComponentStackPropagation::class);
    $service->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    $draft = NewProductDraft::query()->where('handle', $stack->handle)->first();
    NewProductDraft::withoutEvents(fn () => $draft->forceFill(['bundle_product_ids' => []])->save());
    $service->reconcileDraftMembership($draft->fresh());
    expect(Image::query()->active()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->count())->toBe(0);
    NewProductDraft::withoutEvents(fn () => $draft->fresh()->forceFill(['bundle_product_ids' => [$component->id]])->save());
    $service->reconcileDraftMembership($draft->fresh());
    expect(Image::query()->active()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->whereNotNull('shopify_id')->count())->toBe(1);
});

it('does not delete shopify media that another active stack image still uses', function () {
    propagationOn();
    [$component, $image] = propagationComponent('SHARE-MEDIA', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $stack = propagationStack('STACK-SHARE-MEDIA', [$component->id]);
    $http = new PropagationHttp;
    $http->install();
    app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    $inherited = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $unresolved = Image::withoutEvents(fn () => Image::create([
        'product_id' => $stack->id, 'position' => 3, 'shopify_id' => $inherited->shopify_id,
        'sync_state' => Image::SYNC_STATE_SYNCED, 'needs_shopify_image_sync' => false,
    ]));
    $replacement = createImportTestAsset('replacement-photo', 'jpg', 'share-media.jpg');
    $http->bytes = 'replacement-photo';
    $http->install();
    Image::withoutEvents(fn () => $image->forceFill([
        'image_asset_id' => $replacement->id, 'last_shopify_synced_image_asset_id' => $replacement->id,
        'shopify_id' => 'gid://shopify/MediaImage/9',
    ])->save());
    app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    expect($http->deletedIds)->not->toContain($inherited->shopify_id)
        ->and($unresolved->fresh()->sync_state)->toBe(Image::SYNC_STATE_SYNCED)
        ->and($unresolved->fresh()->shopify_id)->toBe($inherited->shopify_id);
});

it('keeps the inherited photograph when the component returns during removal', function () {
    propagationOn();
    [$component, $image] = propagationComponent('RETURN', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $stack = propagationStack('STACK-RETURN', [$component->id]);
    $http = new PropagationHttp;
    $http->install();
    $service = app(ComponentStackPropagation::class);
    $service->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    $inherited = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $draft = NewProductDraft::query()->where('handle', $stack->handle)->first();
    $http->onRetirementRead = function () use ($draft, $component): void {
        NewProductDraft::withoutEvents(fn () => $draft->fresh()->forceFill(['bundle_product_ids' => [$component->id]])->save());
    };
    NewProductDraft::withoutEvents(fn () => $draft->forceFill(['bundle_product_ids' => []])->save());
    try {
        $service->reconcileDraftMembership($draft->fresh());
    } catch (Throwable) {
    }
    expect($inherited->fresh()->sync_state)->toBe(Image::SYNC_STATE_SYNCED)
        ->and($http->deletedIds)->toBe([]);
});

it('detaches the previous variant media after a lost append response shows the replacement is already attached', function () {
    propagationOn();
    [$component, $image] = propagationComponent('LOST-APPEND', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $stack = propagationStack('STACK-LOST-APPEND', [$component->id]);
    $http = new PropagationHttp;
    $http->install();
    app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    $first = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $replacement = createImportTestAsset('replacement-photo', 'jpg', 'lost-append.jpg');
    $http->bytes = 'replacement-photo';
    $http->trackVariants = true;
    $http->loseNextAppend = true;
    $http->variantMediaIds = [$first->shopify_id];
    $http->install();
    Image::withoutEvents(fn () => $image->forceFill([
        'image_asset_id' => $replacement->id, 'last_shopify_synced_image_asset_id' => $replacement->id,
        'shopify_id' => 'gid://shopify/MediaImage/9',
    ])->save());
    app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    expect($http->appends)->toBe(1)
        ->and($http->deletedIds)->toContain($first->shopify_id)
        ->and($first->fresh()->sync_state)->toBe(Image::SYNC_STATE_LOCAL_DELETED)
        ->and($http->variantMediaIds)->not->toContain($first->shopify_id);
});

it('removes a component again after it was added, removed, and added', function () {
    propagationOn();
    [$component, $image] = propagationComponent('CYCLE', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $stack = propagationStack('STACK-CYCLE', [$component->id]);
    $http = new PropagationHttp;
    $http->install();
    $service = app(ComponentStackPropagation::class);
    $service->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    $draft = NewProductDraft::query()->where('handle', $stack->handle)->first();
    $set = function (array $ids) use ($draft, $service): void {
        NewProductDraft::withoutEvents(fn () => $draft->fresh()->forceFill(['bundle_product_ids' => $ids])->save());
        $service->reconcileDraftMembership($draft->fresh());
    };
    $set([]);
    $set([$component->id]);
    $set([]);
    expect(Image::query()->active()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->count())->toBe(0)
        ->and(DB::table('stack_image_sync_tasks')->where('purpose', 'remove')->count())->toBeGreaterThan(1);
});

it('notices a component removed by a direct draft update', function () {
    propagationOn();
    Bus::fake();
    [$component, $image] = propagationComponent('DIRECT', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $stack = propagationStack('STACK-DIRECT', [$component->id]);
    $service = app(ComponentStackPropagation::class);
    $service->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    $service->stageStack($stack);
    DB::table('new_product_drafts')->where('handle', $stack->handle)->update(['bundle_product_ids' => json_encode([])]);
    $service->recoverPending();
    expect(DB::table('stack_image_sync_tasks')->where('stack_product_id', $stack->id)->where('purpose', 'remove')->where('phase', 'pending')->exists())->toBeTrue();
});

it('does not let an older pending retirement block a newer verified generation', function () {
    propagationOn();
    [$component, $image] = propagationComponent('OLD-INTENT', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $stack = propagationStack('STACK-OLD-INTENT', [$component->id]);
    $owned = Image::withoutEvents(fn () => Image::create([
        'product_id' => $stack->id, 'position' => 1, 'ownership' => ImageOwnership::STACK_OWNED,
        'shopify_id' => 'gid://shopify/MediaImage/50', 'sync_state' => Image::SYNC_STATE_SYNCED,
    ]));
    $http = new PropagationHttp;
    $http->nodesByProduct[$stack->shopify_id][] = $http->node($owned->shopify_id);
    $http->install();
    DB::table('inherited_media_retirements')->insert([
        'stack_product_id' => $stack->id, 'image_id' => $owned->id, 'generation_id' => 999,
        'old_media_id' => $owned->shopify_id, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    expect(DB::table('stack_image_sync_tasks')->where('purpose', 'publish')->value('phase'))->toBe('verified')
        ->and(DB::table('inherited_media_retirements')->where('generation_id', 999)->value('status'))->toBe('pending')
        ->and($owned->fresh()->sync_state)->toBe(Image::SYNC_STATE_SYNCED);
});

it('keeps shared provenance when one component is removed', function () {
    propagationOn();
    [$first, $firstImage] = propagationComponent('SHARE-A', 'shared-bytes', 'gid://shopify/MediaImage/7');
    $second = createImageImportProduct('SHARE-B');
    Product::withoutEvents(fn () => $second->forceFill(['approval_version' => 1])->save());
    foreach (range(1, 2) as $ignored) {
        $second->approvals()->create(['user_id' => User::factory()->create()->id, 'approval_version' => 1]);
    }
    $secondImage = Image::withoutEvents(fn () => Image::create([
        'product_id' => $second->id, 'position' => 1, 'image_asset_id' => $firstImage->image_asset_id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP, 'sync_state' => Image::SYNC_STATE_SYNCED,
        'shopify_id' => 'gid://shopify/MediaImage/8', 'last_shopify_synced_image_asset_id' => $firstImage->image_asset_id,
        'needs_shopify_image_sync' => false,
    ]));
    $stack = propagationStack('STACK-PROVENANCE', [$first->id, $second->id]);
    $http = new PropagationHttp;
    $http->bytes = 'shared-bytes';
    $http->install();
    $service = app(ComponentStackPropagation::class);
    $service->recordConfirmedPhotograph($firstImage->fresh(['product', 'imageAsset']), true);
    $service->recordConfirmedPhotograph($secondImage->fresh(['product', 'imageAsset']), true);
    $inherited = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $draft = NewProductDraft::query()->where('handle', $stack->handle)->first();
    NewProductDraft::withoutEvents(fn () => $draft->forceFill(['bundle_product_ids' => [$second->id]])->save());
    $service->reconcileDraftMembership($draft->fresh());
    $closed = DB::table('inherited_image_sources')->where('image_id', $inherited->id)->where('component_product_id', $first->id)->first();
    expect($inherited->fresh()->sync_state)->toBe(Image::SYNC_STATE_SYNCED)
        ->and($closed->removed_at)->not->toBeNull()
        ->and((int) $inherited->fresh()->source_image_id)->toBe($secondImage->id)
        ->and(DB::table('inherited_image_sources')->where('image_id', $inherited->id)->count())->toBe(2);
});

it('keeps an operator alt resolution when the inherited photograph is staged again', function () {
    propagationOn();
    $bytes = 'resolved-bytes';
    [$first, $firstImage] = propagationComponent('RESOLVE-A', $bytes, null, false);
    $second = createImageImportProduct('RESOLVE-B');
    $secondImage = Image::withoutEvents(fn () => Image::create([
        'product_id' => $second->id, 'position' => 1, 'image_asset_id' => $firstImage->image_asset_id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP, 'alt_text' => 'Second approved sentence',
        'sync_state' => Image::SYNC_STATE_SYNCED,
    ]));
    Image::withoutEvents(fn () => $firstImage->forceFill(['alt_text' => 'First approved sentence'])->save());
    foreach ([[$first, $firstImage, 'First approved sentence'], [$second, $secondImage, 'Second approved sentence']] as [$product, $source, $sentence]) {
        $operator = User::factory()->create();
        ProductPartialApprovalRequest::withoutEvents(fn () => ProductPartialApprovalRequest::create([
            'product_id' => $product->id, 'approval_version' => $product->approval_version ?? 1,
            'requested_by' => $operator->id, 'status' => ProductPartialApprovalRequest::STATUS_APPROVED,
            'scopes' => ['image_alt'], 'core_fields' => [], 'request_batch_id' => (string) Str::uuid(),
            'image_alt_snapshot' => ['images' => [[
                'image_id' => $source->id, 'image_asset_id' => $source->image_asset_id,
                'proposed_alt' => $sentence, 'changed' => true,
                'photograph_version' => max(1, (int) $source->fresh()->photograph_version),
                'alt_text_version' => max(1, (int) $source->fresh()->alt_text_version),
            ]]],
            'approved_at' => now(),
        ]));
    }
    $stack = propagationStack('STACK-RESOLVE', [$first->id, $second->id]);
    $service = app(ComponentStackPropagation::class);
    $service->stageStack($stack);
    $existing = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $taskId = DB::table('stack_image_sync_tasks')->insertGetId([
        'stack_product_id' => $stack->id, 'component_product_id' => $first->id, 'component_image_id' => $firstImage->id,
        'generation_id' => (int) DB::table('component_photograph_generations')->insertGetId([
            'component_product_id' => $first->id, 'component_image_id' => $firstImage->id,
            'image_asset_id' => $firstImage->image_asset_id, 'asset_sha256' => $firstImage->imageAsset->sha256,
            'selection_revision' => 1, 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
        ]),
        'purpose' => 'publish', 'phase' => 'alt_conflict', 'attempts' => 1, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $operator = propagationOperator();
    $service->resolveInheritedAlt($taskId, 'First approved sentence', 'Operator selected the first approved source description.', $operator->id);
    $service->stageStack($stack);
    expect($existing->fresh()->alt_text)->toBe('First approved sentence')
        ->and(DB::table('inherited_alt_resolutions')->where('image_id', $existing->id)->where('user_id', $operator->id)->exists())->toBeTrue();
});

it('reads the current product approval instead of a stale loaded relation', function () {
    [$component, $image] = propagationComponent('STALE-APPROVAL', 'photo-bytes', 'gid://shopify/MediaImage/7');
    propagationOn();
    app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    $image->load('product.approvals');
    DB::table('approvals')->where('product_id', $component->id)->delete();
    expect(app(ComponentPhotographSelector::class)->publicationAllowed($image))->toBeFalse();
});

it('rejects recovery from an unauthorized operator and accepts an authorized one', function () {
    propagationOn();
    Bus::fake();
    [$component, $image] = propagationComponent('AUTH', 'photo-bytes', 'gid://shopify/MediaImage/7');
    propagationStack('STACK-AUTH', [$component->id]);
    app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    app(ComponentStackPropagation::class)->fanOut((int) DB::table('component_publication_outbox')->value('id'));
    $taskId = (int) DB::table('stack_image_sync_tasks')->value('id');
    DB::table('stack_image_sync_tasks')->where('id', $taskId)->update(['phase' => 'exhausted']);
    $stranger = User::factory()->create(['email' => 'stranger@example.com', 'is_active' => true]);
    $this->artisan('images:recover-stack-image-task', ['task' => $taskId, '--reason' => 'Operator reviewed the exhausted stack task.', '--user' => $stranger->id])
        ->assertFailed();
    $operator = propagationOperator();
    config(['images.component_stack_propagation' => false]);
    $this->artisan('images:recover-stack-image-task', ['task' => $taskId, '--reason' => 'Operator reviewed the exhausted stack task.', '--user' => $operator->id])
        ->assertSuccessful();
    expect(DB::table('stack_image_sync_tasks')->where('id', $taskId)->value('phase'))->toBe('pending')
        ->and(DB::table('stack_image_sync_task_audits')->where('task_id', $taskId)->where('user_id', $operator->id)->exists())->toBeTrue();
});

it('allocates the next selection revision from the highest existing generation', function () {
    propagationOn();
    [$component, $image] = propagationComponent('REVISION', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $other = createImportTestAsset('other-revision-bytes', 'jpg', 'other-revision.jpg');
    DB::table('component_photograph_generations')->insert([
        'component_product_id' => $component->id, 'component_image_id' => $image->id, 'image_asset_id' => $other->id,
        'asset_sha256' => $other->sha256, 'shopify_media_id' => 'gid://shopify/MediaImage/1',
        'selection_revision' => 5, 'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now(),
    ]);
    app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    expect((int) DB::table('component_photograph_generations')->where('image_asset_id', $image->image_asset_id)->value('selection_revision'))->toBe(6)
        ->and((int) DB::table('component_selection_revisions')->where('component_product_id', $component->id)->value('revision'))->toBe(6);
});

it('reports null, explicit unresolved, and invalid stack ownership without writing it', function () {
    $stack = propagationStack('STACK-REPORT', []);
    foreach ([null, 'unresolved', 'not-a-real-role'] as $ownership) {
        Image::withoutEvents(fn () => Image::create([
            'product_id' => $stack->id, 'position' => 1, 'ownership' => $ownership,
            'sync_state' => Image::SYNC_STATE_SYNCED,
        ]));
    }
    $this->artisan('images:legacy-image-ownership-report')
        ->expectsOutputToContain('null')
        ->expectsOutputToContain('explicit_unresolved')
        ->expectsOutputToContain('invalid')
        ->assertSuccessful();
    expect(Image::query()->where('product_id', $stack->id)->whereNull('ownership')->exists())->toBeTrue()
        ->and(Image::query()->where('product_id', $stack->id)->where('ownership', 'unresolved')->exists())->toBeTrue()
        ->and(Image::query()->where('product_id', $stack->id)->where('ownership', 'not-a-real-role')->exists())->toBeTrue();
});

function approvePropagationAlt(Product $product, Image $image, string $sentence): void
{
    Image::withoutEvents(fn () => $image->forceFill([
        'alt_text' => $sentence,
        'alt_text_status' => Image::ALT_APPROVED,
    ])->save());
    ProductPartialApprovalRequest::create([
        'product_id' => $product->id,
        'approval_version' => (int) ($product->approval_version ?: 1),
        'requested_by' => User::factory()->create()->id,
        'approved_by' => User::factory()->create()->id,
        'status' => ProductPartialApprovalRequest::STATUS_APPROVED,
        'scopes' => [ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT],
        'image_alt_snapshot' => app(ProductPartialApprovalService::class)->imageAltSnapshot($product->fresh()),
        'approved_at' => now(),
    ]);
}

it('publishes the approved component alt on a separate stack media file', function () {
    propagationOn();
    [$component, $image] = propagationComponent('ALT-OK', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $stack = propagationStack('STACK-ALT-OK', [$component->id]);
    $owned = Image::withoutEvents(fn () => Image::create([
        'product_id' => $stack->id, 'position' => 1, 'ownership' => ImageOwnership::STACK_OWNED,
        'alt_text' => 'Owned lifestyle', 'alt_text_status' => Image::ALT_APPROVED,
        'sync_state' => Image::SYNC_STATE_SYNCED, 'shopify_id' => 'gid://shopify/MediaImage/80',
        'needs_shopify_image_sync' => false,
    ]));
    approvePropagationAlt($component, $image, 'Approved component sentence');
    $http = new PropagationHttp;
    $http->bytes = 'photo-bytes';
    $http->install();

    app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);

    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    expect($copy)->not->toBeNull()
        ->and($copy->alt_text)->toBe('Approved component sentence')
        ->and($copy->shopify_alt_text)->toBe('Approved component sentence')
        ->and($copy->shopify_id)->not->toBe($image->shopify_id)
        ->and($copy->shopify_id)->not->toBe($owned->shopify_id)
        ->and($http->creates)->toBe(1)
        ->and($http->fileUpdateIds)->toContain($copy->shopify_id)
        ->and($http->deletedIds)->not->toContain($image->shopify_id)
        ->and($owned->fresh()->alt_text)->toBe('Owned lifestyle')
        ->and($owned->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/80')
        ->and(DB::table('stack_image_sync_tasks')->where('stack_product_id', $stack->id)->value('phase'))->toBe('verified');
});

it('stages an unapproved component alt without publishing it', function () {
    propagationOn();
    [$component, $image] = propagationComponent('ALT-RAW', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $stack = propagationStack('STACK-ALT-RAW', [$component->id]);
    $http = new PropagationHttp;
    $http->bytes = 'photo-bytes';
    $http->install();

    app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);

    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    expect($copy->alt_text)->toBe('Component sentence')
        ->and($copy->shopify_alt_text)->toBeNull()
        ->and($copy->alt_text_status)->not->toBe(Image::ALT_PUBLISHED)
        ->and($http->fileUpdates)->toBe(0)
        ->and($copy->shopify_id)->not->toBe($image->shopify_id)
        ->and(app(ProductPartialApprovalService::class)->approvedAlt($image->fresh()))->toBeNull();
});

it('updates an inherited alt without uploading the photograph again', function () {
    propagationOn();
    [$component, $image] = propagationComponent('ALT-EDIT', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $stack = propagationStack('STACK-ALT-EDIT', [$component->id]);
    approvePropagationAlt($component, $image, 'Approved component sentence');
    $http = new PropagationHttp;
    $http->bytes = 'photo-bytes';
    $http->install();
    app(ComponentStackPropagation::class)->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $stackMediaId = $copy->shopify_id;
    $creates = $http->creates;
    $http->nodesByProduct[$component->shopify_id][] = $http->node((string) $image->shopify_id);

    approvePropagationAlt($component, $image->fresh(), 'Updated approved sentence');
    $published = app(ImageAltTextBackfillService::class)->publishIds([(int) $image->id]);
    expect($published['shopify_failed'])->toBe([]);

    $copy->refresh();
    expect($http->creates)->toBe($creates)
        ->and($copy->shopify_id)->toBe($stackMediaId)
        ->and($copy->alt_text)->toBe('Updated approved sentence')
        ->and($copy->shopify_alt_text)->toBe('Updated approved sentence')
        ->and($copy->needs_shopify_image_sync)->toBeFalse()
        ->and($image->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/7')
        ->and($http->fileUpdateIds)->toContain($stackMediaId);
});

it('removes an inherited stack image without changing the component or another stack', function () {
    propagationOn();
    [$component, $image] = propagationComponent('ALT-KEEP', 'photo-bytes', 'gid://shopify/MediaImage/7');
    $first = propagationStack('STACK-KEEP-A', [$component->id]);
    $second = propagationStack('STACK-KEEP-B', [$component->id]);
    $owned = Image::withoutEvents(fn () => Image::create([
        'product_id' => $first->id, 'position' => 2, 'ownership' => ImageOwnership::STACK_OWNED,
        'sync_state' => Image::SYNC_STATE_SYNCED, 'shopify_id' => 'gid://shopify/MediaImage/81',
        'alt_text' => 'Independent stack', 'needs_shopify_image_sync' => false,
    ]));
    $http = new PropagationHttp;
    $http->bytes = 'photo-bytes';
    $http->install();
    $service = app(ComponentStackPropagation::class);
    $service->recordConfirmedPhotograph($image->fresh(['product', 'imageAsset']), true);
    $firstCopy = Image::query()->where('product_id', $first->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $secondCopy = Image::query()->where('product_id', $second->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $secondMediaId = $secondCopy->shopify_id;
    $localVariant = $first->variants()->first();
    \App\Models\Variant::withoutEvents(fn () => $localVariant->forceFill(['image_id' => $firstCopy->id])->save());
    expect($firstCopy->shopify_id)->not->toBe($image->shopify_id)
        ->and($secondMediaId)->not->toBe($image->shopify_id)
        ->and($firstCopy->shopify_id)->not->toBe($secondMediaId);

    $draft = NewProductDraft::query()->where('handle', $first->handle)->first();
    NewProductDraft::withoutEvents(fn () => $draft->forceFill(['bundle_product_ids' => []])->save());
    $service->reconcileDraftMembership($draft->fresh());
    NewProductDraft::withoutEvents(fn () => $draft->fresh()->forceFill(['bundle_product_ids' => [$component->id]])->save());
    $service->reconcileDraftMembership($draft->fresh());
    NewProductDraft::withoutEvents(fn () => $draft->fresh()->forceFill(['bundle_product_ids' => []])->save());
    $service->reconcileDraftMembership($draft->fresh());

    expect(Image::query()->active()->where('product_id', $first->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->count())->toBe(0)
        ->and($localVariant->fresh()->image_id)->toBeNull()
        ->and(DB::table('inherited_image_source_events')->where('image_id', $firstCopy->id)->where('action', 'remove_source')->exists())->toBeTrue()
        ->and($image->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/7')
        ->and($secondCopy->fresh()->shopify_id)->toBe($secondMediaId)
        ->and($secondCopy->fresh()->sync_state)->not->toBe(Image::SYNC_STATE_LOCAL_DELETED)
        ->and($owned->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/81')
        ->and($owned->fresh()->alt_text)->toBe('Independent stack')
        ->and($http->deletedIds)->not->toContain('gid://shopify/MediaImage/7')
        ->and($http->deletedIds)->not->toContain('gid://shopify/MediaImage/81')
        ->and($http->deletedIds)->not->toContain($secondMediaId);
});


