<?php

namespace App\Services;

final class HeaderStore
{
    // These match Shopify export headers exactly (as seen in your CSV)
    public const HANDLE = 'Handle';
    public const TITLE = 'Title';
    public const BODY_HTML = 'Body (HTML)';
    public const VENDOR = 'Vendor';
    public const TAGS = 'Tags';
    public const TYPE = 'Type';
    public const PUBLISHED = 'Published';
    public const PRODUCT_CATEGORY = 'Product Category';
    public const GOOGLE_PRODUCT_CATEGORY = 'Google Shopping / Google Product Category';
    public const GOOGLE_SHOPPING_GENDER = 'Google Shopping / Gender';
    public const GOOGLE_SHOPPING_AGE_GROUP = 'Google Shopping / Age Group';

    // From your CSV:
    public const COLOR_METAFIELD = 'Color (product.metafields.shopify.color-pattern)';

    // Common Shopify variant fields (confirm your exact header names in CSV):
    public const VARIANT_SKU = 'Variant SKU';
    public const VARIANT_PRICE = 'Variant Price';
    public const VARIANT_COMPARE_AT = 'Variant Compare At Price';
    public const VARIANT_BARCODE = 'Variant Barcode';
    public const VARIANT_GRAMS = 'Variant Grams';
    public const VARIANT_WEIGHT_UNIT = 'Variant Weight Unit';
    public const VARIANT_INVENTORY_QTY = 'Variant Inventory Qty';

    public const OPTION1_NAME = 'Option1 Name';
    public const OPTION1_VALUE = 'Option1 Value';
    public const OPTION2_NAME = 'Option2 Name';
    public const OPTION2_VALUE = 'Option2 Value';
    public const OPTION3_NAME = 'Option3 Name';
    public const OPTION3_VALUE = 'Option3 Value';

    public const IMAGE_SRC = 'Image Src';
    public const IMAGE_POSITION = 'Image Position';
    public const IMAGE_ALT_TEXT = 'Image Alt Text';

    public const STATUS = 'Status'; // if present
    public const SEO_TITLE = 'SEO Title'; // if present in file
    public const SEO_DESCRIPTION = 'SEO Description'; // if present in file
    public const SEO_DEINDEX = 'SEO: Deindex_Products (product.metafields.seo.hide_from_google)';
    public const COST_PER_ITEM = 'Cost per item';
    public const MATERIAL_COST = 'Material Cost';
    public const PRODUCT_MATERIALS = 'Product Materials (product.metafields.custom.product_materials)';
    public const PRODUCT_METALS = 'Product Metals (product.metafields.custom.product_metals)';
    public const PATTERN_CATEGORY = 'Pattern Category (product.metafields.custom.pattern_category)';
    public const SIZE = 'Size';
    public const SIBLINGS = 'Siblings';
    public const SIBLINGS_COLLECTION_NAME = 'Siblings Collection Name';
    public const UVP_SHORT_PARAGRAPH = 'UVP Short Paragraph';
    public const COMPLEMENTARY_PRODUCTS = 'Complementary products (product.metafields.shopify--discovery--product_recommendation.complementary_products)';
    public const JEWELRY_MATERIAL = 'Jewelry material (product.metafields.shopify.jewelry-material)';
    public const MATERIALS_AND_DIMENSIONS = 'Materials and Dimensions (product.metafields.custom.materials_and_dimensions)';
    public const JEWELRY_TYPE = 'Jewelry type (product.metafields.shopify.jewelry-type)';
    public const TARGET_GENDER = 'Target gender (product.metafields.shopify.target-gender)';
    public const AGE_GROUP = 'Age group (product.metafields.shopify.age-group)';
    public const BRACELET_DESIGN = 'Bracelet design (product.metafields.shopify.bracelet-design)';

    public static function productHeaders(): array
    {
        return [
            self::HANDLE,
            self::TITLE,
            self::BODY_HTML,
            self::VENDOR,
            self::PRODUCT_CATEGORY,
            self::GOOGLE_PRODUCT_CATEGORY,
            self::TAGS,
            self::TYPE,
            self::PUBLISHED,
            self::STATUS,
            self::SEO_TITLE,
            self::SEO_DESCRIPTION,
            self::COLOR_METAFIELD,
            self::MATERIALS_AND_DIMENSIONS,
            self::JEWELRY_MATERIAL,
            self::JEWELRY_TYPE,
            self::TARGET_GENDER,
            self::AGE_GROUP,
            self::BRACELET_DESIGN,
        ];
    }

    public static function variantHeaders(): array
    {
        return [
            self::VARIANT_SKU,
            self::VARIANT_PRICE,
            self::VARIANT_COMPARE_AT,
            self::VARIANT_BARCODE,
            self::VARIANT_GRAMS,
            self::VARIANT_WEIGHT_UNIT,
            self::OPTION1_NAME,
            self::OPTION1_VALUE,
            self::OPTION2_NAME,
            self::OPTION2_VALUE,
            self::OPTION3_NAME,
            self::OPTION3_VALUE,
        ];
    }

    public static function imageHeaders(): array
    {
        return [
            self::IMAGE_SRC,
            self::IMAGE_POSITION,
            self::IMAGE_ALT_TEXT,
        ];
    }

    public static function knownHeaders(): array
    {
        return array_values(array_unique(array_merge(
            self::productHeaders(),
            self::variantHeaders(),
            self::imageHeaders(),
        )));
    }

    public static function extraProductHeaders(array $headers): array
    {
        if (empty($headers)) {
            return [];
        }

        $known = self::knownHeaders();

        return array_values(array_filter(
            $headers,
            fn (string $header) => !in_array($header, $known, true)
        ));
    }

    public static function semicolonSeparatedHeaders(): array
    {
        return [
            self::GOOGLE_SHOPPING_GENDER,
            self::GOOGLE_SHOPPING_AGE_GROUP,
            self::TARGET_GENDER,
            self::AGE_GROUP,
            self::COLOR_METAFIELD,
            self::BRACELET_DESIGN,
            'Earring design (product.metafields.shopify.earring-design)',
            self::JEWELRY_MATERIAL,
            self::JEWELRY_TYPE,
            'Necklace design (product.metafields.shopify.necklace-design)',
            'Complementary products (product.metafields.shopify--discovery--product_recommendation.complementary_products)',
            'Related products (product.metafields.shopify--discovery--product_recommendation.related_products)',
            'Search product boosts (product.metafields.shopify--discovery--product_search_boost.queries)',
        ];
    }

    public static function latestTemplatePath(): ?string
    {
        $templateDir = storage_path('app/public/template');
        $paths = glob($templateDir . '/*.csv') ?: [];
        $paths = array_values(array_filter($paths, function (string $path): bool {
            $name = strtolower(basename($path));
            if (str_contains($name, 'drp-downs')) {
                return false;
            }
            if (str_contains($name, 'dropdown')) {
                return false;
            }
            if (str_contains($name, 'products_export')) {
                return true;
            }
            return false;
        }));

        if (empty($paths)) {
            $legacyPath = storage_path('app/private/imports/products.csv');
            return is_file($legacyPath) ? $legacyPath : null;
        }

        usort($paths, fn (string $a, string $b): int => filemtime($b) <=> filemtime($a));
        return $paths[0] ?? null;
    }
}
