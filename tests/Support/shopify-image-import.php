<?php

use App\Models\ImageAsset;
use App\Models\Import;
use App\Models\Product;
use App\Models\User;
use App\Models\Variant;
use Illuminate\Support\Facades\Storage;

if (! function_exists('createImageImportProduct')) {
    function createImageImportProduct(string $sku, array $productOverrides = []): Product
    {
        $user = User::factory()->create();

        $import = Import::create([
            'filename' => 'image-import-test.csv',
            'mode' => 'overwrite',
            'status' => 'ready',
            'created_by' => $user->id,
            'is_current' => true,
        ]);

        $product = Product::withoutEvents(fn (): Product => Product::create(array_merge([
            'import_id' => $import->id,
            'shopify_id' => 'gid://shopify/Product/'.abs(crc32($sku)),
            'handle' => strtolower(str_replace('_', '-', $sku)),
            'title' => $sku,
            'type' => 'Bracelets',
            'status' => 'active',
        ], $productOverrides)));

        Variant::withoutEvents(fn (): Variant => Variant::create([
            'product_id' => $product->id,
            'sku' => $sku,
            'sync_state' => Variant::SYNC_STATE_SYNCED,
            'local_dirty' => false,
        ]));

        return $product;
    }
}

if (! function_exists('createImportTestAsset')) {
    function createImportTestAsset(string $bytes, string $extension, string $filename): ImageAsset
    {
        $sha256 = hash('sha256', $bytes);
        $path = 'product-image-assets/'.substr($sha256, 0, 2).'/'.substr($sha256, 2, 2)."/{$sha256}.{$extension}";

        Storage::disk('public')->put($path, $bytes);

        return ImageAsset::create([
            'sha256' => $sha256,
            'storage_disk' => 'public',
            'storage_path' => $path,
            'original_filename' => $filename,
            'source_url' => 's3://leigh-product-images/incoming/2026-07-06/'.$filename,
            'mime_type' => 'image/png',
            'extension' => $extension,
            'file_size' => strlen($bytes),
            'downloaded_at' => now(),
            'last_verified_at' => now(),
            'status' => ImageAsset::STATUS_AVAILABLE,
        ]);
    }
}
