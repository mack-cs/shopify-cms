<?php

use App\Jobs\SendProductPartialApprovalSlackReminderJob;
use App\Models\Approval;
use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ProductPartialApprovalRequest;
use App\Models\User;
use App\Services\ComponentStackPropagation;
use App\Services\ImageAltTextBackfillService;
use App\Services\ImageOwnership;
use App\Services\ProductPartialApprovalService;
use App\Services\ProductShopifyUpdater;
use App\Services\StackImageSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/shopify-image-import.php';

function defectFlags(): void
{
    config([
        'services.shopify.shop' => 'test-shop.myshopify.com',
        'services.shopify.admin_access_token' => 'test-token',
        'services.shopify.api_version' => '2026-01',
        'images.component_stack_propagation' => false,
        'images.component_stack_local_rebuild' => false,
        'images.component_stack_blind_publication' => false,
    ]);
}

function defectApprove(Product $product): void
{
    Approval::create([
        'product_id' => $product->id,
        'user_id' => User::factory()->create()->id,
        'approval_version' => $product->fresh()->approval_version ?? 1,
    ]);
}

/**
 * @return array{0: Product, 1: Image}
 */
function defectPhotograph(string $sku, string $bytes, string $alt, array $productOverrides = [], array $imageOverrides = []): array
{
    Storage::fake('public');
    $product = createImageImportProduct($sku, $productOverrides);
    $asset = createImportTestAsset($bytes, 'jpg', $sku.'.jpg');
    $image = Image::withoutEvents(fn () => Image::create(array_merge([
        'product_id' => $product->id,
        'position' => 1,
        'image_asset_id' => $asset->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
        'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
        'needs_shopify_image_sync' => true,
        'alt_text' => $alt,
        'approved_filename' => $sku.'.jpg',
    ], $imageOverrides)))->fresh(['imageAsset']);

    return [$product, $image];
}

function defectShopify(Product $product, object $state, array $nodes, string $bytes): void
{
    Http::preventStrayRequests();
    Http::fake(function ($request) use ($product, $state, &$nodes, $bytes) {
        if ($request->method() === 'GET') {
            return Http::response($bytes);
        }
        $payload = $request->data();
        $query = (string) ($payload['query'] ?? '');
        $variables = $payload['variables'] ?? [];
        if (is_object($variables)) {
            $variables = get_object_vars($variables);
        }
        if (str_contains($query, 'productCreateMedia')) {
            $state->creates++;
            $id = 'gid://shopify/MediaImage/'.(8000 + $state->creates);
            $url = 'https://cdn.shopify.com/s/files/1/defect-'.$state->creates.'.jpg';
            $nodes[] = [
                'id' => $id,
                'status' => 'READY',
                'fileStatus' => 'READY',
                'alt' => $state->alt,
                'image' => ['url' => $url],
                'originalSource' => ['url' => $url],
            ];

            return Http::response(['data' => ['productCreateMedia' => ['media' => [['id' => $id]], 'mediaUserErrors' => []]]]);
        }
        if (str_contains($query, 'fileUpdate')) {
            $state->updates++;
            $state->alt = (string) ($variables['files'][0]['alt'] ?? $state->alt);
            foreach ($nodes as &$node) {
                if (($node['id'] ?? null) === ($variables['files'][0]['id'] ?? null)) {
                    $node['alt'] = $state->alt;
                }
            }
            unset($node);

            return Http::response(['data' => ['fileUpdate' => ['files' => [['id' => $variables['files'][0]['id'] ?? null, 'alt' => $state->alt]], 'userErrors' => []]]]);
        }
        if (str_contains($query, 'productReorderMedia')) {
            return Http::response(['data' => ['productReorderMedia' => ['mediaUserErrors' => []]]]);
        }
        $productPayload = [
            'id' => $product->shopify_id,
            'options' => [],
            'variants' => ['nodes' => []],
            'media' => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
            'images' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
        ];

        return Http::response(['data' => ['product' => $productPayload, 'productByHandle' => $productPayload]]);
    });
}

it('does not upload an unapproved replacement of a normal product photograph', function (): void {
    defectFlags();
    [$product, $image] = defectPhotograph('NORMAL-B', 'photograph-a', 'Sentence A', [], [
        'shopify_id' => 'gid://shopify/MediaImage/41',
        'last_shopify_synced_image_asset_id' => null,
    ]);
    $image->forceFill(['last_shopify_synced_image_asset_id' => $image->image_asset_id, 'needs_shopify_image_sync' => false])->save();
    defectApprove($product);
    defectApprove($product);
    $replacement = createImportTestAsset('photograph-b', 'jpg', 'NORMAL-B-new.jpg');
    $image->fresh()->forceFill(['image_asset_id' => $replacement->id, 'needs_shopify_image_sync' => true])->save();
    $state = (object) ['creates' => 0, 'updates' => 0, 'alt' => null];
    $nodes = [[
        'id' => 'gid://shopify/MediaImage/41',
        'status' => 'READY',
        'fileStatus' => 'READY',
        'image' => ['url' => 'https://cdn.shopify.com/s/files/1/normal-a.jpg'],
        'originalSource' => ['url' => 'https://cdn.shopify.com/s/files/1/normal-a.jpg'],
    ]];
    defectShopify($product, $state, $nodes, 'photograph-a');

    $result = app(ProductShopifyUpdater::class)->syncProductImages(collect([$product->fresh()]));

    expect(app(StackImageSync::class)->photographAuthorized($image->fresh()))->toBeFalse()
        ->and($state->creates)->toBe(0)
        ->and($result['synced'])->toBe(0)
        ->and($image->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/41')
        ->and((int) $image->fresh()->last_shopify_synced_image_asset_id)->not->toBe($replacement->id)
        ->and($image->fresh()->needs_shopify_image_sync)->toBeTrue();
});

it('does not upload an unapproved replacement of a stack-owned photograph', function (): void {
    defectFlags();
    [$product, $image] = defectPhotograph('STACK-OWNED', 'stack-a', 'Stack sentence', ['is_bundle' => true], [
        'ownership' => ImageOwnership::STACK_OWNED,
        'shopify_id' => 'gid://shopify/MediaImage/51',
    ]);
    $image->forceFill(['last_shopify_synced_image_asset_id' => $image->image_asset_id, 'needs_shopify_image_sync' => false])->save();
    defectApprove($product);
    defectApprove($product);
    $replacement = createImportTestAsset('stack-b', 'jpg', 'STACK-OWNED-new.jpg');
    $image->fresh()->forceFill(['image_asset_id' => $replacement->id, 'needs_shopify_image_sync' => true])->save();
    $state = (object) ['creates' => 0, 'updates' => 0, 'alt' => null];
    defectShopify($product, $state, [[
        'id' => 'gid://shopify/MediaImage/51',
        'status' => 'READY',
        'fileStatus' => 'READY',
        'image' => ['url' => 'https://cdn.shopify.com/s/files/1/stack-a.jpg'],
        'originalSource' => ['url' => 'https://cdn.shopify.com/s/files/1/stack-a.jpg'],
    ]], 'stack-a');

    $result = app(ProductShopifyUpdater::class)->syncProductImages(collect([$product->fresh()]));

    expect($state->creates)->toBe(0)
        ->and($result['synced'])->toBe(0)
        ->and($image->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/51')
        ->and($image->fresh()->needs_shopify_image_sync)->toBeTrue()
        ->and(app(StackImageSync::class)->photographAuthorized($image->fresh()))->toBeFalse();
});

it('does not publish a new image added after the product was fully approved', function (): void {
    defectFlags();
    [$product, $image] = defectPhotograph('ADDED-B', 'photograph-a', 'Sentence A', [], [
        'shopify_id' => 'gid://shopify/MediaImage/41',
        'needs_shopify_image_sync' => false,
    ]);
    $image->forceFill(['last_shopify_synced_image_asset_id' => $image->image_asset_id])->save();
    defectApprove($product);
    defectApprove($product);
    $addedAsset = createImportTestAsset('photograph-b', 'jpg', 'ADDED-B.jpg');
    $added = Image::withoutEvents(fn () => Image::create([
        'product_id' => $product->id,
        'position' => 2,
        'image_asset_id' => $addedAsset->id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
        'backup_completed_at' => now(),
        'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
        'needs_shopify_image_sync' => true,
        'alt_text' => 'New sentence',
        'approved_filename' => 'ADDED-B.jpg',
    ]));
    $state = (object) ['creates' => 0, 'updates' => 0, 'alt' => null];
    defectShopify($product, $state, [[
        'id' => 'gid://shopify/MediaImage/41',
        'status' => 'READY',
        'fileStatus' => 'READY',
        'image' => ['url' => 'https://cdn.shopify.com/s/files/1/added-a.jpg'],
        'originalSource' => ['url' => 'https://cdn.shopify.com/s/files/1/added-a.jpg'],
    ]], 'photograph-a');

    $result = app(ProductShopifyUpdater::class)->syncSelectedProductImages($product->fresh(), [$added->id]);
    $kept = app(ProductShopifyUpdater::class)->syncSelectedProductImages($product->fresh(), [$image->id]);

    $snapshot = app(StackImageSync::class)->publicationSnapshot($product->fresh());
    expect(collect($snapshot['images'])->pluck('image_id')->all())->toBe([$image->id])
        ->and(DB::table('component_image_authorizations')->where('image_id', $added->id)->count())->toBe(0)
        ->and($product->fresh()->isApprovedByTwo())->toBeTrue()
        ->and($state->creates)->toBe(0)
        ->and($result['synced'])->toBe(0)
        ->and($result['failed'])->toBe(1)
        ->and($added->fresh()->shopify_id)->toBeNull()
        ->and($image->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/41')
        ->and($kept['synced'])->toBe(1)
        ->and($kept['failed'])->toBe(0);
});

it('publishes an authorized normal-product photograph', function (): void {
    defectFlags();
    [$product, $image] = defectPhotograph('NORMAL-OK', 'authorized-bytes', 'Authorized clasp');
    defectApprove($product);
    defectApprove($product);
    $state = (object) ['creates' => 0, 'updates' => 0, 'alt' => null];
    defectShopify($product, $state, [], 'authorized-bytes');

    $result = app(ProductShopifyUpdater::class)->syncProductImages(collect([$product->fresh()]));

    expect(app(StackImageSync::class)->photographAuthorized($image->fresh()))->toBeTrue()
        ->and($result['synced'])->toBe(1)
        ->and($result['failed'])->toBe(0)
        ->and($state->creates)->toBe(1)
        ->and($image->fresh()->shopify_id)->not->toBeNull()
        ->and($image->fresh()->needs_shopify_image_sync)->toBeFalse();
});

it('blocks an unauthorized photograph while an independently approved alt text can still publish', function (): void {
    defectFlags();
    Bus::fake([SendProductPartialApprovalSlackReminderJob::class]);
    [$product, $image] = defectPhotograph('ALT-SPLIT', 'photograph-a', 'Sentence A', [], [
        'shopify_id' => 'gid://shopify/MediaImage/61',
    ]);
    $image->forceFill(['last_shopify_synced_image_asset_id' => $image->image_asset_id, 'needs_shopify_image_sync' => false])->save();
    defectApprove($product);
    defectApprove($product);
    $replacement = createImportTestAsset('photograph-b', 'jpg', 'ALT-SPLIT-new.jpg');
    $image->fresh()->forceFill(['image_asset_id' => $replacement->id])->save();
    app(ImageAltTextBackfillService::class)->markPendingReview($image->fresh(), 'Sentence B');
    $image->fresh()->forceFill([
        'last_shopify_synced_image_asset_id' => $replacement->id,
        'needs_shopify_image_sync' => true,
        'shopify_alt_text' => 'Sentence A',
    ])->save();
    $partial = app(ProductPartialApprovalService::class);
    $partial->request(collect([$product->fresh()]), User::factory()->create()->id, [ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT], []);
    $request = ProductPartialApprovalRequest::query()->where('product_id', $product->id)->latest('id')->first();
    expect($partial->approveRequests(collect([$request]), User::factory()->create()->id)['approved'])->toBe(1)
        ->and(app(StackImageSync::class)->photographAuthorized($image->fresh()))->toBeFalse()
        ->and(app(ImageAltTextBackfillService::class)->approvedUploadAlt($image->fresh()))->toBe('Sentence B');
    $state = (object) ['creates' => 0, 'updates' => 0, 'alt' => 'Sentence A'];
    $url = 'https://cdn.shopify.com/s/files/1/alt-split.jpg';
    defectShopify($product, $state, [[
        'id' => 'gid://shopify/MediaImage/61',
        'status' => 'READY',
        'fileStatus' => 'READY',
        'alt' => 'Sentence A',
        'image' => ['url' => $url],
        'originalSource' => ['url' => $url],
    ]], 'photograph-b');

    $sync = app(ProductShopifyUpdater::class)->syncProductImages(collect([$product->fresh()]));
    $published = app(ImageAltTextBackfillService::class)->publishIds([$image->id]);

    expect($state->creates)->toBe(0)
        ->and($sync['synced'])->toBe(0)
        ->and($image->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/61')
        ->and($image->fresh()->needs_shopify_image_sync)->toBeTrue()
        ->and((int) $image->fresh()->last_shopify_synced_image_asset_id)->toBe($replacement->id)
        ->and($published['published'])->toBe(1)
        ->and($published['shopify_failed'])->toBe([])
        ->and($state->updates)->toBe(1)
        ->and($image->fresh()->alt_text_status)->toBe(Image::ALT_PUBLISHED)
        ->and($image->fresh()->shopify_alt_text)->toBe('Sentence B');
});

it('keeps the first approval snapshot when the same transaction edits the sentence', function (): void {
    defectFlags();
    [$product, $image] = defectPhotograph('SNAPSHOT-TX', 'stable-bytes', 'Sentence A');

    DB::transaction(function () use ($product, $image): void {
        defectApprove($product);
        app(ImageAltTextBackfillService::class)->markPendingReview($image->fresh(), 'Sentence B');
    });

    $snapshot = app(StackImageSync::class)->publicationSnapshot($product->fresh());
    defectApprove($product);

    expect($snapshot['images'][0]['alt_text'])->toBe('Sentence A')
        ->and(app(StackImageSync::class)->publicationSnapshot($product->fresh())['images'][0]['alt_text'])->toBe('Sentence A')
        ->and(app(StackImageSync::class)->altAuthorized($image->fresh()))->toBeFalse()
        ->and(app(StackImageSync::class)->photographAuthorized($image->fresh()))->toBeTrue()
        ->and($image->fresh()->alt_text)->toBe('Sentence B');
});

it('removes the approval and its snapshot together when the transaction rolls back', function (): void {
    defectFlags();
    [$product] = defectPhotograph('SNAPSHOT-ROLLBACK', 'rollback-bytes', 'Sentence A');

    DB::beginTransaction();
    defectApprove($product);
    expect(DB::table('publication_authorization_snapshots')->where('product_id', $product->id)->count())->toBe(1);
    DB::rollBack();

    expect(DB::table('publication_authorization_snapshots')->where('product_id', $product->id)->count())->toBe(0)
        ->and(DB::table('approvals')->where('product_id', $product->id)->count())->toBe(0)
        ->and(DB::table('component_image_authorizations')->where('product_id', $product->id)->count())->toBe(0);
});

it('does not authorize publication from a nested transaction until the outer transaction commits', function (): void {
    defectFlags();
    [$product, $image] = defectPhotograph('SNAPSHOT-NESTED', 'nested-bytes', 'Sentence A');

    DB::beginTransaction();
    try {
        DB::transaction(function () use ($product): void {
            defectApprove($product);
            throw new RuntimeException('inner');
        });
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('inner');
    }
    expect(DB::table('publication_authorization_snapshots')->where('product_id', $product->id)->count())->toBe(0);
    DB::transaction(function () use ($product): void {
        defectApprove($product);
        defectApprove($product);
    });
    expect(DB::table('component_image_authorizations')->where('image_id', $image->id)->count())->toBe(0)
        ->and(DB::table('publication_authorization_snapshots')->where('product_id', $product->id)->count())->toBe(1);
    DB::rollBack();
    expect(DB::table('publication_authorization_snapshots')->where('product_id', $product->id)->count())->toBe(0)
        ->and(DB::table('approvals')->where('product_id', $product->id)->count())->toBe(0)
        ->and(DB::table('component_image_authorizations')->where('image_id', $image->id)->count())->toBe(0);

    DB::transaction(function () use ($product): void {
        DB::transaction(function () use ($product): void {
            defectApprove($product);
        });
        expect(DB::table('component_image_authorizations')->where('product_id', $product->id)->count())->toBe(0);
        defectApprove($product);
    });

    expect(app(StackImageSync::class)->photographAuthorized($image->fresh()))->toBeTrue()
        ->and(DB::table('publication_authorization_snapshots')->where('product_id', $product->id)->count())->toBe(1);
});

it('still publishes an authorized inherited stack photograph', function (): void {
    defectFlags();
    Bus::fake();
    [$component, $source] = defectPhotograph('INHERIT-SRC', 'inherited-bytes', 'Inherited clasp');
    $stack = createImageImportProduct('INHERIT-STACK', ['is_bundle' => true, 'status' => 'active']);
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create([
        'sku' => 'INHERIT-STACK',
        'shopify_id' => $stack->shopify_id,
        'handle' => $stack->handle,
        'title' => $stack->title,
        'status' => 'active',
        'bundle_product_ids' => [$component->id],
    ]));
    $partial = app(ProductPartialApprovalService::class);
    $partial->request(collect([$component->fresh()]), User::factory()->create()->id, [ProductShopifyUpdater::SYNC_SCOPE_IMAGES], []);
    $request = ProductPartialApprovalRequest::query()->where('product_id', $component->id)->first();
    expect($partial->approveRequests(collect([$request]), User::factory()->create()->id)['approved'])->toBe(1)
        ->and(app(StackImageSync::class)->photographAuthorized($source->fresh()))->toBeTrue();
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $state = (object) ['creates' => 0, 'updates' => 0, 'alt' => null];
    defectShopify($stack, $state, [], 'inherited-bytes');

    $published = app(ProductShopifyUpdater::class)->synchronizeInheritedStackImage($stack->fresh(), $copy->id);

    expect($published['confirmed'])->toBeTrue()
        ->and($state->creates)->toBeGreaterThan(0)
        ->and($copy->fresh()->shopify_id)->not->toBeNull()
        ->and($source->fresh()->shopify_id)->toBeNull();
});
