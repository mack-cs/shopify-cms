<?php

use App\Models\Approval;
use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\User;
use App\Services\ComponentStackPropagation;
use App\Services\ImageAltTextBackfillService;
use App\Services\ImageOwnership;
use App\Services\ProductPartialApprovalService;
use App\Services\ProductShopifyUpdater;
use App\Services\StackImageSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/shopify-image-import.php';

function publicationFlags(): void
{
    config([
        'images.component_stack_propagation' => false,
        'images.component_stack_local_rebuild' => false,
        'images.component_stack_blind_publication' => false,
    ]);
}

function publicationProduct(string $sku, array $photos): array
{
    Storage::fake('public');
    $product = createImageImportProduct($sku);
    $images = [];
    foreach ($photos as $index => $photo) {
        $asset = createImportTestAsset($photo['bytes'], 'jpg', $photo['filename']);
        $images[] = Image::withoutEvents(fn () => Image::create([
            'product_id' => $product->id,
            'position' => $index + 1,
            'image_asset_id' => $asset->id,
            'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
            'backup_completed_at' => now(),
            'sync_state' => Image::SYNC_STATE_SYNCED,
            'alt_text' => $photo['alt'],
            'needs_shopify_image_sync' => true,
        ]))->fresh(['imageAsset']);
    }

    return [$product, $images];
}

function publicationApproval(Product $product): void
{
    Approval::create([
        'product_id' => $product->id,
        'user_id' => User::factory()->create()->id,
        'approval_version' => $product->fresh()->approval_version ?? 1,
    ]);
}

function publicationStack(Product $component, string $sku): Product
{
    $stack = createImageImportProduct($sku, ['is_bundle' => true, 'status' => 'active']);
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create([
        'sku' => $sku,
        'shopify_id' => $stack->shopify_id,
        'handle' => $stack->handle,
        'title' => $stack->title,
        'status' => 'active',
        'bundle_product_ids' => [$component->id],
    ]));

    return $stack;
}

it('authorizes the unchanged prepared content only when the second approver agrees', function (): void {
    publicationFlags();
    [$product, $images] = publicationProduct('SECOND', [
        ['bytes' => 'second-bytes', 'filename' => 'SECOND.jpg', 'alt' => 'Prepared clasp'],
    ]);
    $sync = app(StackImageSync::class);
    publicationApproval($product);
    expect($sync->photographAuthorized($images[0]->fresh()))->toBeFalse()
        ->and($sync->publicationSnapshot($product->fresh())['images'][0]['asset_sha256'])->toBe($images[0]->imageAsset->sha256);

    publicationApproval($product);

    expect($product->fresh()->isApprovedByTwo())->toBeTrue()
        ->and($sync->photographAuthorized($images[0]->fresh()))->toBeTrue()
        ->and($sync->altAuthorized($images[0]->fresh()))->toBeTrue()
        ->and(DB::table('publication_authorization_snapshots')->where('product_id', $product->id)->count())->toBe(1);
});

it('does not authorize a photograph or sentence changed between the two approvals', function (): void {
    publicationFlags();
    [$product, $images] = publicationProduct('BETWEEN', [
        ['bytes' => 'kept-bytes', 'filename' => 'BETWEEN.jpg', 'alt' => 'Kept sentence'],
        ['bytes' => 'other-bytes', 'filename' => 'BETWEEN-1.jpg', 'alt' => 'Other sentence'],
    ]);
    publicationApproval($product);
    $replacement = createImportTestAsset('replaced-bytes', 'jpg', 'BETWEEN-new.jpg');
    $images[0]->forceFill(['image_asset_id' => $replacement->id, 'shopify_identity_unresolved' => true])->save();
    app(ImageAltTextBackfillService::class)->markPendingReview($images[1]->fresh(), 'Changed before the second approval');
    publicationApproval($product);
    $sync = app(StackImageSync::class);
    $kept = $images[1]->fresh();
    $changed = $images[0]->fresh();

    expect($sync->photographAuthorized($changed))->toBeFalse()
        ->and($sync->altAuthorized($changed))->toBeTrue()
        ->and($sync->photographAuthorized($kept))->toBeTrue()
        ->and($sync->altAuthorized($kept))->toBeFalse()
        ->and($sync->publicationSnapshot($product->fresh())['images'][0]['asset_sha256'])->not->toBe($replacement->sha256);
});

it('does not let a two-person approval reuse the original sentence after A then B then A', function (): void {
    publicationFlags();
    [$product, $images] = publicationProduct('CYCLE', [
        ['bytes' => 'cycle-bytes', 'filename' => 'CYCLE.jpg', 'alt' => 'Sentence A'],
    ]);
    publicationApproval($product);
    $writer = app(ImageAltTextBackfillService::class);
    $writer->markPendingReview($images[0]->fresh(), 'Sentence B');
    $writer->markPendingReview($images[0]->fresh(), 'Sentence A');
    publicationApproval($product);

    expect($images[0]->fresh()->alt_text_version)->toBe(3)
        ->and(app(StackImageSync::class)->altAuthorized($images[0]->fresh()))->toBeFalse()
        ->and(app(ProductPartialApprovalService::class)->approvedAlt($images[0]->fresh()))->toBeNull();
});

it('does not let a version-less legacy approval authorize a sentence that returned to an old value', function (): void {
    publicationFlags();
    [$product, $images] = publicationProduct('LEGACY', [
        ['bytes' => 'legacy-bytes', 'filename' => 'LEGACY.jpg', 'alt' => 'Sentence A'],
    ]);
    $image = $images[0];
    DB::table('image_alt_approvals')->insert([
        'image_id' => $image->id,
        'image_asset_id' => $image->image_asset_id,
        'asset_sha256' => $image->imageAsset->sha256,
        'alt_text' => 'Sentence A',
        'user_id' => User::factory()->create()->id,
        'context' => 'legacy',
        'reason' => 'Historical approval without a content version.',
        'snapshot' => json_encode(['alt_text' => 'Sentence A']),
        'approved_at' => now()->subMinute(),
    ]);
    expect(app(ProductPartialApprovalService::class)->approvedAlt($image->fresh()))->toBe('Sentence A');

    $writer = app(ImageAltTextBackfillService::class);
    $writer->markPendingReview($image->fresh(), 'Sentence B');
    $writer->markPendingReview($image->fresh(), 'Sentence A');

    expect(app(ProductPartialApprovalService::class)->approvedAlt($image->fresh()))->toBeNull()
        ->and($image->fresh()->shopify_id)->toBeNull();
});

it('does not publish a newer unapproved replacement over verified stack media', function (): void {
    publicationFlags();
    Http::preventStrayRequests();
    [$product, $images] = publicationProduct('HOLD', [
        ['bytes' => 'held-bytes', 'filename' => 'HOLD.jpg', 'alt' => 'Held clasp'],
    ]);
    publicationApproval($product);
    publicationApproval($product);
    $stack = publicationStack($product, 'HOLD-STACK');
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $copy->forceFill([
        'shopify_id' => 'gid://shopify/MediaImage/70',
        'needs_shopify_image_sync' => false,
        'sync_state' => Image::SYNC_STATE_SYNCED,
        'last_shopify_synced_image_asset_id' => $copy->image_asset_id,
    ])->save();
    $replacement = createImportTestAsset('newer-unapproved', 'jpg', 'HOLD-new.jpg');
    $images[0]->fresh()->forceFill(['image_asset_id' => $replacement->id])->save();
    app(ComponentStackPropagation::class)->stageStack($stack->fresh());
    $unapproved = Image::query()->where('product_id', $stack->id)->where('image_asset_id', $replacement->id)->first();
    $creates = 0;
    Http::fake(function ($request) use (&$creates) {
        $payload = json_decode((string) $request->body(), true) ?: [];
        $query = (string) ($payload['query'] ?? '');
        if (str_contains($query, 'productCreateMedia') || str_contains($query, 'fileUpdate')) {
            $creates++;
        }

        return Http::response(['data' => ['product' => [
            'media' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
            'images' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
        ]]]);
    });

    $result = app(ProductShopifyUpdater::class)->synchronizeInheritedStackImage($stack->fresh(), $unapproved->id);

    expect($result['confirmed'])->toBeFalse()
        ->and($creates)->toBe(0)
        ->and($copy->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/70')
        ->and(app(StackImageSync::class)->photographAuthorized($images[0]->fresh()))->toBeFalse();
});

it('reports a missing authorized backup and does not change shopify or the authorization', function (): void {
    publicationFlags();
    [$product, $images] = publicationProduct('MISSING', [
        ['bytes' => 'missing-bytes', 'filename' => 'MISSING.jpg', 'alt' => 'Missing clasp'],
    ]);
    publicationApproval($product);
    publicationApproval($product);
    $stack = publicationStack($product, 'MISSING-STACK');
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    Storage::disk('public')->delete($images[0]->imageAsset->storage_path);
    $authorizations = DB::table('component_image_authorizations')->where('image_id', $images[0]->id)->count();
    $creates = 0;
    Http::fake(function ($request) use (&$creates) {
        $payload = json_decode((string) $request->body(), true) ?: [];
        $query = (string) ($payload['query'] ?? '');
        if (str_contains($query, 'productCreateMedia') || str_contains($query, 'fileUpdate')) {
            $creates++;
        }

        return Http::response(['data' => ['product' => [
            'media' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
            'images' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
        ]]]);
    });

    $result = app(ProductShopifyUpdater::class)->synchronizeInheritedStackImage($stack->fresh(), $copy->id);

    expect($result['confirmed'])->toBeFalse()
        ->and($creates)->toBe(0)
        ->and(collect($result['warnings'])->pluck('warning')->implode(' '))->toContain('CMS backup is missing')
        ->and(DB::table('component_image_authorizations')->where('image_id', $images[0]->id)->count())->toBe($authorizations);
});

it('completes publication for one stack without retiring authorization needed by another stack', function (): void {
    publicationFlags();
    Http::preventStrayRequests();
    [$product, $images] = publicationProduct('MULTI', [
        ['bytes' => 'shared-bytes', 'filename' => 'MULTI.jpg', 'alt' => 'Shared clasp'],
    ]);
    $images[0]->forceFill(['needs_shopify_image_sync' => true, 'shopify_alt_pending' => true, 'alt_text_status' => Image::ALT_APPROVED])->save();
    publicationApproval($product);
    publicationApproval($product);
    $first = publicationStack($product, 'MULTI-A');
    $second = publicationStack($product, 'MULTI-B');
    app(ComponentStackPropagation::class)->stageStack($first);
    app(ComponentStackPropagation::class)->stageStack($second);
    $firstCopy = Image::query()->where('product_id', $first->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $secondCopy = Image::query()->where('product_id', $second->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $firstCopy->forceFill([
        'shopify_id' => 'gid://shopify/MediaImage/81',
        'shopify_alt_text' => 'Shared clasp',
        'needs_shopify_image_sync' => true,
    ])->save();
    $authorizations = DB::table('component_image_authorizations')->where('image_id', $images[0]->id)->count();
    app(StackImageSync::class)->completeVerifiedPublication($firstCopy->fresh(), $images[0]->fresh(), 'full');

    expect($firstCopy->fresh()->needs_shopify_image_sync)->toBeFalse()
        ->and($firstCopy->fresh()->alt_text_status)->toBe(Image::ALT_PUBLISHED)
        ->and($secondCopy->fresh()->needs_shopify_image_sync)->toBeTrue()
        ->and($images[0]->fresh()->needs_shopify_image_sync)->toBeTrue()
        ->and($images[0]->fresh()->alt_text_status)->toBe(Image::ALT_APPROVED)
        ->and(DB::table('component_image_authorizations')->where('image_id', $images[0]->id)->count())->toBe($authorizations)
        ->and(app(StackImageSync::class)->photographAuthorized($images[0]->fresh()))->toBeTrue();
});

it('does not complete a cycle when shopify has not verified the authorized sentence', function (): void {
    publicationFlags();
    [$product, $images] = publicationProduct('UNVERIFIED', [
        ['bytes' => 'unverified-bytes', 'filename' => 'UNVERIFIED.jpg', 'alt' => 'Verified clasp'],
    ]);
    publicationApproval($product);
    publicationApproval($product);
    $stack = publicationStack($product, 'UNVERIFIED-STACK');
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $copy->forceFill([
        'shopify_alt_text' => 'A different shopify sentence',
        'shopify_alt_pending' => true,
        'alt_text_status' => Image::ALT_APPROVED,
        'needs_shopify_image_sync' => true,
    ])->save();
    app(StackImageSync::class)->completeVerifiedPublication($copy->fresh(), $images[0]->fresh(), 'image_alt');

    expect($copy->fresh()->alt_text_status)->toBe(Image::ALT_APPROVED)
        ->and($copy->fresh()->shopify_alt_pending)->toBeTrue()
        ->and(DB::table('component_image_authorizations')->where('image_id', $images[0]->id)->exists())->toBeTrue();
});
