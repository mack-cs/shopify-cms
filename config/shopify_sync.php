<?php

return [
    'timezone' => env('SHOPIFY_SYNC_TIMEZONE', 'Africa/Johannesburg'),

    'shopify' => [
        'shop' => env('SHOPIFY_SYNC_SHOP') ?: env('SHOPIFY_SHOP'),
        'admin_access_token' => env('SHOPIFY_SYNC_ADMIN_ACCESS_TOKEN'),
        'secret_id' => env('AWS_SHOPIFY_SYNC_SECRET_ID'),
        'secret_cache_key' => env('AWS_SHOPIFY_SYNC_SECRET_CACHE_KEY', 'shopify_sync.admin_access_token'),
        'secret_cache_ttl' => (int) env('AWS_SHOPIFY_SYNC_SECRET_CACHE_TTL', env('AWS_SHOPIFY_SECRET_CACHE_TTL', 900)),
        'secret_region' => env('AWS_SHOPIFY_SYNC_SECRET_REGION', env('AWS_SHOPIFY_SECRET_REGION', env('AWS_DEFAULT_REGION', 'us-east-1'))),
        'secret_version' => env('AWS_SHOPIFY_SYNC_SECRET_VERSION', env('AWS_SHOPIFY_SECRET_VERSION', 'latest')),
        'secret_profile' => env('AWS_SHOPIFY_SYNC_SECRET_PROFILE', env('AWS_SHOPIFY_SECRET_PROFILE')),
        'fallback_to_default_token' => filter_var(env('SHOPIFY_SYNC_FALLBACK_TO_DEFAULT_TOKEN', true), FILTER_VALIDATE_BOOLEAN),
    ],

    'orders' => [
        'lookback_days' => (int) env('SHOPIFY_SYNC_ORDER_LOOKBACK_DAYS', 3),
        'poll_delay_seconds' => (int) env('SHOPIFY_SYNC_ORDER_POLL_DELAY_SECONDS', 120),
        'first_poll_delay_seconds' => (int) env('SHOPIFY_SYNC_ORDER_FIRST_POLL_DELAY_SECONDS', 60),
        'max_poll_attempts' => (int) env('SHOPIFY_SYNC_ORDER_MAX_POLL_ATTEMPTS', 100),
        'batch_size' => (int) env('SHOPIFY_SYNC_ORDER_BATCH_SIZE', 500),
        'included_financial_statuses' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('SHOPIFY_SYNC_DEMAND_FINANCIAL_STATUSES', 'PAID,PARTIALLY_PAID,FULFILLED,UNFULFILLED'))
        ))),
    ],

    'inventory' => [
        'poll_delay_seconds' => (int) env('SHOPIFY_SYNC_INVENTORY_POLL_DELAY_SECONDS', 120),
        'first_poll_delay_seconds' => (int) env('SHOPIFY_SYNC_INVENTORY_FIRST_POLL_DELAY_SECONDS', 60),
        'max_poll_attempts' => (int) env('SHOPIFY_SYNC_INVENTORY_MAX_POLL_ATTEMPTS', 100),
        'batch_size' => (int) env('SHOPIFY_SYNC_INVENTORY_BATCH_SIZE', 500),
        'quantity_names' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('SHOPIFY_SYNC_INVENTORY_QUANTITY_NAMES', 'available,on_hand,committed,incoming,reserved,damaged,quality_control,safety_stock'))
        ))),
    ],

    's3' => [
        'disk' => env('SHOPIFY_SYNC_S3_DISK', 's3'),
        'raw_orders_prefix' => trim(env('SHOPIFY_SYNC_RAW_ORDERS_PREFIX', 'raw/orders'), '/'),
        'raw_inventory_prefix' => trim(env('SHOPIFY_SYNC_RAW_INVENTORY_PREFIX', 'raw/inventory'), '/'),
    ],
];
