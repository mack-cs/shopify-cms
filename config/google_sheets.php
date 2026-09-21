<?php

return [
    'enabled' => filter_var(env('GOOGLE_SHEETS_ENABLED', false), FILTER_VALIDATE_BOOLEAN),
    'spreadsheet_id' => env('GOOGLE_SHEETS_SPREADSHEET_ID', ''),
    'master_tab' => env('GOOGLE_SHEETS_MASTER_TAB', 'master-file'),
    'change_log_tab' => env('GOOGLE_SHEETS_CHANGE_LOG_TAB', 'Change Log'),
    'service_account_json' => env('GOOGLE_SHEETS_SERVICE_ACCOUNT_JSON', ''),
    'service_account_json_base64' => env('GOOGLE_SHEETS_SERVICE_ACCOUNT_JSON_BASE64', ''),
    'service_account_json_path' => env('GOOGLE_SHEETS_SERVICE_ACCOUNT_JSON_PATH', ''),
    'timeout_seconds' => (int) env('GOOGLE_SHEETS_TIMEOUT_SECONDS', 60),
    'lock_seconds' => (int) env('GOOGLE_SHEETS_LOCK_SECONDS', 14400),
    'collection_mapping_enabled' => filter_var(
        env('GOOGLE_SHEETS_COLLECTION_MAPPING_ENABLED', env('GOOGLE_SHEETS_ENABLED', false)),
        FILTER_VALIDATE_BOOLEAN,
    ),
    'collection_mapping_spreadsheet_id' => env('GOOGLE_SHEETS_COLLECTION_MAPPING_SPREADSHEET_ID', ''),
    'collection_mapping_timeout_seconds' => (int) env('GOOGLE_SHEETS_COLLECTION_MAPPING_TIMEOUT_SECONDS', 30),
    'collection_mapping_lock_seconds' => (int) env('GOOGLE_SHEETS_COLLECTION_MAPPING_LOCK_SECONDS', 300),
    'timezone' => env('GOOGLE_SHEETS_TIMEZONE', env('APP_TIMEZONE', 'Africa/Johannesburg')),
];
