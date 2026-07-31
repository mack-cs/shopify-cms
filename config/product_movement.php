<?php

return [
    'timezone' => env('PRODUCT_MOVEMENT_TIMEZONE', 'Africa/Johannesburg'),

    'score_weights' => [
        'average_monthly_units' => (float) env('PRODUCT_MOVEMENT_WEIGHT_AVG_MONTHLY', 25),
        'net_units' => (float) env('PRODUCT_MOVEMENT_WEIGHT_NET_UNITS', 20),
        'sales_consistency' => (float) env('PRODUCT_MOVEMENT_WEIGHT_CONSISTENCY', 20),
        'recency' => (float) env('PRODUCT_MOVEMENT_WEIGHT_RECENCY', 15),
        'order_count' => (float) env('PRODUCT_MOVEMENT_WEIGHT_ORDER_COUNT', 10),
        'current_inventory' => (float) env('PRODUCT_MOVEMENT_WEIGHT_CURRENT_INVENTORY', 5),
        'snapshot_velocity' => (float) env('PRODUCT_MOVEMENT_WEIGHT_SNAPSHOT_VELOCITY', 5),
    ],

    'normalization_caps' => [
        'average_monthly_units' => (float) env('PRODUCT_MOVEMENT_CAP_AVG_MONTHLY', 10),
        'net_units' => (float) env('PRODUCT_MOVEMENT_CAP_NET_UNITS', 60),
        'order_count' => (float) env('PRODUCT_MOVEMENT_CAP_ORDER_COUNT', 30),
        'snapshot_units_per_30_days' => (float) env('PRODUCT_MOVEMENT_CAP_SNAPSHOT_VELOCITY', 10),
        'recency_days' => (int) env('PRODUCT_MOVEMENT_RECENCY_DAYS', 180),
    ],

    'classification' => [
        'fast_score' => (float) env('PRODUCT_MOVEMENT_FAST_SCORE', 70),
        'fast_average_monthly_units' => (float) env('PRODUCT_MOVEMENT_FAST_AVG_MONTHLY', 5),
        'fast_consistency_percentage' => (float) env('PRODUCT_MOVEMENT_FAST_CONSISTENCY', 60),
        'fast_recent_days' => (int) env('PRODUCT_MOVEMENT_FAST_RECENT_DAYS', 45),
        'medium_score' => (float) env('PRODUCT_MOVEMENT_MEDIUM_SCORE', 40),
        'medium_recent_days' => (int) env('PRODUCT_MOVEMENT_MEDIUM_RECENT_DAYS', 120),
        'out_of_stock_ratio' => (float) env('PRODUCT_MOVEMENT_OUT_OF_STOCK_RATIO', 0.70),
        'minimum_snapshot_days' => (int) env('PRODUCT_MOVEMENT_MIN_SNAPSHOT_DAYS', 7),
        'recent_product_days' => (int) env('PRODUCT_MOVEMENT_RECENT_PRODUCT_DAYS', 60),
        'recent_product_sales_threshold' => (int) env('PRODUCT_MOVEMENT_RECENT_PRODUCT_SALES_THRESHOLD', 3),
    ],
];
