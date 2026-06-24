<?php

namespace App\Services;

use App\Jobs\ProductImageBackupImagesJob;
use App\Models\Import;
use App\Models\Product;
use App\Models\Category;
use App\Models\Color;
use App\Models\StyleProfile;
use App\Models\Status;
use App\Models\Type;
use App\Models\ShopifyRow;
use App\Models\RequiredField;
use App\Models\Variant;
use App\Models\Image;
use App\Models\Tag;
use App\Models\DropdownOption;
use Illuminate\Support\Facades\DB;
use App\Services\TagNormalizer;
use App\Services\DropdownCollectionCatalog;

final class Normalizer
{
    public function buildNormalizedTables(Import $import): void
    {
        $imageIdsNeedingBackup = [];

        DB::transaction(function () use ($import, &$imageIdsNeedingBackup) {
            $rows = ShopifyRow::where('import_id', $import->id)
                ->whereNotNull('handle')
                ->orderBy('row_index')
                ->get()
                ->groupBy('handle');

            $currentHandles = $rows->keys()
                ->map(fn ($handle) => trim((string) $handle))
                ->filter(fn (string $handle): bool => $handle !== '')
                ->values();

            // Keep normalized catalog as latest Shopify snapshot:
            // - remove products no longer present in latest sync
            // - deduplicate to a single row per handle
            if ($currentHandles->isNotEmpty()) {
                Product::query()
                    ->whereNotIn('handle', $currentHandles->all())
                    ->delete();
            } else {
                Product::query()->delete();
            }

            $existingByHandle = Product::query()
                ->whereIn('handle', $currentHandles->all())
                ->orderBy('id')
                ->get()
                ->groupBy('handle');

            foreach ($rows as $handle => $handleRows) {
                /** @var ShopifyRow $primary */
                $primary = $handleRows->firstWhere('row_type', 'product_primary') ?? $handleRows->first();
                if ($primary) {
                    $data = $primary->data ?? [];
                    $targetGender = trim((string) ($data[HeaderStore::TARGET_GENDER] ?? ''));
                    $ageGroup = trim((string) ($data[HeaderStore::AGE_GROUP] ?? ''));
                    $jewelryType = trim((string) ($data[HeaderStore::JEWELRY_TYPE] ?? ''));
                    $googleShoppingAgeGroup = trim((string) ($data[HeaderStore::GOOGLE_SHOPPING_AGE_GROUP] ?? ''));

                    $updated = false;
                    if (strtolower($targetGender) !== 'unisex') {
                        $data[HeaderStore::TARGET_GENDER] = 'unisex';
                        $updated = true;
                    }
                    if (strtolower($ageGroup) !== 'universal') {
                        $data[HeaderStore::AGE_GROUP] = 'universal';
                        $updated = true;
                    }
                    if (strtolower($jewelryType) !== 'handcrafted-jewellery') {
                        $data[HeaderStore::JEWELRY_TYPE] = 'handcrafted-jewellery';
                        $updated = true;
                    }
                    if (strtolower($googleShoppingAgeGroup) !== 'adult') {
                        $data[HeaderStore::GOOGLE_SHOPPING_AGE_GROUP] = 'adult';
                        $updated = true;
                    }

                    if ($updated) {
                        $primary->data = $data;
                        $primary->save();
                    }
                }

                $categoryName = $primary->get(HeaderStore::PRODUCT_CATEGORY, null);
                $typeName = $primary->get(HeaderStore::TYPE, null);
                $googleCategory = $primary->get(HeaderStore::GOOGLE_PRODUCT_CATEGORY, null);

                $resolved = CategoryTypeMap::resolve($categoryName, $typeName, $googleCategory);
                $resolvedCategory = $this->normalizeValue($resolved['category'] ?? null);
                $resolvedType = $this->normalizeValue($resolved['type'] ?? null);
                $resolvedGoogle = $this->normalizeValue($resolved['google_product_category'] ?? null);

                $normalizedColor = $this->normalizeColorString($primary->get(HeaderStore::COLOR_METAFIELD, null));
                $normalizedTags = TagNormalizer::normalizeString($primary->get(HeaderStore::TAGS, null));
                $collectionContext = $this->resolveCollectionContext($normalizedTags);

                $this->capturePendingDropdownOptions($primary, $collectionContext);

                $this->syncCategory($resolvedCategory, $resolvedGoogle);
                $this->syncColors($normalizedColor);
                $this->syncTags($normalizedTags);
                $this->syncStatus($primary->get(HeaderStore::STATUS, null));
                $this->syncType($resolvedType, $resolvedGoogle);

                $payload = [
                    'import_id' => $import->id,
                    'handle' => $handle,
                    'title' => $primary->get(HeaderStore::TITLE, null),
                    'body_html' => $primary->get(HeaderStore::BODY_HTML, null),
                    'vendor' => $primary->get(HeaderStore::VENDOR, null),
                    'tags' => $normalizedTags,
                    'type' => $resolvedType ?? $primary->get(HeaderStore::TYPE, null),
                    'published' => $primary->get(HeaderStore::PUBLISHED, null),
                    'product_category' => $resolvedCategory ?? $categoryName,
                    'google_product_category' => $resolvedGoogle ?? $googleCategory,
                    'status' => $primary->get(HeaderStore::STATUS, null),
                    'seo_title' => $primary->get(HeaderStore::SEO_TITLE, null),
                    'seo_description' => $primary->get(HeaderStore::SEO_DESCRIPTION, null),
                    'uvp_short_paragraph' => $primary->get(HeaderStore::UVP_SHORT_PARAGRAPH, null),
                    'color_string' => $normalizedColor,
                    'batch' => $this->defaultBatchForImport($import),
                    'is_bundle' => $this->isBundleFromTags($normalizedTags),
                ];

                $existingForHandle = $existingByHandle->get($handle, collect());
                $product = $existingForHandle->first();
                if ($product) {
                    // Drop duplicate legacy rows for this handle, keep first stable row.
                    $duplicateIds = $existingForHandle->skip(1)->pluck('id')->all();
                    if (!empty($duplicateIds)) {
                        Product::query()->whereIn('id', $duplicateIds)->delete();
                    }

                    $product->fill($payload);
                    $product->saveQuietly();
                } else {
                    $product = Product::create($payload);
                }

                // Variants (include primary row if it contains variant data)
                $variantRows = $handleRows->filter(function (ShopifyRow $r) {
                    return $r->variant_key !== null;
                });
                $firstSku = $this->reconcileVariants($product, $variantRows);

                // Images
                $imageRows = $handleRows->filter(function (ShopifyRow $r) {
                    return trim((string)$r->get(HeaderStore::IMAGE_SRC, '')) !== '';
                });
                $imageRow = $imageRows
                    ->sortBy(fn (ShopifyRow $row) => (int) ($row->get(HeaderStore::IMAGE_POSITION, 0) ?: 0))
                    ->first();
                $imageUrl = $this->normalizeValue($imageRow?->get(HeaderStore::IMAGE_SRC, null));
                $imageAlt = $this->normalizeValue($imageRow?->get(HeaderStore::IMAGE_ALT_TEXT, null));

                $imageIdsNeedingBackup = array_merge(
                    $imageIdsNeedingBackup,
                    $this->reconcileImages($product, $imageRows)
                );

                $existingStyleProfile = StyleProfile::where('handle', $handle)->first();
                if ($existingStyleProfile) {
                    $existingStyleProfile->update([
                        'product_id' => $product->id,
                        'seo_sync_status' => 'draft',
                        'seo_synced_at' => null,
                    ]);
                } else {
                    StyleProfile::create([
                        'product_id' => $product->id,
                        'handle' => $handle,
                        'sku' => $firstSku ?? $handle,
                        'image_url' => $imageUrl,
                        'draft_title' => $this->normalizeValue($product->title),
                        'draft_description' => $this->normalizeValue($product->body_html),
                        'draft_seo_title' => $this->normalizeValue($product->seo_title),
                        'draft_seo_description' => $this->normalizeValue($product->seo_description),
                        'draft_image_alt_text' => $imageAlt,
                        'seo_sync_status' => 'draft',
                        'seo_synced_at' => null,
                    ]);
                }

                $resolvedForErrors = CategoryTypeMap::resolve(
                    $product->product_category,
                    $product->type,
                    $product->google_product_category
                );

                $errors = $this->buildErrorFields(
                    $product,
                    $primary,
                    $handleRows,
                    $resolvedForErrors
                );

                Product::withoutEvents(function () use ($product, $errors): void {
                    $product->forceFill([
                        'has_errors' => !empty($errors),
                        'error_fields' => $errors,
                    ])->save();
                });
            }
        });

        $imageIdsNeedingBackup = array_values(array_unique(array_map('intval', $imageIdsNeedingBackup)));
        foreach (array_chunk($imageIdsNeedingBackup, 100) as $chunk) {
            ProductImageBackupImagesJob::dispatch($chunk, $import->created_by, 'Shopify image change backup');
        }
    }

    /**
     * @param \Illuminate\Support\Collection<int, ShopifyRow> $variantRows
     */
    private function reconcileVariants(Product $product, $variantRows): ?string
    {
        $existingVariants = $product->allVariants()
            ->orderBy('id')
            ->get();

        $seenVariantIds = [];
        $firstSku = null;
        $syncedAt = now();

        foreach ($variantRows->values() as $index => $vr) {
            $sku = $this->normalizeValue($vr->get(HeaderStore::VARIANT_SKU, null));
            $barcode = $this->normalizeValue($vr->get(HeaderStore::VARIANT_BARCODE, null)) ?? $sku;
            $weightUnit = $this->normalizeValue($vr->get(HeaderStore::VARIANT_WEIGHT_UNIT, null)) ?? 'g';
            $shopifyId = $this->normalizeValue($vr->get(HeaderStore::INTERNAL_VARIANT_SHOPIFY_ID, null));

            if ($firstSku === null && $sku !== null) {
                $firstSku = $sku;
            }

            $payload = [
                'product_id' => $product->id,
                'shopify_id' => $shopifyId,
                'sku' => $sku,
                'barcode' => $barcode,
                'weight' => $this->toDecimal($vr->get(HeaderStore::VARIANT_GRAMS, null)),
                'weight_unit' => $weightUnit,
                'inventory_tracked' => $this->toBoolean($vr->get(HeaderStore::INTERNAL_VARIANT_INVENTORY_TRACKED, null)),
                'inventory_qty' => $this->toBoolean($vr->get(HeaderStore::INTERNAL_VARIANT_INVENTORY_TRACKED, null)) === false
                    ? null
                    : $this->toInteger($vr->get(HeaderStore::VARIANT_INVENTORY_QTY, null)),
                'option1_name' => $vr->get(HeaderStore::OPTION1_NAME, null),
                'option1_value' => $vr->get(HeaderStore::OPTION1_VALUE, null),
                'option2_name' => $vr->get(HeaderStore::OPTION2_NAME, null),
                'option2_value' => $vr->get(HeaderStore::OPTION2_VALUE, null),
                'option3_name' => $vr->get(HeaderStore::OPTION3_NAME, null),
                'option3_value' => $vr->get(HeaderStore::OPTION3_VALUE, null),
                'price' => $this->toDecimal($vr->get(HeaderStore::VARIANT_PRICE, null)),
                'compare_at_price' => $this->toDecimal($vr->get(HeaderStore::VARIANT_COMPARE_AT, null)),
                'position' => $index + 1,
            ];

            $existingVariant = $this->resolveExistingVariant($existingVariants, $vr, $shopifyId, $seenVariantIds);

            if ($existingVariant) {
                $this->syncInboundVariant($existingVariant, $payload, $syncedAt);
                $seenVariantIds[] = $existingVariant->id;
                continue;
            }

            $createdVariant = null;
            Variant::withoutEvents(function () use ($payload, $syncedAt, &$createdVariant): void {
                $createdVariant = Variant::create(array_merge($payload, [
                    'sync_state' => Variant::SYNC_STATE_SYNCED,
                    'local_dirty' => false,
                    'inventory_local_dirty' => false,
                    'inventory_sync_error' => null,
                    'last_shopify_seen_at' => $syncedAt,
                    'last_synced_at' => $syncedAt,
                    'inventory_last_synced_at' => $syncedAt,
                ]));
            });

            if ($createdVariant) {
                $seenVariantIds[] = $createdVariant->id;
                $existingVariants->push($createdVariant);
            }
        }

        $existingVariants
            ->whereNotIn('id', $seenVariantIds)
            ->each(function (Variant $variant) use ($syncedAt): void {
                $this->markVariantMissingFromShopify($variant, $syncedAt);
            });

        return $firstSku;
    }

    /**
     * @param \Illuminate\Support\Collection<int, ShopifyRow> $imageRows
     */
    private function reconcileImages(Product $product, $imageRows): array
    {
        $existingImages = $product->allImages()
            ->orderBy('id')
            ->get();

        $seenImageIds = [];
        $syncedAt = now();
        $imageIdsNeedingBackup = [];

        foreach ($imageRows as $ir) {
            $shopifyId = $this->normalizeValue($ir->get(HeaderStore::INTERNAL_IMAGE_SHOPIFY_ID, null));
            $payload = [
                'product_id' => $product->id,
                'shopify_id' => $shopifyId,
                'src' => $ir->get(HeaderStore::IMAGE_SRC, null),
                'position' => (int) ($ir->get(HeaderStore::IMAGE_POSITION, 0) ?: 0) ?: null,
                'alt_text' => $ir->get(HeaderStore::IMAGE_ALT_TEXT, null),
            ];

            $existingImage = $this->resolveExistingImage($existingImages, $ir, $shopifyId, $seenImageIds);

            if ($existingImage) {
                if ($this->syncInboundImage($existingImage, $payload, $syncedAt)) {
                    $imageIdsNeedingBackup[] = $existingImage->id;
                }
                $seenImageIds[] = $existingImage->id;
                continue;
            }

            $createdImage = null;
            Image::withoutEvents(function () use ($payload, $syncedAt, &$createdImage): void {
                $createdImage = Image::create(array_merge($payload, [
                    'sync_state' => Image::SYNC_STATE_SYNCED,
                    'local_dirty' => false,
                    'image_asset_id' => null,
                    'backup_status' => Image::BACKUP_STATUS_PENDING,
                    'backup_completed_at' => null,
                    'backup_error' => null,
                    'last_shopify_seen_at' => $syncedAt,
                    'last_synced_at' => $syncedAt,
                ]));
            });

            if ($createdImage) {
                $seenImageIds[] = $createdImage->id;
                $existingImages->push($createdImage);
                $imageIdsNeedingBackup[] = $createdImage->id;
            }
        }

        $existingImages
            ->whereNotIn('id', $seenImageIds)
            ->each(function (Image $image) use ($syncedAt, &$imageIdsNeedingBackup): void {
                if ($this->markImageMissingFromShopify($image, $syncedAt)) {
                    $imageIdsNeedingBackup[] = $image->id;
                }
            });

        return $imageIdsNeedingBackup;
    }

    /**
     * Preserve tool-owned edits when a synced row is already locally dirty.
     *
     * @param array<string, mixed> $payload
     */
    private function syncInboundVariant(Variant $variant, array $payload, $syncedAt): void
    {
        Variant::withoutEvents(function () use ($variant, $payload, $syncedAt): void {
            if ($variant->local_dirty) {
                $updates = [
                    'product_id' => $payload['product_id'],
                    'shopify_id' => $payload['shopify_id'] ?? $variant->shopify_id,
                    'last_shopify_seen_at' => $syncedAt,
                ];

                if (
                    $variant->sync_state !== Variant::SYNC_STATE_LOCAL_DELETED
                    && $this->variantPayloadDiffers($variant, $payload)
                ) {
                    $updates['sync_state'] = Variant::SYNC_STATE_CONFLICT;
                }

                $variant->fill($updates)->save();
                return;
            }

            $variant->fill(array_merge($payload, [
                'sync_state' => Variant::SYNC_STATE_SYNCED,
                'local_dirty' => false,
                'inventory_local_dirty' => false,
                'inventory_sync_error' => null,
                'last_shopify_seen_at' => $syncedAt,
                'last_synced_at' => $syncedAt,
                'inventory_last_synced_at' => $syncedAt,
            ]))->save();
        });
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function syncInboundImage(Image $image, array $payload, $syncedAt): bool
    {
        $wasLocalDirty = (bool) $image->local_dirty;
        $sourceChanged = $this->normalizeComparableValue($image->getAttribute('src')) !== $this->normalizeComparableValue($payload['src'] ?? null);
        $shopifyIdChanged = $this->normalizeComparableValue($image->getAttribute('shopify_id')) !== $this->normalizeComparableValue($payload['shopify_id'] ?? null);

        Image::withoutEvents(function () use ($image, $payload, $syncedAt): void {
            if ($image->local_dirty) {
                $updates = [
                    'product_id' => $payload['product_id'],
                    'shopify_id' => $payload['shopify_id'] ?? $image->shopify_id,
                    'last_shopify_seen_at' => $syncedAt,
                ];

                if (
                    $image->sync_state !== Image::SYNC_STATE_LOCAL_DELETED
                    && $this->imagePayloadDiffers($image, $payload)
                ) {
                    $updates['sync_state'] = Image::SYNC_STATE_CONFLICT;
                }

                $image->fill($updates)->save();
                return;
            }

            $image->fill(array_merge($payload, [
                'sync_state' => Image::SYNC_STATE_SYNCED,
                'local_dirty' => false,
                'last_shopify_seen_at' => $syncedAt,
                'last_synced_at' => $syncedAt,
            ]))->save();
        });

        if ($wasLocalDirty) {
            return false;
        }

        $needsBackup = $sourceChanged
            || $shopifyIdChanged
            || (int) ($image->image_asset_id ?? 0) === 0
            || $image->backup_status !== Image::BACKUP_STATUS_BACKED_UP;

        if (!$needsBackup) {
            return false;
        }

        Image::withoutEvents(function () use ($image, $sourceChanged, $shopifyIdChanged): void {
            $updates = [
                'backup_status' => Image::BACKUP_STATUS_PENDING,
                'backup_completed_at' => null,
                'backup_error' => null,
            ];

            if ($sourceChanged || $shopifyIdChanged) {
                $updates['image_asset_id'] = null;
            }

            $image->forceFill($updates)->save();
        });

        return true;
    }

    private function markVariantMissingFromShopify(Variant $variant, $syncedAt): void
    {
        if ($this->normalizeValue($variant->shopify_id) === null) {
            return;
        }

        Variant::withoutEvents(function () use ($variant, $syncedAt): void {
            if ($variant->local_dirty) {
                if ($variant->sync_state !== Variant::SYNC_STATE_LOCAL_DELETED) {
                    $variant->sync_state = Variant::SYNC_STATE_CONFLICT;
                }
                $variant->save();
                return;
            }

            $variant->forceFill([
                'sync_state' => Variant::SYNC_STATE_REMOTE_DELETED,
                'local_dirty' => false,
                'last_synced_at' => $syncedAt,
            ])->save();
        });
    }

    private function markImageMissingFromShopify(Image $image, $syncedAt): bool
    {
        if ($this->normalizeValue($image->shopify_id) === null) {
            return false;
        }

        $needsBackup = !$image->local_dirty && (int) ($image->image_asset_id ?? 0) === 0;

        Image::withoutEvents(function () use ($image, $syncedAt): void {
            if ($image->local_dirty) {
                if ($image->sync_state !== Image::SYNC_STATE_LOCAL_DELETED) {
                    $image->sync_state = Image::SYNC_STATE_CONFLICT;
                }
                $image->save();
                return;
            }

            $image->forceFill([
                'sync_state' => Image::SYNC_STATE_REMOTE_DELETED,
                'local_dirty' => false,
                'last_synced_at' => $syncedAt,
            ])->save();
        });

        return $needsBackup;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function variantPayloadDiffers(Variant $variant, array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if ($key === 'product_id') {
                continue;
            }

            if ($this->normalizeVariantComparableValue($key, $variant->getAttribute($key)) !== $this->normalizeVariantComparableValue($key, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function imagePayloadDiffers(Image $image, array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if ($key === 'product_id') {
                continue;
            }

            if ($this->normalizeComparableValue($image->getAttribute($key)) !== $this->normalizeComparableValue($value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param \Illuminate\Support\Collection<int, Variant> $existingVariants
     * @param array<int, int> $seenVariantIds
     */
    private function resolveExistingVariant($existingVariants, ShopifyRow $row, ?string $shopifyId, array $seenVariantIds): ?Variant
    {
        if ($shopifyId !== null) {
            $match = $existingVariants->first(function (Variant $variant) use ($shopifyId, $seenVariantIds): bool {
                return !in_array($variant->id, $seenVariantIds, true)
                    && trim((string) $variant->shopify_id) === $shopifyId;
            });

            if ($match) {
                return $match;
            }
        }

        $variantKey = trim((string) ($row->variant_key ?? ''));
        if ($variantKey === '') {
            return null;
        }

        return $existingVariants->first(function (Variant $variant) use ($variantKey, $seenVariantIds): bool {
            return !in_array($variant->id, $seenVariantIds, true)
                && $this->variantKeyFromModel($variant) === $variantKey;
        });
    }

    /**
     * @param \Illuminate\Support\Collection<int, Image> $existingImages
     * @param array<int, int> $seenImageIds
     */
    private function resolveExistingImage($existingImages, ShopifyRow $row, ?string $shopifyId, array $seenImageIds): ?Image
    {
        if ($shopifyId !== null) {
            $match = $existingImages->first(function (Image $image) use ($shopifyId, $seenImageIds): bool {
                return !in_array($image->id, $seenImageIds, true)
                    && trim((string) $image->shopify_id) === $shopifyId;
            });

            if ($match) {
                return $match;
            }
        }

        $imageKey = trim((string) ($row->image_key ?? ''));
        if ($imageKey === '') {
            return null;
        }

        return $existingImages->first(function (Image $image) use ($imageKey, $seenImageIds): bool {
            return !in_array($image->id, $seenImageIds, true)
                && $this->imageKeyFromModel($image) === $imageKey;
        });
    }

    private function variantKeyFromModel(Variant $variant): ?string
    {
        return RowKey::variantKey([
            HeaderStore::VARIANT_SKU => $variant->sku,
            HeaderStore::OPTION1_VALUE => $variant->option1_value,
            HeaderStore::OPTION2_VALUE => $variant->option2_value,
            HeaderStore::OPTION3_VALUE => $variant->option3_value,
        ]);
    }

    private function imageKeyFromModel(Image $image): ?string
    {
        return RowKey::imageKey([
            HeaderStore::IMAGE_SRC => $image->src,
            HeaderStore::IMAGE_POSITION => $image->position,
        ]);
    }

    public function recalculateErrors(Import $import): void
    {
        DB::transaction(function () use ($import) {
            $rows = ShopifyRow::where('import_id', $import->id)
                ->whereNotNull('handle')
                ->orderBy('row_index')
                ->get()
                ->groupBy('handle');

            foreach ($rows as $handle => $handleRows) {
                /** @var ShopifyRow $primary */
                $primary = $handleRows->firstWhere('row_type', 'product_primary') ?? $handleRows->first();
                if (!$primary) {
                    continue;
                }

                $product = Product::where('import_id', $import->id)
                    ->where('handle', $handle)
                    ->first();

                if (!$product) {
                    continue;
                }

                $this->recalculateErrorsForProduct($product, $handleRows, $primary);
            }
        });
    }

    public function recalculateErrorsForProduct(Product $product, $handleRows = null, ?ShopifyRow $primary = null): void
    {
        $product->refresh();
        $product->unsetRelation('variants');
        $product->unsetRelation('images');

        $handleRows = $handleRows
            ?? ShopifyRow::where('import_id', $product->import_id)
                ->where('handle', $product->handle)
                ->orderBy('row_index')
                ->get();

        $primary = $primary
            ?? $handleRows->firstWhere('row_type', 'product_primary')
            ?? $handleRows->first();

        $resolved = CategoryTypeMap::resolve(
            $product->product_category,
            $product->type,
            $product->google_product_category
        );

        $errors = $this->buildErrorFields(
            $product,
            $primary,
            $handleRows,
            $resolved
        );

        Product::withoutEvents(function () use ($product, $errors): void {
            $product->forceFill([
                'has_errors' => !empty($errors),
                'error_fields' => $errors,
            ])->save();
        });
    }

    private function toDecimal(mixed $v): ?float
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        if ($s === '') return null;
        return (float)$s;
    }

    private function normalizeVariantComparableValue(string $field, mixed $value): mixed
    {
        return match ($field) {
            'price', 'compare_at_price' => $this->normalizeDecimalComparableValue($value, 2),
            'weight' => $this->normalizeDecimalComparableValue($value, 3),
            default => $this->normalizeComparableValue($value),
        };
    }

    private function normalizeDecimalComparableValue(mixed $value, int $precision): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace(' ', '', trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, ',') && !str_contains($normalized, '.')) {
            $normalized = str_replace(',', '.', $normalized);
        } else {
            $normalized = str_replace(',', '', $normalized);
        }

        if (!is_numeric($normalized)) {
            return (string) $this->normalizeComparableValue($value);
        }

        return number_format((float) $normalized, $precision, '.', '');
    }

    private function normalizeComparableValue(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            return $trimmed === '' ? null : $trimmed;
        }

        return $value;
    }

    private function toInteger(mixed $v): ?int
    {
        if ($v === null) {
            return null;
        }

        $s = trim((string) $v);
        if ($s === '' || !is_numeric($s)) {
            return null;
        }

        return (int) $s;
    }

    private function toBoolean(mixed $v): ?bool
    {
        if ($v === null) {
            return null;
        }

        if (is_bool($v)) {
            return $v;
        }

        $s = strtolower(trim((string) $v));
        if ($s === '') {
            return null;
        }

        return match ($s) {
            '1', 'true', 'yes', 'y' => true,
            '0', 'false', 'no', 'n' => false,
            default => null,
        };
    }

    private function defaultBatchForImport(Import $import): string
    {
        $stamp = $import->created_at?->format('YmdH') ?? now()->format('YmdH');
        return "import_{$stamp}";
    }

    private function isBundleFromTags(?string $tags): bool
    {
        return TagNormalizer::containsBundleOrStackTag($tags);
    }

    private function syncCategory(?string $name, ?string $googleCategory): ?Category
    {
        $name = $this->normalizeValue($name);
        if ($name === null) {
            return null;
        }

        $lower = strtolower($name);
        return Category::whereRaw('LOWER(name) = ?', [$lower])->first();
    }

    private function syncColors(?string $colorString): void
    {
        $parts = $this->parseColorTokens($colorString);
        if (empty($parts)) {
            return;
        }

        foreach ($parts as $name) {
            $lower = strtolower($name);
            $existing = Color::whereRaw('LOWER(name) = ?', [$lower])->first();
            if (!$existing) {
                Color::create(['name' => $name, 'active' => true]);
            }
        }
    }

    private function syncTags(?string $tagsString): void
    {
        $tokens = TagNormalizer::parseTokens($tagsString);
        if (empty($tokens)) {
            return;
        }

        foreach ($tokens as $token) {
            $existing = Tag::whereRaw('LOWER(name) = ?', [strtolower($token)])->first();
            if (!$existing) {
                Tag::create(['name' => $token, 'active' => true]);
            }
        }
    }

    private function normalizeValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            $flat = array_values(array_unique(array_filter(array_map(
                fn ($v) => trim((string) $v),
                $value
            ))));

            if (empty($flat)) {
                return null;
            }

            return implode('; ', $flat);
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function rowValueInsensitive(array $data, string $attribute): mixed
    {
        $needle = strtolower(trim($attribute));
        foreach ($data as $key => $value) {
            if (strtolower(trim((string) $key)) === $needle) {
                return $value;
            }
        }
        return null;
    }

    private function normalizeColorString(?string $value): ?string
    {
        $parts = $this->parseColorTokens($value);
        return empty($parts) ? null : implode('; ', $parts);
    }

    private function parseColorTokens(?string $value): array
    {
        $value = $this->normalizeValue($value);
        if ($value === null) {
            return [];
        }

        $normalized = str_replace(',', ';', $value);
        $rawParts = array_filter(array_map('trim', explode(';', $normalized)));

        $tokens = [];
        $seen = [];
        foreach ($rawParts as $part) {
            $token = $this->normalizeColorToken($part);
            if ($token === null) {
                continue;
            }

            $key = strtolower($token);
            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $tokens[] = $token;
        }

        return $tokens;
    }

    private function normalizeColorToken(string $value): ?string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        $normalized = strtolower($trimmed);
        $normalized = str_replace('&', 'and', $normalized);
        $normalized = preg_replace('/\s+/', '-', $normalized);
        $normalized = preg_replace('/-+/', '-', $normalized);
        $normalized = trim($normalized, '-');

        if ($normalized === 'multi') {
            $normalized = 'multicolour';
        }

        return $normalized === '' ? null : $normalized;
    }

    private function syncStatus(?string $status): void
    {
        $status = $this->normalizeValue($status);
        if ($status === null) {
            return;
        }

        $lower = strtolower($status);
        $existing = Status::whereRaw('LOWER(name) = ?', [$lower])->first();
        if (!$existing) {
            Status::create(['name' => $status, 'active' => true]);
        }
    }

    private function syncType(?string $typeName, ?string $googleCategory): void
    {
        $typeName = $this->normalizeValue($typeName);
        if ($typeName === null) {
            return;
        }

        $googleCategory = $this->normalizeValue($googleCategory);
        if (!CategoryTypeMap::byType($typeName)) {
            return;
        }

        $type = Type::firstOrCreate(
            ['name' => $typeName],
            ['google_product_category' => $googleCategory]
        );

        if ($type->google_product_category === null && $googleCategory !== null) {
            $type->update(['google_product_category' => $googleCategory]);
        }
    }

    private function buildErrorFields(Product $product, ?ShopifyRow $primary, $handleRows, array $resolved): array
    {
        $errors = [];

        if (!empty($resolved['mismatch'])) {
            $errors[] = 'mismatch:category_type';
        }

        $requiredDefinitions = $this->requiredDefinitions();
        $requiredProductFields = $requiredDefinitions['product'];
        $productValues = [
            'handle' => $product->handle,
            'product_category' => $resolved['category'] ?? null,
            'type' => $resolved['type'] ?? null,
            'google_product_category' => $resolved['google_product_category'] ?? null,
            'seo_title' => $product->seo_title,
            'seo_description' => $product->seo_description,
            'title' => $product->title,
            'body_html' => $product->body_html,
            'vendor' => $product->vendor,
            'tags' => $product->tags,
            'color' => $product->color_string,
            'color_string' => $product->color_string,
            'published' => $product->published,
            'status' => $product->status,
        ];

        foreach ($requiredProductFields as $field) {
            $attribute = $field['attribute'];
            $label = $field['label'] ?? $attribute;
            $value = $productValues[$attribute] ?? null;
            if ($this->normalizeValue($value) === null) {
                $errors[] = "missing:{$label}";
            }
        }

        $categoryValue = $this->normalizeValue($resolved['category'] ?? $product->product_category);
        if ($categoryValue !== null) {
            $exists = Category::whereRaw('LOWER(name) = ?', [strtolower($categoryValue)])->exists();
            if (!$exists) {
                $errors[] = 'invalid:category';
            }
        }

        $requiredRowFields = $requiredDefinitions['row'];

        foreach ($requiredRowFields as $field) {
            $attribute = $field['attribute'];
            $label = $field['label'] ?? $attribute;
            $rowValue = $primary?->get($attribute, null);
            if ($rowValue === null && $primary) {
                $rowValue = $this->rowValueInsensitive($primary->data ?? [], $attribute);
            }

            // Some required definitions may use Shopify variant headers (e.g. "Variant SKU")
            // as row fields. If variant records already contain the value, don't raise false
            // row-level missing errors.
            if ($this->normalizeValue($rowValue) === null && $this->isVariantAttribute($attribute)) {
                foreach ($product->variants as $variant) {
                    $variantValue = $this->variantValueFromModel($variant, $attribute);
                    if ($this->normalizeValue($variantValue) !== null) {
                        continue 2;
                    }
                }
            }

            if ($this->normalizeValue($rowValue) === null) {
                $errors[] = "missing:{$label}";
            }
        }

        $errors = array_merge(
            $errors,
            $this->dropdownInactiveErrors($product, $primary)
        );

        $colorTokens = $this->parseColorTokens($product->color_string);
        if (in_array('multicolour', $colorTokens, true)
            && (in_array('solid', $colorTokens, true) || in_array('plain', $colorTokens, true))
        ) {
            $errors[] = 'conflict:color_multicolour_solid_plain';
        }

        $variants = $product->variants;

        $requiredVariantFields = $requiredDefinitions['variant'];
        if (!empty($requiredVariantFields)) {
            if ($variants->isEmpty()) {
                foreach ($requiredVariantFields as $field) {
                    $attribute = $field['attribute'];
                    $label = $field['label'] ?? $attribute;
                    $errors[] = "missing:{$label}";
                }
            } else {
                foreach ($variants as $variant) {
                    foreach ($requiredVariantFields as $field) {
                        $attribute = $field['attribute'];
                        $label = $field['label'] ?? $attribute;
                        $value = $this->variantValueFromModel($variant, $attribute);
                        if ($this->normalizeValue($value) === null) {
                            $errors[] = "missing:{$label}";
                            break 2;
                        }
                    }

                    $sku = $this->normalizeValue($variant->sku ?? null);
                    $barcode = $this->normalizeValue($variant->barcode ?? null);
                    if ($sku !== null && $barcode !== null && $barcode !== $sku) {
                        $errors[] = 'mismatch:variant_barcode';
                        break;
                    }
                }
            }
        }

        $errors = $this->suppressVariantSkuMissingWhenVariantHasSku($product, $errors);

        if ($variants->isNotEmpty()) {
            foreach ($variants as $variant) {
                $unit = $this->normalizeValue($variant->weight_unit);
                if ($unit !== null && strtolower($unit) !== 'g') {
                    $errors[] = 'invalid:variant_weight_unit';
                    break;
                }
            }
        }

        $requiredImageFields = $requiredDefinitions['image'];
        if (!empty($requiredImageFields)) {
            $images = $product->images;

            if ($images->isEmpty()) {
                foreach ($requiredImageFields as $field) {
                    $attribute = $field['attribute'];
                    $label = $field['label'] ?? $attribute;
                    $errors[] = "missing:{$label}";
                }
            } else {
                foreach ($images as $image) {
                    foreach ($requiredImageFields as $field) {
                        $attribute = $field['attribute'];
                        $label = $field['label'] ?? $attribute;
                        $value = $this->imageValueFromModel($image, $attribute);
                        if ($this->normalizeValue($value) === null) {
                            $errors[] = "missing:{$label}";
                            break 2;
                        }
                    }
                }
            }
        }

        return array_values(array_unique($errors));
    }

    private function capturePendingDropdownOptions(ShopifyRow $primary, array $collectionContext): void
    {
        $headers = $this->controlledDropdownHeaders();
        foreach ($headers as $header) {
            $raw = $primary->get($header, null);
            $values = $this->parseDropdownValues($header, $raw);
            if (empty($values)) {
                continue;
            }

            $targetContexts = $this->targetCollectionContextsForHeader($header, $collectionContext);
            if (empty($targetContexts)) {
                continue;
            }

            foreach ($values as $value) {
                foreach ($targetContexts as $ctx) {
                    $query = DropdownOption::query()
                        ->where('header', $header)
                        ->whereRaw('LOWER(value) = ?', [strtolower($value)]);

                    if ($ctx['tag_primary'] !== null) {
                        $query->where('collection_tag_primary', $ctx['tag_primary']);
                    } else {
                        $query->whereNull('collection_tag_primary');
                    }

                    if ($ctx['tag_secondary'] !== null) {
                        $query->where('collection_tag_secondary', $ctx['tag_secondary']);
                    } else {
                        $query->whereNull('collection_tag_secondary');
                    }

                    if ($query->exists()) {
                        continue;
                    }

                    DropdownOption::create([
                        'header' => $header,
                        'value' => $value,
                        'collection_style' => $ctx['collection_style'],
                        'collection_tag_primary' => $ctx['tag_primary'],
                        'collection_tag_secondary' => $ctx['tag_secondary'],
                        'active' => false,
                        'sort_order' => 0,
                    ]);
                }
            }
        }
    }

    private function dropdownInactiveErrors(Product $product, ?ShopifyRow $primary): array
    {
        if (!$primary) {
            return [];
        }

        $errors = [];
        $collectionContext = $this->resolveCollectionContext($product->tags);
        $headers = $this->controlledDropdownHeaders();

        foreach ($headers as $header) {
            $raw = $primary->get($header, null);
            $values = $this->parseDropdownValues($header, $raw);
            if (empty($values)) {
                continue;
            }

            // No configured options for this header/context means there is no rule
            // to validate against yet, so don't mark inactive.
            $contextQuery = DropdownOption::query()->where('header', $header);
            if ($collectionContext['tag_primary'] !== null) {
                $contextQuery->where('collection_tag_primary', $collectionContext['tag_primary']);
            } else {
                $contextQuery->whereNull('collection_tag_primary');
            }
            if ($collectionContext['tag_secondary'] !== null) {
                $contextQuery->where('collection_tag_secondary', $collectionContext['tag_secondary']);
            } else {
                $contextQuery->whereNull('collection_tag_secondary');
            }
            if (!$contextQuery->exists()) {
                continue;
            }

            foreach ($values as $value) {
                $query = DropdownOption::query()
                    ->where('header', $header)
                    ->whereRaw('LOWER(value) = ?', [strtolower($value)]);

                if ($collectionContext['tag_primary'] !== null) {
                    $query->where('collection_tag_primary', $collectionContext['tag_primary']);
                } else {
                    $query->whereNull('collection_tag_primary');
                }

                if ($collectionContext['tag_secondary'] !== null) {
                    $query->where('collection_tag_secondary', $collectionContext['tag_secondary']);
                } else {
                    $query->whereNull('collection_tag_secondary');
                }

                $option = $query->first();
                if (!$option || !$option->active) {
                    $errors[] = "inactive:dropdown:{$header}:{$value}";
                }
            }
        }

        return $errors;
    }

    private function controlledDropdownHeaders(): array
    {
        return [
            HeaderStore::COLOR_METAFIELD,
            HeaderStore::JEWELRY_MATERIAL,
            HeaderStore::MATERIALS_AND_DIMENSIONS,
            HeaderStore::BRACELET_DESIGN,
            'Necklace design (product.metafields.shopify.necklace-design)',
            'Earring design (product.metafields.shopify.earring-design)',
            'Pattern Category (product.metafields.custom.pattern_category)',
            'Product Metals (product.metafields.custom.product_metals)',
        ];
    }

    /**
     * @return array<int, array{collection_style:string,tag_primary:string,tag_secondary:?string}>
     */
    private function targetCollectionContextsForHeader(string $header, array $collectionContext): array
    {
        if (($collectionContext['tag_primary'] ?? null) !== null) {
            return [$collectionContext];
        }

        return [[
            'collection_style' => null,
            'tag_primary' => null,
            'tag_secondary' => null,
        ]];
    }

    private function parseDropdownValues(string $header, mixed $raw): array
    {
        $value = $this->normalizeValue(is_string($raw) ? $raw : null);
        if ($value === null) {
            return [];
        }

        if ($header === HeaderStore::COLOR_METAFIELD) {
            return $this->parseColorTokens($value);
        }

        $normalized = str_replace(',', ';', $value);
        $parts = array_map('trim', explode(';', $normalized));
        return array_values(array_filter($parts, fn (string $part) => $part !== ''));
    }

    private function resolveCollectionContext(?string $tags): array
    {
        $tokens = TagNormalizer::parseTokens($tags);
        if (empty($tokens)) {
            return [
                'collection_style' => null,
                'tag_primary' => null,
                'tag_secondary' => null,
            ];
        }

        $knownContexts = app(DropdownCollectionCatalog::class)->contexts();
        foreach ($knownContexts as $ctx) {
            $primary = strtolower((string) ($ctx['tag_primary'] ?? ''));
            $secondary = strtolower((string) ($ctx['tag_secondary'] ?? ''));
            $tokenSet = array_map('strtolower', $tokens);

            if ($primary === '' || !in_array($primary, $tokenSet, true)) {
                continue;
            }
            if ($secondary !== '' && !in_array($secondary, $tokenSet, true)) {
                continue;
            }

            return [
                'collection_style' => $ctx['collection_style'],
                'tag_primary' => $ctx['tag_primary'],
                'tag_secondary' => $ctx['tag_secondary'],
            ];
        }

        // Do not invent collection context from arbitrary product tags.
        // Pending dropdown logic must rely only on known dropdown collections.
        return [
            'collection_style' => null,
            'tag_primary' => null,
            'tag_secondary' => null,
        ];
    }

    private function requiredDefinitions(): array
    {
        $required = RequiredField::query()->where('required', true)->get();
        if ($required->isEmpty()) {
            $fallbackProduct = [];
            foreach (config('product_error_rules.product_fields', []) as $attribute) {
                $fallbackProduct[] = ['attribute' => $attribute, 'label' => $attribute];
            }

            $fallbackRow = [];
            foreach (config('product_error_rules.row_fields', []) as $attribute) {
                $fallbackRow[] = ['attribute' => $attribute, 'label' => $attribute];
            }

            $fallbackVariant = [];
            foreach (config('product_error_rules.variant_fields', []) as $attribute) {
                $fallbackVariant[] = ['attribute' => $attribute, 'label' => $attribute];
            }

            return [
                'product' => $fallbackProduct,
                'row' => $fallbackRow,
                'variant' => $fallbackVariant,
                'image' => [],
            ];
        }

        $definitions = [
            'product' => [],
            'row' => [],
            'variant' => [],
            'image' => [],
        ];

        foreach ($required as $field) {
            if ($field->source === 'row' && $field->attribute === HeaderStore::SEO_DEINDEX) {
                continue;
            }
            if ($field->source === 'product') {
                $definitions['product'][] = [
                    'attribute' => $field->attribute,
                    'label' => $field->label,
                ];
                continue;
            }
            if ($field->source === 'row') {
                $definitions['row'][] = [
                    'attribute' => $field->attribute,
                    'label' => $field->label,
                ];
                continue;
            }
            if ($field->source === 'variant') {
                $definitions['variant'][] = [
                    'attribute' => $field->attribute,
                    'label' => $field->label,
                ];
                continue;
            }
            if ($field->source === 'image') {
                $definitions['image'][] = [
                    'attribute' => $field->attribute,
                    'label' => $field->label,
                ];
            }
        }

        return $definitions;
    }

    private function variantValueFromModel(Variant $variant, string $attribute): mixed
    {
        return match ($this->normalizeKey($attribute)) {
            'sku', 'variant_sku' => $variant->sku,
            'barcode', 'variant_barcode' => $variant->barcode,
            'price', 'variant_price' => $variant->price,
            'compare_at_price', 'variant_compare_at_price' => $variant->compare_at_price,
            'option1_name' => $variant->option1_name,
            'option1_value' => $variant->option1_value,
            'option2_name' => $variant->option2_name,
            'option2_value' => $variant->option2_value,
            'option3_name' => $variant->option3_name,
            'option3_value' => $variant->option3_value,
            'weight_unit', 'variant_weight_unit' => $variant->weight_unit,
            default => null,
        };
    }

    private function isVariantAttribute(string $attribute): bool
    {
        return in_array($this->normalizeKey($attribute), [
            'sku',
            'variant_sku',
            'barcode',
            'variant_barcode',
            'price',
            'variant_price',
            'compare_at_price',
            'variant_compare_at_price',
            'option1_name',
            'option1_value',
            'option2_name',
            'option2_value',
            'option3_name',
            'option3_value',
            'weight_unit',
            'variant_weight_unit',
        ], true);
    }

    private function normalizeKey(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
        return trim($value, '_');
    }

    private function suppressVariantSkuMissingWhenVariantHasSku(Product $product, array $errors): array
    {
        $hasVariantSku = $product->variants->contains(function (Variant $variant): bool {
            return $this->normalizeValue($variant->sku) !== null;
        });

        if (!$hasVariantSku) {
            return $errors;
        }

        return array_values(array_filter($errors, function (string $error): bool {
            if (!str_starts_with($error, 'missing:')) {
                return true;
            }

            $label = substr($error, strlen('missing:'));
            $normalized = $this->normalizeKey($label);
            return !in_array($normalized, ['sku', 'variant_sku'], true);
        }));
    }

    private function imageValueFromModel(Image $image, string $attribute): mixed
    {
        return match ($attribute) {
            'src' => $image->src,
            'position' => $image->position,
            'alt_text' => $image->alt_text,
            default => null,
        };
    }
}
