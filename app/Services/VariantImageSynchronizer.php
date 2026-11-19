<?php

namespace App\Services;

use App\Contracts\ShopifyGraphqlGateway;
use App\Models\Image;
use App\Models\Product;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Append and verify the replacement before detaching; uncertain steps are never replayed. */
final class VariantImageSynchronizer
{
    public function __construct(private readonly ShopifyGraphqlGateway $shopify) {}

    public function mediaIds(string $variantId, ?string $productId = null): array
    {
        $cursor = null;
        $seen = [];
        $ids = [];
        do {
            $data = $this->shopify->graphql(<<<'GQL'
query VerifyVariantImageAssignment($id: ID!, $after: String) {
  productVariant(id: $id) { product { id } media(first: 250, after: $after) {
    nodes { id } pageInfo { hasNextPage endCursor }
  } }
}
GQL, ['id' => $variantId, 'after' => $cursor]);
            $connection = $data['productVariant']['media'] ?? null;
            if ($productId !== null && ($data['productVariant']['product']['id'] ?? null) !== $productId) {
                throw new RuntimeException('The Shopify variant does not belong to the expected product.');
            }
            if (! is_array($connection) || ! is_array($connection['nodes'] ?? null) || ! is_bool($connection['pageInfo']['hasNextPage'] ?? null)) {
                throw new RuntimeException('Incomplete variant media read; no reassignment is safe.');
            }
            $ids = array_merge($ids, array_column($connection['nodes'], 'id'));
            if (! $connection['pageInfo']['hasNextPage']) {
                return array_values(array_unique($ids));
            }
            $cursor = $connection['pageInfo']['endCursor'] ?? null;
            if (! is_string($cursor) || $cursor === '' || isset($seen[$cursor])) {
                throw new RuntimeException('Variant media pagination did not advance.');
            }
            $seen[$cursor] = true;
        } while (true);
    }

    public function synchronize(Product $product, string $variantId, ?Image $image, ?callable $assertCurrent = null): void
    {
        if (DB::transactionLevel() > 0 && ! app()->environment('testing')) {
            throw new RuntimeException('Variant synchronization requires durable operation state outside a transaction.');
        }
        $mutex = new ImageSyncMutex;
        if (! $mutex->acquire((int) $product->id)) {
            throw new RuntimeException('Another image operation is running.');
        }
        $operation = null;
        try {
            $assertCurrent && $assertCurrent();
            $product = $product->fresh();
            $image = $image?->fresh(['product', 'imageAsset']);
            $desired = $image ? trim((string) $image->shopify_id) : null;
            if ($image) {
                $node = collect((new ShopifyProductMediaReader($this->shopify))->media((string) $product->shopify_id, 'VerifyVariantTargetPhotograph'))
                    ->first(fn ($node) => ($node['id'] ?? null) === $desired);
                if ($image->product_id !== $product->id || ImageOwnership::effective($image) === ImageOwnership::UNRESOLVED
                    || $image->shopify_identity_unresolved || $image->is_duplicate_hidden
                    || in_array($image->sync_state, [Image::SYNC_STATE_LOCAL_DELETED, Image::SYNC_STATE_REMOTE_DELETED], true)
                    || ! preg_match('~^gid://shopify/MediaImage/\d+$~', $desired)
                    || ! is_array($node) || ! ShopifyProductMediaReader::ready($node)
                    || ! app(ImagePhotographEvidence::class)->matchesMedia($image, $node)) {
                    throw new RuntimeException('Variant target photograph identity is unresolved. Existing associations were preserved.');
                }
            }
            $current = $this->mediaIds($variantId, $product->shopify_id);
            $key = hash('sha256', $product->id.':'.$variantId);
            $operation = DB::table('image_variant_operations')->where('active_key', $key)->first();
            if ($operation && ($operation->desired_media_id !== $desired || (int) $operation->image_asset_id !== (int) $image?->image_asset_id)) {
                throw new RuntimeException('Resolve the previous uncertain variant image operation before changing its photograph.');
            }
            $expected = $desired ? [$desired] : [];
            if ($current === $expected) {
                $assertCurrent && $assertCurrent();
                if ($operation) {
                    $this->complete($operation->id);
                }

                return;
            }
            if (! $operation) {
                $mutex->assertOwned();
                $id = DB::table('image_variant_operations')->insertGetId([
                    'product_id' => $product->id, 'variant_gid' => $variantId, 'active_key' => $key,
                    'image_id' => $image?->id, 'image_asset_id' => $image?->image_asset_id, 'desired_media_id' => $desired,
                    'before_media_ids' => json_encode($current, JSON_THROW_ON_ERROR), 'status' => 'prepared', 'created_at' => now(), 'updated_at' => now(),
                ]);
                $operation = DB::table('image_variant_operations')->find($id);
            }
            if ($desired && ! in_array($desired, $current, true)) {
                if ($operation->status !== 'prepared') {
                    throw new RuntimeException('Uncertain variant append awaits confirmation; no mutation was replayed.');
                }
                $assertCurrent && $assertCurrent();
                $this->mutate($operation, 'append', $product, $variantId, [$desired], $image, $mutex);
                $current = $this->mediaIds($variantId, $product->shopify_id);
                if (! in_array($desired, $current, true)) {
                    throw new RuntimeException('Variant replacement is awaiting confirmation. The old association was preserved.');
                }
            }
            $old = array_values(array_diff($current, $expected));
            if ($old !== []) {
                if ($operation->status === 'detach') {
                    throw new RuntimeException('Uncertain variant detach awaits confirmation; no mutation was replayed.');
                }
                $assertCurrent && $assertCurrent();
                $this->mutate($operation, 'detach', $product, $variantId, $old, $image, $mutex);
            }
            $mutex->assertOwned();
            if ($this->mediaIds($variantId, $product->shopify_id) !== $expected) {
                throw new RuntimeException('Variant image reassignment is not confirmed.');
            }
            $assertCurrent && $assertCurrent();
            $this->complete($operation->id);
        } catch (\Throwable $exception) {
            if ($operation) {
                DB::table('image_variant_operations')->where('id', $operation->id)->update(['error' => $exception->getMessage(), 'updated_at' => now()]);
            }
            throw $exception;
        } finally {
            $mutex->release();
        }
    }

    private function mutate(object $operation, string $action, Product $product, string $variantId, array $mediaIds, ?Image $image, ImageSyncMutex $mutex): void
    {
        $mutex->assertOwned();
        if ($image) {
            $live = $image->fresh();
            if (! $live || $live->image_asset_id !== $image->image_asset_id || $live->shopify_id !== $image->shopify_id || $live->shopify_identity_unresolved) {
                throw new RuntimeException('Variant target photograph changed before reassignment.');
            }
        }
        DB::table('image_variant_operations')->where('id', $operation->id)->update(['status' => $action, 'updated_at' => now()]);
        $operation->status = $action;
        $field = $action === 'append' ? 'productVariantAppendMedia' : 'productVariantDetachMedia';
        $inputType = 'ProductVariant'.($action === 'append' ? 'Append' : 'Detach').'MediaInput';
        $data = $this->shopify->graphql('mutation SyncVerifiedVariantImage($productId: ID!, $variantMedia: ['.$inputType.'!]!) { '.$field.'(productId: $productId, variantMedia: $variantMedia) { userErrors { field message } } }',
            ['productId' => $product->shopify_id, 'variantMedia' => [['variantId' => $variantId, 'mediaIds' => $mediaIds]]]);
        $errors = $data[$field]['userErrors'] ?? null;
        if (! is_array($errors) || $errors !== []) {
            throw new RuntimeException('Variant mutation was not confirmed; reconcile before retrying.');
        }
    }

    public function recover(int $id, int $operatorId, string $reason, bool $abandon): void
    {
        $operator = app(ImageRecoveryOperator::class)->authorize($operatorId);
        $reason = app(ImageRecoveryOperator::class)->reason($reason);
        $operation = DB::table('image_variant_operations')->find($id);
        if (! $operation || $operation->active_key === null) {
            throw new RuntimeException('Choose an active unresolved variant operation.');
        }
        $mutex = new ImageSyncMutex;
        if (! $mutex->acquire((int) $operation->product_id)) {
            throw new RuntimeException('Another image operation is running.');
        }
        try {
            $product = Product::findOrFail($operation->product_id);
            $current = $this->mediaIds($operation->variant_gid, $product->shopify_id);
            $expected = $operation->desired_media_id ? [$operation->desired_media_id] : [];
            if (! $abandon && $current !== $expected) {
                throw new RuntimeException('Variant state does not confirm the expected association. No mutation was sent.');
            }
            if (! $abandon && $operation->image_id) {
                $image = Image::find($operation->image_id);
                if (! $image || (int) $image->image_asset_id !== (int) $operation->image_asset_id || $image->shopify_id !== $operation->desired_media_id) {
                    throw new RuntimeException('The photograph changed; explicitly abandon this obsolete operation instead.');
                }
                $node = collect((new ShopifyProductMediaReader($this->shopify))->media((string) $product->shopify_id, 'RecoverVariantTargetPhotograph'))
                    ->first(fn ($node) => ($node['id'] ?? null) === $operation->desired_media_id);
                if (! is_array($node) || ! ShopifyProductMediaReader::ready($node) || ! app(ImagePhotographEvidence::class)->matchesMedia($image, $node)) {
                    throw new RuntimeException('The current photograph is not verified for confirmation.');
                }
            }
            $mutex->assertOwned();
            $updated = DB::table('image_variant_operations')->where('id', $id)->where('active_key', $operation->active_key)
                ->where('status', $operation->status)->where('updated_at', $operation->updated_at)->update([
                    'status' => $abandon ? 'abandoned' : 'confirmed', 'active_key' => null,
                    'resolved_by' => $operator->id, 'resolution_reason' => $reason, 'updated_at' => now(),
                ]);
            if (! $updated) {
                throw new RuntimeException('The operation changed during recovery. Read its current state again.');
            }
        } finally {
            $mutex->release();
        }
    }

    private function complete(int $id): void
    {
        DB::table('image_variant_operations')->where('id', $id)->update(['status' => 'confirmed', 'active_key' => null, 'error' => null, 'updated_at' => now()]);
    }
}
