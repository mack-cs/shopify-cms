<?php

return [
    'default_lead_time_days' => (int) env('PROCUREMENT_DEFAULT_LEAD_TIME_DAYS', 56),
    'attention_horizon_days' => (int) env('PROCUREMENT_ATTENTION_HORIZON_DAYS', 21),
    'product_movement_daily' => (bool) env('PROCUREMENT_PRODUCT_MOVEMENT_DAILY', true),
    'pipeline_daily' => (bool) env('PROCUREMENT_PIPELINE_DAILY', true),
    'daily_time' => env('PROCUREMENT_DAILY_TIME', '06:30'),
    'timezone' => env('PROCUREMENT_TIMEZONE', 'Africa/Johannesburg'),
    'movement_months' => (int) env('PROCUREMENT_MOVEMENT_MONTHS', 6),
    'python_executable' => env('PROCUREMENT_PYTHON_EXECUTABLE', 'python'),
    'pipeline_path' => env('PROCUREMENT_PIPELINE_PATH', 'D:\\python_projects\\leigh_ml_procurement_v1'),
    'process_timeout_seconds' => (int) env('PROCUREMENT_PROCESS_TIMEOUT_SECONDS', 7200),
    'queue' => env('PROCUREMENT_QUEUE', 'procurement'),
    'movement_source_version' => env('PROCUREMENT_MOVEMENT_SOURCE_VERSION', 'product-movement-v2'),
];
