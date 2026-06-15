<?php

namespace App\Observers;

use App\Models\Image;
use App\Models\Import;
use App\Models\Product;
use App\Models\ShopifyRow;
use App\Services\HeaderStore;
use App\Services\Normalizer;
use App\Services\ProductImageFilenameService;
use App\Services\RowKey;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;

class ImageObserver
{
    public function creating(Image $image): void
    {
        $this->applyManagedApprovedFilename($image);
        $image->needs_shopify_image_sync = true;
        $image->shopify_image_sync_error = null;
        $this->applyLocalSyncState($image, isCreating: true);
    }

    public function created(Image $image): void
    {
        $this->syncShopifyRow($image, null);
        $product = $image->product_id ? Product::find($image->product_id) : null;
        if ($product) {
            app(Normalizer::class)->recalculateErrorsForProduct($product);
        }
    }

    public function updating(Image $image): void
    {
        $dirty = array_keys($image->getDirty());
        $meaningful = array_diff($dirty, $this->nonContentFields());
        if (empty($meaningful)) {
            return;
        }

        $contentDirty = $this->contentDirty($image);
        $isLocalDelete = $image->isDirty('sync_state') && $image->sync_state === Image::SYNC_STATE_LOCAL_DELETED;

        if (!empty(array_intersect(['src', 'image_path'], $contentDirty))) {
            $image->image_asset_id = null;
            $image->backup_status = Image::BACKUP_STATUS_PENDING;
            $image->backup_completed_at = null;
            $image->backup_error = null;
        }

        if (!empty(array_intersect(['src', 'image_path', 'position'], $contentDirty))) {
            $this->applyManagedApprovedFilename($image);
            $image->needs_shopify_image_sync = true;
            $image->shopify_image_sync_error = null;
        }

        if (in_array('image_path', $contentDirty, true)) {
            $previousPath = $image->getOriginal('image_path');
            if (is_string($previousPath) && trim($previousPath) !== '' && $previousPath !== $image->image_path) {
                $this->deleteStoredImage($previousPath);
            }
        }

        $this->applyLocalSyncState($image);
        $this->bumpProductApprovalVersion($image->product_id);

        if ($isLocalDelete) {
            $this->deleteShopifyRow($image, $image->getOriginal());
            return;
        }

        if (!empty($contentDirty)) {
            $this->syncShopifyRow($image, $image->getOriginal());
        }
    }

    public function deleted(Image $image): void
    {
        $this->deleteStoredImage($image->getOriginal('image_path'));

        $product = $image->product_id ? Product::find($image->product_id) : null;
        if (!$product) {
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
        if (!$oldKey) {
            return;
        }

        ShopifyRow::where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->where('row_type', 'image')
            ->where('image_key', $oldKey)
            ->delete();

        app(Normalizer::class)->recalculateErrorsForProduct($product);
    }

    public function updated(Image $image): void
    {
        $product = $image->product_id ? Product::find($image->product_id) : null;
        if (!$product) {
            return;
        }

        app(Normalizer::class)->recalculateErrorsForProduct($product);
    }

    private function bumpProductApprovalVersion(?int $productId): void
    {
        if (!$productId) {
            return;
        }

        Product::withoutEvents(function () use ($productId): void {
            $product = Product::find($productId);
            if (!$product) {
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
        if (!$product || $product->first_image_auto_rename_completed_at === null) {
            return;
        }

        $hasSource = filled(trim((string) ($image->image_path ?? '')))
            || filled(trim((string) ($image->src ?? '')));
        if (!$hasSource) {
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
        if (!$product) {
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

        if (!$row) {
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
        if (!empty($headers)) {
            return $headers;
        }

        $currentImport = Import::where('is_current', true)->first();
        $headers = $currentImport?->headers ?? [];
        if (!empty($headers)) {
            return $headers;
        }

        $templatePath = HeaderStore::latestTemplatePath();
        if ($templatePath === null || !is_file($templatePath)) {
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
     * @return array<int, string>
     */
    private function contentDirty(Image $image): array
    {
        return array_values(array_diff(array_keys($image->getDirty()), $this->nonContentFields()));
    }

    private function applyLocalSyncState(Image $image, bool $isCreating = false): void
    {
        $contentDirty = $this->contentDirty($image);

        if (!$isCreating && empty($contentDirty)) {
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
        ];
    }

    private function deleteShopifyRow(Image $image, ?array $original = null): void
    {
        $product = $image->product_id ? Product::find($image->product_id) : null;
        if (!$product) {
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
        if (!$oldKey) {
            return;
        }

        ShopifyRow::where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->where('row_type', 'image')
            ->where('image_key', $oldKey)
            ->delete();
    }
}
