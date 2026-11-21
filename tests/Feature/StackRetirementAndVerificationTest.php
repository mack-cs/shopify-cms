<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\User;
use App\Services\ComponentStackPropagation;
use App\Services\ImageOwnership;
use App\Services\StackImageSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/shopify-image-import.php';

final class Phase3Remote implements ShopifyGraphqlGateway
{
    public array $nodes = [];

    public array $bodies = [];

    public int $creates = 0;

    public int $removals = 0;

    public int $altUpdates = 0;

    public int $verificationReads = 0;

    public bool $processing = false;

    public bool $keepRemovedMedia = false;

    public bool $incomplete = false;

    public bool $galleryIncomplete = false;

    public bool $loseCreateId = false;

    public ?string $createdAlt = null;

    public ?string $createdBytes = null;

    public ?\Closure $onRetirementRead = null;

    public function __construct(public string $bytes = 'unrelated') {}

    public function node(string $id, string $url, ?string $alt = null): array
    {
        return [
            'id' => $id,
            'status' => 'READY',
            'fileStatus' => 'READY',
            'alt' => $alt,
            'image' => ['url' => $url],
            'originalSource' => ['url' => $url],
        ];
    }

    public function graphql(string $query, array $variables = []): array
    {
        if (str_contains($query, 'productCreateMedia')) {
            $this->creates++;
            $id = 'gid://shopify/MediaImage/'.(9800 + $this->creates);
            if (! $this->loseCreateId) {
                $url = 'https://cdn.shopify.com/s/files/created-'.$this->creates.'.jpg';
                $this->nodes[] = $this->node($id, $url, $this->createdAlt);
                if ($this->createdBytes !== null) {
                    $this->bodies[$url] = $this->createdBytes;
                }
            }

            return ['productCreateMedia' => ['media' => $this->loseCreateId ? [] : [['id' => $id]], 'mediaUserErrors' => []]];
        }
        if (str_contains($query, 'fileUpdate')) {
            $file = $variables['files'][0] ?? [];
            $id = (string) ($file['id'] ?? '');
            if (array_key_exists('referencesToRemove', $file)) {
                $this->removals++;
                if (! $this->keepRemovedMedia) {
                    $this->nodes = array_values(array_filter($this->nodes, fn (array $node): bool => $node['id'] !== $id));
                }
            }
            if (array_key_exists('alt', $file)) {
                $this->altUpdates++;
                foreach ($this->nodes as $index => $node) {
                    if ($node['id'] === $id) {
                        $this->nodes[$index]['alt'] = $file['alt'];
                    }
                }
            }

            return ['fileUpdate' => ['files' => [['id' => $id, 'alt' => $file['alt'] ?? null]], 'userErrors' => []]];
        }
        if (str_contains($query, 'productVariantDetachMedia') || str_contains($query, 'productVariantAppendMedia') || str_contains($query, 'productReorderMedia')) {
            return ['productVariantDetachMedia' => ['userErrors' => []], 'productVariantAppendMedia' => ['userErrors' => []], 'productReorderMedia' => ['mediaUserErrors' => []]];
        }
        if (str_contains($query, 'StackRetirementVariantMedia') || str_contains($query, 'productVariant')) {
            return ['productVariant' => ['media' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]];
        }
        if (str_contains($query, 'StackRetirementMedia') && $this->onRetirementRead instanceof \Closure) {
            ($this->onRetirementRead)();
        }
        if (str_contains($query, 'StackGalleryVerification')) {
            $this->verificationReads++;
        }
        $nodes = $this->nodes;
        if ($this->processing) {
            $nodes = array_map(function (array $node): array {
                $node['status'] = 'PROCESSING';
                $node['fileStatus'] = 'PROCESSING';

                return $node;
            }, $nodes);
        }
        $incomplete = $this->incomplete || ($this->galleryIncomplete && str_contains($query, 'StackGalleryVerification'));

        return ['product' => [
            'media' => ['nodes' => $nodes, 'pageInfo' => ['hasNextPage' => $incomplete, 'endCursor' => $incomplete ? null : 'end']],
            'images' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
            'variants' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]],
        ]];
    }

    public function install(): void
    {
        config([
            'services.shopify.shop' => 'phase3-audit.myshopify.com',
            'services.shopify.admin_access_token' => 'test-token',
            'services.shopify.api_version' => '2026-01',
            'images.component_stack_propagation' => false,
            'images.component_stack_local_rebuild' => false,
            'images.component_stack_blind_publication' => false,
        ]);
        app()->instance(ShopifyGraphqlGateway::class, $this);
        Http::preventStrayRequests();
        Http::fake(function ($request) {
            if ($request->method() === 'GET') {
                return Http::response($this->bodies[$request->url()] ?? $this->bytes);
            }

            return Http::response(['data' => $this->graphql($request->data()['query'] ?? '', $request->data()['variables'] ?? [])]);
        });
    }
}

function phase3Flags(): void
{
    config([
        'images.component_stack_propagation' => false,
        'images.component_stack_local_rebuild' => false,
        'images.component_stack_blind_publication' => false,
    ]);
}

function phase3Approve(Product $product): void
{
    foreach ([1, 2] as $ignored) {
        DB::table('approvals')->insert([
            'product_id' => $product->id,
            'user_id' => User::factory()->create()->id,
            'approval_version' => $product->fresh()->approval_version ?? 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

/**
 * @param  array<int, array{bytes:string, filename:string, alt:string}>  $photos
 * @return array{0: Product, 1: array<int, Image>, 2: Product, 3: NewProductDraft}
 */
function phase3Pair(string $sku, array $photos): array
{
    Storage::fake('public');
    $component = createImageImportProduct($sku);
    $images = [];
    foreach ($photos as $index => $photo) {
        $asset = createImportTestAsset($photo['bytes'], 'jpg', $photo['filename']);
        $images[] = Image::withoutEvents(fn () => Image::create([
            'product_id' => $component->id,
            'position' => $index + 1,
            'image_asset_id' => $asset->id,
            'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
            'backup_completed_at' => now(),
            'sync_state' => Image::SYNC_STATE_SYNCED,
            'alt_text' => $photo['alt'],
            'needs_shopify_image_sync' => false,
        ]));
    }
    $stack = createImageImportProduct($sku.'-STACK', ['is_bundle' => true, 'status' => 'active']);
    $draft = NewProductDraft::withoutEvents(fn () => NewProductDraft::create([
        'sku' => $sku.'-STACK',
        'shopify_id' => $stack->shopify_id,
        'handle' => $stack->handle,
        'title' => $stack->title,
        'status' => 'active',
        'bundle_product_ids' => [$component->id],
    ]));

    return [$component, $images, $stack, $draft];
}

it('removes a published inherited photograph and does not leave it active', function (): void {
    phase3Flags();
    [, $images, $stack] = phase3Pair('REMOVE', [
        ['bytes' => 'stay-bytes', 'filename' => 'REMOVE.jpg', 'alt' => 'Stays'],
        ['bytes' => 'gone-bytes', 'filename' => 'REMOVE-1.jpg', 'alt' => 'Goes'],
    ]);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('source_image_id', $images[1]->id)->first();
    Image::withoutEvents(fn () => $copy->forceFill(['shopify_id' => 'gid://shopify/MediaImage/771'])->save());
    phase3Approve($stack);
    Image::withoutEvents(fn () => $images[1]->forceFill(['sync_state' => Image::SYNC_STATE_LOCAL_DELETED])->save());
    $remote = new Phase3Remote('unrelated');
    $remote->bodies['https://cdn.shopify.com/s/files/gone.jpg'] = 'gone-bytes';
    $remote->nodes[] = $remote->node('gid://shopify/MediaImage/771', 'https://cdn.shopify.com/s/files/gone.jpg');
    $remote->install();

    $plan = app(StackImageSync::class)->synchronizeStack((int) $stack->id);

    expect($plan['retire'])->toBe([(int) $copy->id])
        ->and($plan['outcome'])->toBe('retirement_confirmed')
        ->and($remote->verificationReads)->toBeGreaterThan(0)
        ->and(collect($remote->nodes)->pluck('id'))->not->toContain('gid://shopify/MediaImage/771')
        ->and($remote->removals)->toBe(1)
        ->and($remote->creates)->toBe(0)
        ->and($copy->fresh()->sync_state)->toBe(Image::SYNC_STATE_LOCAL_DELETED)
        ->and(Image::query()->where('product_id', $stack->id)->whereKey($copy->id)->active()->exists())->toBeFalse()
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->value('status'))->toBe('confirmed')
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->value('resolution'))->toBe('shopify_absent');
});

it('plans a replaced photograph for retirement and waits until the replacement is confirmed', function (): void {
    phase3Flags();
    [, $images, $stack] = phase3Pair('REPLACE', [
        ['bytes' => 'old-bytes', 'filename' => 'REPLACE.jpg', 'alt' => 'Old clasp'],
    ]);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $old = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    Image::withoutEvents(fn () => $old->forceFill(['shopify_id' => 'gid://shopify/MediaImage/772'])->save());
    $replacement = createImportTestAsset('new-bytes', 'jpg', 'REPLACE.jpg');
    Image::withoutEvents(fn () => $images[0]->forceFill(['image_asset_id' => $replacement->id, 'photograph_version' => 2])->save());
    app(ComponentStackPropagation::class)->stageStack($stack->fresh());
    $current = Image::query()->where('product_id', $stack->id)->where('source_image_id', $images[0]->id)->active()->where('image_asset_id', $replacement->id)->first();
    DB::table('component_image_authorizations')->insert([
        'product_id' => $images[0]->product_id, 'image_id' => $images[0]->id, 'image_asset_id' => $replacement->id,
        'asset_sha256' => $replacement->sha256, 'photograph_version' => 2, 'alt_text_version' => 1, 'alt_text' => 'Old clasp',
        'scope' => 'images', 'created_at' => now(), 'updated_at' => now(),
    ]);
    phase3Approve($stack);
    $planned = app(StackImageSync::class)->plan($stack->fresh());
    expect($planned['uploads'])->toBe([(int) $current->id])
        ->and($planned['retire'])->toBe([(int) $old->id]);

    $remote = new Phase3Remote('old-bytes');
    $remote->loseCreateId = true;
    $remote->bodies['https://cdn.shopify.com/s/files/old.jpg'] = 'old-bytes';
    $remote->nodes[] = $remote->node('gid://shopify/MediaImage/772', 'https://cdn.shopify.com/s/files/old.jpg');
    $remote->install();
    $pending = app(StackImageSync::class)->synchronizeStack((int) $stack->id);

    expect($pending['outcome'])->toBe('uncertain')
        ->and($remote->creates)->toBe(1)
        ->and($remote->removals)->toBe(0)
        ->and($old->fresh()->sync_state)->not->toBe(Image::SYNC_STATE_LOCAL_DELETED)
        ->and($old->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/772');
});

it('removes only the obsolete association after its replacement is confirmed', function (): void {
    phase3Flags();
    [, $images, $stack] = phase3Pair('AFTER', [
        ['bytes' => 'before-bytes', 'filename' => 'AFTER.jpg', 'alt' => 'Before'],
    ]);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $old = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    Image::withoutEvents(fn () => $old->forceFill(['shopify_id' => 'gid://shopify/MediaImage/773'])->save());
    $replacement = createImportTestAsset('after-bytes', 'jpg', 'AFTER.jpg');
    Image::withoutEvents(fn () => $images[0]->forceFill(['image_asset_id' => $replacement->id, 'photograph_version' => 2])->save());
    app(ComponentStackPropagation::class)->stageStack($stack->fresh());
    $current = Image::query()->active()->where('product_id', $stack->id)->where('image_asset_id', $replacement->id)->first();
    Image::withoutEvents(fn () => $current->forceFill([
        'shopify_id' => 'gid://shopify/MediaImage/774',
        'needs_shopify_image_sync' => false,
        'shopify_alt_text' => 'Before',
    ])->save());
    DB::table('component_image_authorizations')->insert([
        'product_id' => $images[0]->product_id, 'image_id' => $images[0]->id, 'image_asset_id' => $replacement->id,
        'asset_sha256' => $replacement->sha256, 'photograph_version' => 2, 'alt_text_version' => 1, 'alt_text' => 'Before',
        'scope' => 'images', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('stack_image_sync_ledger')->insert([
        'stack_product_id' => $stack->id, 'image_id' => $current->id, 'source_image_id' => $images[0]->id,
        'verified_photograph_version' => 2, 'outcome' => 'verified', 'shopify_media_id' => 'gid://shopify/MediaImage/774',
        'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    phase3Approve($stack);
    $remote = new Phase3Remote;
    $remote->bodies['https://cdn.shopify.com/s/files/before.jpg'] = 'before-bytes';
    $remote->bodies['https://cdn.shopify.com/s/files/after.jpg'] = 'after-bytes';
    $remote->nodes[] = $remote->node('gid://shopify/MediaImage/773', 'https://cdn.shopify.com/s/files/before.jpg');
    $remote->nodes[] = $remote->node('gid://shopify/MediaImage/774', 'https://cdn.shopify.com/s/files/after.jpg', 'Before');
    $remote->install();

    $plan = app(StackImageSync::class)->synchronizeStack((int) $stack->id);

    expect($plan['uploads'])->toBe([])
        ->and($plan['retire'])->toBe([(int) $old->id])
        ->and($plan['outcome'])->toBe('retirement_confirmed')
        ->and($remote->creates)->toBe(0)
        ->and($remote->removals)->toBe(1)
        ->and($old->fresh()->sync_state)->toBe(Image::SYNC_STATE_LOCAL_DELETED)
        ->and($current->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/774')
        ->and(DB::table('stack_image_sync_ledger')->where('image_id', $old->id)->where('outcome', 'retirement_confirmed')->whereNull('verified_photograph_version')->exists())->toBeTrue();
});

it('recovers a not-ready retirement and does not replay an uncertain removal', function (): void {
    phase3Flags();
    [, $images, $stack] = phase3Pair('RETRY', [
        ['bytes' => 'retry-bytes', 'filename' => 'RETRY.jpg', 'alt' => 'Retry'],
        ['bytes' => 'other-bytes', 'filename' => 'RETRY-1.jpg', 'alt' => 'Other'],
    ]);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('source_image_id', $images[0]->id)->first();
    Image::withoutEvents(fn () => $copy->forceFill(['shopify_id' => 'gid://shopify/MediaImage/775'])->save());
    phase3Approve($stack);
    Image::withoutEvents(fn () => $images[0]->forceFill(['sync_state' => Image::SYNC_STATE_LOCAL_DELETED])->save());
    $remote = new Phase3Remote;
    $remote->processing = true;
    $remote->bodies['https://cdn.shopify.com/s/files/retry.jpg'] = 'retry-bytes';
    $remote->nodes[] = $remote->node('gid://shopify/MediaImage/775', 'https://cdn.shopify.com/s/files/retry.jpg');
    $remote->install();

    $failed = app(StackImageSync::class)->synchronizeStack((int) $stack->id);
    expect($failed['outcome'])->toBe('failed')
        ->and($remote->removals)->toBe(0)
        ->and($copy->fresh()->sync_state)->not->toBe(Image::SYNC_STATE_LOCAL_DELETED)
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->value('status'))->toBe('pending')
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->value('last_error'))->toContain('not READY');

    $remote->processing = false;
    $confirmed = app(StackImageSync::class)->synchronizeStack((int) $stack->id);
    expect($confirmed['outcome'])->toBe('retirement_confirmed')
        ->and($remote->removals)->toBe(1)
        ->and($copy->fresh()->sync_state)->toBe(Image::SYNC_STATE_LOCAL_DELETED);

    $remote->keepRemovedMedia = true;
    $remote->nodes[] = $remote->node('gid://shopify/MediaImage/776', 'https://cdn.shopify.com/s/files/uncertain.jpg');
    $second = Image::query()->where('product_id', $stack->id)->where('source_image_id', $images[1]->id)->first();
    Image::withoutEvents(fn () => $second->forceFill(['shopify_id' => 'gid://shopify/MediaImage/776'])->save());
    Image::withoutEvents(fn () => $images[1]->forceFill(['sync_state' => Image::SYNC_STATE_LOCAL_DELETED])->save());
    $remote->bodies['https://cdn.shopify.com/s/files/uncertain.jpg'] = 'other-bytes';
    $uncertain = app(StackImageSync::class)->synchronizeStack((int) $stack->id);
    $again = app(StackImageSync::class)->synchronizeStack((int) $stack->id);

    expect($uncertain['outcome'])->toBe('uncertain')
        ->and($again['outcome'])->toBe('uncertain')
        ->and($remote->removals)->toBe(2)
        ->and(DB::table('inherited_media_retirements')->where('image_id', $second->id)->value('status'))->toBe('mutating')
        ->and($second->fresh()->sync_state)->not->toBe(Image::SYNC_STATE_LOCAL_DELETED);
});

it('removes an unpublished copy when the last component is removed and preserves a still-required photograph', function (): void {
    phase3Flags();
    [$component, $images, $stack, $draft] = phase3Pair('EMPTY', [
        ['bytes' => 'only-bytes', 'filename' => 'EMPTY.jpg', 'alt' => 'Only'],
    ]);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $draft->forceFill(['bundle_product_ids' => []])->save();

    $result = app(ComponentStackPropagation::class)->stageStack($stack->fresh());

    expect($result['rebuilt'])->toBeTrue()
        ->and($copy->fresh()->sync_state)->toBe(Image::SYNC_STATE_LOCAL_DELETED)
        ->and(Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->active()->exists())->toBeFalse();

    $other = createImageImportProduct('EMPTY-OTHER');
    Image::withoutEvents(fn () => Image::create([
        'product_id' => $other->id, 'position' => 1, 'image_asset_id' => $images[0]->image_asset_id,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP, 'sync_state' => Image::SYNC_STATE_SYNCED, 'alt_text' => 'Only',
    ]));
    $sharedStack = createImageImportProduct('SHARED-STACK', ['is_bundle' => true, 'status' => 'active']);
    $sharedDraft = NewProductDraft::withoutEvents(fn () => NewProductDraft::create([
        'sku' => 'SHARED-STACK', 'shopify_id' => $sharedStack->shopify_id, 'handle' => $sharedStack->handle,
        'title' => 'Shared', 'status' => 'active', 'bundle_product_ids' => [$component->id, $other->id],
    ]));
    Image::withoutEvents(fn () => $images[0]->forceFill(['sync_state' => Image::SYNC_STATE_SYNCED])->save());
    app(ComponentStackPropagation::class)->stageStack($sharedStack);
    $shared = Image::query()->where('product_id', $sharedStack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    Image::withoutEvents(fn () => $shared->forceFill(['shopify_id' => 'gid://shopify/MediaImage/777'])->save());
    $draft->forceFill(['bundle_product_ids' => [$component->id]])->save();
    $sharedDraft->forceFill(['bundle_product_ids' => [$other->id]])->save();
    phase3Approve($sharedStack);
    $remote = new Phase3Remote;
    $remote->bodies['https://cdn.shopify.com/s/files/shared.jpg'] = 'only-bytes';
    $remote->nodes[] = $remote->node('gid://shopify/MediaImage/777', 'https://cdn.shopify.com/s/files/shared.jpg');
    $remote->install();

    $plan = app(StackImageSync::class)->synchronizeStack((int) $sharedStack->id);

    expect($remote->removals)->toBe(0)
        ->and($shared->fresh()->sync_state)->not->toBe(Image::SYNC_STATE_LOCAL_DELETED)
        ->and($shared->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/777')
        ->and($plan['outcome'])->not->toBe('retirement_confirmed');
});

it('does not verify a legacy stack that has no photograph authorization', function (): void {
    phase3Flags();
    Http::preventStrayRequests();
    [, , $stack] = phase3Pair('LEGACY2', [
        ['bytes' => 'legacy-bytes', 'filename' => 'LEGACY2.jpg', 'alt' => 'Legacy'],
    ]);
    phase3Approve($stack);

    $this->artisan('images:rebuild-stack-baseline', ['stack' => $stack->id, '--publish' => true])->assertSuccessful();

    Http::assertNothingSent();
    expect(DB::table('stack_image_sync_ledger')->where('stack_product_id', $stack->id)->where('outcome', 'baseline_verified')->exists())->toBeFalse()
        ->and(DB::table('stack_image_sync_ledger')->where('stack_product_id', $stack->id)->where('outcome', 'verified')->exists())->toBeFalse()
        ->and(DB::table('stack_image_sync_ledger')->where('stack_product_id', $stack->id)->whereNotNull('verified_at')->exists())->toBeFalse()
        ->and(DB::table('component_image_authorizations')->count())->toBe(0);
});

it('verifies an unchanged gallery only after a complete Shopify read-back', function (): void {
    phase3Flags();
    [, $images, $stack] = phase3Pair('READ', [
        ['bytes' => 'read-bytes', 'filename' => 'READ.jpg', 'alt' => 'Read sentence'],
    ]);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    Image::withoutEvents(fn () => $copy->forceFill([
        'shopify_id' => 'gid://shopify/MediaImage/778',
        'needs_shopify_image_sync' => false,
        'shopify_alt_text' => 'Read sentence',
    ])->save());
    DB::table('component_image_authorizations')->insert([
        'product_id' => $images[0]->product_id, 'image_id' => $images[0]->id, 'image_asset_id' => $images[0]->image_asset_id,
        'asset_sha256' => $images[0]->imageAsset->sha256, 'photograph_version' => 1, 'alt_text_version' => 1,
        'alt_text' => 'Read sentence', 'scope' => 'full', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('stack_image_sync_ledger')->insert([
        'stack_product_id' => $stack->id, 'image_id' => $copy->id, 'source_image_id' => $images[0]->id,
        'verified_photograph_version' => 1, 'verified_alt_version' => 1, 'outcome' => 'verified',
        'shopify_media_id' => 'gid://shopify/MediaImage/778', 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    phase3Approve($stack);
    $remote = new Phase3Remote;
    $remote->bodies['https://cdn.shopify.com/s/files/read.jpg'] = 'read-bytes';
    $remote->nodes[] = $remote->node('gid://shopify/MediaImage/778', 'https://cdn.shopify.com/s/files/read.jpg', 'Read sentence');
    $remote->install();

    $this->artisan('images:rebuild-stack-baseline', ['stack' => $stack->id, '--publish' => true])->assertSuccessful();

    $receipt = DB::table('stack_image_sync_ledger')->where('image_id', $copy->id)->where('outcome', 'verified')->orderByDesc('id')->first();
    expect(DB::table('stack_image_sync_ledger')->where('stack_product_id', $stack->id)->where('outcome', 'baseline_verified')->exists())->toBeTrue()
        ->and($receipt->verified_photograph_version)->toBe(1)
        ->and($receipt->verified_alt_version)->toBe(1)
        ->and($receipt->shopify_media_id)->toBe('gid://shopify/MediaImage/778')
        ->and($receipt->verified_at)->not->toBeNull()
        ->and($remote->creates)->toBe(0)
        ->and($remote->removals)->toBe(0);
});

it('does not verify alt text from a photograph-only read or from cached alt text', function (): void {
    phase3Flags();
    [, $images, $stack] = phase3Pair('ALTREAD', [
        ['bytes' => 'alt-bytes', 'filename' => 'ALTREAD.jpg', 'alt' => 'Authorized sentence'],
    ]);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    Image::withoutEvents(fn () => $copy->forceFill([
        'shopify_id' => 'gid://shopify/MediaImage/779',
        'needs_shopify_image_sync' => false,
        'shopify_alt_text' => 'Authorized sentence',
    ])->save());
    DB::table('component_image_authorizations')->insert([
        'product_id' => $images[0]->product_id, 'image_id' => $images[0]->id, 'image_asset_id' => $images[0]->image_asset_id,
        'asset_sha256' => $images[0]->imageAsset->sha256, 'photograph_version' => 1, 'alt_text_version' => 1,
        'alt_text' => null, 'scope' => 'images', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('stack_image_sync_ledger')->insert([
        'stack_product_id' => $stack->id, 'image_id' => $copy->id, 'source_image_id' => $images[0]->id,
        'verified_photograph_version' => 1, 'outcome' => 'verified', 'shopify_media_id' => 'gid://shopify/MediaImage/779',
        'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    phase3Approve($stack);
    $remote = new Phase3Remote;
    $remote->bodies['https://cdn.shopify.com/s/files/alt.jpg'] = 'alt-bytes';
    $remote->nodes[] = $remote->node('gid://shopify/MediaImage/779', 'https://cdn.shopify.com/s/files/alt.jpg', 'A different remote sentence');
    $remote->install();

    $photoOnly = app(StackImageSync::class)->synchronizeStack((int) $stack->id);
    $photoReceipt = DB::table('stack_image_sync_ledger')->where('image_id', $copy->id)->where('outcome', 'verified')->orderByDesc('id')->first();
    expect($photoOnly['outcome'])->toBe('verified')
        ->and($photoReceipt->verified_photograph_version)->toBe(1)
        ->and($photoReceipt->verified_alt_version)->toBeNull();

    DB::table('component_image_authorizations')->insert([
        'product_id' => $images[0]->product_id, 'image_id' => $images[0]->id, 'image_asset_id' => $images[0]->image_asset_id,
        'asset_sha256' => $images[0]->imageAsset->sha256, 'photograph_version' => 1, 'alt_text_version' => 1,
        'alt_text' => 'Authorized sentence', 'scope' => 'image_alt', 'created_at' => now(), 'updated_at' => now(),
    ]);
    $drift = app(StackImageSync::class)->synchronizeStack((int) $stack->id);

    expect($drift['outcome'])->toBe('failed')
        ->and($copy->fresh()->shopify_alt_text)->toBe('A different remote sentence')
        ->and(DB::table('stack_image_sync_ledger')->where('image_id', $copy->id)->where('outcome', 'verified')->orderByDesc('id')->value('verified_alt_version'))->toBeNull()
        ->and(DB::table('stack_image_sync_ledger')->where('stack_product_id', $stack->id)->where('baseline_verified', true)->exists())->toBeFalse();
});

it('does not verify a baseline when the Shopify gallery read is incomplete', function (): void {
    phase3Flags();
    [, $images, $stack] = phase3Pair('PARTIAL', [
        ['bytes' => 'partial-bytes', 'filename' => 'PARTIAL.jpg', 'alt' => 'Partial'],
    ]);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    Image::withoutEvents(fn () => $copy->forceFill([
        'shopify_id' => 'gid://shopify/MediaImage/780',
        'needs_shopify_image_sync' => false,
        'shopify_alt_text' => 'Partial',
    ])->save());
    DB::table('component_image_authorizations')->insert([
        'product_id' => $images[0]->product_id, 'image_id' => $images[0]->id, 'image_asset_id' => $images[0]->image_asset_id,
        'asset_sha256' => $images[0]->imageAsset->sha256, 'photograph_version' => 1, 'alt_text_version' => 1,
        'alt_text' => 'Partial', 'scope' => 'full', 'created_at' => now(), 'updated_at' => now(),
    ]);
    DB::table('stack_image_sync_ledger')->insert([
        'stack_product_id' => $stack->id, 'image_id' => $copy->id, 'source_image_id' => $images[0]->id,
        'verified_photograph_version' => 1, 'verified_alt_version' => 1, 'outcome' => 'verified',
        'shopify_media_id' => 'gid://shopify/MediaImage/780', 'verified_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
    phase3Approve($stack);
    $remote = new Phase3Remote;
    $remote->incomplete = true;
    $remote->nodes[] = $remote->node('gid://shopify/MediaImage/780', 'https://cdn.shopify.com/s/files/partial.jpg', 'Partial');
    $remote->install();

    $this->artisan('images:rebuild-stack-baseline', ['stack' => $stack->id, '--publish' => true])->assertSuccessful();

    expect(DB::table('stack_image_sync_ledger')->where('stack_product_id', $stack->id)->where('outcome', 'baseline_verified')->exists())->toBeFalse()
        ->and(DB::table('stack_image_sync_ledger')->where('stack_product_id', $stack->id)->orderByDesc('id')->value('outcome'))->toBe('uncertain')
        ->and($copy->fresh()->needs_shopify_image_sync)->toBeFalse();
});

function phase3ApprovePhotograph(Product $product, Image $image, string $sentence): void
{
    \Illuminate\Support\Facades\Bus::fake();
    app(\App\Services\ImageAltTextBackfillService::class)->markPendingReview($image->fresh(), $sentence);
    $service = app(\App\Services\ProductPartialApprovalService::class);
    foreach (['images', 'image_alt'] as $scope) {
        $requested = $service->request(collect([$product->fresh()]), User::factory()->create()->id, [$scope], []);
        expect($requested['requested'])->toBe(1);
        $request = \App\Models\ProductPartialApprovalRequest::query()
            ->where('product_id', $product->id)
            ->where('status', \App\Models\ProductPartialApprovalRequest::STATUS_PENDING)
            ->latest('id')
            ->first();
        expect($service->approveRequests(collect([$request]), User::factory()->create()->id)['approved'])->toBe(1);
    }
}

it('does not retire published media when stack membership is unknown', function (): void {
    phase3Flags();
    [, $images, $stack, $draft] = phase3Pair('UNKNOWN', [
        ['bytes' => 'known-bytes', 'filename' => 'UNKNOWN.jpg', 'alt' => 'Known'],
    ]);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    Image::withoutEvents(fn () => $copy->forceFill(['shopify_id' => 'gid://shopify/MediaImage/781'])->save());
    phase3Approve($stack);
    NewProductDraft::withoutEvents(fn () => $draft->delete());
    $remote = new Phase3Remote;
    $remote->nodes[] = $remote->node('gid://shopify/MediaImage/781', 'https://cdn.shopify.com/s/files/unknown.jpg');
    $remote->install();

    $plan = app(StackImageSync::class)->synchronizeStack((int) $stack->id);

    expect(app(\App\Services\StackComponentCatalog::class)->membershipIsKnown($stack->fresh()))->toBeFalse()
        ->and($plan['outcome'])->toBe('uncertain')
        ->and($plan['retire'])->toBe([])
        ->and($remote->removals)->toBe(0)
        ->and($copy->fresh()->sync_state)->not->toBe(Image::SYNC_STATE_LOCAL_DELETED)
        ->and($copy->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/781')
        ->and(DB::table('inherited_image_sources')->where('image_id', $copy->id)->whereNull('removed_at')->exists())->toBeTrue()
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->where('status', 'confirmed')->exists())->toBeFalse()
        ->and(DB::table('stack_image_sync_ledger')->where('stack_product_id', $stack->id)->where('outcome', 'baseline_verified')->exists())->toBeFalse();
});

it('does not delete an unpublished inherited copy when membership is unknown', function (): void {
    phase3Flags();
    [, , $stack, $draft] = phase3Pair('UNKNOWN-LOCAL', [
        ['bytes' => 'local-bytes', 'filename' => 'UNKNOWN-LOCAL.jpg', 'alt' => 'Local'],
    ]);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    NewProductDraft::withoutEvents(fn () => $draft->delete());

    $staged = app(ComponentStackPropagation::class)->stageStack($stack->fresh());
    $plan = app(StackImageSync::class)->synchronizeStack((int) $stack->id);

    expect($staged['rebuilt'])->toBeFalse()
        ->and($plan['outcome'])->toBe('uncertain')
        ->and($copy->fresh()->sync_state)->not->toBe(Image::SYNC_STATE_LOCAL_DELETED)
        ->and(Image::query()->whereKey($copy->id)->active()->exists())->toBeTrue()
        ->and(DB::table('inherited_image_sources')->where('image_id', $copy->id)->whereNull('removed_at')->exists())->toBeTrue();
});

it('still retires published media when membership is known and empty', function (): void {
    phase3Flags();
    [, , $stack, $draft] = phase3Pair('KNOWN-EMPTY', [
        ['bytes' => 'empty-bytes', 'filename' => 'KNOWN-EMPTY.jpg', 'alt' => 'Empty'],
    ]);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    Image::withoutEvents(fn () => $copy->forceFill(['shopify_id' => 'gid://shopify/MediaImage/782'])->save());
    NewProductDraft::withoutEvents(fn () => $draft->forceFill(['bundle_product_ids' => []])->save());
    phase3Approve($stack);
    $remote = new Phase3Remote;
    $remote->bodies['https://cdn.shopify.com/s/files/empty.jpg'] = 'empty-bytes';
    $remote->nodes[] = $remote->node('gid://shopify/MediaImage/782', 'https://cdn.shopify.com/s/files/empty.jpg');
    $remote->install();

    $plan = app(StackImageSync::class)->synchronizeStack((int) $stack->id);

    expect(app(\App\Services\StackComponentCatalog::class)->membershipState($stack->fresh()))->toBe('empty')
        ->and($plan['outcome'])->toBe('retirement_confirmed')
        ->and($remote->removals)->toBe(1)
        ->and($remote->verificationReads)->toBeGreaterThan(0)
        ->and($copy->fresh()->sync_state)->toBe(Image::SYNC_STATE_LOCAL_DELETED);
});

it('blocks retirement when membership becomes unknown before the Shopify mutation', function (): void {
    phase3Flags();
    [, , $stack, $draft] = phase3Pair('RACE', [
        ['bytes' => 'race-bytes', 'filename' => 'RACE.jpg', 'alt' => 'Race'],
    ]);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    Image::withoutEvents(fn () => $copy->forceFill(['shopify_id' => 'gid://shopify/MediaImage/783'])->save());
    phase3Approve($stack);
    NewProductDraft::withoutEvents(fn () => $draft->forceFill(['bundle_product_ids' => []])->save());
    $remote = new Phase3Remote;
    $remote->bodies['https://cdn.shopify.com/s/files/race.jpg'] = 'race-bytes';
    $remote->nodes[] = $remote->node('gid://shopify/MediaImage/783', 'https://cdn.shopify.com/s/files/race.jpg');
    $remote->onRetirementRead = function () use ($draft): void {
        NewProductDraft::withoutEvents(fn () => $draft->delete());
    };
    $remote->install();

    $plan = app(StackImageSync::class)->synchronizeStack((int) $stack->id);

    expect($plan['outcome'])->not->toBe('retirement_confirmed')
        ->and($plan['outcome'])->not->toBe('verified')
        ->and($remote->removals)->toBe(0)
        ->and($copy->fresh()->sync_state)->not->toBe(Image::SYNC_STATE_LOCAL_DELETED)
        ->and($copy->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/783')
        ->and(DB::table('inherited_media_retirements')->where('image_id', $copy->id)->value('status'))->not->toBe('confirmed');
});

it('does not record a verified baseline when the photograph is ready and the approved alt is missing', function (): void {
    phase3Flags();
    [$component, $images, $stack] = phase3Pair('MISSING-ALT', [
        ['bytes' => 'missing-alt-bytes', 'filename' => 'MISSING-ALT.jpg', 'alt' => 'Approved sentence'],
    ]);
    phase3ApprovePhotograph($component, $images[0], 'Approved sentence');
    app(ComponentStackPropagation::class)->stageStack($stack);
    phase3Approve($stack);
    $remote = new Phase3Remote;
    $remote->createdBytes = 'missing-alt-bytes';
    $remote->createdAlt = null;
    $remote->install();

    $this->artisan('images:rebuild-stack-baseline', ['stack' => $stack->id, '--publish' => true])->assertSuccessful();
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $receipt = DB::table('stack_image_sync_ledger')->where('image_id', $copy->id)->where('outcome', 'verified')->orderByDesc('id')->first();

    expect($remote->creates)->toBe(1)
        ->and($receipt->verified_photograph_version)->toBe(1)
        ->and($receipt->verified_alt_version)->toBeNull()
        ->and($copy->fresh()->needs_shopify_image_sync)->toBeFalse()
        ->and($copy->fresh()->shopify_alt_pending)->toBeTrue()
        ->and($copy->fresh()->shopify_id)->not->toBeNull()
        ->and(DB::table('stack_image_sync_ledger')->where('stack_product_id', $stack->id)->where('outcome', 'baseline_verified')->exists())->toBeFalse();
});

it('records a verified baseline only when the photograph and approved alt both read back', function (): void {
    phase3Flags();
    [$component, $images, $stack] = phase3Pair('BOTH-ALT', [
        ['bytes' => 'both-alt-bytes', 'filename' => 'BOTH-ALT.jpg', 'alt' => 'Approved sentence'],
    ]);
    phase3ApprovePhotograph($component, $images[0], 'Approved sentence');
    app(ComponentStackPropagation::class)->stageStack($stack);
    phase3Approve($stack);
    $remote = new Phase3Remote;
    $remote->createdBytes = 'both-alt-bytes';
    $remote->createdAlt = 'Approved sentence';
    $remote->install();

    $this->artisan('images:rebuild-stack-baseline', ['stack' => $stack->id, '--publish' => true])->assertSuccessful();
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();
    $receipt = DB::table('stack_image_sync_ledger')->where('image_id', $copy->id)->where('outcome', 'verified')->whereNotNull('verified_alt_version')->orderByDesc('id')->first();

    expect($remote->creates)->toBe(1)
        ->and($receipt->verified_photograph_version)->toBe(1)
        ->and($receipt->verified_alt_version)->not->toBeNull()
        ->and(DB::table('stack_image_sync_ledger')->where('stack_product_id', $stack->id)->where('outcome', 'baseline_verified')->exists())->toBeTrue();
});

it('recovers a missing alt without uploading the photograph again', function (): void {
    phase3Flags();
    [$component, $images, $stack] = phase3Pair('ALT-ONLY', [
        ['bytes' => 'alt-only-bytes', 'filename' => 'ALT-ONLY.jpg', 'alt' => 'Approved sentence'],
    ]);
    phase3ApprovePhotograph($component, $images[0], 'Approved sentence');
    app(ComponentStackPropagation::class)->stageStack($stack);
    phase3Approve($stack);
    $remote = new Phase3Remote;
    $remote->createdBytes = 'alt-only-bytes';
    $remote->createdAlt = null;
    $remote->install();
    $first = app(StackImageSync::class)->synchronizeStack((int) $stack->id);
    $copy = Image::query()->where('product_id', $stack->id)->where('ownership', ImageOwnership::COMPONENT_INHERITED)->first();

    expect($first['outcome'])->not->toBe('verified')
        ->and($remote->creates)->toBe(1)
        ->and($copy->fresh()->needs_shopify_image_sync)->toBeFalse()
        ->and($copy->fresh()->shopify_alt_pending)->toBeTrue();

    $second = app(StackImageSync::class)->synchronizeStack((int) $stack->id);

    expect($second['uploads'])->toBe([])
        ->and($second['outcome'])->toBe('verified')
        ->and($remote->creates)->toBe(1)
        ->and($remote->altUpdates)->toBe(1)
        ->and($copy->fresh()->shopify_id)->not->toBeNull();

    $this->artisan('images:rebuild-stack-baseline', ['stack' => $stack->id, '--publish' => true])->assertSuccessful();

    expect($remote->creates)->toBe(1)
        ->and(DB::table('stack_image_sync_ledger')->where('stack_product_id', $stack->id)->where('outcome', 'baseline_verified')->exists())->toBeTrue();
});

it('does not treat a failed gallery read after retirement as success', function (): void {
    phase3Flags();
    [, $images, $stack] = phase3Pair('READFAIL', [
        ['bytes' => 'readfail-bytes', 'filename' => 'READFAIL.jpg', 'alt' => 'Goes'],
        ['bytes' => 'readfail-stay', 'filename' => 'READFAIL-1.jpg', 'alt' => 'Stays'],
    ]);
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::query()->where('product_id', $stack->id)->where('source_image_id', $images[0]->id)->first();
    Image::withoutEvents(fn () => $copy->forceFill(['shopify_id' => 'gid://shopify/MediaImage/784'])->save());
    phase3Approve($stack);
    Image::withoutEvents(fn () => $images[0]->forceFill(['sync_state' => Image::SYNC_STATE_LOCAL_DELETED])->save());
    $remote = new Phase3Remote;
    $remote->galleryIncomplete = true;
    $remote->bodies['https://cdn.shopify.com/s/files/readfail.jpg'] = 'readfail-bytes';
    $remote->nodes[] = $remote->node('gid://shopify/MediaImage/784', 'https://cdn.shopify.com/s/files/readfail.jpg');
    $remote->install();

    $plan = app(StackImageSync::class)->synchronizeStack((int) $stack->id);

    expect($remote->removals)->toBe(1)
        ->and($remote->verificationReads)->toBeGreaterThan(0)
        ->and($plan['outcome'])->toBe('uncertain')
        ->and($plan['outcome'])->not->toBe('verified')
        ->and($plan['outcome'])->not->toBe('retirement_confirmed')
        ->and(DB::table('stack_image_sync_ledger')->where('stack_product_id', $stack->id)->where('outcome', 'baseline_verified')->exists())->toBeFalse();
});
