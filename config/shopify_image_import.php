<?php

return [
    'disk' => env('SHOPIFY_IMAGE_IMPORT_DISK', 'shopify_product_images'),
    'root_prefix' => env('SHOPIFY_IMAGE_IMPORT_ROOT_PREFIX', 'incoming'),
];
