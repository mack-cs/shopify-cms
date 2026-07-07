<?php

namespace App\Services;

use App\Models\Image;
use App\Models\ImageAsset;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyImageImportBatch;
use App\Models\ShopifyImageImportItem;
use App\Models\Variant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ShopifyImageImportService
{
    private const SUPPORTED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    public function __construct(
        private readonly ProductShopifyUpdater $shopifyUpdater,
        private readonly ProductImageBackupService $backupService,
    ) {}

    public function normalizePrefix(string $input): string
    {
        $prefix = trim(str_replace('\\', '/', $input));
        $prefix = trim($prefix, '/');

        if ($prefix === '') {
            throw new \InvalidArgumentException('Enter an S3 folder or date.');
        }

        $root = trim((string) config('shopify_image_import.root_prefix', 'incoming'), '/');
        if ($root !== '' && $prefix !== $root && !str_starts_with($prefix . '/', $root . '/')) {
            $prefix = $root . '/' . $prefix;
        }

        return $prefix;
    }

    /**
     * @return array{
     *   total_files:int,
     *   matched_count:int,
     *   updated_count:int,
     *   failed_count:int,
     *   affected_stack_product_ids:array<int, int>
     * }
     */
    public function runBatch(ShopifyImageImportBatch $batch): array
    {
        $prefix = $this->normalizePrefix($batch->s3_prefix);
        $keys = $this->imageKeysForPrefix($prefix);

        $matched = 0;
        $updated = 0;
        $failed = 0;
        $affectedStackProductIds = [];
        $updatedProductIds = [];

        $batch->forceFill([
            's3_prefix' => $prefix,
            'total_files' => count($keys),
            'matched_count' => 0,
            'updated_count' => 0,
            'failed_count' => 0,
        ])->save();

        foreach ($keys as $key) {
            $result = $this->processImageKey($batch->fresh() ?? $batch, $key);

            if ($result['matched']) {
                $matched++;
            }

            if ($result['updated']) {
                $updated++;
            }

            if ($result['failed']) {
                $failed++;
            }

            if ($result['affected_stack_product_id'] !== null) {
                $affectedStackProductIds[$result['affected_stack_product_id']] = $result['affected_stack_product_id'];
            }

            if ($result['updated_product_id'] !== null) {
                $updatedProductIds[$result['updated_product_id']] = $result['updated_product_id'];
            }

            $batch->forceFill([
                'matched_count' => $matched,
                'updated_count' => $updated,
                'failed_count' => $failed,
            ])->save();
        }

        foreach ($this->stackProductIdsForUpdatedComponents(array_values($updatedProductIds)) as $stackProductId) {
            $affectedStackProductIds[$stackProductId] = $stackProductId;
        }

        return [
            'total_files' => count($keys),
            'matched_count' => $matched,
            'updated_count' => $updated,
            'failed_count' => $failed,
            'affected_stack_product_ids' => array_values($affectedStackProductIds),
        ];
    }

    /**
     * @param array<int, int> $stackProductIds
     * @return array{rebuilt:int, failed:int, messages:array<int, string>}
     */
    public function rebuildStacksForBatch(ShopifyImageImportBatch $batch, array $stackProductIds): array
    {
        $stackProductIds = array_values(array_unique(array_filter(array_map('intval', $stackProductIds))));
        $rebuilt = 0;
        $failed = 0;
        $messages = [];

        foreach ($stackProductIds as $stackProductId) {
            $stack = Product::query()->find($stackProductId);
            if (!$stack instanceof Product) {
                $failed++;
                $messages[] = "Stack product {$stackProductId} was not found.";
                continue;
            }

            try {
                $result = $this->rebuildStackImages($stack);
                if ($result['rebuilt']) {
                    $rebuilt++;
                    $this->markProductImportStatus($stack, $batch, 'updated');
                } else {
                    $failed++;
                }

                if ($result['message'] !== '') {
                    $messages[] = $result['message'];
                }
            } catch (\Throwable $e) {
                $failed++;
                $messages[] = "{$stack->handle}: {$e->getMessage()}";

                $this->markProductImportStatus($stack, $batch, 'stack_rebuild_failed');

                logger()->error('Shopify stack image rebuild failed.', [
                    'batch_id' => $batch->id,
                    'product_id' => $stack->id,
                    'handle' => $stack->handle,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        return [
            'rebuilt' => $rebuilt,
            'failed' => $failed,
            'messages' => $messages,
        ];
    }

    /**
     * @param array<int, int> $componentProductIds
     * @return array<int, int>
     */
    public function stackProductIdsForUpdatedComponents(array $componentProductIds): array
    {
        $componentProductIds = array_values(array_unique(array_filter(array_map('intval', $componentProductIds))));
        if ($componentProductIds === []) {
            return [];
        }

        $wanted = array_fill_keys($componentProductIds, true);

        return $this->stackProductIdsFromDrafts(
            fn (array $linkedComponentIds): bool => collect($linkedComponentIds)
                ->contains(fn (int $id): bool => isset($wanted[$id]))
        );
    }

    /**
     * @return array<int, int>
     */
    public function stackProductIdsForAllLinkedComponents(): array
    {
        return $this->stackProductIdsFromDrafts(
            fn (array $linkedComponentIds): bool => $linkedComponentIds !== []
        );
    }

    /**
     * @return array<int, string>
     */
    private function imageKeysForPrefix(string $prefix): array
    {
        $disk = Storage::disk($this->diskName());
        $normalizedPrefix = trim($prefix, '/');

        return collect($disk->files($normalizedPrefix))
            ->map(fn (string $key): string => trim(str_replace('\\', '/', $key), '/'))
            ->filter(fn (string $key): bool => dirname($key) === $normalizedPrefix)
            ->filter(fn (string $key): bool => $this->isSupportedImageKey($key))
            ->sort()
            ->values()
            ->all();
    }

    /**
     * @return array{matched:bool, updated:bool, failed:bool, affected_stack_product_id:?int, updated_product_id:?int}
     */
    private function processImageKey(ShopifyImageImportBatch $batch, string $key): array
    {
        $sku = $this->skuFromKey($key);
        $item = ShopifyImageImportItem::query()->updateOrCreate(
            [
                'batch_id' => $batch->id,
                's3_key' => $key,
            ],
            [
                'sku' => $sku,
                'status' => ShopifyImageImportItem::STATUS_PENDING,
                'message' => null,
            ],
        );

        if ($sku === '') {
            $this->markItem($item, ShopifyImageImportItem::STATUS_FAILED, 'No SKU could be extracted from the filename.');
            return ['matched' => false, 'updated' => false, 'failed' => true, 'affected_stack_product_id' => null, 'updated_product_id' => null];
        }

        $product = $this->findProductBySku($sku);
        if (!$product instanceof Product) {
            $this->markItem($item, ShopifyImageImportItem::STATUS_FAILED, "No product variant matched SKU {$sku}.");
            return ['matched' => false, 'updated' => false, 'failed' => true, 'affected_stack_product_id' => null, 'updated_product_id' => null];
        }

        $item->forceFill([
            'product_id' => $product->id,
            'shopify_product_id' => $product->shopify_id,
        ])->save();

        try {
            $asset = $this->storeS3ImageAsAsset($key);
            $prepared = $this->prepareFirstImageForImport($product, $asset, basename($key));
            $image = $prepared['image'];
            $alreadySynced = $prepared['already_synced'];

            if (!$alreadySynced) {
                $syncResult = $this->shopifyUpdater->syncSelectedProductImages(
                    $product->fresh() ?? $product,
                    [$image->id],
                    true,
                );

                $image->refresh();

                if ($syncResult['failed'] > 0 || !empty($syncResult['warnings']) || $image->needs_shopify_image_sync) {
                    $message = $this->syncFailureMessage($syncResult, $image);
                    $this->markItem($item, ShopifyImageImportItem::STATUS_FAILED, $message);
                    $this->markProductImportStatus($product, $batch, 'failed');

                    return ['matched' => true, 'updated' => false, 'failed' => true, 'affected_stack_product_id' => null, 'updated_product_id' => null];
                }
            }

            $this->pointImageSrcAtManagedRoute($image->fresh() ?? $image);
            $this->markProductImportStatus($product, $batch, 'updated');

            $message = $alreadySynced
                ? 'Image already matched the latest imported asset; Shopify upload skipped.'
                : 'First Shopify image replaced.';

            $this->markItem($item, ShopifyImageImportItem::STATUS_UPDATED, $message);

            return [
                'matched' => true,
                'updated' => true,
                'failed' => false,
                'affected_stack_product_id' => $this->isStackProduct($product) ? (int) $product->id : null,
                'updated_product_id' => (int) $product->id,
            ];
        } catch (\Throwable $e) {
            $this->markItem($item, ShopifyImageImportItem::STATUS_FAILED, $e->getMessage());
            $this->markProductImportStatus($product, $batch, 'failed');

            logger()->error('Shopify image import item failed.', [
                'batch_id' => $batch->id,
                's3_key' => $key,
                'sku' => $sku,
                'product_id' => $product->id,
                'message' => $e->getMessage(),
            ]);

            return ['matched' => true, 'updated' => false, 'failed' => true, 'affected_stack_product_id' => null, 'updated_product_id' => null];
        }
    }

    /**
     * @return array{image:Image, already_synced:bool}
     */
    private function prepareFirstImageForImport(Product $product, ImageAsset $asset, string $filename): array
    {
        return DB::transaction(function () use ($product, $asset, $filename): array {
            $image = $this->firstActiveImage($product);
            $alreadySynced = false;

            if (!$image instanceof Image) {
                $image = new Image([
                    'product_id' => $product->id,
                    'position' => 1,
                ]);
            } else {
                $alreadySynced = (int) ($image->image_asset_id ?? 0) === (int) $asset->id
                    && (int) ($image->last_shopify_synced_image_asset_id ?? 0) === (int) $asset->id
                    && $image->sync_state === Image::SYNC_STATE_SYNCED
                    && !$image->needs_shopify_image_sync
                    && filled(trim((string) $image->shopify_id));
            }

            $hasShopifyId = filled(trim((string) ($image->shopify_id ?? '')));
            $needsSync = !$alreadySynced;

            Image::withoutEvents(function () use ($image, $asset, $filename, $hasShopifyId, $needsSync, $alreadySynced): void {
                $image->forceFill([
                    'image_asset_id' => $asset->id,
                    'image_path' => null,
                    'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
                    'backup_completed_at' => now(),
                    'backup_error' => null,
                    'approved_filename' => $this->safeFilename($filename),
                    'filename_mode' => Image::FILENAME_MODE_MANUAL,
                    'position' => 1,
                    'sync_state' => $alreadySynced
                        ? Image::SYNC_STATE_SYNCED
                        : ($hasShopifyId ? Image::SYNC_STATE_LOCAL_UPDATED : Image::SYNC_STATE_LOCAL_NEW),
                    'local_dirty' => $needsSync,
                    'needs_shopify_image_sync' => $needsSync,
                    'shopify_image_sync_error' => null,
                    'is_duplicate_hidden' => false,
                    'duplicate_of_image_id' => null,
                    'duplicate_hidden_at' => null,
                    'duplicate_hidden_by' => null,
                    'duplicate_hidden_reason' => null,
                ])->save();
            });

            $this->removeExtraActivePositionOneImages($product, $image);

            return [
                'image' => $image->fresh() ?? $image,
                'already_synced' => $alreadySynced,
            ];
        });
    }

    /**
     * @return array{rebuilt:bool, message:string}
     */
    private function rebuildStackImages(Product $stack): array
    {
        $lifestyle = $this->firstActiveImage($stack);
        if (!$lifestyle instanceof Image) {
            return ['rebuilt' => false, 'message' => "{$stack->handle}: stack has no lifestyle image at position 1."];
        }

        $components = $this->linkedComponentProducts($stack);
        if ($components->isEmpty()) {
            return ['rebuilt' => false, 'message' => "{$stack->handle}: no linked components found."];
        }

        $componentSources = [];
        $seenAssetIds = [];

        foreach ($components as $component) {
            if (!$component instanceof Product) {
                continue;
            }

            $componentImage = $this->firstComponentShopifyImage($component);
            if (!$componentImage instanceof Image) {
                continue;
            }

            $asset = $this->ensureImageAssetAvailable($componentImage);
            if (!$asset instanceof ImageAsset) {
                continue;
            }

            if (isset($seenAssetIds[$asset->id])) {
                continue;
            }

            $seenAssetIds[$asset->id] = true;
            $componentSources[] = [
                'component' => $component,
                'image' => $componentImage->fresh() ?? $componentImage,
                'asset' => $asset,
            ];
        }

        if ($componentSources === []) {
            return ['rebuilt' => false, 'message' => "{$stack->handle}: no component first images had available managed assets."];
        }

        $createdImageIds = DB::transaction(function () use ($stack, $lifestyle, $componentSources): array {
            Image::withoutEvents(function () use ($lifestyle): void {
                $lifestyle->forceFill([
                    'position' => 1,
                    'is_duplicate_hidden' => false,
                ])->save();
            });

            $this->removeStackImagesFromPositionTwo($stack, $lifestyle);

            $created = [];
            $position = 2;

            foreach ($componentSources as $source) {
                /** @var Product $component */
                $component = $source['component'];
                /** @var Image $componentImage */
                $componentImage = $source['image'];
                /** @var ImageAsset $asset */
                $asset = $source['asset'];

                $image = new Image();
                Image::withoutEvents(function () use ($image, $stack, $component, $componentImage, $asset, $position): void {
                    $image->forceFill([
                        'product_id' => $stack->id,
                        'shopify_id' => null,
                        'image_asset_id' => $asset->id,
                        'sync_state' => Image::SYNC_STATE_LOCAL_NEW,
                        'local_dirty' => true,
                        'src' => null,
                        'image_path' => null,
                        'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
                        'backup_completed_at' => now(),
                        'backup_error' => null,
                        'approved_filename' => $this->safeFilename($componentImage->preferredFilename()),
                        'filename_mode' => Image::FILENAME_MODE_MANUAL,
                        'needs_shopify_image_sync' => true,
                        'shopify_image_sync_error' => null,
                        'position' => $position,
                        'alt_text' => $component->title,
                    ])->save();
                });

                $this->pointImageSrcAtManagedRoute($image->fresh() ?? $image);
                $created[] = (int) $image->id;
                $position++;
            }

            return $created;
        });

        $syncResult = $this->shopifyUpdater->syncProductImagesForImport($stack->fresh() ?? $stack);
        $failedImages = Image::query()
            ->whereIn('id', $createdImageIds)
            ->where('needs_shopify_image_sync', true)
            ->count();

        if ($syncResult['failed'] > 0 || !empty($syncResult['warnings']) || $failedImages > 0) {
            return [
                'rebuilt' => false,
                'message' => "{$stack->handle}: stack image rebuild did not fully sync. " . $this->syncFailureMessage($syncResult, $lifestyle),
            ];
        }

        return [
            'rebuilt' => true,
            'message' => "{$stack->handle}: rebuilt " . count($createdImageIds) . ' component image(s).',
        ];
    }

    private function storeS3ImageAsAsset(string $key): ImageAsset
    {
        $disk = Storage::disk($this->diskName());
        $bytes = $disk->get($key);

        if (!is_string($bytes) || $bytes === '') {
            throw new \RuntimeException("S3 object {$key} is empty or could not be read.");
        }

        $extension = $this->extensionFromName($key);
        $mimeType = $this->mimeTypeFromExtension($extension);
        $bucket = trim((string) config('filesystems.disks.' . $this->diskName() . '.bucket'));
        $sourceUrl = 's3://' . ($bucket !== '' ? $bucket . '/' : '') . $key;

        return $this->storeBytesAsAsset(
            $bytes,
            $extension,
            $mimeType,
            basename($key),
            $sourceUrl,
        );
    }

    private function storeBytesAsAsset(
        string $bytes,
        ?string $extension,
        ?string $mimeType,
        ?string $originalFilename,
        ?string $sourceUrl,
    ): ImageAsset {
        $sha256 = hash('sha256', $bytes);
        $extension = $this->normalizeExtension($extension, $mimeType);
        $storagePath = $this->storagePathForHash($sha256, $extension);
        $disk = Storage::disk('public');

        $asset = ImageAsset::query()->where('sha256', $sha256)->first();
        if ($asset instanceof ImageAsset) {
            if (!$disk->exists($asset->storage_path ?: $storagePath)) {
                $disk->put($asset->storage_path ?: $storagePath, $bytes);
            }

            $asset->forceFill([
                'storage_disk' => $asset->storage_disk ?: 'public',
                'storage_path' => $asset->storage_path ?: $storagePath,
                'original_filename' => $asset->original_filename ?: $originalFilename,
                'source_url' => $asset->source_url ?: $sourceUrl,
                'mime_type' => $asset->mime_type ?: $mimeType,
                'extension' => $asset->extension ?: $extension,
                'file_size' => $asset->file_size ?: strlen($bytes),
                'last_verified_at' => now(),
                'missing_at' => null,
                'status' => ImageAsset::STATUS_AVAILABLE,
            ])->save();

            return $asset->fresh() ?? $asset;
        }

        $disk->put($storagePath, $bytes);

        return ImageAsset::create([
            'sha256' => $sha256,
            'storage_disk' => 'public',
            'storage_path' => $storagePath,
            'original_filename' => $originalFilename,
            'source_url' => $sourceUrl,
            'mime_type' => $mimeType,
            'extension' => $extension,
            'file_size' => strlen($bytes),
            'downloaded_at' => now(),
            'last_verified_at' => now(),
            'missing_at' => null,
            'status' => ImageAsset::STATUS_AVAILABLE,
        ]);
    }

    private function ensureImageAssetAvailable(Image $image): ?ImageAsset
    {
        $image->loadMissing('imageAsset');
        if ($image->imageAsset instanceof ImageAsset && $image->imageAsset->isAvailable()) {
            return $image->imageAsset;
        }

        try {
            $this->backupService->backupImage($image);
        } catch (\Throwable $e) {
            logger()->warning('Component image backup failed during stack rebuild.', [
                'image_id' => $image->id,
                'product_id' => $image->product_id,
                'message' => $e->getMessage(),
            ]);
        }

        $image->refresh()->loadMissing('imageAsset');

        return $image->imageAsset instanceof ImageAsset && $image->imageAsset->isAvailable()
            ? $image->imageAsset
            : null;
    }

    private function findProductBySku(string $sku): ?Product
    {
        $normalized = mb_strtolower(trim($sku));

        $variant = Variant::query()
            ->whereRaw('LOWER(TRIM(sku)) = ?', [$normalized])
            ->with('product')
            ->orderBy('id')
            ->first();

        return $variant?->product;
    }

    private function firstActiveImage(Product $product): ?Image
    {
        return $product->allImages()
            ->whereNotIn('sync_state', [
                Image::SYNC_STATE_LOCAL_DELETED,
                Image::SYNC_STATE_REMOTE_DELETED,
            ])
            ->where(function ($query): void {
                $query->whereNull('is_duplicate_hidden')
                    ->orWhere('is_duplicate_hidden', false);
            })
            ->orderByRaw('CASE WHEN position IS NULL THEN 1 ELSE 0 END')
            ->orderBy('position')
            ->orderBy('id')
            ->first();
    }

    private function firstComponentShopifyImage(Product $product): ?Image
    {
        return $product->allImages()
            ->whereNotIn('sync_state', [
                Image::SYNC_STATE_LOCAL_DELETED,
                Image::SYNC_STATE_REMOTE_DELETED,
            ])
            ->whereNotNull('shopify_id')
            ->where('shopify_id', '!=', '')
            ->where(function ($query): void {
                $query->whereNull('is_duplicate_hidden')
                    ->orWhere('is_duplicate_hidden', false);
            })
            ->orderByRaw('CASE WHEN position IS NULL THEN 1 ELSE 0 END')
            ->orderBy('position')
            ->orderBy('id')
            ->first();
    }

    private function removeExtraActivePositionOneImages(Product $product, Image $primary): void
    {
        $product->allImages()
            ->whereKeyNot($primary->id)
            ->where('position', 1)
            ->whereNotIn('sync_state', [
                Image::SYNC_STATE_LOCAL_DELETED,
                Image::SYNC_STATE_REMOTE_DELETED,
            ])
            ->where(function ($query): void {
                $query->whereNull('is_duplicate_hidden')
                    ->orWhere('is_duplicate_hidden', false);
            })
            ->get()
            ->each(function (Image $image): void {
                Image::withoutEvents(function () use ($image): void {
                    if (blank($image->shopify_id)) {
                        $image->delete();
                        return;
                    }

                    $image->forceFill([
                        'sync_state' => Image::SYNC_STATE_LOCAL_DELETED,
                        'local_dirty' => true,
                    ])->save();
                });
            });
    }

    private function removeStackImagesFromPositionTwo(Product $stack, Image $lifestyle): void
    {
        $stack->allImages()
            ->whereKeyNot($lifestyle->id)
            ->where(function ($query): void {
                $query->whereNull('position')
                    ->orWhere('position', '>=', 2);
            })
            ->whereNotIn('sync_state', [
                Image::SYNC_STATE_LOCAL_DELETED,
                Image::SYNC_STATE_REMOTE_DELETED,
            ])
            ->where(function ($query): void {
                $query->whereNull('is_duplicate_hidden')
                    ->orWhere('is_duplicate_hidden', false);
            })
            ->get()
            ->each(function (Image $image): void {
                Image::withoutEvents(function () use ($image): void {
                    if (blank($image->shopify_id)) {
                        $image->delete();
                        return;
                    }

                    $image->forceFill([
                        'sync_state' => Image::SYNC_STATE_LOCAL_DELETED,
                        'local_dirty' => true,
                    ])->save();
                });
            });
    }

    private function pointImageSrcAtManagedRoute(Image $image): void
    {
        $src = $image->desiredSyncSourceUrl();
        if ($src === null) {
            return;
        }

        Image::withoutEvents(function () use ($image, $src): void {
            $image->forceFill([
                'src' => $src,
            ])->save();
        });
    }

    private function markProductImportStatus(Product $product, ShopifyImageImportBatch $batch, string $status): void
    {
        Product::withoutEvents(function () use ($product, $batch, $status): void {
            $product->forceFill([
                'image_import_batch_id' => $batch->id,
                'image_imported_at' => now(),
                'image_import_status' => $status,
            ])->save();
        });
    }

    private function markItem(ShopifyImageImportItem $item, string $status, string $message): void
    {
        $item->forceFill([
            'status' => $status,
            'message' => Str::limit($message, 2000, ''),
        ])->save();
    }

    private function syncFailureMessage(array $syncResult, Image $image): string
    {
        $parts = [];

        if (!empty($syncResult['failures'])) {
            $parts[] = collect($syncResult['failures'])
                ->take(3)
                ->map(fn (array $failure): string => (string) ($failure['details'] ?? $failure['reason'] ?? 'Shopify sync failed.'))
                ->implode(' | ');
        }

        if (!empty($syncResult['warnings'])) {
            $parts[] = collect($syncResult['warnings'])
                ->take(3)
                ->map(fn (array $warning): string => (string) ($warning['warning'] ?? 'Shopify sync warning.'))
                ->implode(' | ');
        }

        if (filled($image->shopify_image_sync_error)) {
            $parts[] = (string) $image->shopify_image_sync_error;
        }

        return implode(' | ', array_filter($parts)) ?: 'Shopify image sync failed.';
    }

    private function isStackProduct(Product $product): bool
    {
        if ($this->linkedComponentIds($product) !== []) {
            return true;
        }

        if ((bool) ($product->is_bundle ?? false)) {
            return true;
        }

        $type = strtolower(trim((string) ($product->type ?? '')));
        if (in_array($type, ['bundle', 'bundles', 'stack', 'stacks'], true)) {
            return true;
        }

        $tags = TagNormalizer::parseTokens((string) ($product->tags ?? ''));

        return count(array_intersect($tags, ['bundle', 'bundles', 'stack', 'stacks'])) > 0;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Product>
     */
    private function linkedComponentProducts(Product $stack): \Illuminate\Support\Collection
    {
        $ids = $this->linkedComponentIds($stack);
        if ($ids === []) {
            return collect();
        }

        $order = array_flip($ids);

        return Product::query()
            ->whereIn('id', $ids)
            ->get()
            ->sortBy(fn (Product $product): int => $order[(int) $product->id] ?? PHP_INT_MAX)
            ->values();
    }

    /**
     * @return array<int, int>
     */
    private function linkedComponentIds(Product $stack): array
    {
        $shopifyId = trim((string) ($stack->shopify_id ?? ''));
        $handle = trim((string) ($stack->handle ?? ''));

        if ($shopifyId === '' && $handle === '') {
            return [];
        }

        $draft = NewProductDraft::query()
            ->where(function ($query) use ($shopifyId, $handle): void {
                if ($shopifyId !== '') {
                    $query->where('shopify_id', $shopifyId);
                }

                if ($handle !== '') {
                    $shopifyId !== ''
                        ? $query->orWhere('handle', $handle)
                        : $query->where('handle', $handle);
                }
            })
            ->orderByDesc('updated_at')
            ->first(['bundle_product_ids']);

        return $this->normalizeProductIds($draft?->bundle_product_ids);
    }

    /**
     * @return array<int, int>
     */
    private function stackProductIdsFromDrafts(callable $shouldInclude): array
    {
        $drafts = NewProductDraft::query()
            ->whereNotNull('bundle_product_ids')
            ->orderByDesc('updated_at')
            ->get(['id', 'shopify_id', 'handle', 'bundle_product_ids'])
            ->filter(function (NewProductDraft $draft) use ($shouldInclude): bool {
                $linkedComponentIds = $this->normalizeProductIds($draft->bundle_product_ids);

                return $linkedComponentIds !== [] && (bool) $shouldInclude($linkedComponentIds, $draft);
            })
            ->values();

        if ($drafts->isEmpty()) {
            return [];
        }

        $shopifyIds = $drafts
            ->map(fn (NewProductDraft $draft): string => trim((string) ($draft->shopify_id ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $handles = $drafts
            ->map(fn (NewProductDraft $draft): string => trim((string) ($draft->handle ?? '')))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($shopifyIds === [] && $handles === []) {
            return [];
        }

        $products = Product::query()
            ->where(function ($query) use ($shopifyIds, $handles): void {
                if ($shopifyIds !== []) {
                    $query->whereIn('shopify_id', $shopifyIds);
                }

                if ($handles !== []) {
                    $shopifyIds !== []
                        ? $query->orWhereIn('handle', $handles)
                        : $query->whereIn('handle', $handles);
                }
            })
            ->orderBy('id')
            ->get(['id', 'shopify_id', 'handle']);

        $productsByShopifyId = [];
        $productsByHandle = [];

        foreach ($products as $product) {
            $shopifyId = trim((string) ($product->shopify_id ?? ''));
            if ($shopifyId !== '' && !isset($productsByShopifyId[$shopifyId])) {
                $productsByShopifyId[$shopifyId] = $product;
            }

            $handle = trim((string) ($product->handle ?? ''));
            if ($handle !== '' && !isset($productsByHandle[$handle])) {
                $productsByHandle[$handle] = $product;
            }
        }

        $stackProductIds = [];

        foreach ($drafts as $draft) {
            $shopifyId = trim((string) ($draft->shopify_id ?? ''));
            $handle = trim((string) ($draft->handle ?? ''));
            $stack = null;

            if ($shopifyId !== '' && isset($productsByShopifyId[$shopifyId])) {
                $stack = $productsByShopifyId[$shopifyId];
            } elseif ($handle !== '' && isset($productsByHandle[$handle])) {
                $stack = $productsByHandle[$handle];
            }

            if ($stack instanceof Product) {
                $stackProductIds[(int) $stack->id] = (int) $stack->id;
            }
        }

        return array_values($stackProductIds);
    }

    /**
     * @return array<int, int>
     */
    private function normalizeProductIds(mixed $ids): array
    {
        $ids = is_array($ids) ? $ids : [];
        $normalized = [];
        $seen = [];

        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id <= 0 || isset($seen[$id])) {
                continue;
            }

            $seen[$id] = true;
            $normalized[] = $id;
        }

        return $normalized;
    }

    private function isSupportedImageKey(string $key): bool
    {
        return in_array($this->extensionFromName($key), self::SUPPORTED_EXTENSIONS, true);
    }

    private function skuFromKey(string $key): string
    {
        return trim((string) pathinfo($key, PATHINFO_FILENAME));
    }

    private function diskName(): string
    {
        return trim((string) config('shopify_image_import.disk', 'shopify_product_images')) ?: 'shopify_product_images';
    }

    private function safeFilename(string $filename): string
    {
        $basename = basename($filename);
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $basename) ?: '';
        $safe = trim($safe, '.-_');

        return $safe !== '' ? $safe : 'shopify-image.' . ($this->extensionFromName($filename) ?: 'jpg');
    }

    private function storagePathForHash(string $sha256, ?string $extension): string
    {
        $prefixA = substr($sha256, 0, 2);
        $prefixB = substr($sha256, 2, 2);
        $suffix = $extension ? ".{$extension}" : '';

        return "product-image-assets/{$prefixA}/{$prefixB}/{$sha256}{$suffix}";
    }

    private function extensionFromName(?string $value): ?string
    {
        $extension = strtolower(trim((string) pathinfo((string) $value, PATHINFO_EXTENSION)));

        return $extension !== '' ? $extension : null;
    }

    private function normalizeExtension(?string $extension, ?string $mimeType = null): ?string
    {
        $normalized = strtolower(trim((string) $extension));
        $normalized = preg_replace('/[^a-z0-9]+/', '', $normalized) ?? $normalized;

        if ($normalized === 'jpeg') {
            return 'jpg';
        }

        if ($normalized !== '') {
            return $normalized;
        }

        return match ($this->normalizeMimeType($mimeType)) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            default => null,
        };
    }

    private function mimeTypeFromExtension(?string $extension): ?string
    {
        return match ($this->normalizeExtension($extension)) {
            'jpg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'webp' => 'image/webp',
            default => null,
        };
    }

    private function normalizeMimeType(?string $mimeType): ?string
    {
        $normalized = strtolower(trim((string) $mimeType));
        if ($normalized === '') {
            return null;
        }

        return trim(explode(';', $normalized)[0] ?? '') ?: null;
    }
}
