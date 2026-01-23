<?php

namespace App\Services;

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
use Illuminate\Support\Facades\DB;
use App\Services\TagNormalizer;

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
                if ($primary) {
                    $data = $primary->data ?? [];
                    $targetGender = trim((string) ($data[HeaderStore::TARGET_GENDER] ?? ''));
                    $ageGroup = trim((string) ($data[HeaderStore::AGE_GROUP] ?? ''));

                    $updated = false;
                    if ($targetGender === '') {
                        $data[HeaderStore::TARGET_GENDER] = 'unisex';
                        $updated = true;
                    }
                    if ($ageGroup === '') {
                        $data[HeaderStore::AGE_GROUP] = 'adults';
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

                $this->syncCategory($resolvedCategory, $resolvedGoogle);
                $this->syncColors($normalizedColor);
                $this->syncTags($normalizedTags);
                $this->syncStatus($primary->get(HeaderStore::STATUS, null));
                $this->syncType($resolvedType, $resolvedGoogle);

                $product = Product::create([
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
                    'color_string' => $normalizedColor,
                    'batch' => $this->defaultBatchForImport($import),
                    'is_bundle' => $this->isBundleFromTags($normalizedTags),
                ]);

                // Variants (include primary row if it contains variant data)
                $variantRows = $handleRows->filter(function (ShopifyRow $r) {
                    return $r->variant_key !== null;
                });
                $firstSku = null;
                foreach ($variantRows as $vr) {
                    $sku = $this->normalizeValue($vr->get(HeaderStore::VARIANT_SKU, null));
                    $barcode = $this->normalizeValue($vr->get(HeaderStore::VARIANT_BARCODE, null)) ?? $sku;
                    if ($firstSku === null && $sku !== null) {
                        $firstSku = $sku;
                    }
                    Variant::create([
                        'product_id' => $product->id,
                        'sku' => $sku,
                        'barcode' => $barcode,
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
                $imageRow = $imageRows
                    ->sortBy(fn (ShopifyRow $row) => (int) ($row->get(HeaderStore::IMAGE_POSITION, 0) ?: 0))
                    ->first();
                $imageUrl = $this->normalizeValue($imageRow?->get(HeaderStore::IMAGE_SRC, null));
                $imageAlt = $this->normalizeValue($imageRow?->get(HeaderStore::IMAGE_ALT_TEXT, null));

                foreach ($imageRows as $ir) {
                    Image::create([
                        'product_id' => $product->id,
                        'src' => $ir->get(HeaderStore::IMAGE_SRC, null),
                        'position' => (int)($ir->get(HeaderStore::IMAGE_POSITION, 0) ?: 0) ?: null,
                        'alt_text' => $ir->get(HeaderStore::IMAGE_ALT_TEXT, null),
                    ]);
                }

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

    private function defaultBatchForImport(Import $import): string
    {
        $stamp = $import->created_at?->format('YmdH') ?? now()->format('YmdH');
        return "import_{$stamp}";
    }

    private function isBundleFromTags(?string $tags): bool
    {
        $tokens = TagNormalizer::parseTokens($tags);
        if (empty($tokens)) {
            return false;
        }

        return in_array('bundle', $tokens, true) || in_array('bundles', $tokens, true);
    }

    private function syncCategory(?string $name, ?string $googleCategory): ?Category
    {
        $name = $this->normalizeValue($name);
        if ($name === null) {
            return null;
        }

        $resolved = CategoryTypeMap::byCategory($name);
        if (!$resolved) {
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

    private function normalizeValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
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

        $requiredRowFields = $requiredDefinitions['row'];

        foreach ($requiredRowFields as $field) {
            $attribute = $field['attribute'];
            $label = $field['label'] ?? $attribute;
            $rowValue = $primary?->get($attribute, null);
            if ($this->normalizeValue($rowValue) === null) {
                $errors[] = "missing:{$label}";
            }
        }

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
        return match ($attribute) {
            'sku' => $variant->sku,
            'barcode' => $variant->barcode,
            'price' => $variant->price,
            'compare_at_price' => $variant->compare_at_price,
            'option1_name' => $variant->option1_name,
            'option1_value' => $variant->option1_value,
            'option2_name' => $variant->option2_name,
            'option2_value' => $variant->option2_value,
            'option3_name' => $variant->option3_name,
            'option3_value' => $variant->option3_value,
            'weight_unit' => $variant->weight_unit,
            default => null,
        };
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
