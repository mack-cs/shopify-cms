<?php

namespace App\Services;

use App\Models\ShopifyRow;
use App\Models\Variant;

final class ShopifyVariantConflictResolver
{
    public function applyLatestShopifyValues(Variant $variant): bool
    {
        $row = $this->findImportedRow($variant);
        if (! $row instanceof ShopifyRow) {
            return false;
        }

        $sku = $this->trimToNull($row->get(HeaderStore::VARIANT_SKU, null));
        $tracked = $this->toBoolean($row->get(HeaderStore::INTERNAL_VARIANT_INVENTORY_TRACKED, null));

        $updates = [
            'shopify_id' => $this->trimToNull($row->get(HeaderStore::INTERNAL_VARIANT_SHOPIFY_ID, null)) ?? $variant->shopify_id,
            'sku' => $sku,
            'barcode' => $this->trimToNull($row->get(HeaderStore::VARIANT_BARCODE, null)) ?? $sku,
            'weight' => $this->toDecimal($row->get(HeaderStore::VARIANT_GRAMS, null)),
            'weight_unit' => $this->trimToNull($row->get(HeaderStore::VARIANT_WEIGHT_UNIT, null)) ?? 'g',
            'inventory_tracked' => $tracked,
            'inventory_qty' => $tracked === false
                ? null
                : $this->toInteger($row->get(HeaderStore::VARIANT_INVENTORY_QTY, null)),
            'option1_name' => $row->get(HeaderStore::OPTION1_NAME, null),
            'option1_value' => $row->get(HeaderStore::OPTION1_VALUE, null),
            'option2_name' => $row->get(HeaderStore::OPTION2_NAME, null),
            'option2_value' => $row->get(HeaderStore::OPTION2_VALUE, null),
            'option3_name' => $row->get(HeaderStore::OPTION3_NAME, null),
            'option3_value' => $row->get(HeaderStore::OPTION3_VALUE, null),
            'price' => $this->toDecimal($row->get(HeaderStore::VARIANT_PRICE, null)),
            'compare_at_price' => $this->toDecimal($row->get(HeaderStore::VARIANT_COMPARE_AT, null)),
            'sync_state' => Variant::SYNC_STATE_SYNCED,
            'local_dirty' => false,
            'inventory_local_dirty' => false,
            'inventory_sync_error' => null,
            'last_shopify_seen_at' => now(),
            'last_synced_at' => now(),
            'inventory_last_synced_at' => now(),
        ];

        Variant::withoutEvents(function () use ($variant, $updates): void {
            $variant->forceFill($updates)->save();
        });

        return true;
    }

    public function findImportedRow(Variant $variant): ?ShopifyRow
    {
        $product = $variant->product;
        if ($product === null) {
            return null;
        }

        $rows = ShopifyRow::query()
            ->where('import_id', $product->import_id)
            ->where('handle', $product->handle)
            ->whereNotNull('variant_key')
            ->orderBy('row_index')
            ->get();

        $shopifyId = $this->trimToNull($variant->shopify_id);
        if ($shopifyId !== null) {
            $match = $rows->first(fn (ShopifyRow $row): bool => $this->trimToNull(
                $row->get(HeaderStore::INTERNAL_VARIANT_SHOPIFY_ID, null)
            ) === $shopifyId);

            if ($match instanceof ShopifyRow) {
                return $match;
            }
        }

        $variantKey = RowKey::variantKey([
            HeaderStore::VARIANT_SKU => $variant->sku,
            HeaderStore::OPTION1_VALUE => $variant->option1_value,
            HeaderStore::OPTION2_VALUE => $variant->option2_value,
            HeaderStore::OPTION3_VALUE => $variant->option3_value,
        ]);
        if ($variantKey === null) {
            return null;
        }

        $match = $rows->first(fn (ShopifyRow $row): bool => trim((string) ($row->variant_key ?? '')) === $variantKey);

        return $match instanceof ShopifyRow ? $match : null;
    }

    private function trimToNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }

    private function toDecimal(mixed $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $normalized = str_replace(' ', '', trim((string) $value));
        if ($normalized === '') {
            return null;
        }

        if (str_contains($normalized, ',') && ! str_contains($normalized, '.')) {
            $normalized = str_replace(',', '.', $normalized);
        } else {
            $normalized = str_replace(',', '', $normalized);
        }

        return is_numeric($normalized) ? (float) $normalized : null;
    }

    private function toInteger(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string) $value);

        return is_numeric($normalized) ? (int) $normalized : null;
    }

    private function toBoolean(mixed $value): ?bool
    {
        if ($value === null) {
            return null;
        }

        if (is_bool($value)) {
            return $value;
        }

        return match (strtolower(trim((string) $value))) {
            '1', 'true', 'yes', 'y' => true,
            '0', 'false', 'no', 'n' => false,
            default => null,
        };
    }
}
