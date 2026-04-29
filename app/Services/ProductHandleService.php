<?php

namespace App\Services;

use App\Models\NewProductDraft;
use App\Models\Product;
use App\Models\ProductUrlRedirect;
use App\Models\ShopifyMetafield;
use App\Models\ShopifyRow;
use App\Models\StyleProfile;
use Illuminate\Support\Str;

class ProductHandleService
{
    public function syncApprovedHandleToCurrentTitle(Product $product): ?string
    {
        $approvedHandle = $this->generateUniqueApprovedHandle($product);

        Product::withoutEvents(function () use ($product, $approvedHandle): void {
            $product->forceFill([
                'approved_handle' => $approvedHandle,
            ])->save();
        });

        $product->forceFill(['approved_handle' => $approvedHandle]);

        return $approvedHandle;
    }

    public function lockInitialApprovedHandle(Product $product): ?string
    {
        $approvedHandle = trim((string) ($product->approved_handle ?? ''));
        if ($approvedHandle === '') {
            $approvedHandle = $this->generateUniqueApprovedHandle($product);
        }

        Product::withoutEvents(function () use ($product, $approvedHandle): void {
            $product->forceFill([
                'approved_handle' => $approvedHandle,
                'first_handle_auto_lock_completed_at' => now(),
                'first_handle_auto_lock_approval_version' => (int) ($product->approval_version ?? 1),
            ])->save();
        });

        return $approvedHandle;
    }

    public function promoteApprovedHandle(Product $product): bool
    {
        $approvedHandle = trim((string) ($product->approved_handle ?? ''));
        $currentHandle = trim((string) ($product->handle ?? ''));

        if ($approvedHandle === '' || $approvedHandle === $currentHandle) {
            return false;
        }

        Product::withoutEvents(function () use ($product, $approvedHandle): void {
            $product->forceFill([
                'handle' => $approvedHandle,
            ])->save();
        });

        ShopifyRow::query()
            ->where('import_id', $product->import_id)
            ->where('handle', $currentHandle)
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($approvedHandle): void {
                foreach ($rows as $row) {
                    $row->handle = $approvedHandle;
                    $data = is_array($row->data) ? $row->data : [];
                    if (array_key_exists(HeaderStore::HANDLE, $data)) {
                        $data[HeaderStore::HANDLE] = $approvedHandle;
                        $row->data = $data;
                    }
                    $row->save();
                }
            });

        ShopifyMetafield::query()
            ->where('import_id', $product->import_id)
            ->where('handle', $currentHandle)
            ->update(['handle' => $approvedHandle]);

        NewProductDraft::query()
            ->where('handle', $currentHandle)
            ->update(['handle' => $approvedHandle]);

        StyleProfile::query()
            ->where(function ($query) use ($product, $currentHandle): void {
                $query->where('product_id', $product->id)
                    ->orWhere('handle', $currentHandle);
            })
            ->update(['handle' => $approvedHandle]);

        $this->createPendingRedirect($product, $currentHandle, $approvedHandle);

        $product->forceFill(['handle' => $approvedHandle]);

        return true;
    }

    public function generateUniqueApprovedHandle(Product $product): string
    {
        $base = Str::slug((string) ($product->title ?? ''));
        if ($base === '') {
            $base = trim((string) ($product->handle ?? ''));
        }
        if ($base === '') {
            $base = 'product-' . $product->getKey();
        }

        $maxLength = 255;
        $base = substr($base, 0, $maxLength);
        $candidate = $base;
        $suffix = 2;

        while ($this->handleExistsElsewhere($product, $candidate)) {
            $suffixLabel = '-' . $suffix;
            $candidate = substr($base, 0, max(1, $maxLength - strlen($suffixLabel))) . $suffixLabel;
            $suffix++;
        }

        return $candidate;
    }

    private function handleExistsElsewhere(Product $product, string $candidate): bool
    {
        return Product::query()
            ->whereKeyNot($product->getKey())
            ->where(function ($query) use ($candidate): void {
                $query->where('handle', $candidate)
                    ->orWhere('approved_handle', $candidate);
            })
            ->exists();
    }

    private function createPendingRedirect(Product $product, string $oldHandle, string $newHandle): void
    {
        $oldHandle = trim($oldHandle);
        $newHandle = trim($newHandle);

        if ($oldHandle === '' || $newHandle === '' || $oldHandle === $newHandle) {
            return;
        }

        ProductUrlRedirect::query()->updateOrCreate(
            ['path' => "/products/{$oldHandle}"],
            [
                'product_id' => $product->id,
                'created_by' => null,
                'old_handle' => $oldHandle,
                'new_handle' => $newHandle,
                'target' => "/products/{$newHandle}",
                'status' => ProductUrlRedirect::STATUS_PENDING,
                'shopify_redirect_id' => null,
                'last_error' => null,
                'synced_at' => null,
            ]
        );
    }
}
