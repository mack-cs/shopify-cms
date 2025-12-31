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
    public const COST_PER_ITEM = 'Cost per item';
    public const JEWELRY_MATERIAL = 'Jewelry material (product.metafields.shopify.jewelry-material)';

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
        ];
    }

    public static function variantHeaders(): array
    {
        return [
            self::VARIANT_SKU,
            self::VARIANT_PRICE,
            self::VARIANT_COMPARE_AT,
            self::VARIANT_BARCODE,
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
            'Target gender (product.metafields.shopify.target-gender)',
            'Age group (product.metafields.shopify.age-group)',
            self::COLOR_METAFIELD,
            'Bracelet design (product.metafields.shopify.bracelet-design)',
            'Earring design (product.metafields.shopify.earring-design)',
            'Jewelry material (product.metafields.shopify.jewelry-material)',
            'Jewelry type (product.metafields.shopify.jewelry-type)',
            'Necklace design (product.metafields.shopify.necklace-design)',
            'Complementary products (product.metafields.shopify--discovery--product_recommendation.complementary_products)',
            'Related products (product.metafields.shopify--discovery--product_recommendation.related_products)',
            'Search product boosts (product.metafields.shopify--discovery--product_search_boost.queries)',
        ];
    }
}
