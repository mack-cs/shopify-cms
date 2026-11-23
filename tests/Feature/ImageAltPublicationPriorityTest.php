<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\RolesEnum;
use App\Filament\Pages\ImageAltTextRecovery;
use App\Models\Approval;
use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ProductPartialApprovalRequest;
use App\Models\User;
use App\Services\ComponentStackPropagation;
use App\Services\ImageAltApprovalEvidence;
use App\Services\ImageAltRecovery;
use App\Services\ImageAltTextBackfillService;
use App\Services\InheritedAltResolution;
use App\Services\ProductPartialApprovalService;
use App\Services\ProductShopifyUpdater;
use App\Services\ShopifyImageImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/shopify-image-import.php';

final class PriorityAltGateway implements ShopifyGraphqlGateway
{
    public array $nodes = [];

    public array $bytes = [];

    public int $updates = 0;

    public int $creates = 0;

    public bool $failUpdate = false;

    public bool $delayConfirmation = false;

    public ?int $maximumTransactionLevel = null;

    public bool $replacePhotographAfterUpdate = false;

    public function graphql(string $query, array $variables = []): array
    {
        if ($this->maximumTransactionLevel !== null && DB::transactionLevel() > $this->maximumTransactionLevel) {
            throw new RuntimeException('Network call inside a row-lock transaction');
        }
        if (str_contains($query, 'productReorderMedia')) {
            $order = array_column($variables['moves'], 'id');
            usort($this->nodes[$variables['id']], fn ($a, $b) => array_search($a['id'], $order, true) <=> array_search($b['id'], $order, true));

            return ['productReorderMedia' => ['job' => ['id' => 'gid://shopify/Job/1'], 'mediaUserErrors' => []]];
        }
        if (str_contains($query, 'productCreateMedia')) {
            $this->creates++;
            parse_str((string) parse_url($variables['media'][0]['originalSource'], PHP_URL_QUERY), $queryArgs);
            $hash = $queryArgs['asset'] ?? '';
            $bytes = null;
            foreach ($this->bytes as $body) {
                if (hash('sha256', $body) === $hash) {
                    $bytes = $body;
                }
            }
            if ($bytes === null) {
                throw new RuntimeException('Unknown immutable upload bytes');
            }
            $id = 'gid://shopify/MediaImage/'.(900000 + $this->creates);
            $url = 'https://cdn.shopify.com/s/files/1/new-'.$hash.'.jpg';
            $this->bytes[$url] = $bytes;
            $this->nodes[$variables['productId']][] = ['id' => $id, 'alt' => null, 'status' => 'READY', 'fileStatus' => 'READY', 'image' => ['url' => $url], 'originalSource' => ['url' => $url]];

            return ['productCreateMedia' => ['media' => [['id' => $id]], 'mediaUserErrors' => []]];
        }
        if (str_contains($query, 'fileUpdate')) {
            $this->updates++;
            if ($this->failUpdate) {
                throw new RuntimeException('Accepted update response may have been lost');
            }
            $file = $variables['files'][0];
            if (! $this->delayConfirmation) {
                foreach ($this->nodes as &$nodes) {
                    foreach ($nodes as &$node) {
                        if ($node['id'] === $file['id']) {
                            $node['alt'] = $file['alt'];
                            if ($this->replacePhotographAfterUpdate) {
                                $this->bytes[$node['originalSource']['url']] = 'unexpected replacement photograph';
                            }
                        }
                    }
                }
            }

            return ['fileUpdate' => ['files' => [$file], 'userErrors' => []]];
        }
        if (str_starts_with(ltrim($query), 'mutation')) {
            throw new RuntimeException('Unexpected mutation');
        }
        $productId = $variables['id'] ?? $variables['productId'] ?? Product::where('handle', $variables['handle'] ?? '')->value('shopify_id');
        $product = ['id' => $productId, 'options' => [], 'variants' => ['nodes' => []], 'media' => ['nodes' => $this->nodes[$productId] ?? [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]], 'images' => ['nodes' => [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]];

        return [str_contains($query, 'productByHandle') ? 'productByHandle' : 'product' => $product];
    }
}

beforeEach(function () {
    Storage::fake('public');
    Bus::fake();
    config(['images.component_stack_propagation' => false]);
    $this->gateway = new PriorityAltGateway;
    app()->instance(ShopifyGraphqlGateway::class, $this->gateway);
    config(['services.shopify.shop' => 'test-shop.myshopify.com', 'services.shopify.admin_access_token' => 'test-token']);
    Http::fake(function ($request) {
        if ($request->method() === 'GET') {
            return Http::response($this->gateway->bytes[strtok($request->url(), '?')] ?? 'wrong photograph');
        }
        $payload = $request->data();

        return Http::response(['data' => $this->gateway->graphql($payload['query'], (array) ($payload['variables'] ?? []))]);
    });
    Role::findOrCreate(RolesEnum::Admin->value);
    $this->reviewer = User::factory()->create(['is_active' => true]);
    $this->reviewer->assignRole(RolesEnum::Admin->value);
});

function priorityImage($product, string $sentence, PriorityAltGateway $gateway, int $position = 1, array $extra = []): Image
{
    $bytes = 'priority-'.$product->id.'-'.$position;
    $asset = createImportTestAsset($bytes, 'jpg', 'priority.jpg');
    $id = 'gid://shopify/MediaImage/'.($product->id * 100 + $position);
    $url = 'https://cdn.shopify.com/s/files/1/priority-'.$product->id.'-'.$position.'.jpg';
    $gateway->bytes[$url] = $bytes;
    $gateway->nodes[$product->shopify_id][] = ['id' => $id, 'alt' => 'Old remote sentence', 'status' => 'READY', 'fileStatus' => 'READY', 'image' => ['url' => $url], 'originalSource' => ['url' => $url]];

    return Image::withoutEvents(fn () => Image::create(array_merge([
        'product_id' => $product->id, 'image_asset_id' => $asset->id, 'shopify_id' => $id, 'src' => $url, 'position' => $position, 'alt_text' => $sentence,
        'alt_text_status' => Image::ALT_PENDING_REVIEW, 'backup_status' => Image::BACKUP_STATUS_BACKED_UP, 'backup_completed_at' => now(),
        'last_shopify_synced_image_asset_id' => $asset->id, 'needs_shopify_image_sync' => false, 'sync_state' => Image::SYNC_STATE_SYNCED,
        'ownership' => $product->isStackProduct() ? 'stack_owned' : null,
    ], $extra)));
}

function priorityPartial($product, array $images, bool $legacy = false): ProductPartialApprovalRequest
{
    return ProductPartialApprovalRequest::withoutEvents(fn () => ProductPartialApprovalRequest::create([
        'product_id' => $product->id, 'approval_version' => $product->approval_version ?? 1, 'requested_by' => User::factory()->create()->id, 'status' => 'approved',
        'request_batch_id' => (string) Str::uuid(), 'scopes' => ['image_alt'], 'core_fields' => [],
        'image_alt_snapshot' => ['images' => array_map(fn ($i) => array_merge(['image_id' => $i->id, 'proposed_alt' => $i->alt_text, 'src' => $i->src, 'changed' => true], $legacy ? [] : ['image_asset_id' => $i->image_asset_id]), $images)], 'approved_at' => now(),
    ]));
}

it('publishes every independently approved photograph and skips mixed unapproved images idempotently', function () {
    $this->gateway->maximumTransactionLevel = DB::transactionLevel();
    $p = createImageImportProduct('PRIORITY-MULTI');
    $a = priorityImage($p, 'First approved sentence', $this->gateway);
    $b = priorityImage($p, 'Second approved sentence', $this->gateway, 2);
    $c = priorityImage($p, 'Unapproved sentence', $this->gateway, 3);
    app(ImageAltApprovalEvidence::class)->approve($a, $this->reviewer->id, 'Reviewed the first exact photograph and description.');
    app(ImageAltApprovalEvidence::class)->approve($b, $this->reviewer->id, 'Reviewed the second exact photograph and description.');
    $svc = app(ImageAltTextBackfillService::class);
    $result = $svc->publishIds([$a->id, $b->id, $c->id]);
    expect($result['published'])->toBe(2)->and($result['shopify_failed'])->toBe([])->and($a->fresh()->alt_text_status)->toBe(Image::ALT_PUBLISHED)
        ->and($b->fresh()->alt_text_status)->toBe(Image::ALT_PUBLISHED)->and($c->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW);
    $svc->publishIds([$a->id, $b->id]);
    expect($this->gateway->updates)->toBe(2)->and($this->gateway->creates)->toBe(0);
});

it('does not invalidate one approved image when a different image sentence changes', function () {
    $p = createImageImportProduct('PRIORITY-INDEPENDENT');
    $a = priorityImage($p, 'One approved', $this->gateway);
    $b = priorityImage($p, 'Two approved', $this->gateway, 2);
    priorityPartial($p, [$a, $b]);
    app(ImageAltTextBackfillService::class)->markPendingReview($b, 'Unapproved edit');
    expect(app(ProductPartialApprovalService::class)->approvedAlt($a))->toBe('One approved')->and(app(ProductPartialApprovalService::class)->approvedAlt($b->fresh()))->toBeNull();
    expect(app(ImageAltTextBackfillService::class)->publishIds([$a->id, $b->id])['published'])->toBe(1);
});

it('captures every image sentence at full product approval and uses the normal alt scope', function () {
    $p = createImageImportProduct('PRIORITY-FULL');
    $a = priorityImage($p, 'Full first', $this->gateway);
    $b = priorityImage($p, 'Full second', $this->gateway, 2);
    foreach (range(1, 2) as $ignored) {
        Approval::create(['product_id' => $p->id, 'user_id' => User::factory()->create()->id, 'approval_version' => $p->approval_version ?? 1]);
    }
    expect(DB::table('image_alt_approvals')->where('context', 'full_product_approval')->count())->toBe(2);
    $result = app(ProductShopifyUpdater::class)->updateApprovedProducts(collect([$p->fresh()]), ['image_alt']);
    expect($result['failed'])->toBe(0)->and($this->gateway->updates)->toBe(2)->and($b->fresh()->alt_text_status)->toBe(Image::ALT_PUBLISHED);
    app(ImageAltTextBackfillService::class)->markPendingReview($b->fresh(), 'New sentence needs review');
    expect(app(ProductPartialApprovalService::class)->approvedAlt($b->fresh()))->toBeNull();
});

it('preserves a legacy approval with corroborated photograph evidence and requires review when evidence is missing', function () {
    $p = createImageImportProduct('PRIORITY-LEGACY');
    $a = priorityImage($p, 'Historical approved sentence', $this->gateway);
    $a->imageAsset->update(['source_url' => $a->src, 'downloaded_at' => now()->subDay()]);
    $request = priorityPartial($p, [$a], true);
    expect(app(ProductPartialApprovalService::class)->approvedAlt($a->fresh()))->toBe($a->alt_text);
    $a->imageAsset->update(['downloaded_at' => now()->addDay()]);
    expect(app(ProductPartialApprovalService::class)->approvedAlt($a->fresh()))->toBeNull()->and($request->fresh()->status)->toBe('approved')
        ->and($a->fresh()->alt_text)->toBe('Historical approved sentence');
});

it('requires pending confirmation recovery before replacing a photograph', function () {
    $p = createImageImportProduct('PENDING-REPLACEMENT');
    $image = priorityImage($p, 'Approved pending description', $this->gateway);
    Image::withoutEvents(fn () => $image->forceFill(['alt_confirmation_media_id' => $image->shopify_id, 'alt_confirmation_text' => $image->alt_text, 'alt_text_status' => Image::ALT_AWAITING_CONFIRMATION])->save());
    $oldAsset = $image->image_asset_id;
    $replacement = createImportTestAsset('replacement while pending', 'jpg', 'pending-replacement.jpg');
    expect(fn () => (new ReflectionMethod(ShopifyImageImportService::class, 'prepareImageForImport'))
        ->invoke(app(ShopifyImageImportService::class), $p, $replacement, 'pending-replacement-v2.jpg', 1, 2))
        ->toThrow(RuntimeException::class, 'Resolve the pending');
    expect($image->fresh()->image_asset_id)->toBe($oldAsset)->and($image->fresh()->alt_confirmation_text)->toBe('Approved pending description');
});

it('cannot reuse approval or media identity after photograph replacement', function () {
    $p = createImageImportProduct('PRIORITY-REPLACE');
    $a = priorityImage($p, 'Approved old photograph', $this->gateway);
    priorityPartial($p, [$a]);
    $asset = createImportTestAsset('changed bytes', 'jpg', 'new.jpg');
    Image::withoutEvents(fn () => $a->forceFill(['image_asset_id' => $asset->id, 'shopify_identity_unresolved' => true])->save());
    expect(app(ProductPartialApprovalService::class)->approvedAlt($a->fresh()))->toBeNull();
    app(ImageAltTextBackfillService::class)->publishIds([$a->id]);
    expect($this->gateway->updates)->toBe(0)->and($this->gateway->creates)->toBe(0);
});

it('publishes stack owned images at any position and independently reviewed inherited sentences', function () {
    $p = createImageImportProduct('PRIORITY-STACK', ['is_bundle' => true]);
    $a = priorityImage($p, 'Owned second photograph', $this->gateway, 2);
    $component = createImageImportProduct('PRIORITY-COMP');
    $source = priorityImage($component, 'Source sentence', $this->gateway);
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['sku' => 'PRIORITY-STACK', 'shopify_id' => $p->shopify_id, 'handle' => $p->handle, 'title' => 'Stack', 'status' => 'active', 'bundle_product_ids' => [$component->id]]));
    $copy = priorityImage($p, 'Independent inherited sentence', $this->gateway, 3, ['ownership' => 'component_inherited', 'source_image_id' => $source->id, 'image_asset_id' => $source->image_asset_id, 'last_shopify_synced_image_asset_id' => $source->image_asset_id]);
    $nodeIndex = count($this->gateway->nodes[$p->shopify_id]) - 1;
    $url = $this->gateway->nodes[$p->shopify_id][$nodeIndex]['originalSource']['url'];
    $this->gateway->bytes[$url] = 'priority-'.$component->id.'-1';
    app(ImageAltTextBackfillService::class)->markPendingReview($copy, 'Independent inherited sentence');
    foreach ([$a, $copy->fresh()] as $image) {
        app(ImageAltApprovalEvidence::class)->approve($image, $this->reviewer->id, 'Reviewed this independent photograph and sentence.');
    }
    $result = app(ImageAltTextBackfillService::class)->publishIds([$a->id, $copy->id]);
    expect($result['published'])->toBe(2)->and($result['shopify_failed'])->toBe([])->and($source->fresh()->alt_text_status)->toBe(Image::ALT_PENDING_REVIEW);
});

it('retains uncertain state and confirms delayed Shopify publication without resending fileUpdate', function () {
    $p = createImageImportProduct('PRIORITY-DELAY');
    $a = priorityImage($p, 'Approved delayed sentence', $this->gateway);
    priorityPartial($p, [$a]);
    $this->gateway->delayConfirmation = true;
    $service = app(ImageAltTextBackfillService::class);
    $service->publishIds([$a->id]);
    expect($a->fresh()->alt_text_status)->toBe(Image::ALT_AWAITING_CONFIRMATION)->and($this->gateway->updates)->toBe(1);
    $service->retryIds([$a->id]);
    expect($this->gateway->updates)->toBe(1);
    $this->gateway->nodes[$p->shopify_id][0]['alt'] = $a->alt_text;
    $service->retryIds([$a->id]);
    expect($this->gateway->updates)->toBe(1)->and($a->fresh()->alt_text_status)->toBe(Image::ALT_PUBLISHED);
});

it('does not report publication when Shopify changes the photograph during an alt update', function () {
    $p = createImageImportProduct('CHANGED-DURING-ALT');
    $image = priorityImage($p, 'Approved original photograph', $this->gateway);
    priorityPartial($p, [$image]);
    $this->gateway->replacePhotographAfterUpdate = true;
    $result = app(ImageAltTextBackfillService::class)->publishIds([$image->id]);
    expect($result['shopify_failed'])->toHaveCount(1)->and($image->fresh()->alt_text_status)->toBe(Image::ALT_AWAITING_CONFIRMATION)
        ->and($image->fresh()->alt_confirmation_media_id)->toBe($image->shopify_id);
    app(ImageAltTextBackfillService::class)->publishIds([$image->id]);
    expect($this->gateway->updates)->toBe(1);
});

it('reports a lost request as uncertain and preserves sentence and approval evidence', function () {
    $p = createImageImportProduct('PRIORITY-LOST');
    $a = priorityImage($p, 'Keep approved sentence', $this->gateway);
    $request = priorityPartial($p, [$a]);
    $this->gateway->failUpdate = true;
    app(ImageAltTextBackfillService::class)->publishIds([$a->id]);
    expect($a->fresh()->alt_text_status)->toBe(Image::ALT_AWAITING_CONFIRMATION)->and($a->fresh()->alt_text)->toBe('Keep approved sentence')
        ->and($request->fresh()->status)->toBe('approved');
});

it('requires explicit ownership review and refuses unsafe inherited source links', function () {
    $p = createImageImportProduct('PRIORITY-UNKNOWN', ['is_bundle' => true]);
    $a = priorityImage($p, 'Preserve legacy sentence', $this->gateway, 1, ['ownership' => null]);
    expect(fn () => app(ImageAltApprovalEvidence::class)->approve($a, $this->reviewer->id, 'Reviewed but ownership remains unknown.'))->toThrow(RuntimeException::class);
    app(ImageAltRecovery::class)->classify($a, 'stack_owned', null, $this->reviewer->id, 'Operator verified this is an independently owned photograph.');
    app(ImageAltApprovalEvidence::class)->approve($a->fresh(), $this->reviewer->id, 'Reviewed the classified photograph and its sentence.');
    expect(app(ImageAltTextBackfillService::class)->publishIds([$a->id])['published'])->toBe(1)
        ->and(DB::table('image_alt_review_events')->where('action', 'classify')->count())->toBe(1);
});

it('exposes the recovery workflow only to authorized reviewers', function () {
    $p = createImageImportProduct('PRIORITY-PAGE');
    $a = priorityImage($p, 'Review this sentence', $this->gateway);
    $this->actingAs($this->reviewer);
    Livewire::test(ImageAltTextRecovery::class)->loadTable()->assertSuccessful()->assertCanSeeTableRecords([$a])->assertSee('priority-page')->assertSee('Review approval evidence');
    $this->actingAs(User::factory()->create());
    expect(ImageAltTextRecovery::canAccess())->toBeFalse();
});

it('publishes new component sentences to every current inherited copy without uploading unchanged photographs', function () {
    $component = createImageImportProduct('PRIORITY-FANOUT');
    $source = priorityImage($component, 'First component sentence', $this->gateway);
    priorityPartial($component, [$source]);
    $copies = [];
    $owned = [];
    foreach (range(1, 2) as $n) {
        $stack = createImageImportProduct('PRIORITY-DEPENDENT-'.$n, ['is_bundle' => true]);
        $owned[] = priorityImage($stack, 'Keep owned sentence '.$n, $this->gateway);
        NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['sku' => 'DEPENDENT-'.$n, 'shopify_id' => $stack->shopify_id, 'handle' => $stack->handle, 'title' => 'Stack', 'status' => 'active', 'bundle_product_ids' => [$component->id]]));
        $copy = priorityImage($stack, 'Outdated inherited sentence', $this->gateway, 2, ['ownership' => 'component_inherited', 'source_image_id' => $source->id, 'image_asset_id' => $source->image_asset_id, 'last_shopify_synced_image_asset_id' => $source->image_asset_id]);
        $url = $this->gateway->nodes[$stack->shopify_id][1]['originalSource']['url'];
        $this->gateway->bytes[$url] = 'priority-'.$component->id.'-1';
        $copies[] = $copy;
    }
    $service = app(ImageAltTextBackfillService::class);
    $first = $service->publishIds([$source->id]);
    expect($first['shopify_failed'])->toBe([])->and($first['published'])->toBe(3)->and($this->gateway->updates)->toBe(3);
    $service->markPendingReview($source->fresh(), 'Second approved component sentence');
    app(ImageAltApprovalEvidence::class)->approve($source->fresh(), $this->reviewer->id, 'Reviewed the changed description of the same photograph.');
    $second = $service->publishIds([$source->id]);
    expect($second['shopify_failed'])->toBe([])->and($second['published'])->toBe(3);
    foreach ($copies as $copy) {
        expect($copy->fresh()->alt_text)->toBe('Second approved component sentence')->and($copy->fresh()->alt_text_status)->toBe(Image::ALT_PUBLISHED);
    }
    foreach ($owned as $n => $image) {
        expect($image->fresh()->alt_text)->toBe('Keep owned sentence '.($n + 1));
    }
    $service->publishIds([$source->id]);
    expect($this->gateway->updates)->toBe(6)->and($this->gateway->creates)->toBe(0)
        ->and(config('images.component_stack_propagation'))->toBeFalse();
});

it('keeps independently reviewed inherited descriptions out of component fanout', function () {
    $component = createImageImportProduct('PRIORITY-OVERRIDE');
    $source = priorityImage($component, 'Component sentence', $this->gateway);
    priorityPartial($component, [$source]);
    $stack = createImageImportProduct('PRIORITY-OVERRIDE-STACK', ['is_bundle' => true]);
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['sku' => 'OVERRIDE', 'shopify_id' => $stack->shopify_id, 'handle' => $stack->handle, 'title' => 'Stack', 'status' => 'active', 'bundle_product_ids' => [$component->id]]));
    $copy = priorityImage($stack, 'Independent sentence', $this->gateway, 2, ['ownership' => 'component_inherited', 'source_image_id' => $source->id, 'image_asset_id' => $source->image_asset_id, 'last_shopify_synced_image_asset_id' => $source->image_asset_id, 'alt_inheritance_mode' => 'independent']);
    $url = $this->gateway->nodes[$stack->shopify_id][0]['originalSource']['url'];
    $this->gateway->bytes[$url] = 'priority-'.$component->id.'-1';
    app(ImageAltApprovalEvidence::class)->approve($copy, $this->reviewer->id, 'Reviewed an independent description for this stack photograph.');
    $service = app(ImageAltTextBackfillService::class);
    expect($service->publishIds([$source->id])['published'])->toBe(1)
        ->and($copy->fresh()->alt_text)->toBe('Independent sentence');
    expect($service->publishIds([$copy->id])['published'])->toBe(1)
        ->and($copy->fresh()->shopify_alt_text)->toBe('Independent sentence')->and($this->gateway->creates)->toBe(0);
});

it('honors a current explicit conflict resolution when publishing from another component', function () {
    $a = createImageImportProduct('PRIORITY-RESOLVE-A');
    $sourceA = priorityImage($a, 'Sentence A', $this->gateway);
    $b = createImageImportProduct('PRIORITY-RESOLVE-B');
    $sourceB = priorityImage($b, 'Sentence B', $this->gateway, 1, ['image_asset_id' => $sourceA->image_asset_id, 'last_shopify_synced_image_asset_id' => $sourceA->image_asset_id]);
    priorityPartial($a, [$sourceA]);
    priorityPartial($b, [$sourceB]);
    $stack = createImageImportProduct('PRIORITY-RESOLVE-STACK', ['is_bundle' => true]);
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['sku' => 'RESOLVE', 'shopify_id' => $stack->shopify_id, 'handle' => $stack->handle, 'title' => 'Stack', 'status' => 'active', 'bundle_product_ids' => [$a->id, $b->id]]));
    $copy = priorityImage($stack, 'Sentence A', $this->gateway, 2, ['ownership' => 'component_inherited', 'source_image_id' => $sourceA->id, 'image_asset_id' => $sourceA->image_asset_id, 'last_shopify_synced_image_asset_id' => $sourceA->image_asset_id]);
    $url = $this->gateway->nodes[$stack->shopify_id][0]['originalSource']['url'];
    $this->gateway->bytes[$url] = 'priority-'.$a->id.'-1';
    foreach ([$sourceA, $sourceB] as $source) {
        DB::table('inherited_image_sources')->insert(['image_id' => $copy->id, 'component_image_id' => $source->id, 'component_product_id' => $source->product_id, 'image_asset_id' => $sourceA->image_asset_id, 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('inherited_alt_resolutions')->insert(['image_id' => $copy->id, 'image_asset_id' => $sourceA->image_asset_id, 'alt_text' => 'Sentence B', 'source_generation_ids' => '[]', 'context_hash' => app(InheritedAltResolution::class)->context($copy), 'user_id' => $this->reviewer->id, 'reason' => 'Reviewed both descriptions and selected sentence B.', 'created_at' => now(), 'updated_at' => now()]);
    $result = app(ImageAltTextBackfillService::class)->publishIds([$sourceA->id]);
    expect($result['shopify_failed'])->toBe([])->and($copy->fresh()->alt_text)->toBe('Sentence B')
        ->and($copy->fresh()->shopify_alt_text)->toBe('Sentence B')->and($this->gateway->creates)->toBe(0);
    DB::table('component_photograph_generations')->insert(['component_product_id' => $b->id, 'component_image_id' => $sourceB->id,
        'image_asset_id' => $sourceB->image_asset_id, 'asset_sha256' => $sourceB->imageAsset->sha256, 'selection_revision' => 2,
        'status' => 'confirmed', 'created_at' => now(), 'updated_at' => now()]);
    expect(app(ImageAltTextBackfillService::class)->approvedUploadAlt($copy->fresh()))->toBeNull()
        ->and($copy->fresh()->alt_text)->toBe('Sentence B');
    DB::table('inherited_image_sources')->where('component_image_id', $sourceB->id)->update(['removed_at' => now()]);
    expect(app(ImageAltTextBackfillService::class)->approvedUploadAlt($copy->fresh()))->toBe('Sentence A');
});

it('refuses alt writes to shared component media and preserves remote and CMS sentences', function () {
    $component = createImageImportProduct('PRIORITY-SHARED');
    $source = priorityImage($component, 'Approved source', $this->gateway);
    priorityPartial($component, [$source]);
    $stack = createImageImportProduct('PRIORITY-SHARED-STACK', ['is_bundle' => true]);
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['sku' => 'SHARED', 'shopify_id' => $stack->shopify_id, 'handle' => $stack->handle, 'title' => 'Stack', 'status' => 'active', 'bundle_product_ids' => [$component->id]]));
    $copy = priorityImage($stack, 'Approved source', $this->gateway, 2, ['ownership' => 'component_inherited', 'source_image_id' => $source->id, 'image_asset_id' => $source->image_asset_id, 'last_shopify_synced_image_asset_id' => $source->image_asset_id, 'shopify_id' => $source->shopify_id]);
    $this->gateway->nodes[$stack->shopify_id] = [$this->gateway->nodes[$component->shopify_id][0]];
    $result = app(ImageAltTextBackfillService::class)->publishIds([$copy->id]);
    expect($result['shopify_failed'])->toHaveCount(1)->and($this->gateway->updates)->toBe(0)->and($copy->fresh()->alt_text)->toBe('Approved source');
});

it('refuses approval of a sentence changed after the operator opened the review', function () {
    $p = createImageImportProduct('PRIORITY-STALE-REVIEW');
    $a = priorityImage($p, 'New sentence', $this->gateway);
    expect(fn () => app(ImageAltApprovalEvidence::class)->approve($a, $this->reviewer->id, 'Reviewed the sentence displayed when the dialog opened.', 'Previous sentence', $a->image_asset_id))->toThrow(RuntimeException::class, 'changed during review');
    expect(DB::table('image_alt_approvals')->where('image_id', $a->id)->count())->toBe(0);
});

it('prepares a missing media rebuild without changing approval evidence or deleting remote files', function () {
    $p = createImageImportProduct('PRIORITY-REBUILD');
    $a = priorityImage($p, 'Preserve this approved sentence', $this->gateway);
    app(ImageAltApprovalEvidence::class)->approve($a, $this->reviewer->id, 'Reviewed the exact photograph and description for recovery.');
    $this->gateway->nodes[$p->shopify_id] = [];
    app(ImageAltRecovery::class)->prepareRebuild($a, $this->reviewer->id, 'Confirmed the missing media and reviewed this backup for rebuilding.');
    expect($a->fresh()->shopify_id)->toBeNull()->and($a->fresh()->alt_text)->toBe('Preserve this approved sentence')
        ->and(app(ProductPartialApprovalService::class)->approvedAlt($a->fresh()))->toBe('Preserve this approved sentence')
        ->and(DB::table('image_alt_review_events')->where('action', 'prepare_rebuild')->count())->toBe(1)
        ->and($this->gateway->creates)->toBe(0)->and($this->gateway->updates)->toBe(0);
});

it('retains historical snapshots when an approved description is edited', function () {
    $p = createImageImportProduct('PRIORITY-HISTORY');
    $a = priorityImage($p, 'Previously approved', $this->gateway);
    $request = priorityPartial($p, [$a]);
    $before = $request->image_alt_snapshot;
    app(ImageAltTextBackfillService::class)->markPendingReview($a, 'Needs a new review');
    expect($request->fresh()->image_alt_snapshot)->toBe($before)->and($request->fresh()->status)->toBe('approved')
        ->and(app(ProductPartialApprovalService::class)->approvedAlt($a->fresh()))->toBeNull();
});

it('uploads a newly approved photograph before publishing its alt during combined product sync', function () {
    $p = createImageImportProduct('PRIORITY-COMBINED');
    $first = priorityImage($p, 'First sentence', $this->gateway);
    $second = priorityImage($p, 'New photograph sentence', $this->gateway, 2);
    array_pop($this->gateway->nodes[$p->shopify_id]);
    Image::withoutEvents(fn () => $second->forceFill(['shopify_id' => null, 'needs_shopify_image_sync' => true, 'shopify_identity_unresolved' => true, 'sync_state' => Image::SYNC_STATE_LOCAL_NEW])->save());
    foreach (range(1, 2) as $ignored) {
        Approval::create(['product_id' => $p->id, 'user_id' => User::factory()->create()->id, 'approval_version' => $p->approval_version ?? 1]);
    }
    $service = app(ProductShopifyUpdater::class);
    $result = $service->updateApprovedProducts(collect([$p->fresh()]), ['images', 'image_alt']);
    expect($result['failures'])->toBe([])->and($result['failed'])->toBe(0)->and($this->gateway->creates)->toBe(1)->and($this->gateway->updates)->toBe(2)
        ->and($second->fresh()->alt_text_status)->toBe(Image::ALT_PUBLISHED);
    $service->updateApprovedProducts(collect([$p->fresh()]), ['images', 'image_alt']);
    expect($this->gateway->creates)->toBe(1)->and($this->gateway->updates)->toBe(2);
});

it('publishes five photographs and descriptions then edits replaces and reorders without duplicate uploads', function () {
    $p = createImageImportProduct('FIVE-GALLERY');
    $images = collect(range(1, 5))->map(fn ($position) => priorityImage($p, 'Description '.$position, $this->gateway, $position));
    $approveProduct = function () use ($p): void {
        foreach (range(1, 2) as $ignored) {
            Approval::create(['product_id' => $p->id, 'user_id' => User::factory()->create()->id, 'approval_version' => $p->fresh()->approval_version ?? 1]);
        }
    };
    $approveProduct();
    $updater = app(ProductShopifyUpdater::class);
    $first = $updater->updateApprovedProducts(collect([$p->fresh()]), ['images', 'image_alt']);
    expect($first['failed'])->toBe(0)->and($first['image_outcomes'][$p->id]['photographs'])->toBe('verified')
        ->and($this->gateway->updates)->toBe(5)->and($this->gateway->creates)->toBe(0);
    $alt = app(ImageAltTextBackfillService::class);
    $alt->publishIds($images->pluck('id')->all());
    expect($this->gateway->updates)->toBe(5);
    $third = $images[2];
    $alt->markPendingReview($third->fresh(), 'Revised third description');
    app(ImageAltApprovalEvidence::class)->approve($third->fresh(), $this->reviewer->id, 'Reviewed the changed third description and unchanged photograph.');
    $alt->publishIds($images->pluck('id')->all());
    expect($this->gateway->updates)->toBe(6)->and($this->gateway->creates)->toBe(0);
    $replacement = createImportTestAsset('five gallery replacement', 'jpg', 'third-replacement.jpg');
    $this->gateway->bytes['https://cdn.shopify.com/s/files/replacement-test.jpg'] = 'five gallery replacement';
    (new ReflectionMethod(ShopifyImageImportService::class, 'prepareImageForImport'))
        ->invoke(app(ShopifyImageImportService::class), $p->fresh(), $replacement, 'third-replacement-v2.jpg', 3, 2);
    expect(app(ProductPartialApprovalService::class)->approvedAlt($third->fresh()))->toBeNull()->and($third->fresh()->shopify_id)->toBeNull();
    $alt->markPendingReview($third->fresh(), 'Replacement photograph description');
    $approveProduct();
    $result = $updater->updateApprovedProducts(collect([$p->fresh()]), ['images', 'image_alt']);
    expect($result['failed'])->toBe(0)->and($third->fresh()->alt_text_status)->toBe(Image::ALT_PUBLISHED)
        ->and($this->gateway->creates)->toBe(1);
    Image::withoutEvents(function () use ($images): void {
        foreach ($images->reverse()->values() as $index => $image) {
            $image->fresh()->forceFill(['position' => $index + 1])->save();
        }
    });
    $approveProduct();
    $reordered = $updater->updateApprovedProducts(collect([$p->fresh()]), ['images', 'image_alt']);
    expect($reordered['failed'])->toBe(0)->and($this->gateway->creates)->toBe(1)->and($this->gateway->updates)->toBe(7)
        ->and($p->allImages()->active()->count())->toBe(5);
});

it('manually rebuilds approved inherited media with its own identity while automatic propagation is disabled', function () {
    $component = createImageImportProduct('PRIORITY-MANUAL-COMP');
    $source = priorityImage($component, 'Approved component description', $this->gateway);
    foreach (range(1, 2) as $ignored) {
        Approval::create(['product_id' => $component->id, 'user_id' => User::factory()->create()->id, 'approval_version' => $component->approval_version ?? 1]);
    }
    $stack = createImageImportProduct('PRIORITY-MANUAL-STACK', ['is_bundle' => true]);
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['sku' => 'MANUAL', 'shopify_id' => $stack->shopify_id, 'handle' => $stack->handle, 'title' => 'Stack', 'status' => 'active', 'bundle_product_ids' => [$component->id]]));
    app(ComponentStackPropagation::class)->stageStack($stack);
    $copy = Image::where('product_id', $stack->id)->first();
    foreach (range(1, 2) as $ignored) {
        Approval::create(['product_id' => $stack->id, 'user_id' => User::factory()->create()->id, 'approval_version' => $stack->approval_version ?? 1]);
    }
    $result = app(ProductShopifyUpdater::class)->updateApprovedProducts(collect([$stack->fresh()]), ['images', 'image_alt']);
    expect($result['failures'])->toBe([])->and($this->gateway->creates)->toBe(1)->and($this->gateway->updates)->toBe(1)
        ->and($copy->fresh()->shopify_id)->not->toBe($source->shopify_id)
        ->and($copy->fresh()->alt_text)->toBe('Approved component description')->and($copy->fresh()->alt_text_status)->toBe(Image::ALT_PUBLISHED)
        ->and(DB::table('component_publication_outbox')->count())->toBe(0);
});
