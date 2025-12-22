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
}
