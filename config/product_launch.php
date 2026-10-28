<?php

return [
    'enabled' => filter_var(env('PRODUCT_LAUNCH_ENABLED', true), FILTER_VALIDATE_BOOLEAN),
    'queue' => env('PRODUCT_LAUNCH_QUEUE', 'default'),
    /*
    | Comma-separated Shopify publication GIDs offered in the launch confirmation.
    | Launch never publishes a product that was not explicitly selected.
    */
    'publications' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('PRODUCT_LAUNCH_PUBLICATIONS', ''))
    ))),
];
