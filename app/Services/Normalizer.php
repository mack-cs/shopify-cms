<?php

namespace App\Services;

use App\Models\Import;
use App\Models\Product;
use App\Models\Variant;
use App\Models\Image;
use App\Models\ShopifyRow;
use Illuminate\Support\Facades\DB;

final class Normalizer
{
    public function buildNormalizedTables(Import $import): void
    {
        DB::transaction(function () use ($import) {
            // wipe existing normalized records for this import (overwrite safe)
            Product::where('import_id', $import->id)->delete();

            $rows = ShopifyRow::where('import_id', $import->id)
                ->whereNotNull('handle')
                ->orderBy('row_index')
                ->get()
                ->groupBy('handle');

            foreach ($rows as $handle => $handleRows) {
                /** @var ShopifyRow $primary */
                $primary = $handleRows->firstWhere('row_type', 'product_primary') ?? $handleRows->first();

                $product = Product::create([
                    'import_id' => $import->id,
                    'handle' => $handle,
                    'title' => $primary->get(HeaderStore::TITLE, null),
                    'body_html' => $primary->get(HeaderStore::BODY_HTML, null),
                    'vendor' => $primary->get(HeaderStore::VENDOR, null),
                    'tags' => $primary->get(HeaderStore::TAGS, null),
                    'product_category' => $primary->get(HeaderStore::PRODUCT_CATEGORY, null),
                    'google_product_category' => $primary->get(HeaderStore::GOOGLE_PRODUCT_CATEGORY, null),
                    'status' => $primary->get(HeaderStore::STATUS, null),
                    'seo_title' => $primary->get(HeaderStore::SEO_TITLE, null),
                    'seo_description' => $primary->get(HeaderStore::SEO_DESCRIPTION, null),
                    'color_string' => $primary->get(HeaderStore::COLOR_METAFIELD, null),
                    'batch' => $this->defaultBatchForImport($import),
                    'is_bundle' => $this->inferIsBundle($handle, $primary->get(HeaderStore::TITLE, null)),
                ]);

                // Variants
                $variantRows = $handleRows->where('row_type', 'variant');
                foreach ($variantRows as $vr) {
                    Variant::create([
                        'product_id' => $product->id,
                        'sku' => $vr->get(HeaderStore::VARIANT_SKU, null),
                        'barcode' => $vr->get(HeaderStore::VARIANT_BARCODE, null),
                        'option1_name' => $vr->get(HeaderStore::OPTION1_NAME, null),
                        'option1_value' => $vr->get(HeaderStore::OPTION1_VALUE, null),
                        'option2_name' => $vr->get(HeaderStore::OPTION2_NAME, null),
                        'option2_value' => $vr->get(HeaderStore::OPTION2_VALUE, null),
                        'option3_name' => $vr->get(HeaderStore::OPTION3_NAME, null),
                        'option3_value' => $vr->get(HeaderStore::OPTION3_VALUE, null),
                        'price' => $this->toDecimal($vr->get(HeaderStore::VARIANT_PRICE, null)),
                        'compare_at_price' => $this->toDecimal($vr->get(HeaderStore::VARIANT_COMPARE_AT, null)),
                        // add more variant columns if you care
                    ]);
                }

                // Images
                $imageRows = $handleRows->filter(function (ShopifyRow $r) {
                    return trim((string)$r->get(HeaderStore::IMAGE_SRC, '')) !== '';
                });

                foreach ($imageRows as $ir) {
                    Image::create([
                        'product_id' => $product->id,
                        'src' => $ir->get(HeaderStore::IMAGE_SRC, null),
                        'position' => (int)($ir->get(HeaderStore::IMAGE_POSITION, 0) ?: 0) ?: null,
                        'alt_text' => $ir->get(HeaderStore::IMAGE_ALT_TEXT, null),
                    ]);
                }
            }
        });
    }

    private function toDecimal(mixed $v): ?float
    {
        if ($v === null) return null;
        $s = trim((string)$v);
        if ($s === '') return null;
        return (float)$s;
    }

    private function defaultBatchForImport(Import $import): string
    {
        $stamp = $import->created_at?->format('YmdH') ?? now()->format('YmdH');
        return "import_{$stamp}";
    }

    private function inferIsBundle(?string $handle, ?string $title): bool
    {
        $haystack = trim(($handle ?? '') . ' ' . ($title ?? ''));
        if ($haystack === '') {
            return false;
        }

        return preg_match('/\\b(trio|quad)\\b/i', $haystack) === 1;
    }
}
