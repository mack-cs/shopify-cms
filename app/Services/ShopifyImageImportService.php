<?php

namespace App\Services;

use App\Models\Image;
use App\Models\ImageAsset;
use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ShopifyImageImportBatch;
use App\Models\ShopifyImageImportItem;
use App\Models\Variant;
use App\Services\StackRebuildLockStore;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ShopifyImageImportService
{
    private const SUPPORTED_EXTENSIONS = ['jpg', 'jpeg', 'png', 'webp'];

    /** @var array<string, array{nodes?: array<int, mixed>, error?: string}> */
    private array $componentMediaReads = [];

    public function __construct(
        private readonly ProductImageBackupService $backupService,
        private readonly ImageAltTextBackfillService $altText,
    ) {}

    public function normalizePrefix(string $input): string
    {
        $prefix = trim(str_replace('\\', '/', $input));
        $prefix = trim($prefix, '/');

        if ($prefix === '') {
            throw new \InvalidArgumentException('Enter an S3 folder or date.');
        }

        $root = trim((string) config('shopify_image_import.root_prefix', 'incoming'), '/');
        if ($root !== '' && $prefix !== $root && ! str_starts_with($prefix.'/', $root.'/')) {
            $prefix = $root.'/'.$prefix;
        }

        return $prefix;
    }

    /**
     * Whether the selected folder contains alt-text.csv.
     *
     * @return 'found'|'missing'|'unavailable'|'empty'
     */
    public function altTextCsvStatus(string $input): string
    {
        try {
            $prefix = $this->normalizePrefix($input);
        } catch (\InvalidArgumentException) {
            return 'empty';
        }

        try {
            $exists = Storage::disk($this->diskName())->exists($this->altTextCsvKey($prefix));
        } catch (\Throwable) {
            return 'unavailable';
        }

        return $exists === true ? 'found' : 'missing';
    }

    /**
     * Build a blank alt-text.csv for the supported images directly in the selected folder.
     * An empty selection returns the header only. This does not write anything to S3.
     *
     * @return array{ok:bool, csv:string, filenames:array<int, string>, message:?string}
     */
    public function altTextTemplate(string $input): array
    {
        $headerOnly = [
            'ok' => true,
            'csv' => $this->altTextTemplateDocument([]),
            'filenames' => [],
            'message' => null,
        ];

        if (trim($input) === '') {
            return $headerOnly;
        }

        try {
            $prefix = $this->normalizePrefix($input);
        } catch (\InvalidArgumentException) {
            return $headerOnly;
        }

        try {
            $filenames = [];
            foreach ($this->imageKeysForPrefix($prefix) as $key) {
                $filename = $this->imageBasename($key);
                if (mb_strtolower($filename) === 'alt-text.csv') {
                    continue;
                }
                $filenames[] = $filename;
            }
        } catch (\Throwable) {
            return [
                'ok' => false,
                'csv' => '',
                'filenames' => [],
                'message' => 'The S3 folder could not be read. The template was not downloaded.',
            ];
        }

        return [
            'ok' => true,
            'csv' => $this->altTextTemplateDocument($filenames),
            'filenames' => $filenames,
            'message' => $filenames === []
                ? 'This folder has no supported product images. The template contains the header only.'
                : null,
        ];
    }

    /**
     * @return array{
     *   total_files:int,
     *   matched_count:int,
     *   updated_count:int,
     *   failed_count:int,
     *   affected_stack_product_ids:array<int, int>,
     *   direct_stack_product_ids:array<int, int>,
     *   dependent_stack_product_ids:array<int, int>,
     *   alt_text_csv_found:bool,
     *   alt_text_applied:int,
     *   alt_text_missing:int,
     *   alt_text_unchanged:int,
     *   alt_text_preserved:int,
     *   alt_text_error_count:int
     * }
     */
    public function runBatch(ShopifyImageImportBatch $batch): array
    {
        $this->assertImportFilenameColumns($batch);

        $prefix = $this->normalizePrefix($batch->s3_prefix);
        $keys = $this->imageKeysForPrefix($prefix);

        $matched = 0;
        $updated = 0;
        $failed = 0;
        $directStackProductIds = [];
        $affectedStackProductIds = [];
        $updatedProductIds = [];
        $outcomes = [];

        $batch->forceFill([
            's3_prefix' => $prefix,
            'total_files' => count($keys),
            'matched_count' => 0,
            'updated_count' => 0,
            'failed_count' => 0,
            'alt_text_csv_found' => false,
            'alt_text_applied' => 0,
            'alt_text_missing' => 0,
            'alt_text_unchanged' => 0,
            'alt_text_preserved' => 0,
            'alt_text_error_count' => 0,
            'alt_text_report' => null,
        ])->save();

        $rejectedKeys = $this->rejectedFilenameVersions($keys);

        foreach ($keys as $key) {
            $result = isset($rejectedKeys[$key])
                ? $this->rejectImageKey($batch->fresh() ?? $batch, $key, $rejectedKeys[$key])
                : $this->processImageKey($batch->fresh() ?? $batch, $key);
            $basename = $this->imageBasename($key);
            $outcomes[] = [
                'item_id' => $result['item_id'],
                's3_key' => $key,
                'basename' => $basename,
                'match_key' => $this->altTextMatchKey($basename),
                'image_id' => $result['image_id'],
                'image_asset_id' => $result['image_asset_id'],
                'photograph_version' => $result['photograph_version'],
                'updated' => $result['updated'],
                'failed' => $result['failed'],
            ];

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
                $directStackProductIds[$result['affected_stack_product_id']] = $result['affected_stack_product_id'];
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

        $dependentStackProductIds = [];
        foreach ($this->stackProductIdsForUpdatedComponents(array_values($updatedProductIds)) as $stackProductId) {
            $affectedStackProductIds[$stackProductId] = $stackProductId;
            if (! isset($directStackProductIds[$stackProductId])) {
                $dependentStackProductIds[$stackProductId] = $stackProductId;
            }
        }

        $alt = $this->applyImportedAltText($batch, $prefix, $outcomes);

        return [
            'total_files' => count($keys),
            'matched_count' => $matched,
            'updated_count' => $updated,
            'failed_count' => $failed,
            'affected_stack_product_ids' => array_values($affectedStackProductIds),
            'direct_stack_product_ids' => array_values($directStackProductIds),
            'dependent_stack_product_ids' => array_values($dependentStackProductIds),
            'alt_text_csv_found' => $alt['found'],
            'alt_text_applied' => $alt['applied'],
            'alt_text_missing' => $alt['missing'],
            'alt_text_unchanged' => $alt['unchanged'],
            'alt_text_preserved' => $alt['preserved'],
            'alt_text_error_count' => $alt['errors'],
        ];
    }

    /**
     * @param  array<int, int>  $stackProductIds
     * @param  array{key: string, owner: string}|null  $claim
     * @return array{rebuilt:int, incomplete:int, failed:int, compare_at_updated:int, messages:array<int, string>, recorded:bool}
     */
    public function rebuildStacksForBatch(ShopifyImageImportBatch $batch, array $stackProductIds, ?array $claim = null): array
    {
        $stackProductIds = array_values(array_unique(array_filter(array_map('intval', $stackProductIds))));
        $rebuilt = 0;
        $incomplete = 0;
        $failed = 0;
        $skipped = 0;
        $compareAtUpdated = 0;
        $messages = [];

        foreach ($stackProductIds as $stackProductId) {
            $stack = Product::query()->find($stackProductId);
            if (! $stack instanceof Product) {
                $failed++;
                $messages[] = "Stack product {$stackProductId} was not found.";

                continue;
            }

            try {
                $result = $this->rebuildStackImages($stack, $batch, $claim);
                if (($result['skipped'] ?? false) === true) {
                    $skipped++;

                    continue;
                }
                $outcome = $this->classifyStackRebuild($result);
                $message = (string) ($result['message'] ?? '');
                if ($outcome === ShopifyImageImportBatch::REBUILD_COMPLETED) {
                    $rebuilt++;
                } elseif ($outcome === ShopifyImageImportBatch::REBUILD_INCOMPLETE) {
                    $incomplete++;
                } else {
                    $failed++;
                }
                $this->markRebuildStatus($stack, $batch, $outcome, $message, $claim);

                if ($message !== '') {
                    $messages[] = $message;
                }
            } catch (\Throwable $e) {
                $failed++;
                $message = "{$stack->handle}: {$e->getMessage()}";
                $messages[] = $message;
                $this->markRebuildStatus($stack, $batch, ShopifyImageImportBatch::REBUILD_FAILED, $message, $claim);

                logger()->error('Shopify stack image rebuild failed.', [
                    'batch_id' => $batch->id,
                    'product_id' => $stack->id,
                    'handle' => $stack->handle,
                    'message' => $e->getMessage(),
                ]);
            }

            try {
                $price = $this->rebuildStackCompareAtPrice($stack->fresh() ?? $stack);
                if ($price['updated']) {
                    $compareAtUpdated++;
                }
                if ($price['message'] !== '') {
                    $messages[] = $price['message'];
                }
            } catch (\Throwable $e) {
                $messages[] = "{$stack->handle}: compare-at price was not updated. {$e->getMessage()}";
                logger()->error('Stack compare-at rebuild failed.', [
                    'batch_id' => $batch->id,
                    'product_id' => $stack->id,
                    'handle' => $stack->handle,
                    'message' => $e->getMessage(),
                ]);
            }
        }

        $recorded = $this->recordBatchRebuild($batch, $stackProductIds, $claim, $rebuilt, $incomplete, $failed, $skipped, $messages);

        return [
            'rebuilt' => $rebuilt,
            'incomplete' => $incomplete,
            'failed' => $failed,
            'compare_at_updated' => $compareAtUpdated,
            'messages' => $messages,
            'recorded' => $recorded,
        ];
    }

    /**
     * @param  array<int, int>  $componentProductIds
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
            ->sortBy(function (string $key): array {
                $stem = trim((string) pathinfo($key, PATHINFO_FILENAME));
                $base = $stem;
                $position = 1;
                if (preg_match('/^(.+)-(\d+)$/', $stem, $matches) === 1) {
                    $base = $matches[1];
                    $position = max(1, (int) $matches[2]);
                }

                return [mb_strtolower($base), $position, $key];
            })
            ->values()
            ->all();
    }

    /**
     * @return array{matched:bool, updated:bool, failed:bool, affected_stack_product_id:?int, updated_product_id:?int, item_id:int, image_id:?int}
     */
    private function processImageKey(ShopifyImageImportBatch $batch, string $key): array
    {
        $identity = $this->imageIdentityFromKey($key);
        $sku = $identity['sku'];
        $position = $identity['position'];
        $item = ShopifyImageImportItem::query()->updateOrCreate(
            [
                'batch_id' => $batch->id,
                's3_key' => $key,
            ],
            [
                'sku' => $sku,
                'status' => ShopifyImageImportItem::STATUS_PENDING,
                'message' => null,
                'alt_message' => null,
            ],
        );

        if ($sku === '') {
            $this->markItem($item, ShopifyImageImportItem::STATUS_FAILED, 'No SKU could be extracted from the filename.');

            return $this->imageImportResult($item, false, true, null, null, null);
        }

        $product = $this->findProductBySku($sku);
        if (! $product instanceof Product) {
            $this->markItem($item, ShopifyImageImportItem::STATUS_FAILED, "No product variant matched SKU {$sku}.");

            return $this->imageImportResult($item, false, true, null, null, null);
        }

        $item->forceFill([
            'product_id' => $product->id,
            'shopify_product_id' => $product->shopify_id,
        ])->save();

        try {
            $asset = $this->storeS3ImageAsAsset($key);
            $prepared = $this->prepareImageForImport($product, $asset, basename($key), $position, (int) $identity['filename_version']);
            $image = $prepared['image'];
            $alreadySynced = $prepared['already_synced'];
            $product = $product->fresh() ?? $product;

            $this->pointImageSrcAtManagedRoute($image->fresh() ?? $image);
            $this->markProductImportStatus($product, $batch, 'updated');

            $positionLabel = "Image position {$position}";
            $message = $alreadySynced
                ? "{$positionLabel} already matched the imported file. Shopify was left unchanged."
                : "{$positionLabel} was saved. Shopify sync is pending approval.";

            $this->markItem($item, ShopifyImageImportItem::STATUS_UPDATED, $message);

            return $this->imageImportResult(
                $item,
                true,
                false,
                $this->isStackProduct($product) ? (int) $product->id : null,
                (int) $product->id,
                (int) $image->id,
                (int) $image->image_asset_id,
                (int) $image->photograph_version,
            );
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

            return $this->imageImportResult($item, true, true, null, null, null);
        }
    }

    /**
     * @return array{matched:bool, updated:bool, failed:bool, affected_stack_product_id:?int, updated_product_id:?int, item_id:int, image_id:?int, image_asset_id:?int, photograph_version:?int}
     */
    private function imageImportResult(
        ShopifyImageImportItem $item,
        bool $matched,
        bool $failed,
        ?int $stackProductId,
        ?int $productId,
        ?int $imageId,
        ?int $imageAssetId = null,
        ?int $photographVersion = null,
    ): array {
        return [
            'matched' => $matched,
            'updated' => $matched && ! $failed,
            'failed' => $failed,
            'affected_stack_product_id' => $stackProductId,
            'updated_product_id' => $productId,
            'item_id' => (int) $item->id,
            'image_id' => $imageId,
            'image_asset_id' => $imageAssetId,
            'photograph_version' => $photographVersion,
        ];
    }

    /**
     * @return array{image:Image, already_synced:bool}
     */
    private function prepareImageForImport(Product $product, ImageAsset $asset, string $filename, int $position = 1, int $filenameVersion = 1): array
    {
        $mutex = new ImageSyncMutex;
        if (! $mutex->acquire((int) $product->id)) {
            throw new \RuntimeException('An image operation is running for this product. Retry the import later.');
        }
        $boundary = null;
        try {
            $mutex->assertOwned();
            $boundary = app(StackSynchronizationBoundary::class)->acquireForComponent((int) $product->id);

            return $this->prepareImageForImportLocked($product, $asset, $filename, $position, $filenameVersion);
        } finally {
            $boundary?->release();
            $mutex->release();
        }
    }

    private function prepareImageForImportLocked(Product $product, ImageAsset $asset, string $filename, int $position = 1, int $filenameVersion = 1): array
    {
        $position = max(1, $position);
        $filenameVersion = max(1, $filenameVersion);

        return DB::transaction(function () use ($product, $asset, $filename, $position, $filenameVersion): array {
            Product::query()->whereKey($product->id)->lockForUpdate()->firstOrFail();
            $atPosition = $product->allImages()->active()->where('position', $position)->lockForUpdate()->get();
            $inheritedAtPosition = $product->isStackProduct()
                ? $atPosition->filter(fn (Image $candidate): bool => $this->importTargetsInheritedPhotograph($candidate))->values()
                : collect();
            $candidates = $product->isStackProduct()
                ? $atPosition->reject(fn (Image $candidate): bool => $this->importTargetsInheritedPhotograph($candidate))->values()
                : $atPosition;
            $sameBytes = $candidates->filter(
                fn (Image $candidate): bool => (int) $candidate->image_asset_id > 0 && (int) $candidate->image_asset_id === (int) $asset->id
            );
            if ($sameBytes->count() > 1) {
                throw new \RuntimeException('This import position contains different or unresolved photographs. Review the collision explicitly.');
            }
            if ($sameBytes->count() === 1) {
                $image = $sameBytes->first();
            } elseif ($candidates->count() > 1) {
                throw new \RuntimeException('This import position contains different or unresolved photographs. Review the collision explicitly.');
            } else {
                $image = $candidates->first();
            }
            if ($image instanceof Image && $this->importTargetsInheritedPhotograph($image)) {
                throw new \RuntimeException('Image '.$image->id.' at position '.$position.' is an inherited component photograph. The file was not imported and ownership was not changed.');
            }
            $photographMatches = false;
            if ($image instanceof Image) {
                $acceptedVersion = $image->filename_version === null ? 1 : (int) $image->filename_version;
                $comparison = $this->existingPhotographComparison($image, $asset);
                if ($comparison === 'unreadable') {
                    throw new \RuntimeException('Image position '.$position.' has a photograph whose bytes could not be read. '.$filename.' was not imported.');
                }
                $photographMatches = $comparison === 'match';
                // Existing versioned inputs remain compatible, but a replacement never requires a suffix.
                $filenameVersion = max($filenameVersion, $photographMatches ? $acceptedVersion : $acceptedVersion + 1);
            }
            $alreadySynced = false;

            if (! $image instanceof Image) {
                $image = new Image([
                    'product_id' => $product->id,
                    'position' => $position,
                ]);
            } else {
                $alreadySynced = (int) ($image->image_asset_id ?? 0) === (int) $asset->id
                    && (int) ($image->last_shopify_synced_image_asset_id ?? 0) === (int) $asset->id
                    && $image->sync_state === Image::SYNC_STATE_SYNCED
                    && ! $image->needs_shopify_image_sync
                    && filled(trim((string) $image->shopify_id));
                if (! $alreadySynced && $photographMatches
                    && $image->sync_state === Image::SYNC_STATE_SYNCED
                    && ! $image->needs_shopify_image_sync
                    && filled(trim((string) $image->shopify_id))) {
                    $alreadySynced = true;
                }
            }

            $hasShopifyId = filled(trim((string) ($image->shopify_id ?? '')));
            $needsSync = ! $alreadySynced;
            $previousAssetId = (int) ($image->image_asset_id ?? 0);
            $replacedPhotograph = $image->exists && ! $photographMatches && $previousAssetId !== (int) $asset->id;

            if ($replacedPhotograph) {
                // Keep the prior remote evidence while accepting the new CMS photo.
                // The active upload claim still prevents a second Shopify create.
                app(ImagePhotographEvidence::class)->archive($image, 'S3 photograph replacement');
            }
            if ($product->isStackProduct() && ! $photographMatches) {
                $filenameVersion = app(ProductImageFilenameService::class)->nextAvailableImportVersion($product, $position, $filenameVersion);
            }
            if ($inheritedAtPosition->isNotEmpty()) {
                $this->relocateInheritedPhotographs($product, $inheritedAtPosition, $position);
            }
            Image::withoutEvents(function () use ($image, $asset, $filename, $filenameVersion, $hasShopifyId, $needsSync, $alreadySynced, $position, $replacedPhotograph, $photographMatches, $product): void {
                $attributes = [
                    'image_asset_id' => $asset->id,
                    'image_path' => null,
                    'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
                    'backup_completed_at' => now(),
                    'backup_error' => null,
                    's3_filename' => $this->imageBasename($filename),
                    'filename_version' => $filenameVersion,
                    'position' => $position,
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
                ];
                $existingApproved = trim((string) ($image->approved_filename ?? ''));
                if (! ($photographMatches && $existingApproved !== '')) {
                    $attributes['approved_filename'] = app(ProductImageFilenameService::class)->generateApprovedImportFilename(
                        $product,
                        $position,
                        $filenameVersion,
                        (string) $this->extensionFromName($filename),
                    );
                    $attributes['filename_mode'] = Image::FILENAME_MODE_MANUAL;
                }
                if ($replacedPhotograph) {
                    $attributes['alt_text'] = $image->alt_text ?? $image->shopify_alt_text;
                    $attributes['imported_alt_text'] = null;
                    $attributes['source_image_id'] = null;
                    $attributes['shopify_id'] = null;
                    $attributes['shopify_cdn_url'] = null;
                    $attributes['src'] = null;
                    $attributes['shopify_identity_unresolved'] = true;
                    $attributes['last_shopify_synced_image_asset_id'] = null;
                    $attributes['alt_confirmation_media_id'] = null;
                    $attributes['alt_confirmation_text'] = null;
                    $attributes['shopify_alt_pending'] = false;
                    $attributes['shopify_alt_sync_error'] = null;
                    $attributes['shopify_alt_text'] = null;
                    $attributes['alt_text_status'] = filled($attributes['alt_text']) ? Image::ALT_PENDING_REVIEW : null;
                    $attributes['alt_text_version'] = max(1, (int) $image->alt_text_version) + 1;
                }
                $resultingSource = $replacedPhotograph ? null : $image->source_image_id;
                if ($product->isStackProduct() && $image->ownership === null && $resultingSource === null) {
                    $attributes['ownership'] = \App\Services\ImageOwnership::STACK_OWNED;
                }
                if ($photographMatches) {
                    unset($attributes['image_asset_id'], $attributes['image_path']);
                    $attributes['photograph_version'] = max(1, (int) $image->photograph_version);
                    $attributes['alt_text_version'] = max(1, (int) $image->alt_text_version);
                }
                $image->forceFill($attributes)->save();
            });
            if (! $photographMatches) {
                $this->invalidateGalleryApproval($product);
            }

            return [
                'image' => $image->fresh() ?? $image,
                'already_synced' => $alreadySynced,
            ];
        });
    }

    private function importTargetsInheritedPhotograph(Image $image): bool
    {
        return \App\Services\ImageOwnership::isInherited($image) || (int) $image->source_image_id > 0;
    }

    /**
     * A stack upload keeps the filename position. Inherited photographs already
     * sitting there move to the next free positions, in image id order. Only
     * the position changes, so ownership, source, approval, and Shopify media
     * stay with the inherited photograph.
     *
     * @param  Collection<int, Image>  $inherited
     */
    private function relocateInheritedPhotographs(Product $product, Collection $inherited, int $reservedPosition): void
    {
        $movingIds = $inherited->map(fn (Image $image): int => (int) $image->id)->all();
        $occupied = [];
        foreach ($product->allImages()->active()->get(['id', 'position']) as $row) {
            if (in_array((int) $row->id, $movingIds, true)) {
                continue;
            }
            $current = (int) $row->position;
            if ($current > 0) {
                $occupied[$current] = true;
            }
        }
        $occupied[max(1, $reservedPosition)] = true;

        foreach ($inherited->sortBy('id') as $image) {
            $next = 1;
            while (isset($occupied[$next])) {
                $next++;
            }
            DB::table('images')->where('id', $image->id)->update(['position' => $next]);
            $occupied[$next] = true;
        }
    }

    /**
     * @return 'match'|'different'|'unreadable'|'absent'
     */
    private function existingPhotographComparison(Image $image, ImageAsset $incoming): string
    {
        $bytes = $this->readExistingPhotographBytes($image);
        if ($bytes === false) {
            return 'unreadable';
        }
        if ($bytes === null) {
            return 'absent';
        }

        $incomingHash = strtolower(trim((string) $incoming->sha256));
        if (strlen($incomingHash) !== 64) {
            return 'unreadable';
        }

        return hash_equals($incomingHash, hash('sha256', $bytes)) ? 'match' : 'different';
    }

    /**
     * Read the photograph through its managed asset, then its local upload.
     * False means a photograph is recorded but its bytes cannot be trusted.
     */
    private function readExistingPhotographBytes(Image $image): string|false|null
    {
        $assetBytes = $this->managedAssetBytes($image);
        $pathBytes = $this->localPathBytes($image);
        if ($assetBytes === false || $pathBytes === false) {
            return false;
        }
        if (is_string($assetBytes) && is_string($pathBytes)) {
            if (! hash_equals(hash('sha256', $assetBytes), hash('sha256', $pathBytes))) {
                return false;
            }

            return $assetBytes;
        }
        if (is_string($assetBytes) || is_string($pathBytes)) {
            return is_string($assetBytes) ? $assetBytes : $pathBytes;
        }

        if ((int) $image->image_asset_id > 0
            || trim((string) $image->src) !== ''
            || trim((string) $image->shopify_cdn_url) !== ''
            || filled(trim((string) $image->shopify_id))) {
            return false;
        }

        return null;
    }

    /**
     * @return string|false|null False when a managed asset is recorded but cannot be read.
     */
    private function managedAssetBytes(Image $image): string|false|null
    {
        if ((int) $image->image_asset_id < 1) {
            return null;
        }

        $image->loadMissing('imageAsset');
        $asset = $image->imageAsset;
        if (! $asset instanceof ImageAsset || ! $asset->isAvailable()) {
            return false;
        }

        $diskName = trim((string) $asset->storage_disk) !== '' ? $asset->storage_disk : 'public';
        $path = trim((string) $asset->storage_path);
        $contents = $path === '' ? null : Storage::disk($diskName)->get($path);
        if (! is_string($contents) || $contents === '') {
            return false;
        }
        $stored = strtolower(trim((string) $asset->sha256));
        if ($stored !== '' && ! hash_equals($stored, hash('sha256', $contents))) {
            return false;
        }

        return $contents;
    }

    /**
     * @return string|false|null False when a local path is recorded but cannot be read.
     */
    private function localPathBytes(Image $image): string|false|null
    {
        $localPath = trim((string) $image->image_path);
        if ($localPath === '') {
            return null;
        }
        if (! Storage::disk('public')->exists($localPath)) {
            return false;
        }
        $contents = Storage::disk('public')->get($localPath);

        return is_string($contents) && $contents !== '' ? $contents : false;
    }

    private function assertImportFilenameColumns(ShopifyImageImportBatch $batch): void
    {
        $missing = array_values(array_filter(
            ['filename_version', 's3_filename'],
            fn (string $column): bool => ! Schema::hasColumn('images', $column),
        ));
        if ($missing === []) {
            return;
        }

        $message = 'The images.'.implode(' and images.', $missing).' column is missing. Run the filename migration before importing. No images were changed.';
        $batch->forceFill([
            'status' => ShopifyImageImportBatch::STATUS_FAILED,
            'error_message' => $message,
            'completed_at' => now(),
            'matched_count' => 0,
            'updated_count' => 0,
            'failed_count' => 0,
        ])->save();

        throw new \RuntimeException($message);
    }

    /**
     * @param  array{key: string, owner: string}|null  $claim
     * @return array{rebuilt:bool, message:string, unavailable:array<int, string>, skipped?:bool}
     */
    private function rebuildStackImages(Product $stack, ShopifyImageImportBatch $batch, ?array $claim = null): array
    {
        if ($this->newerBatchOwns($stack->id, $batch)) {
            return $this->skippedRebuild();
        }

        $mutex = new ImageSyncMutex;
        if (! $mutex->acquire((int) $stack->id)) {
            throw new \RuntimeException('Another image operation is running for this stack. Retry later.');
        }
        try {
            $mutex->assertOwned();
            if (! $this->rebuildAttemptMayCommit($stack->id, $batch, $claim)) {
                return $this->skippedRebuild();
            }

            return $this->rebuildStackImagesLocked($stack, $batch, $claim);
        } finally {
            $mutex->release();
        }
    }

    /**
     * @param  array{key: string, owner: string}|null  $claim
     * @return array{rebuilt:bool, message:string, unavailable:array<int, string>, skipped?:bool}
     */
    private function rebuildStackImagesLocked(Product $stack, ShopifyImageImportBatch $batch, ?array $claim = null): array
    {
        $prepared = $this->prepareComponentSources($stack);

        return DB::transaction(function () use ($stack, $batch, $claim, $prepared): array {
            $locked = Product::query()->whereKey($stack->id)->lockForUpdate()->first();
            if (! $locked instanceof Product || ! $this->rebuildAttemptMayCommit((int) $locked->id, $batch, $claim, $locked)) {
                return $this->skippedRebuild();
            }

            $again = Product::query()->whereKey($stack->id)->lockForUpdate()->first();
            if (! $again instanceof Product || ! $this->rebuildAttemptMayCommit((int) $again->id, $batch, $claim, $again)) {
                return $this->skippedRebuild();
            }

            $before = $this->activeGallery($stack);
            $staged = app(ComponentStackPropagation::class)->stageStack($stack, $prepared['rejected']);
            if ($before !== $this->activeGallery($stack)) {
                $this->invalidateGalleryApproval($stack);
            }
            $unavailable = $this->mergeUnavailableSources($prepared['reasons'], $staged['unavailable'] ?? []);
            $message = (string) ($staged['message'] ?? '');
            foreach ($prepared['reasons'] as $reason) {
                if (! str_contains($message, $reason)) {
                    $message .= ($message === '' ? '' : ' ').$reason;
                }
            }

            return [
                'rebuilt' => (bool) ($staged['rebuilt'] ?? false),
                'message' => $message,
                'unavailable' => $unavailable,
            ];
        });
    }

    private function activeGallery(Product $product): array
    {
        return $product->allImages()->active()->orderBy('id')
            ->get(['id', 'position', 'image_asset_id'])->toArray();
    }

    private function invalidateGalleryApproval(Product $product): void
    {
        Product::query()->whereKey($product->id)->update([
            'approval_version' => DB::raw('COALESCE(approval_version, 1) + 1'),
        ]);
    }

    /**
     * Resolve a verified managed backup before inherited images are staged.
     * Shopify is only read. Nothing is uploaded, published, or deleted.
     *
     * @return array{reasons: array<int, string>, rejected: array<int, string>}
     */
    private function prepareComponentSources(Product $stack): array
    {
        $this->componentMediaReads = [];
        if (app(StackComponentCatalog::class)->membershipState($stack) !== 'known') {
            return ['reasons' => [], 'rejected' => []];
        }

        $reasons = [];
        $rejected = [];
        foreach (app(StackComponentCatalog::class)->componentIds($stack) as $componentId) {
            $component = Product::query()->find($componentId);
            if (! $component instanceof Product) {
                continue;
            }
            $image = app(ComponentPhotographSelector::class)->positionOne($component);
            if ($image instanceof Image && ($reason = $this->prepareInheritedSource($image, $component)) !== null) {
                $rejected[(int) $image->id] = $reason;
                $sku = trim((string) ($component->variants()->orderBy('id')->value('sku') ?: $component->handle));
                $reasons[] = 'Image '.$image->id.' on '.($sku !== '' ? $sku : 'Product #'.$component->id).' was not copied: '.$reason;
            }
        }

        return ['reasons' => $reasons, 'rejected' => $rejected];
    }

    public function prepareInheritedSource(Image $image, Product $component): ?string
    {
        $image->refresh();
        $image->loadMissing('imageAsset');
        if (is_string($this->managedAssetBytes($image))) {
            return null;
        }
        if ((int) $image->image_asset_id > 0 && $image->imageAsset instanceof ImageAsset && $image->imageAsset->isAvailable()) {
            return 'the managed backup does not match its stored SHA-256.';
        }

        $hasAsset = (int) $image->image_asset_id > 0;
        $local = $this->localPathBytes($image);
        if (is_string($local)) {
            $stored = strtolower(trim((string) ($image->imageAsset?->sha256 ?? '')));
            if ($stored !== '' && ! hash_equals($stored, hash('sha256', $local))) {
                return 'the local CMS photograph does not match the stored SHA-256.';
            }
            $extension = $this->extensionFromName($image->image_path);
            $asset = $this->storeBytesAsAsset(
                $local,
                $extension,
                $this->mimeTypeFromExtension($extension),
                basename((string) $image->image_path),
                null,
            );
            if ($hasAsset && (int) $asset->id !== (int) $image->image_asset_id) {
                return 'the local CMS photograph does not match the current managed backup.';
            }
            $this->attachVerifiedBackup($image, $asset);

            return null;
        }

        $shopify = $this->verifiedShopifyPhotograph($image, $component);
        if (is_string($shopify)) {
            if ($local === false) {
                return 'the local CMS photograph is recorded but could not be read. '.$shopify;
            }

            return $shopify;
        }

        $stored = strtolower(trim((string) ($image->imageAsset?->sha256 ?? '')));
        if ($stored !== '' && ! hash_equals($stored, hash('sha256', $shopify['bytes']))) {
            return 'the Shopify original does not match the photograph SHA-256.';
        }
        $asset = $this->storeBytesAsAsset(
            $shopify['bytes'],
            $shopify['extension'],
            $this->mimeTypeFromExtension($shopify['extension']),
            $shopify['filename'],
            $shopify['source_url'],
        );
        if ($hasAsset && (int) $asset->id !== (int) $image->image_asset_id) {
            return 'the Shopify original does not match the current managed backup.';
        }
        $this->attachVerifiedBackup($image, $asset);

        return null;
    }

    /**
     * A Shopify file is usable only when this photograph's MediaImage id is attached to this component product.
     *
     * @return array{bytes:string, extension:?string, filename:string, source_url:string}|string
     */
    private function verifiedShopifyPhotograph(Image $image, Product $component): array|string
    {
        if ((int) $image->product_id !== (int) $component->id) {
            return 'the photograph does not belong to this component product.';
        }
        if ($image->shopify_identity_unresolved) {
            return 'photograph identity is unresolved.';
        }
        if ($image->shopify_media_create_uncertain) {
            return 'the Shopify attachment is uncertain because its media create has not been reconciled.';
        }
        if (app(ImageUploadOperations::class)->unresolvedForCurrentPhotograph($image) !== null) {
            return 'an unresolved Shopify upload operation still belongs to this photograph and its SHA-256.';
        }
        $syncedAssetId = (int) ($image->last_shopify_synced_image_asset_id ?? 0);
        $currentAssetId = (int) ($image->image_asset_id ?? 0);
        if ($syncedAssetId > 0 && $syncedAssetId !== $currentAssetId) {
            return 'the Shopify attachment belongs to a different photograph.';
        }

        $mediaId = trim((string) $image->shopify_id);
        if (preg_match('~^gid://shopify/MediaImage/\d+$~', $mediaId) !== 1) {
            $url = ShopifyMediaPath::rememberableCdnUrl($image->shopify_cdn_url)
                ?? ShopifyMediaPath::rememberableCdnUrl($image->src);
            if ($url === null) {
                return 'the position-1 photograph has no usable CMS file or Shopify URL.';
            }

            // A URL on this CMS source can supply local display bytes. It does
            // not establish a MediaImage identity or authorize publication.
            return $this->downloadStackSource($image, $url, true);
        }

        $productGid = trim((string) $component->shopify_id);
        if ($productGid === '') {
            return 'this component has no Shopify product id, so its attachment cannot be verified.';
        }

        $media = $this->componentMedia($productGid);
        if (isset($media['error'])) {
            return 'Shopify media for this component could not be read. '.$media['error'];
        }

        $node = null;
        foreach ($media['nodes'] ?? [] as $candidate) {
            if (is_array($candidate) && (string) ($candidate['id'] ?? '') === $mediaId) {
                $node = $candidate;
                break;
            }
        }
        if (! is_array($node)) {
            return 'the Shopify MediaImage is not attached to this component product.';
        }
        if (! ShopifyProductMediaReader::ready($node)) {
            return 'the Shopify file is not ready.';
        }

        $url = trim((string) ($node['originalSource']['url'] ?? ''));
        if ($url === '') {
            return 'the Shopify file has no original source.';
        }

        return $this->downloadStackSource($image, $url);
    }

    private function downloadStackSource(Image $image, string $url, bool $validateImage = false): array|string
    {
        try {
            $bytes = app(ImagePhotographEvidence::class)->download($url);
        } catch (\Throwable $exception) {
            return 'the Shopify original could not be downloaded. '.$exception->getMessage();
        }
        if ($validateImage && @getimagesizefromstring($bytes) === false) {
            return 'the Shopify URL did not return a readable image.';
        }

        $filename = basename((string) (parse_url($url, PHP_URL_PATH) ?: ''));
        if ($filename === '' || $filename === '/' || $filename === '.') {
            $filename = 'component-'.$image->id.'.jpg';
        }

        return [
            'bytes' => $bytes,
            'extension' => $this->extensionFromName($filename),
            'filename' => $filename,
            'source_url' => $url,
        ];
    }

    /**
     * @return array{nodes?: array<int, mixed>, error?: string}
     */
    private function componentMedia(string $productGid): array
    {
        if (! array_key_exists($productGid, $this->componentMediaReads)) {
            try {
                $read = app(ShopifyProductMediaReader::class)->read($productGid, 'StackSourceMedia');
                $nodes = $read['media']['nodes'] ?? null;
                $this->componentMediaReads[$productGid] = [
                    'nodes' => is_array($nodes) ? $nodes : [],
                ];
            } catch (\Throwable $exception) {
                $this->componentMediaReads[$productGid] = ['error' => $exception->getMessage()];
            }
        }

        return $this->componentMediaReads[$productGid];
    }

    private function attachVerifiedBackup(Image $image, ImageAsset $asset): void
    {
        $current = (int) $image->image_asset_id;
        if ($current > 0 && $current !== (int) $asset->id) {
            return;
        }

        DB::table('images')->where('id', $image->id)->update([
            'image_asset_id' => $asset->id,
            'backup_status' => Image::BACKUP_STATUS_BACKED_UP,
            'backup_completed_at' => now(),
            'updated_at' => now(),
        ]);
        $image->image_asset_id = $asset->id;
        $image->setRelation('imageAsset', $asset);
    }

    /**
     * @param  array<int, string>  $prepared
     * @param  array<int, mixed>  $staged
     * @return array<int, string>
     */
    private function mergeUnavailableSources(array $prepared, array $staged): array
    {
        $covered = [];
        foreach ($prepared as $reason) {
            if (preg_match('/Image (\d+)/', $reason, $match) === 1) {
                $covered[(int) $match[1]] = true;
            }
        }

        $combined = $prepared;
        foreach ($staged as $reason) {
            $reason = trim((string) $reason);
            if ($reason === '') {
                continue;
            }
            if (preg_match('/Image (\d+)/', $reason, $match) === 1 && isset($covered[(int) $match[1]])) {
                continue;
            }
            $combined[] = $reason;
        }

        return array_values(array_unique($combined));
    }

    /**
     * @param  array{rebuilt?:bool, message?:string, unavailable?:array<int, string>}  $result
     */
    private function classifyStackRebuild(array $result): string
    {
        $unavailable = array_values($result['unavailable'] ?? []);
        $message = (string) ($result['message'] ?? '');
        $rebuilt = (bool) ($result['rebuilt'] ?? false);
        $aborted = ! $rebuilt && (
            $unavailable === []
            || str_contains($message, 'stopped before replacing')
            || str_contains($message, 'membership is unknown')
        );
        if ($rebuilt && $unavailable === []) {
            return ShopifyImageImportBatch::REBUILD_COMPLETED;
        }
        if (! $aborted && $unavailable !== []) {
            return ShopifyImageImportBatch::REBUILD_INCOMPLETE;
        }

        return ShopifyImageImportBatch::REBUILD_FAILED;
    }

    /**
     * @param  array{key: string, owner: string}|null  $claim
     */
    private function markRebuildStatus(Product $product, ShopifyImageImportBatch $batch, string $status, string $message, ?array $claim = null): void
    {
        $stored = $message === '' ? null : Str::limit($message, 2000, '');
        $query = Product::query()
            ->whereKey($product->id)
            ->where(function ($query) use ($batch): void {
                $query->whereNull('image_rebuild_batch_id')
                    ->orWhere('image_rebuild_batch_id', '<=', $batch->id);
            });
        if ($claim !== null) {
            $query->where(function ($query) use ($claim): void {
                $query->whereNull('image_rebuild_owner')
                    ->orWhere('image_rebuild_owner', $claim['owner']);
            })->whereExists(function ($query) use ($claim): void {
                $query->selectRaw('1')
                    ->from('stack_rebuild_claims')
                    ->where('claim_key', $claim['key'])
                    ->where('owner', $claim['owner']);
            });
        }

        $values = [
            'image_rebuild_status' => $status,
            'image_rebuild_message' => $stored,
            'image_rebuild_batch_id' => $batch->id,
            'updated_at' => now(),
        ];
        if ($claim !== null) {
            $values['image_rebuild_owner'] = $claim['owner'];
        }
        if ($query->update($values) === 1) {
            $product->forceFill($values)->syncOriginal();
        }
    }

    /**
     * @param  array<int, int>  $stackProductIds
     * @param  array{key: string, owner: string}|null  $claim
     * @param  array<int, string>  $messages
     */
    private function recordBatchRebuild(
        ShopifyImageImportBatch $batch,
        array $stackProductIds,
        ?array $claim,
        int $rebuilt,
        int $incomplete,
        int $failed,
        int $skipped,
        array $messages,
    ): bool {
        $lostClaim = $claim !== null && ! app(StackRebuildLockStore::class)->owns($claim['key'], $claim['owner']);
        $nothingOwned = $stackProductIds !== [] && $skipped === count($stackProductIds) && $rebuilt + $incomplete + $failed === 0;
        if ($lostClaim || $nothingOwned) {
            return false;
        }

        $batchOutcome = $failed > 0
            ? ShopifyImageImportBatch::REBUILD_FAILED
            : ($incomplete > 0
                ? ShopifyImageImportBatch::REBUILD_INCOMPLETE
                : ShopifyImageImportBatch::REBUILD_COMPLETED);
        $values = [
            'rebuild_status' => $batchOutcome,
            'rebuild_message' => $messages === [] ? null : Str::limit(implode(' | ', array_slice($messages, 0, 8)), 5000, ''),
            'rebuild_stack_product_ids' => json_encode(array_values($stackProductIds)),
            'updated_at' => now(),
        ];
        $query = ShopifyImageImportBatch::query()->whereKey($batch->id);
        if ($claim !== null) {
            $query->whereExists(function ($query) use ($claim): void {
                $query->selectRaw('1')
                    ->from('stack_rebuild_claims')
                    ->where('claim_key', $claim['key'])
                    ->where('owner', $claim['owner']);
            });
        }
        if ($query->update($values) === 0 && $claim !== null) {
            return false;
        }
        $batch->forceFill([
            'rebuild_status' => $batchOutcome,
            'rebuild_message' => $values['rebuild_message'],
            'rebuild_stack_product_ids' => array_values($stackProductIds),
        ])->syncOriginal();

        return true;
    }

    /**
     * @param  array{key: string, owner: string}|null  $claim
     */
    private function rebuildAttemptMayCommit(int $productId, ShopifyImageImportBatch $batch, ?array $claim, ?Product $locked = null): bool
    {
        $current = $locked instanceof Product
            ? $locked->image_rebuild_batch_id
            : Product::query()->whereKey($productId)->value('image_rebuild_batch_id');
        if ($current !== null && (int) $current > (int) $batch->id) {
            return false;
        }
        if ($claim === null) {
            return true;
        }

        return app(StackRebuildLockStore::class)->owns($claim['key'], $claim['owner']);
    }

    private function newerBatchOwns(int $productId, ShopifyImageImportBatch $batch): bool
    {
        $current = Product::query()->whereKey($productId)->value('image_rebuild_batch_id');

        return $current !== null && (int) $current > (int) $batch->id;
    }

    /**
     * @return array{rebuilt:bool, message:string, unavailable:array<int, string>, skipped:bool}
     */
    private function skippedRebuild(): array
    {
        return [
            'rebuilt' => false,
            'message' => '',
            'unavailable' => [],
            'skipped' => true,
        ];
    }

    /**
     * Set the stack compare-at price to the sum of its component prices.
     *
     * @return array{updated:bool, message:string}
     */
    private function rebuildStackCompareAtPrice(Product $stack): array
    {
        $components = $this->linkedComponentProducts($stack);
        if ($components->isEmpty()) {
            return ['updated' => false, 'message' => "{$stack->handle}: compare-at price was not updated because no linked components were found."];
        }

        $missing = [];
        foreach ($components as $component) {
            if (! $component instanceof Product) {
                continue;
            }

            $price = $component->variants()->orderBy('id')->value('price');
            if ($price === null || trim((string) $price) === '') {
                $missing[] = trim((string) ($component->variants()->orderBy('id')->value('sku') ?? ''))
                    ?: ($component->handle ?: ('Product #'.$component->id));
            }
        }

        if ($missing !== []) {
            return [
                'updated' => false,
                'message' => "{$stack->handle}: compare-at price was not updated because these components have no price: ".implode(', ', $missing).'.',
            ];
        }

        $draft = $this->findStackDraft($stack);
        if (! $draft instanceof NewProductDraft) {
            return ['updated' => false, 'message' => "{$stack->handle}: compare-at price was not updated because the stack has no draft."];
        }

        $result = app(StackCompareAtPriceService::class)->applyToDraft($draft);

        return [
            'updated' => $result['updated'],
            'message' => $result['message'],
        ];
    }

    private function findStackDraft(Product $stack): ?NewProductDraft
    {
        $shopifyId = trim((string) ($stack->shopify_id ?? ''));
        $handle = trim((string) ($stack->handle ?? ''));
        if ($shopifyId === '' && $handle === '') {
            return null;
        }

        return NewProductDraft::query()
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
            ->first();
    }

    private function storeS3ImageAsAsset(string $key): ImageAsset
    {
        $disk = Storage::disk($this->diskName());
        $bytes = $disk->get($key);

        if (! is_string($bytes) || $bytes === '') {
            throw new \RuntimeException("S3 object {$key} is empty or could not be read.");
        }

        $extension = $this->extensionFromName($key);
        $mimeType = $this->mimeTypeFromExtension($extension);
        $bucket = trim((string) config('filesystems.disks.'.$this->diskName().'.bucket'));
        $sourceUrl = 's3://'.($bucket !== '' ? $bucket.'/' : '').$key;

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
            if (! $disk->exists($asset->storage_path ?: $storagePath)) {
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

    /**
     * @return Collection<int, Image>
     */
    private function ownedPositionOneImages(Product $stack): Collection
    {
        return $stack->allImages()
            ->active()
            ->whereNull('source_image_id')
            ->where('position', 1)
            ->orderBy('id')
            ->get();
    }

    private function firstComponentShopifyImage(Product $product): ?Image
    {
        return app(ComponentPhotographSelector::class)->selected($product);
    }

    private function inheritedComponentAlt(Image $componentImage): ?string
    {
        $alt = trim((string) ($componentImage->alt_text ?? ''));

        return $alt === '' ? null : $alt;
    }

    /**
     * @param  array<int, int>  $exceptIds
     */
    private function removeStackImagesFromPositionTwo(Product $stack, Image $lifestyle, array $exceptIds = []): int
    {
        // Obsolete copies remain available for explicit review; never delete by position.
        return 0;
    }

    private function pointImageSrcAtManagedRoute(Image $image): void
    {
        $src = $image->desiredSyncSourceUrl();
        if ($src === null) {
            return;
        }

        $cdnUrl = ShopifyMediaPath::rememberableCdnUrl($image->shopify_cdn_url)
            ?? ShopifyMediaPath::rememberableCdnUrl($image->src);

        Image::withoutEvents(function () use ($image, $src, $cdnUrl): void {
            $updates = ['src' => $src];
            if ($cdnUrl !== null) {
                $updates['shopify_cdn_url'] = $cdnUrl;
            }
            $image->forceFill($updates)->save();
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
     * @return Collection<int, Product>
     */
    private function linkedComponentProducts(Product $stack): Collection
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
        return app(StackComponentCatalog::class)->componentIds($stack);
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
            if ($shopifyId !== '' && ! isset($productsByShopifyId[$shopifyId])) {
                $productsByShopifyId[$shopifyId] = $product;
            }

            $handle = trim((string) ($product->handle ?? ''));
            if ($handle !== '' && ! isset($productsByHandle[$handle])) {
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

    /**
     * A bare filename is position 1. A trailing -N is the next gallery slot, so -1 is position 2.
     * A trailing -vN is the filename revision and does not change the position.
     *
     * @return array{sku:string, position:int, filename_version:int}
     */
    private function imageIdentityFromKey(string $key): array
    {
        $stem = trim((string) pathinfo($key, PATHINFO_FILENAME));
        if ($stem === '') {
            return ['sku' => '', 'position' => 1, 'filename_version' => 1];
        }

        if ($this->findProductBySku($stem) instanceof Product) {
            return ['sku' => $stem, 'position' => 1, 'filename_version' => 1];
        }

        $filenameVersion = 1;
        $body = $stem;
        if (preg_match('/^(.+)-v(\d+)$/i', $stem, $versionMatch) === 1 && (int) $versionMatch[2] >= 1) {
            $filenameVersion = (int) $versionMatch[2];
            $body = trim($versionMatch[1]);
        }

        if ($body !== '' && $this->findProductBySku($body) instanceof Product) {
            return ['sku' => $body, 'position' => 1, 'filename_version' => $filenameVersion];
        }

        if (preg_match('/^(.+)-(\d+)$/', $body, $positionMatch) === 1) {
            $suffix = (int) $positionMatch[2];
            $base = trim($positionMatch[1]);
            if ($suffix >= 1 && $base !== '' && $this->findProductBySku($base) instanceof Product) {
                return ['sku' => $base, 'position' => $suffix + 1, 'filename_version' => $filenameVersion];
            }
        }

        return ['sku' => $stem, 'position' => 1, 'filename_version' => 1];
    }

    /**
     * A repeated claim for any filename version rejects the whole slot.
     * Unique versions keep the highest one.
     *
     * @param  array<int, string>  $keys
     * @return array<string, string>
     */
    private function rejectedFilenameVersions(array $keys): array
    {
        $groups = [];
        foreach ($keys as $key) {
            $identity = $this->imageIdentityFromKey($key);
            $product = $this->findProductBySku($identity['sku']);
            if ($identity['sku'] === '' || ! $product instanceof Product) {
                continue;
            }
            $groups[$product->id.'|'.$identity['position']][$key] = $identity;
        }

        $rejected = [];
        foreach ($groups as $members) {
            if (count($members) < 2) {
                continue;
            }
            $versionCounts = [];
            foreach ($members as $identity) {
                $version = (int) $identity['filename_version'];
                $versionCounts[$version] = ($versionCounts[$version] ?? 0) + 1;
            }
            $duplicatedVersions = array_keys(array_filter($versionCounts, fn (int $count): bool => $count > 1));
            if ($duplicatedVersions !== []) {
                sort($duplicatedVersions, SORT_NUMERIC);
                $names = array_map(fn (string $key): string => basename($key), array_keys($members));
                sort($names, SORT_STRING);
                $list = implode(', ', $names);
                $versionList = implode(', ', $duplicatedVersions);
                foreach ($members as $key => $identity) {
                    $rejected[$key] = "Position {$identity['position']} has more than one file for filename version {$versionList} ({$list}). None were imported.";
                }

                continue;
            }
            $highest = max(array_map(fn (array $identity): int => $identity['filename_version'], $members));
            $winners = array_filter($members, fn (array $identity): bool => $identity['filename_version'] === $highest);
            if (count($winners) > 1) {
                $names = array_map(fn (string $key): string => basename($key), array_keys($winners));
                sort($names, SORT_STRING);
                $list = implode(', ', $names);
                foreach ($members as $key => $identity) {
                    $rejected[$key] = "Position {$identity['position']} has more than one file at filename version {$highest} ({$list}). None were imported.";
                }

                continue;
            }
            $winnerKey = (string) array_key_first($winners);
            $winnerName = basename($winnerKey);
            foreach ($members as $key => $identity) {
                if ($key === $winnerKey) {
                    continue;
                }
                $rejected[$key] = "Position {$identity['position']} kept filename version {$highest} from {$winnerName}. ".basename($key)." is filename version {$identity['filename_version']} and was not imported.";
            }
        }

        return $rejected;
    }

    /**
     * @return array{matched:bool, updated:bool, failed:bool, affected_stack_product_id:?int, updated_product_id:?int, item_id:int, image_id:?int}
     */
    private function rejectImageKey(ShopifyImageImportBatch $batch, string $key, string $message): array
    {
        $identity = $this->imageIdentityFromKey($key);
        $product = $this->findProductBySku($identity['sku']);
        $item = ShopifyImageImportItem::query()->updateOrCreate(
            [
                'batch_id' => $batch->id,
                's3_key' => $key,
            ],
            [
                'sku' => $identity['sku'],
                'product_id' => $product?->id,
                'shopify_product_id' => $product?->shopify_id,
                'status' => ShopifyImageImportItem::STATUS_FAILED,
                'message' => Str::limit($message, 2000, ''),
                'alt_message' => null,
            ],
        );

        return $this->imageImportResult($item, true, true, null, null, null);
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

        return $safe !== '' ? $safe : 'shopify-image.'.($this->extensionFromName($filename) ?: 'jpg');
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

    /**
     * @param  array<int, array{item_id:int, s3_key:string, basename:string, match_key:string, image_id:?int, updated:bool, failed:bool}>  $outcomes
     * @return array{found:bool, applied:int, missing:int, unchanged:int, preserved:int, errors:int}
     */
    private function applyImportedAltText(ShopifyImageImportBatch $batch, string $prefix, array $outcomes): array
    {
        $empty = ['found' => false, 'applied' => 0, 'missing' => 0, 'unchanged' => 0, 'preserved' => 0, 'errors' => 0];

        try {
            $csv = $this->readAltTextCsv($prefix);
        } catch (\Throwable $e) {
            logger()->error('Shopify image import could not read alt-text.csv.', [
                'batch_id' => $batch->id,
                'prefix' => $prefix,
                'message' => $e->getMessage(),
            ]);
            $csv = [
                'found' => true,
                'valid' => false,
                'rows' => [],
                'errors' => [[
                    'kind' => 'error',
                    'filename' => 'alt-text.csv',
                    'message' => 'alt-text.csv could not be read. No alt text was applied.',
                ]],
            ];
        }

        if (! $csv['found']) {
            $this->storeAltTextResult($batch, $empty, []);

            return $empty;
        }

        $result = $csv['valid']
            ? $this->matchAltTextRows($outcomes, $csv['rows'], $csv['errors'])
            : [
                'applied' => 0,
                'missing' => 0,
                'unchanged' => 0,
                'preserved' => 0,
                'errors' => count($csv['errors']),
                'report' => $csv['errors'],
            ];

        $stored = [
            'found' => true,
            'applied' => $result['applied'],
            'missing' => $result['missing'],
            'unchanged' => $result['unchanged'],
            'preserved' => $result['preserved'],
            'errors' => $result['errors'],
        ];
        $this->storeAltTextResult($batch, $stored, $result['report']);

        return $stored;
    }

    /**
     * @param  array<int, array{item_id:int, s3_key:string, basename:string, match_key:string, image_id:?int, updated:bool, failed:bool}>  $outcomes
     * @param  array<int, array{row:int, filename:string, match_key:string, alt_text:string}>  $rows
     * @param  array<int, array{kind:string, filename:string, message:string}>  $errors
     * @return array{applied:int, missing:int, unchanged:int, preserved:int, errors:int, report:array<int, array{kind:string, filename:string, message:string}>}
     */
    private function matchAltTextRows(array $outcomes, array $rows, array $errors): array
    {
        $applied = 0;
        $missing = 0;
        $unchanged = 0;
        $preserved = 0;
        $report = $errors;
        $byKey = [];
        $survivorKeyByImage = [];

        foreach ($outcomes as $outcome) {
            $byKey[$outcome['match_key']] = $outcome;
            if ($outcome['updated'] && $outcome['image_id'] !== null) {
                $survivorKeyByImage[$outcome['image_id']] = $outcome['match_key'];
            }
        }

        $duplicateKeys = [];
        $seenKeys = [];
        foreach ($rows as $row) {
            if ($row['match_key'] === '') {
                continue;
            }
            if (isset($seenKeys[$row['match_key']])) {
                $duplicateKeys[$row['match_key']] = $row['filename'];
            }
            $seenKeys[$row['match_key']] = $row['filename'];
        }

        $handledImages = [];

        foreach ($rows as $row) {
            if ($row['match_key'] === '') {
                $report[] = [
                    'kind' => 'error',
                    'filename' => $row['filename'] !== '' ? $row['filename'] : 'row '.$row['row'],
                    'message' => 'Row '.$row['row'].' has no filename.',
                ];

                continue;
            }

            if (isset($duplicateKeys[$row['match_key']])) {
                $duplicateOutcome = $byKey[$row['match_key']] ?? null;
                if (
                    is_array($duplicateOutcome)
                    && $duplicateOutcome['image_id'] !== null
                    && ($survivorKeyByImage[$duplicateOutcome['image_id']] ?? null) === $row['match_key']
                ) {
                    $handledImages[$duplicateOutcome['image_id']] = true;
                }
                $report[] = [
                    'kind' => 'error',
                    'filename' => $row['filename'],
                    'message' => 'Row '.$row['row'].' duplicates filename '.$duplicateKeys[$row['match_key']].'. Neither row was applied.',
                ];
                $this->rememberAltMessage(
                    is_array($duplicateOutcome) ? $duplicateOutcome['item_id'] : null,
                    'Duplicate alt-text.csv filename. Alt text was not applied.'
                );

                continue;
            }

            $outcome = $byKey[$row['match_key']] ?? null;
            if ($outcome === null) {
                $report[] = [
                    'kind' => 'error',
                    'filename' => $row['filename'],
                    'message' => 'No image imported from this folder matched '.$row['filename'].'.',
                ];

                continue;
            }

            if (! $outcome['updated'] || $outcome['image_id'] === null) {
                $report[] = [
                    'kind' => 'error',
                    'filename' => $row['filename'],
                    'message' => 'The image import failed, so this alt text was not applied.',
                ];
                $this->rememberAltMessage($outcome['item_id'], 'Alt text was not applied because the image import failed.');

                continue;
            }

            if (($survivorKeyByImage[$outcome['image_id']] ?? null) !== $outcome['match_key']) {
                $winner = $byKey[$survivorKeyByImage[$outcome['image_id']]]['basename'] ?? 'another file';
                $report[] = [
                    'kind' => 'error',
                    'filename' => $row['filename'],
                    'message' => $row['filename'].' was replaced by '.$winner.' during import, so its alt text was not applied.',
                ];
                $this->rememberAltMessage($outcome['item_id'], 'Alt text was not applied because '.$winner.' replaced this file.');

                continue;
            }

            if (trim($row['alt_text']) === '') {
                $missing++;
                $handledImages[$outcome['image_id']] = true;
                $report[] = [
                    'kind' => 'missing',
                    'filename' => $row['filename'],
                    'message' => 'Alt text is blank, so the existing description was left unchanged.',
                ];
                $this->rememberAltMessage($outcome['item_id'], 'Alt text is blank. The existing description was left unchanged.');

                continue;
            }

            if (mb_strlen(trim($row['alt_text'])) > 255) {
                $report[] = [
                    'kind' => 'error',
                    'filename' => $row['filename'],
                    'message' => 'Alt text exceeds the 255 character limit and was not saved.',
                ];
                $this->rememberAltMessage($outcome['item_id'], 'Alt text exceeds the 255 character limit and was not saved.');
                $handledImages[$outcome['image_id']] = true;

                continue;
            }

            $image = Image::query()->find($outcome['image_id']);
            if (! $image instanceof Image) {
                $report[] = [
                    'kind' => 'error',
                    'filename' => $row['filename'],
                    'message' => 'The imported image record was not available, so alt text was not applied.',
                ];
                $this->rememberAltMessage($outcome['item_id'], 'Alt text was not applied because the image record was not available.');
                $handledImages[$outcome['image_id']] = true;

                continue;
            }

            try {
                $stage = $this->altText->stageImportedAlt(
                    $image,
                    $row['alt_text'],
                    isset($outcome['image_asset_id']) ? (int) $outcome['image_asset_id'] : null,
                    isset($outcome['photograph_version']) ? (int) $outcome['photograph_version'] : null,
                );
            } catch (\Throwable $e) {
                $report[] = [
                    'kind' => 'error',
                    'filename' => $row['filename'],
                    'message' => $e->getMessage(),
                ];
                $this->rememberAltMessage($outcome['item_id'], 'Alt text was not applied. '.$e->getMessage());
                $handledImages[$outcome['image_id']] = true;

                continue;
            }

            $handledImages[$outcome['image_id']] = true;
            if ($stage === 'applied') {
                $applied++;
                $this->rememberAltMessage($outcome['item_id'], 'Alt text saved for review.');

                continue;
            }
            if ($stage === 'preserved') {
                $preserved++;
                $report[] = [
                    'kind' => 'preserved',
                    'filename' => $row['filename'],
                    'message' => 'A newer CMS description was kept.',
                ];
                $this->rememberAltMessage($outcome['item_id'], 'A newer CMS description was kept.');

                continue;
            }

            $unchanged++;
            $this->rememberAltMessage($outcome['item_id'], 'Alt text already matched. Approval state was left unchanged.');
        }

        foreach ($survivorKeyByImage as $imageId => $matchKey) {
            if (isset($handledImages[$imageId])) {
                continue;
            }
            $outcome = $byKey[$matchKey];
            $missing++;
            $report[] = [
                'kind' => 'missing',
                'filename' => $outcome['basename'],
                'message' => 'No alt-text.csv row matched this imported file.',
            ];
            $this->rememberAltMessage($outcome['item_id'], 'No alt text was supplied for this file.');
        }

        return [
            'applied' => $applied,
            'missing' => $missing,
            'unchanged' => $unchanged,
            'preserved' => $preserved,
            'errors' => count(array_filter($report, fn (array $row): bool => ($row['kind'] ?? '') === 'error')),
            'report' => $report,
        ];
    }

    /**
     * @param  array{found:bool, applied:int, missing:int, unchanged:int, preserved:int, errors:int}  $result
     * @param  array<int, array{kind:string, filename:string, message:string}>  $report
     */
    private function storeAltTextResult(ShopifyImageImportBatch $batch, array $result, array $report): void
    {
        $batch->forceFill([
            'alt_text_csv_found' => $result['found'],
            'alt_text_applied' => $result['applied'],
            'alt_text_missing' => $result['missing'],
            'alt_text_unchanged' => $result['unchanged'],
            'alt_text_preserved' => $result['preserved'],
            'alt_text_error_count' => $result['errors'],
            'alt_text_report' => $report === [] ? null : array_values($report),
        ])->save();
    }

    private function rememberAltMessage(?int $itemId, string $message): void
    {
        if ($itemId === null || $itemId < 1) {
            return;
        }

        ShopifyImageImportItem::query()->whereKey($itemId)->update([
            'alt_message' => Str::limit($message, 2000, ''),
        ]);
    }

    /**
     * @return array{found:bool, valid:bool, rows:array<int, array{row:int, filename:string, match_key:string, alt_text:string}>, errors:array<int, array{kind:string, filename:string, message:string}>}
     */
    private function readAltTextCsv(string $prefix): array
    {
        $disk = Storage::disk($this->diskName());
        $key = $this->altTextCsvKey($prefix);
        if (! $disk->exists($key)) {
            return ['found' => false, 'valid' => false, 'rows' => [], 'errors' => []];
        }

        $contents = $disk->get($key);
        if (! is_string($contents) || trim($contents) === '') {
            return [
                'found' => true,
                'valid' => false,
                'rows' => [],
                'errors' => [[
                    'kind' => 'error',
                    'filename' => 'alt-text.csv',
                    'message' => 'alt-text.csv is empty. No alt text was applied.',
                ]],
            ];
        }

        $contents = ltrim(str_replace("\r\n", "\n", $contents), "\xEF\xBB\xBF");
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('alt-text.csv could not be read.');
        }
        fwrite($handle, $contents);
        rewind($handle);
        $headers = fgetcsv($handle);
        if (! is_array($headers)) {
            fclose($handle);

            return [
                'found' => true,
                'valid' => false,
                'rows' => [],
                'errors' => [[
                    'kind' => 'error',
                    'filename' => 'alt-text.csv',
                    'message' => 'alt-text.csv must include filename and alt_text columns. No alt text was applied.',
                ]],
            ];
        }

        $columns = [];
        foreach ($headers as $index => $header) {
            $name = $this->altTextHeader((string) $header);
            if ($name === '') {
                continue;
            }
            if (isset($columns[$name])) {
                fclose($handle);

                return [
                    'found' => true,
                    'valid' => false,
                    'rows' => [],
                    'errors' => [[
                        'kind' => 'error',
                        'filename' => 'alt-text.csv',
                        'message' => 'alt-text.csv repeats the '.$name.' column. No alt text was applied.',
                    ]],
                ];
            }
            $columns[$name] = $index;
        }

        if (! isset($columns['filename'], $columns['alt_text'])) {
            fclose($handle);

            return [
                'found' => true,
                'valid' => false,
                'rows' => [],
                'errors' => [[
                    'kind' => 'error',
                    'filename' => 'alt-text.csv',
                    'message' => 'alt-text.csv must include filename and alt_text columns. No alt text was applied.',
                ]],
            ];
        }

        $rows = [];
        $rowNumber = 1;
        while (($values = fgetcsv($handle)) !== false) {
            $rowNumber++;
            if ($values === [null] || (count($values) === 1 && trim((string) $values[0]) === '')) {
                continue;
            }
            if (str_starts_with(trim((string) ($values[0] ?? '')), '#')) {
                continue;
            }
            $filename = $this->imageBasename(trim((string) ($values[$columns['filename']] ?? '')));
            $rows[] = [
                'row' => $rowNumber,
                'filename' => $filename,
                'match_key' => $this->altTextMatchKey($filename),
                'alt_text' => trim((string) ($values[$columns['alt_text']] ?? '')),
            ];
        }
        fclose($handle);

        return ['found' => true, 'valid' => true, 'rows' => $rows, 'errors' => []];
    }

    private function altTextHeader(string $header): string
    {
        $header = strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $header) ?? $header));
        $header = str_replace([' ', '-'], '_', $header);

        return match ($header) {
            'file_name', 'image_filename', 'image_file' => 'filename',
            'alt', 'image_alt', 'image_alt_text' => 'alt_text',
            default => $header,
        };
    }

    private function altTextMatchKey(string $filename): string
    {
        return mb_strtolower($this->imageBasename($filename));
    }

    private function imageBasename(string $path): string
    {
        return basename(str_replace('\\', '/', trim($path)));
    }

    private function altTextCsvKey(string $prefix): string
    {
        return trim($prefix, '/').'/alt-text.csv';
    }

    /**
     * @param  array<int, string>  $filenames
     */
    private function altTextTemplateDocument(array $filenames): string
    {
        $handle = fopen('php://temp', 'r+b');
        if ($handle === false) {
            throw new \RuntimeException('The alt text template could not be created.');
        }

        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, ['filename', 'alt_text'], ',', '"', '\\');
        foreach ($filenames as $filename) {
            fputcsv($handle, [$filename, ''], ',', '"', '\\');
        }
        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        if (! is_string($csv) || $csv === '') {
            throw new \RuntimeException('The alt text template could not be created.');
        }

        return $csv;
    }
}
