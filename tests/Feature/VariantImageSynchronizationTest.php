<?php

use App\Contracts\ShopifyGraphqlGateway;
use App\Enums\RolesEnum;
use App\Models\Image;
use App\Models\User;
use App\Services\VariantImageSynchronizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);
require_once __DIR__.'/../Support/shopify-image-import.php';

final class VariantEvidenceGateway implements ShopifyGraphqlGateway
{
    public array $current = ['gid://shopify/MediaImage/10'];

    public array $actions = [];

    public bool $delayAppend = false;

    public bool $loseAppend = false;

    public bool $incomplete = false;

    public ?string $productId = null;

    public function graphql(string $query, array $variables = []): array
    {
        if (str_contains($query, 'productVariantAppendMedia') || str_contains($query, 'productVariantDetachMedia')) {
            $action = str_contains($query, 'productVariantAppendMedia') ? 'append' : 'detach';
            $this->actions[] = $action;
            $ids = $variables['variantMedia'][0]['mediaIds'];
            if ($action === 'append') {
                if (! $this->delayAppend) {
                    $this->current = array_values(array_unique(array_merge($this->current, $ids)));
                }
                if ($this->loseAppend) {
                    throw new RuntimeException('Response lost after acceptance');
                }
            } else {
                $this->current = array_values(array_diff($this->current, $ids));
            }

            return [($action === 'append' ? 'productVariantAppendMedia' : 'productVariantDetachMedia') => ['userErrors' => []]];
        }
        if (str_contains($query, 'VerifyVariantImageAssignment')) {
            return ['productVariant' => ['product' => ['id' => $this->productId], 'media' => ['nodes' => array_map(fn ($id) => ['id' => $id], $this->current), 'pageInfo' => ['hasNextPage' => $this->incomplete, 'endCursor' => null]]]];
        }

        return ['product' => ['media' => ['nodes' => [['id' => 'gid://shopify/MediaImage/20', 'status' => 'READY', 'fileStatus' => 'READY', 'originalSource' => ['url' => 'https://cdn.shopify.com/s/files/verified-variant.jpg']]], 'pageInfo' => ['hasNextPage' => false, 'endCursor' => null]]]];
    }
}

beforeEach(function (): void {
    Storage::fake('public');
    $this->product = createImageImportProduct('VARIANT-EVIDENCE');
    $asset = createImportTestAsset('verified photograph', 'jpg', 'variant.jpg');
    $this->image = Image::withoutEvents(fn () => Image::create(['product_id' => $this->product->id, 'shopify_id' => 'gid://shopify/MediaImage/20', 'image_asset_id' => $asset->id, 'sync_state' => Image::SYNC_STATE_SYNCED]));
    $this->remotePhotograph = 'verified photograph';
    Http::fake(fn () => Http::response($this->remotePhotograph));
    $this->gateway = new VariantEvidenceGateway;
    $this->gateway->productId = $this->product->shopify_id;
    $this->sync = new VariantImageSynchronizer($this->gateway);
});

it('verifies replacement attachment before removing old variant media and is idempotent', function (): void {
    $this->sync->synchronize($this->product, 'gid://shopify/ProductVariant/5', $this->image);
    expect($this->gateway->actions)->toBe(['append', 'detach'])->and($this->gateway->current)->toBe([$this->image->shopify_id])
        ->and(DB::table('image_variant_operations')->value('status'))->toBe('confirmed');
    $this->sync->synchronize($this->product, 'gid://shopify/ProductVariant/5', $this->image);
    expect($this->gateway->actions)->toBe(['append', 'detach'])->and(DB::table('image_variant_operations')->count())->toBe(1);
});

it('reconciles an accepted append whose response was lost without replaying it', function (): void {
    $this->gateway->loseAppend = true;
    expect(fn () => $this->sync->synchronize($this->product, 'gid://shopify/ProductVariant/5', $this->image))->toThrow(RuntimeException::class, 'Response lost');
    expect($this->gateway->actions)->toBe(['append'])->and($this->gateway->current)->toHaveCount(2);
    $this->gateway->loseAppend = false;
    $this->sync->synchronize($this->product, 'gid://shopify/ProductVariant/5', $this->image);
    expect($this->gateway->actions)->toBe(['append', 'detach'])->and(DB::table('image_variant_operations')->value('status'))->toBe('confirmed');
});

it('keeps delayed variant creation visible and requires explicit recovery instead of repeating a mutation', function (): void {
    $this->gateway->delayAppend = true;
    expect(fn () => $this->sync->synchronize($this->product, 'gid://shopify/ProductVariant/5', $this->image))->toThrow(RuntimeException::class, 'awaiting confirmation');
    $this->travel(2)->days();
    expect(fn () => $this->sync->synchronize($this->product, 'gid://shopify/ProductVariant/5', $this->image))->toThrow(RuntimeException::class, 'no mutation was replayed');
    expect($this->gateway->actions)->toBe(['append'])->and($this->gateway->current)->toBe(['gid://shopify/MediaImage/10']);
    Role::findOrCreate(RolesEnum::Admin->value);
    $operator = User::factory()->create(['is_active' => true]);
    $operator->assignRole(RolesEnum::Admin->value);
    $id = (int) DB::table('image_variant_operations')->value('id');
    expect(fn () => $this->sync->recover($id, $operator->id, 'Reviewed the incomplete association and its remote state.', false))->toThrow(RuntimeException::class, 'does not confirm');
    $this->sync->recover($id, $operator->id, 'Reviewed the incomplete association and explicitly abandoned tracking.', true);
    expect(DB::table('image_variant_operations')->find($id)->status)->toBe('abandoned')->and($this->gateway->actions)->toBe(['append']);
});

it('stops before detaching when the local mapping changes during replacement attachment', function (): void {
    $checks = 0;
    expect(fn () => $this->sync->synchronize($this->product, 'gid://shopify/ProductVariant/5', $this->image, function () use (&$checks): void {
        $checks++;
        if ($checks > 2) {
            throw new RuntimeException('Concurrent local mapping change');
        }
    }))->toThrow(RuntimeException::class, 'Concurrent local mapping');
    expect($this->gateway->actions)->toBe(['append'])->and($this->gateway->current)->toHaveCount(2)
        ->and(DB::table('image_variant_operations')->whereNotNull('active_key')->count())->toBe(1);
});

it('does not mutate when variant ownership or pagination is uncertain', function (): void {
    $this->gateway->productId = 'gid://shopify/Product/999';
    expect(fn () => $this->sync->synchronize($this->product, 'gid://shopify/ProductVariant/5', $this->image))->toThrow(RuntimeException::class, 'expected product');
    $this->gateway->productId = $this->product->shopify_id;
    $this->gateway->incomplete = true;
    expect(fn () => $this->sync->synchronize($this->product, 'gid://shopify/ProductVariant/5', $this->image))->toThrow(RuntimeException::class, 'pagination');
    expect($this->gateway->actions)->toBe([])->and(DB::table('image_variant_operations')->count())->toBe(0);
});

it('refuses stale photograph bytes instead of relying on its media id or filename', function (): void {
    $this->remotePhotograph = 'wrong photograph';
    expect(fn () => $this->sync->synchronize($this->product, 'gid://shopify/ProductVariant/5', $this->image))->toThrow(RuntimeException::class, 'identity is unresolved');
    expect($this->gateway->actions)->toBe([]);
});
