<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\RolesEnum;
use App\Models\Approval;
use App\Models\Image;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ProductPartialApprovalRequest;
use App\Models\User;
use App\Services\ImagePhotographEvidence;
use App\Services\ImageUploadOperations;
use App\Services\ProductPartialApprovalService;
use App\Services\ProductShopifyUpdater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/shopify-image-import.php';

final class RecoveryShopifyFake implements ShopifyGraphqlGateway
{
    public array $nodes = [];

    public array $globalNodes = [];

    public array $queries = [];

    public int $mutations = 0;

    public int $creates = 0;

    public bool $failReads = false;

    public bool $malformedNode = false;

    public array $scopes = ['read_files'];

    public ?Closure $onRead = null;

    public function node(string $id = 'gid://shopify/MediaImage/7', string $alt = 'approved sentence'): array
    {
        return ['id' => $id, 'alt' => $alt, 'status' => 'READY', 'fileStatus' => 'READY', 'image' => ['url' => 'https://cdn.shopify.com/s/files/recovery.jpg?v=3']];
    }

    public function graphql(string $query, array $variables = []): array
    {
        $this->queries[] = $query;
        if (str_starts_with(trim($query), 'mutation')) {
            $this->mutations++;
            if (str_contains($query, 'productCreateMedia')) {
                $this->creates++;
                $node = $this->node('gid://shopify/MediaImage/'.(100 + $this->creates));
                $this->nodes[] = $node;

                return ['productCreateMedia' => ['media' => [['id' => $node['id']]], 'mediaUserErrors' => []]];
            }
            if (str_contains($query, 'fileUpdate')) {
                $file = $variables['files'][0];
                foreach ($this->nodes as &$node) {
                    if ($node['id'] === $file['id']) {
                        $node['alt'] = $file['alt'];
                    }
                }

                return ['fileUpdate' => ['files' => [$file], 'userErrors' => []]];
            }

            return ['productReorderMedia' => ['mediaUserErrors' => []]];
        }
        ($this->onRead ?? fn () => null)();
        if ($this->failReads) {
            throw new RuntimeException('Shopify read failed.');
        }
        if (str_contains($query, 'VerifyDeletedImageFile')) {
            return $this->malformedNode ? [] : ['node' => $this->globalNodes[$variables['id']] ?? null, 'currentAppInstallation' => ['accessScopes' => array_map(fn ($scope) => ['handle' => $scope], $this->scopes)]];
        }
        $field = str_contains($query, 'images(first:') ? 'images' : 'media';

        return ['product' => [$field => ['nodes' => $field === 'media' ? $this->nodes : [], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]];
    }
}

function recoveryOperator(): User
{
    $user = User::factory()->create(['is_active' => true]);
    Role::findOrCreate(RolesEnum::Admin->value);
    $user->assignRole(RolesEnum::Admin->value);

    return $user;
}

function recoveryImage(): Image
{
    Storage::fake('public');
    $product = createImageImportProduct('RECOVERY');
    $asset = createImportTestAsset('recovery photograph', 'jpg', 'recovery.jpg');

    return Image::withoutEvents(fn () => Image::create([
        'product_id' => $product->id, 'position' => 1, 'image_asset_id' => $asset->id, 'sync_state' => Image::SYNC_STATE_SYNCED,
        'backup_status' => Image::BACKUP_STATUS_BACKED_UP, 'shopify_id' => 'gid://shopify/MediaImage/7',
        'alt_text' => 'approved sentence', 'alt_text_status' => Image::ALT_AWAITING_CONFIRMATION, 'shopify_alt_text' => 'old sentence',
        'alt_confirmation_media_id' => 'gid://shopify/MediaImage/7', 'alt_confirmation_text' => 'approved sentence', 'shopify_alt_pending' => true,
    ]));
}

function recoveryApproval(Image $image, User $operator): ProductPartialApprovalRequest
{
    return ProductPartialApprovalRequest::withoutEvents(fn () => ProductPartialApprovalRequest::create([
        'product_id' => $image->product_id, 'approval_version' => $image->product->approval_version ?? 1,
        'requested_by' => $operator->id, 'approved_by' => $operator->id, 'target_approver_id' => $operator->id,
        'request_batch_id' => (string) Str::uuid(), 'status' => ProductPartialApprovalRequest::STATUS_APPROVED,
        'scopes' => [ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT], 'core_fields' => [],
        'image_alt_snapshot' => app(ProductPartialApprovalService::class)->imageAltSnapshot($image->product), 'approved_at' => now(),
    ]));
}

function recoveryHttp(RecoveryShopifyFake $remote): void
{
    app()->instance(ShopifyGraphqlGateway::class, $remote);
    Http::fake(function ($request) use ($remote) {
        if ($request->method() === 'GET') {
            return Http::response('recovery photograph');
        }

        return Http::response(['data' => $remote->graphql($request->data()['query'] ?? '', $request->data()['variables'] ?? [])]);
    });
}

it('confirms the exact pending sentence and archives state without a Shopify mutation', function () {
    $i = recoveryImage();
    $operator = recoveryOperator();
    $approval = recoveryApproval($i, $operator);
    $before = $approval->fresh()->getAttributes();
    $remote = new RecoveryShopifyFake;
    $remote->nodes[] = $remote->node();
    recoveryHttp($remote);
    $this->artisan('images:resolve-alt-confirmation', ['image' => $i->id, '--operator' => $operator->id])->assertSuccessful();
    expect($i->fresh()->alt_text_status)->toBe(Image::ALT_PUBLISHED)->and($i->fresh()->alt_confirmation_text)->toBeNull()
        ->and($i->fresh()->alt_text)->toBe('approved sentence')->and($i->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/7')
        ->and($remote->mutations)->toBe(0)->and($approval->fresh()->getAttributes())->toBe($before);
    $snapshot = json_decode(DB::table('image_revisions')->value('snapshot'), true);
    expect($snapshot['alt_confirmation_text'])->toBe('approved sentence');
});

it('retains mismatched, missing, or unreadable confirmations irrespective of age', function (string $kind) {
    $i = recoveryImage();
    $operator = recoveryOperator();
    recoveryApproval($i, $operator);
    Image::withoutEvents(fn () => $i->forceFill(['updated_at' => now()->subDays(30)])->save());
    $before = $i->fresh()->getAttributes();
    $remote = new RecoveryShopifyFake;
    if ($kind !== 'missing') {
        $remote->nodes[] = $remote->node(alt: 'different sentence');
    }
    $remote->failReads = $kind === 'api_error';
    recoveryHttp($remote);
    $this->artisan('images:resolve-alt-confirmation', ['image' => $i->id, '--operator' => $operator->id])->assertFailed();
    expect($i->fresh()->getAttributes())->toBe($before)->and($remote->mutations)->toBe(0)->and(DB::table('image_revisions')->count())->toBe(0);
})->with(['mismatch', 'missing', 'api_error']);

it('abandons tracking only with an authorized attributed reason and permits a later ordinary retry', function () {
    $i = recoveryImage();
    $operator = recoveryOperator();
    $approval = recoveryApproval($i, $operator);
    $snapshot = $approval->image_alt_snapshot;
    $remote = new RecoveryShopifyFake;
    $remote->nodes[] = $remote->node(alt: 'old sentence');
    recoveryHttp($remote);
    $arguments = ['image' => $i->id, '--operator' => $operator->id, '--abandon' => true];
    $this->artisan('images:resolve-alt-confirmation', $arguments)->assertFailed();
    expect($i->fresh()->alt_confirmation_media_id)->not->toBeNull();
    $this->artisan('images:resolve-alt-confirmation', $arguments + ['--reason' => 'Reviewed the unresolved operation and authorize ending local tracking.'])->assertSuccessful();
    expect($i->fresh()->alt_text_status)->toBe(Image::ALT_APPROVED)->and($i->fresh()->alt_confirmation_media_id)->toBeNull()
        ->and($i->fresh()->alt_text)->toBe('approved sentence')->and($approval->fresh()->image_alt_snapshot)->toBe($snapshot)->and($remote->mutations)->toBe(0);
    expect(DB::table('image_revisions')->value('reason'))->toContain('operator='.$operator->id);
    $result = app(ProductShopifyUpdater::class)->updateApprovedProducts(collect([$i->product->fresh()]), [ProductShopifyUpdater::SYNC_SCOPE_IMAGE_ALT], []);
    expect($result['updated'])->toBe(1)->and($result['failed'])->toBe(0)
        ->and($i->fresh()->alt_text_status)->toBe(Image::ALT_PUBLISHED)->and($remote->mutations)->toBe(1);
});

it('allows explicit abandonment after a complete read confirms media is missing', function () {
    $i = recoveryImage();
    $operator = recoveryOperator();
    recoveryApproval($i, $operator);
    $remote = new RecoveryShopifyFake;
    recoveryHttp($remote);
    $this->artisan('images:resolve-alt-confirmation', ['image' => $i->id, '--operator' => $operator->id, '--abandon' => true, '--reason' => 'Reviewed the complete product media list and confirmed the pending target is gone.'])->assertSuccessful();
    expect($i->fresh()->alt_confirmation_text)->toBeNull()->and($remote->mutations)->toBe(0);
});

it('does not abandon when Shopify cannot be read or the operator is unauthorized', function (bool $unauthorized) {
    $i = recoveryImage();
    $operator = $unauthorized ? User::factory()->create(['is_active' => true]) : recoveryOperator();
    $remote = new RecoveryShopifyFake;
    $remote->failReads = true;
    recoveryHttp($remote);
    $before = $i->fresh()->getAttributes();
    $this->artisan('images:resolve-alt-confirmation', ['image' => $i->id, '--operator' => $operator->id, '--abandon' => true, '--reason' => 'Reviewed recovery but cannot establish the current Shopify state.'])->assertFailed();
    expect($i->fresh()->getAttributes())->toBe($before)->and($remote->mutations)->toBe(0);
})->with([true, false]);

it('does not resolve a different current sentence or photograph as published', function (bool $newPhoto) {
    $i = recoveryImage();
    $operator = recoveryOperator();
    recoveryApproval($i, $operator);
    Image::withoutEvents(fn () => $i->forceFill($newPhoto ? ['shopify_identity_unresolved' => true] : ['alt_text' => 'different approved sentence'])->save());
    $before = $i->fresh()->getAttributes();
    $remote = new RecoveryShopifyFake;
    $remote->nodes[] = $remote->node();
    recoveryHttp($remote);
    $this->artisan('images:resolve-alt-confirmation', ['image' => $i->id, '--operator' => $operator->id])->assertFailed();
    expect($i->fresh()->getAttributes())->toBe($before)->and($remote->mutations)->toBe(0);
})->with([true, false]);

it('honors the synchronization mutex for confirmation recovery', function () {
    $i = recoveryImage();
    $operator = recoveryOperator();
    $lock = Cache::lock(ProductShopifyUpdater::IMAGE_SYNC_LOCK_PREFIX.$i->product_id, 3600);
    expect($lock->get())->toBeTrue();
    $remote = new RecoveryShopifyFake;
    recoveryHttp($remote);
    try {
        $this->artisan('images:resolve-alt-confirmation', ['image' => $i->id, '--operator' => $operator->id])->assertFailed();
    } finally {
        $lock->release();
    }
    expect($remote->queries)->toBe([])->and($i->fresh()->alt_confirmation_text)->toBe('approved sentence');
});

it('uploads a different photograph despite unrelated processing media', function () {
    $i = recoveryImage();
    Image::withoutEvents(fn () => $i->forceFill(['shopify_id' => null, 'alt_confirmation_media_id' => null, 'alt_confirmation_text' => null])->save());
    foreach ([1, 2] as $ignored) {
        Approval::create([
            'product_id' => $i->product_id,
            'user_id' => User::factory()->create()->id,
            'approval_version' => $i->product->fresh()->approval_version ?? 1,
        ]);
    }
    $remote = new RecoveryShopifyFake;
    $unrelated = $remote->node('gid://shopify/MediaImage/88');
    $unrelated['status'] = 'PROCESSING';
    $unrelated['fileStatus'] = 'PROCESSING';
    $unrelated['image'] = null;
    $remote->nodes[] = $unrelated;
    recoveryHttp($remote);
    (new ReflectionMethod(ProductShopifyUpdater::class, 'updateImages'))->invoke(app(ProductShopifyUpdater::class), $i->product, $i->product->shopify_id, [], [], null, false);
    expect($remote->creates)->toBe(1)->and($i->fresh()->shopify_id)->toBe('gid://shopify/MediaImage/101');
});

it('blocks creation for a known processing identity or an uncertain upload operation', function (bool $uncertain) {
    $i = recoveryImage();
    $remote = new RecoveryShopifyFake;
    $node = $remote->node();
    $node['status'] = 'PROCESSING';
    $node['fileStatus'] = 'PROCESSING';
    $remote->nodes[] = $node;
    recoveryHttp($remote);
    if ($uncertain) {
        Image::withoutEvents(fn () => $i->forceFill(['shopify_id' => null])->save());
        $operation = (new ImageUploadOperations)->claim($i, $i->product->shopify_id, []);
        (new ImageUploadOperations)->record($operation, 'processing', 'gid://shopify/MediaImage/7');
    }
    (new ReflectionMethod(ProductShopifyUpdater::class, 'updateImages'))->invoke(app(ProductShopifyUpdater::class), $i->product, $i->product->shopify_id, [], [], null, false);
    expect($remote->creates)->toBe(0);
})->with([true, false]);

it('follows controlled redirects to the exact Shopify asset bucket and preserves signed parameters', function () {
    $i = recoveryImage();
    $destination = 'https://shopify-shop-assets.storage.googleapis.com/s/files/original.jpg?X-Goog-Signature=secret';
    Http::fake(['https://cdn.shopify.com/start' => Http::response('', 302, ['Location' => $destination]), $destination => Http::response('recovery photograph')]);
    expect(app(ImagePhotographEvidence::class)->matches($i, 'https://cdn.shopify.com/start'))->toBeTrue();
    Http::assertSent(fn ($request) => $request->url() === $destination);
});

it('rejects unsafe redirect destinations before sending a request', function (string $destination) {
    Http::fake(['https://cdn.shopify.com/start' => Http::response('', 302, ['Location' => $destination])]);
    expect(fn () => app(ImagePhotographEvidence::class)->probe('https://cdn.shopify.com/start'))->toThrow(InvalidArgumentException::class);
    Http::assertSentCount(1);
})->with(['http://cdn.shopify.com/private', 'https://127.0.0.1/private', 'https://cdn.shopify.com.attacker.test/private', 'https://other-bucket.storage.googleapis.com/private', 'https://user:password@cdn.shopify.com/private', 'https://cdn.shopify.com:8443/private']);

it('rejects an unsafe initial URL without any request', function () {
    expect(fn () => app(ImagePhotographEvidence::class)->probe('https://169.254.169.254/latest/meta-data'))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

it('limits redirects, supports relative locations, and requires a complete 200 body', function () {
    Http::fake(['https://cdn.shopify.com/start' => Http::response('', 302, ['Location' => '/final']), 'https://cdn.shopify.com/final' => Http::response('bytes')]);
    expect(app(ImagePhotographEvidence::class)->probe('https://cdn.shopify.com/start')['sha256'])->toBe(hash('sha256', 'bytes'));
    Http::fake(['https://cdn.shopify.com/partial' => Http::response('bytes', 206)]);
    expect(fn () => app(ImagePhotographEvidence::class)->probe('https://cdn.shopify.com/partial'))->toThrow(RuntimeException::class, 'HTTP 206');
});

it('keeps the production verification command read-only and redacts signed URLs', function () {
    $i = recoveryImage();
    $before = $i->fresh()->getAttributes();
    $remote = new RecoveryShopifyFake;
    $node = $remote->node();
    $node['originalSource'] = ['url' => 'https://shopify-shop-assets.storage.googleapis.com/s/files/original.jpg?signature=do-not-print'];
    $remote->nodes[] = $node;
    recoveryHttp($remote);
    $this->artisan('images:verify-shopify-photograph', ['image' => $i->id])->expectsOutputToContain('"matches_backup": true')->doesntExpectOutputToContain('do-not-print')->assertSuccessful();
    expect($i->fresh()->getAttributes())->toBe($before)->and($remote->mutations)->toBe(0)->and(DB::table('image_revisions')->count())->toBe(0);
});

it('releases an asset key only after global Shopify deletion is confirmed', function (string $result) {
    $i = recoveryImage();
    $operator = recoveryOperator();
    Image::withoutEvents(fn () => $i->forceFill(['shopify_media_create_uncertain' => false])->save());
    $service = new ImageUploadOperations;
    $operation = $service->claim($i, $i->product->shopify_id, []);
    $service->complete($operation, 'gid://shopify/MediaImage/7');
    Image::withoutEvents(fn () => $i->forceFill(['shopify_media_create_uncertain' => false])->save());
    $remote = new RecoveryShopifyFake;
    if ($result === 'exists') {
        $remote->globalNodes['gid://shopify/MediaImage/7'] = ['id' => 'gid://shopify/MediaImage/7'];
    }
    $remote->scopes = $result === 'no_scope' ? ['read_products'] : ['read_files'];
    $remote->malformedNode = $result === 'malformed';
    $remote->failReads = $result === 'api_error';
    recoveryHttp($remote);
    $arguments = ['operation' => $operation->id, '--confirmed-deleted' => true, '--operator' => $operator->id, '--reason' => 'Reviewed the deleted Shopify file and request a separate future upload.'];
    $pendingBefore = $i->fresh()->alt_confirmation_text;

    if ($result === 'deleted') {
        $this->artisan('images:resolve-upload', $arguments)->assertSuccessful();
        expect(DB::table('image_upload_operations')->value('asset_key'))->toBeNull()->and($i->fresh()->shopify_id)->toBeNull();
        expect($service->claim($i->fresh(), $i->product->shopify_id, []))->not->toBeNull();
    } else {
        $this->artisan('images:resolve-upload', $arguments)->assertFailed();
        expect(DB::table('image_upload_operations')->value('asset_key'))->not->toBeNull();
    }
    expect($i->fresh()->alt_confirmation_text)->toBe($pendingBefore)->and($i->fresh()->alt_text)->toBe('approved sentence')->and($remote->mutations)->toBe(0);
})->with(['deleted', 'exists', 'malformed', 'api_error', 'no_scope']);
it('does not hold new database row locks while the recovery command reads Shopify', function () {
    $i = recoveryImage();
    $operator = recoveryOperator();
    recoveryApproval($i, $operator);
    $level = DB::transactionLevel();
    $remote = new RecoveryShopifyFake;
    $remote->nodes[] = $remote->node();
    $remote->onRead = function () use ($level) {
        expect(DB::transactionLevel())->toBe($level);
    };
    recoveryHttp($remote);
    $this->artisan('images:resolve-alt-confirmation', ['image' => $i->id, '--operator' => $operator->id])->assertSuccessful();
});

it('does not clear confirmation after concurrent local changes during the fresh read', function () {
    $i = recoveryImage();
    $operator = recoveryOperator();
    recoveryApproval($i, $operator);
    $remote = new RecoveryShopifyFake;
    $remote->nodes[] = $remote->node();
    $remote->onRead = function () use ($i) {
        Image::withoutEvents(fn () => $i->fresh()->forceFill(['alt_text' => 'new reviewed sentence'])->save());
    };
    recoveryHttp($remote);
    $this->artisan('images:resolve-alt-confirmation', ['image' => $i->id, '--operator' => $operator->id, '--abandon' => true, '--reason' => 'Reviewed the operation before another local edit arrived.'])->assertFailed();
    expect($i->fresh()->alt_confirmation_text)->toBe('approved sentence')->and($i->fresh()->alt_text)->toBe('new reviewed sentence')->and(DB::table('image_revisions')->count())->toBe(0);
});

it('blocks an unfinished upload belonging to an obsolete stack copy before adoption', function () {
    $component = createImageImportProduct('COMPONENT');
    $copy = recoveryImage();
    $newAsset = createImportTestAsset('new component photograph', 'jpg', 'new.jpg');
    $source = Image::withoutEvents(fn () => Image::create(['product_id' => $component->id, 'position' => 1, 'image_asset_id' => $newAsset->id, 'sync_state' => Image::SYNC_STATE_SYNCED]));
    Product::withoutEvents(fn () => $copy->product->forceFill(['is_bundle' => true])->save());
    NewProductDraft::withoutEvents(fn () => NewProductDraft::create(['sku' => 'RECOVERY', 'handle' => $copy->product->handle, 'shopify_id' => $copy->product->shopify_id, 'title' => 'Stack', 'status' => 'active', 'bundle_product_ids' => [$component->id]]));
    Image::withoutEvents(fn () => $copy->forceFill(['source_image_id' => $source->id, 'position' => 2, 'shopify_id' => null])->save());
    $operation = (new ImageUploadOperations)->claim($copy, $copy->product->shopify_id, []);
    $remote = new RecoveryShopifyFake;
    $remote->nodes[] = $remote->node();
    recoveryHttp($remote);
    (new ReflectionMethod(ProductShopifyUpdater::class, 'updateImages'))->invoke(app(ProductShopifyUpdater::class), $copy->product->fresh(), $copy->product->shopify_id, [], [], null, false);
    expect($copy->fresh()->shopify_id)->toBeNull()->and($remote->creates)->toBe(0)
        ->and(DB::table('image_upload_operations')->where('id', $operation->id)->value('status'))->toBe('submitting');
});

it('bounds redirects and never substitutes a preview for an unverified original', function () {
    $i = recoveryImage();
    Http::fake(['https://cdn.shopify.com/loop' => Http::response('', 302, ['Location' => '/loop'])]);
    expect(fn () => app(ImagePhotographEvidence::class)->probe('https://cdn.shopify.com/loop'))->toThrow(RuntimeException::class, 'loop');
    $node = ['originalSource' => ['url' => 'https://untrusted.example/photo.jpg'], 'image' => ['url' => 'https://cdn.shopify.com/valid']];
    expect(app(ImagePhotographEvidence::class)->matchesMedia($i, $node))->toBeFalse();
    Http::assertSentCount(1);
});

it('refuses an inactive administrator for abandonment', function () {
    $i = recoveryImage();
    $operator = recoveryOperator();
    $operator->update(['is_active' => false]);
    $this->artisan('images:resolve-alt-confirmation', ['image' => $i->id, '--operator' => $operator->id, '--abandon' => true, '--reason' => 'An inactive account must not be authorized for recovery.'])->assertFailed();
    expect($i->fresh()->alt_confirmation_text)->toBe('approved sentence');
    Http::assertNothingSent();
});
it('blocks a processing URL candidate even without a stored media id', function () {
    $i = recoveryImage();
    $remote = new RecoveryShopifyFake;
    $node = $remote->node();
    $node['status'] = 'PROCESSING';
    $node['fileStatus'] = 'PROCESSING';
    $remote->nodes[] = $node;
    Image::withoutEvents(fn () => $i->forceFill(['shopify_id' => null, 'shopify_cdn_url' => $node['image']['url']])->save());
    recoveryHttp($remote);
    (new ReflectionMethod(ProductShopifyUpdater::class, 'updateImages'))->invoke(app(ProductShopifyUpdater::class), $i->product, $i->product->shopify_id, [], [], null, false);
    expect($remote->creates)->toBe(0);
});

it('does not replay a lost-response upload while its unidentifiable file is processing', function () {
    $i = recoveryImage();
    Image::withoutEvents(fn () => $i->forceFill(['shopify_id' => null])->save());
    $operation = (new ImageUploadOperations)->claim($i, $i->product->shopify_id, []);
    $remote = new RecoveryShopifyFake;
    $node = $remote->node('gid://shopify/MediaImage/88');
    $node['status'] = 'PROCESSING';
    $node['fileStatus'] = 'PROCESSING';
    $node['image'] = null;
    $remote->nodes[] = $node;
    recoveryHttp($remote);
    (new ReflectionMethod(ProductShopifyUpdater::class, 'updateImages'))->invoke(app(ProductShopifyUpdater::class), $i->product, $i->product->shopify_id, [], [], null, false);
    expect($remote->creates)->toBe(0)->and(DB::table('image_upload_operations')->where('id', $operation->id)->value('active_image_id'))->toBe($i->id);
});

it('stops a nonrepeating redirect chain after three redirects', function () {
    $requests = 0;
    Http::fake(function () use (&$requests) {
        $requests++;

        return Http::response('', 302, ['Location' => '/hop'.$requests]);
    });
    expect(fn () => app(ImagePhotographEvidence::class)->probe('https://cdn.shopify.com/hop0'))->toThrow(RuntimeException::class, 'redirect limit');
    Http::assertSentCount(4);
});
