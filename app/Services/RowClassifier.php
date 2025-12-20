<?php

namespace App\Services;

final class RowClassifier
{
    /**
     * @return array{row_type:string, variant_key:?string, image_key:?string}
     */
    public function classify(array $row, bool $isFirstRowForHandle): array
    {
        $handle = trim((string)($row[HeaderStore::HANDLE] ?? ''));

        if ($handle === '') {
            return ['row_type' => 'unknown', 'variant_key' => null, 'image_key' => null];
        }

        $variantKey = RowKey::variantKey($row);
        $imageKey = RowKey::imageKey($row);

        // Shopify CSV often has product info repeated; treat first row per handle as primary
        if ($isFirstRowForHandle) {
            return ['row_type' => 'product_primary', 'variant_key' => $variantKey, 'image_key' => $imageKey];
        }

        // Heuristic: SKU/options present -> variant row
        if ($variantKey !== null) {
            return ['row_type' => 'variant', 'variant_key' => $variantKey, 'image_key' => $imageKey];
        }

        // Heuristic: image src present -> image row
        if ($imageKey !== null) {
            return ['row_type' => 'image', 'variant_key' => null, 'image_key' => $imageKey];
        }

        return ['row_type' => 'unknown', 'variant_key' => null, 'image_key' => null];
    }
}
