<?php

namespace App\Services;

use App\Models\Import;
use App\Models\Product;
use App\Models\Variant;
use App\Models\Image;
use App\Models\Category;
use App\Models\Color;
use App\Models\Status;
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

                $categoryName = $primary->get(HeaderStore::PRODUCT_CATEGORY, null);
                $googleCategory = $primary->get(HeaderStore::GOOGLE_PRODUCT_CATEGORY, null);
                $category = $this->syncCategory($categoryName, $googleCategory);
                $this->syncColors($primary->get(HeaderStore::COLOR_METAFIELD, null));
                $this->syncStatus($primary->get(HeaderStore::STATUS, null));

                $product = Product::create([
                    'import_id' => $import->id,
                    'handle' => $handle,
                    'title' => $primary->get(HeaderStore::TITLE, null),
                    'body_html' => $primary->get(HeaderStore::BODY_HTML, null),
                    'vendor' => $primary->get(HeaderStore::VENDOR, null),
                    'tags' => $primary->get(HeaderStore::TAGS, null),
                    'product_category' => $category?->name,
                    'google_product_category' => $category?->google_product_category,
                    'status' => $primary->get(HeaderStore::STATUS, null),
                    'seo_title' => $primary->get(HeaderStore::SEO_TITLE, null),
                    'seo_description' => $primary->get(HeaderStore::SEO_DESCRIPTION, null),
                    'color_string' => $primary->get(HeaderStore::COLOR_METAFIELD, null),
                    'batch' => $this->defaultBatchForImport($import),
                    'is_bundle' => $this->inferIsBundle($handle, $primary->get(HeaderStore::TITLE, null)),
                ]);

                // Variants (include primary row if it contains variant data)
                $variantRows = $handleRows->filter(function (ShopifyRow $r) {
                    return $r->variant_key !== null;
                });
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

    private function syncCategory(?string $name, ?string $googleCategory): ?Category
    {
        $name = $this->normalizeValue($name);
        if ($name === null) {
            return null;
        }

        $lower = strtolower($name);
        $category = Category::whereRaw('LOWER(name) = ?', [$lower])->first();

        if (!$category) {
            return Category::create([
                'name' => $name,
                'google_product_category' => $this->normalizeValue($googleCategory),
                'active' => true,
            ]);
        }

        if (!$category->google_product_category && $this->normalizeValue($googleCategory)) {
            $category->update(['google_product_category' => $this->normalizeValue($googleCategory)]);
        }

        return $category;
    }

    private function syncColors(?string $colorString): void
    {
        $colorString = $this->normalizeValue($colorString);
        if ($colorString === null) {
            return;
        }

        $parts = str_contains($colorString, ';')
            ? explode(';', $colorString)
            : explode(',', $colorString);

        foreach ($parts as $part) {
            $name = $this->normalizeValue($part);
            if ($name === null) {
                continue;
            }

            $lower = strtolower($name);
            $existing = Color::whereRaw('LOWER(name) = ?', [$lower])->first();
            if (!$existing) {
                Color::create(['name' => $name, 'active' => true]);
            }
        }
    }

    private function normalizeValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
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
}
