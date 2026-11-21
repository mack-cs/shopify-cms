<?php

namespace App\Observers;

use App\Models\Image;
use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyRow;
use App\Services\HeaderStore;
use App\Services\ImagePhotographEvidence;
use App\Services\Normalizer;
use App\Services\ProductImageFilenameService;
use App\Services\RowKey;
use App\Services\ShopifyMediaPath;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;

class ImageObserver
{
    public function creating(Image $image): void
    {
        if ($image->ownership === null && $image->source_image_id === null && $image->product_id) {
            $product = $image->relationLoaded('product') ? $image->product : Product::query()->find($image->product_id);
            if ($product instanceof Product && $product->isStackProduct()) {
                $image->ownership = \App\Services\ImageOwnership::STACK_OWNED;
            }
        }
        $this->applyManagedApprovedFilename($image);
        $image->needs_shopify_image_sync = true;
        $image->shopify_image_sync_error = null;
        if ((int) ($image->photograph_version ?? 0) < 1) {
            $image->photograph_version = 1;
        }
        if ((int) ($image->alt_text_version ?? 0) < 1) {
            $image->alt_text_version = 1;
        }
        $this->syncImageAttention($image);
        $this->applyLocalSyncState($image, isCreating: true);
    }

    public function created(Image $image): void
    {
        $this->syncShopifyRow($image, null);
        $product = $image->product_id ? Product::find($image->product_id) : null;
        if ($product) {
            app(Normalizer::class)->recalculateErrorsForProduct($product);
        }
        $this->queueDependentStackRebuild($image);
    }

    public function updating(Image $image): void
    {
        $dirty = array_keys($image->getDirty());
        $this->syncImageAttention($image);
        $meaningful = array_diff($dirty, $this->nonContentFields());
        if (empty($meaningful)) {
            return;
        }

        $contentDirty = $this->contentDirty($image);
        $isLocalDelete = $image->isDirty('sync_state') && $image->sync_state === Image::SYNC_STATE_LOCAL_DELETED;

        if ($this->shopifyIdentityChanged($image, $contentDirty)) {
            app(ImagePhotographEvidence::class)->archive($image, 'Local photograph replacement');
            $image->image_asset_id = null;
            $image->backup_status = Image::BACKUP_STATUS_PENDING;
            $image->backup_completed_at = null;
            $image->backup_error = null;
            $image->shopify_id = null;
            $image->shopify_cdn_url = null;
            $image->shopify_identity_unresolved = true;
            $image->alt_text = null;
            $image->alt_text_status = null;
            $image->shopify_alt_text = null;
            $image->alt_confirmation_media_id = null;
            $image->alt_confirmation_text = null;
            $image->needs_shopify_image_sync = true;
            $image->shopify_image_sync_error = null;
        }

        if (! empty(array_intersect(['src', 'image_path', 'position'], $contentDirty))) {
            $this->applyManagedApprovedFilename($image);
        }

        if (in_array('image_path', $contentDirty, true)) {
            $previousPath = $image->getOriginal('image_path');
            if (is_string($previousPath) && trim($previousPath) !== '' && $previousPath !== $image->image_path) {
                // Retain previous bytes for the archived photograph revision.
            }
        }

        $this->applyLocalSyncState($image);
        $this->bumpProductApprovalVersion($image->product_id);

        if ($isLocalDelete) {
            $this->deleteShopifyRow($image, $image->getOriginal());

            return;
        }

        if (! empty($contentDirty)) {
            $this->syncShopifyRow($image, $image->getOriginal());
        }
    }

    public function updated(Image $image): void
    {
        if ($image->wasChanged(['position', 'is_duplicate_hidden', 'sync_state'])) {
            try {
                app(\App\Services\ComponentStackPropagation::class)->reconcileComponentSelection((int) $image->product_id);
            } catch (\Throwable $exception) {
                logger()->warning('Component photograph selection was not propagated.', [
                    'image_id' => $image->id,
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        if ($image->wasChanged(['alt_text', 'position', 'image_asset_id', 'image_path', 'src', 'sync_state', 'is_duplicate_hidden'])) {
            $this->queueDependentStackRebuild($image);
        }

        $product = $image->product_id ? Product::find($image->product_id) : null;
        if (! $product) {
            return;
        }

        app(Normalizer::class)->recalculateErrorsForProduct($product);
    }

    public function deleted(Image $image): void
    {
        $this->deleteStoredImage($image->getOriginal('image_path'));

        $product = $image->product_id ? Product::find($image->product_id) : null;
        if (! $product) {
            return;
        }

        $headers = $this->headersForProduct($product);
        if (empty($headers)) {
            return;
        }

        $oldRow = [
            HeaderStore::HANDLE => $product->handle,
            HeaderStore::IMAGE_SRC => $image->getOriginal('src'),
            HeaderStore::IMAGE_POSITION => $image->getOriginal('position'),
        ];
        $oldKey = RowKey::imageKey($oldRow);
        if (! $oldKey) {
            return;
        }

        ShopifyRow::where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->where('row_type', 'image')
            ->where('image_key', $oldKey)
            ->delete();

        app(Normalizer::class)->recalculateErrorsForProduct($product);
    }

    private function bumpProductApprovalVersion(?int $productId): void
    {
        if (! $productId) {
            return;
        }

        Product::withoutEvents(function () use ($productId): void {
            $product = Product::find($productId);
            if (! $product) {
                return;
            }

            $product->approval_version = ($product->approval_version ?? 1) + 1;
            $product->save();
        });
    }

    private function applyManagedApprovedFilename(Image $image): void
    {
        $productId = (int) ($image->product_id ?? 0);
        if ($productId <= 0) {
            return;
        }

        $product = Product::find($productId);
        if (! $product || $product->first_image_auto_rename_completed_at === null) {
            return;
        }

        $hasSource = filled(trim((string) ($image->image_path ?? '')))
            || filled(trim((string) ($image->src ?? '')));
        if (! $hasSource) {
            return;
        }

        $position = $image->position;
        if ($position === null) {
            $position = (int) ($product->allImages()
                ->whereNotIn('sync_state', [
                    Image::SYNC_STATE_LOCAL_DELETED,
                    Image::SYNC_STATE_REMOTE_DELETED,
                ])
                ->max('position') ?? 0) + 1;
        }

        $filename = app(ProductImageFilenameService::class)
            ->generateForImage($image, $product->title, (int) $position);

        $image->approved_filename = $filename;
        $image->filename_mode = Image::FILENAME_MODE_AUTO;
    }

    private function syncShopifyRow(Image $image, ?array $original): void
    {
        $product = $image->product_id ? Product::find($image->product_id) : null;
        if (! $product) {
            return;
        }

        $headers = $this->headersForProduct($product);
        if (empty($headers)) {
            return;
        }

        $rowData = array_fill_keys($headers, '');
        if (array_key_exists(HeaderStore::HANDLE, $rowData)) {
            $rowData[HeaderStore::HANDLE] = $product->handle;
        }
        if (array_key_exists(HeaderStore::IMAGE_SRC, $rowData)) {
            $rowData[HeaderStore::IMAGE_SRC] = $image->src ?? '';
        }
        if (array_key_exists(HeaderStore::IMAGE_POSITION, $rowData)) {
            $rowData[HeaderStore::IMAGE_POSITION] = $image->position ?? '';
        }
        if (array_key_exists(HeaderStore::IMAGE_ALT_TEXT, $rowData)) {
            $rowData[HeaderStore::IMAGE_ALT_TEXT] = $image->alt_text ?? '';
        }

        $newKey = RowKey::imageKey($rowData);

        $row = null;
        if ($original) {
            $oldRow = [
                HeaderStore::HANDLE => $product->handle,
                HeaderStore::IMAGE_SRC => $original['src'] ?? null,
                HeaderStore::IMAGE_POSITION => $original['position'] ?? null,
            ];
            $oldKey = RowKey::imageKey($oldRow);
            if ($oldKey) {
                $row = ShopifyRow::where('import_id', $product->import_id)
                    ->where('handle', $product->handle)
                    ->where('row_type', 'image')
                    ->where('image_key', $oldKey)
                    ->first();
            }
        }

        if (! $row) {
            $rowIndex = (int) (ShopifyRow::where('import_id', $product->import_id)->max('row_index') ?? 0);
            $rowIndex++;

            ShopifyRow::create([
                'import_id' => $product->import_id,
                'row_index' => $rowIndex,
                'handle' => $product->handle,
                'row_type' => 'image',
                'variant_key' => null,
                'image_key' => $newKey,
                'data' => $rowData,
            ]);

            return;
        }

        $row->image_key = $newKey;
        $row->data = $rowData;
        $row->save();
    }

    private function headersForProduct(Product $product): array
    {
        $headers = $product->import?->headers ?? [];
        if (! empty($headers)) {
            return $headers;
        }

        $currentImport = Import::where('is_current', true)->first();
        $headers = $currentImport?->headers ?? [];
        if (! empty($headers)) {
            return $headers;
        }

        $templatePath = HeaderStore::latestTemplatePath();
        if ($templatePath === null || ! is_file($templatePath)) {
            return [];
        }

        $csv = Reader::createFromPath($templatePath);
        $csv->setHeaderOffset(0);

        return $csv->getHeader();
    }

    private function deleteStoredImage(?string $path): void
    {
        $trimmed = is_string($path) ? trim($path) : '';
        if ($trimmed === '') {
            return;
        }

        Storage::disk('public')->delete($trimmed);
    }

    /**
     * A cache-buster query change is still the same photograph.
     *
     * @param  array<int, string>  $dirty
     */
    private function shopifyIdentityChanged(Image $image, array $dirty): bool
    {
        if (in_array('image_path', $dirty, true)) {
            return true;
        }
        if (! in_array('src', $dirty, true)) {
            return false;
        }

        return ShopifyMediaPath::canonical($image->getOriginal('src')) !== ShopifyMediaPath::canonical($image->src);
    }

    /**
     * @return array<int, string>
     */
    private function contentDirty(Image $image): array
    {
        return array_values(array_diff(array_keys($image->getDirty()), $this->nonContentFields()));
    }

    private function applyLocalSyncState(Image $image, bool $isCreating = false): void
    {
        $contentDirty = $this->contentDirty($image);

        if (! $isCreating && empty($contentDirty)) {
            if ($image->sync_state === Image::SYNC_STATE_LOCAL_DELETED) {
                $image->local_dirty = true;
            }

            return;
        }

        if ($image->sync_state === Image::SYNC_STATE_LOCAL_DELETED) {
            $image->local_dirty = true;

            return;
        }

        if (blank($image->shopify_id)) {
            $image->sync_state = Image::SYNC_STATE_LOCAL_NEW;
            $image->local_dirty = true;

            return;
        }

        $image->sync_state = Image::SYNC_STATE_LOCAL_UPDATED;
        $image->local_dirty = true;
    }

    /**
     * @return array<int, string>
     */
    private function nonContentFields(): array
    {
        return [
            'updated_at',
            'created_at',
            'sync_state',
            'local_dirty',
            'is_duplicate_hidden',
            'duplicate_of_image_id',
            'duplicate_hidden_at',
            'duplicate_hidden_by',
            'duplicate_hidden_reason',
            'duplicate_restored_at',
            'duplicate_restored_by',
            'last_shopify_seen_at',
            'last_synced_at',
            'image_asset_id',
            'backup_status',
            'backup_completed_at',
            'backup_error',
            'approved_filename',
            'filename_mode',
            'last_shopify_synced_image_asset_id',
            'last_shopify_synced_filename',
            'last_shopify_image_synced_at',
            'needs_shopify_image_sync',
            'shopify_image_sync_error',
            'shopify_media_create_uncertain',
            'shopify_identity_unresolved',
            'alt_confirmation_media_id',
            'alt_confirmation_text',
            'shopify_cdn_url',
            'alt_text',
            'imported_alt_text',
            'alt_text_status',
            'shopify_alt_pending',
            'shopify_alt_sync_error',
            'shopify_alt_text',
            'source_image_id',
            'ownership',
            'alt_inheritance_mode',
            'photograph_version',
            'alt_text_version',
            'image_attention_since',
        ];
    }

    private function syncImageAttention(Image $image): void
    {
        $outstanding = (bool) $image->needs_shopify_image_sync
            || (bool) $image->shopify_alt_pending
            || (bool) $image->shopify_media_create_uncertain
            || in_array($image->alt_text_status, [Image::ALT_PENDING_REVIEW, Image::ALT_FAILED, Image::ALT_AWAITING_CONFIRMATION], true);
        if (! $outstanding) {
            $image->image_attention_since = null;

            return;
        }
        $existing = $image->image_attention_since ?: $image->getOriginal('image_attention_since');
        $image->image_attention_since = $existing ?: now();
    }

    private function queueDependentStackRebuild(Image $image): void
    {
        if ($image->ownership === \App\Services\ImageOwnership::COMPONENT_INHERITED) {
            return;
        }
        try {
            app(\App\Services\StackImageSync::class)->queueLocalRebuild((int) $image->product_id);
        } catch (\Throwable $exception) {
            logger()->warning('Dependent stack rebuild was not queued.', [
                'image_id' => $image->id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function deleteShopifyRow(Image $image, ?array $original = null): void
    {
        $product = $image->product_id ? Product::find($image->product_id) : null;
        if (! $product) {
            return;
        }

        $headers = $this->headersForProduct($product);
        if (empty($headers)) {
            return;
        }

        $oldRow = [
            HeaderStore::HANDLE => $product->handle,
            HeaderStore::IMAGE_SRC => $original['src'] ?? $image->getOriginal('src'),
            HeaderStore::IMAGE_POSITION => $original['position'] ?? $image->getOriginal('position'),
        ];
        $oldKey = RowKey::imageKey($oldRow);
        if (! $oldKey) {
            return;
        }

        ShopifyRow::where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->where('row_type', 'image')
            ->where('image_key', $oldKey)
            ->delete();
    }
}
