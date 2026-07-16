<?php

return [
    'site_url' => env('SEARCH_CONSOLE_SITE_URL', ''),

    'service_account_json' => env('SEARCH_CONSOLE_SERVICE_ACCOUNT_JSON', ''),
    'service_account_json_base64' => env('SEARCH_CONSOLE_SERVICE_ACCOUNT_JSON_BASE64', ''),
    'service_account_json_path' => env('SEARCH_CONSOLE_SERVICE_ACCOUNT_JSON_PATH', ''),

    'timezone' => env('SEARCH_CONSOLE_TIMEZONE', env('APP_TIMEZONE', 'Africa/Johannesburg')),
    'auto_import_enabled' => filter_var(env('SEARCH_CONSOLE_AUTO_IMPORT_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'row_limit' => (int) env('SEARCH_CONSOLE_ROW_LIMIT', 25000),
    'max_rows' => (int) env('SEARCH_CONSOLE_MAX_ROWS', 100000),
];
