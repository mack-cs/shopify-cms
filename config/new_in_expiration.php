<?php

return [
    /*
    | Schedule flag: gates the 03:15 daily sweep only.
    | Requires enforcement_enabled=true; otherwise the schedule stays inactive.
    */
    'enabled' => filter_var(env('NEW_IN_EXPIRATION_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | Outbound / assignment enforcement: age lookups, tag stripping, product locks,
    | and mutation-boundary guards. Default false for safe deploy.
    | The UTC cast on products.shopify_created_at is always active (storage correctness).
    */
    'enforcement_enabled' => filter_var(env('NEW_IN_EXPIRATION_ENFORCEMENT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    /*
    | Run recovery independently while the daily sweep is switched off.
    | With daily expiration enabled, recovery runs automatically.
    */
    'recovery_enabled' => filter_var(env('NEW_IN_EXPIRATION_RECOVERY_ENABLED', false), FILTER_VALIDATE_BOOLEAN),

    'days' => (int) env('NEW_IN_EXPIRATION_DAYS', 75),
    'timezone' => env('NEW_IN_EXPIRATION_TIMEZONE', 'Africa/Johannesburg'),
    'schedule_time' => env('NEW_IN_EXPIRATION_SCHEDULE_TIME', '03:15'),
    'queue_connection' => env('NEW_IN_EXPIRATION_QUEUE_CONNECTION') ?: env('QUEUE_CONNECTION', 'database'),
    'queue' => env('NEW_IN_EXPIRATION_QUEUE', 'default'),
    'page_size' => (int) env('NEW_IN_EXPIRATION_PAGE_SIZE', 50),
    'lock_seconds' => (int) env('NEW_IN_EXPIRATION_LOCK_SECONDS', 7200),
    'product_lock_seconds' => (int) env('NEW_IN_EXPIRATION_PRODUCT_LOCK_SECONDS', 180),
];
