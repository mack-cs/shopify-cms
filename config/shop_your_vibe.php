<?php

return [
    'low_stock_threshold' => (int) env('SHOP_YOUR_VIBE_LOW_STOCK_THRESHOLD', 5),
    'sort_approval_emails' => filter_var(env('SYV_SORT_APPROVAL_EMAILS', true), FILTER_VALIDATE_BOOLEAN),
    'launch_sort_notify_to' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('SYV_LAUNCH_SORT_NOTIFY_TO', '')),
    ))),
];
