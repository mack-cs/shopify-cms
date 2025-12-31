<?php

use App\Services\HeaderStore;

return [
    'product_fields' => [
        'handle',
        'product_category',
        'type',
        'google_product_category',
        'seo_title',
        'seo_description',
        'title',
        'body_html',
        'vendor',
        'tags',
        'color_string',
    ],
    'row_fields' => [
        HeaderStore::JEWELRY_MATERIAL,
        HeaderStore::COST_PER_ITEM,
    ],
    'variant_fields' => [
        'sku',
    ],
];
