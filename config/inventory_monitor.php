<?php

return [
    'enabled' => env('INVENTORY_MONITOR_ENABLED', false),
    'recipient' => env('INVENTORY_MONITOR_EMAIL', 'shonayimack@mackscs.com'),
    'pending_minutes' => (int) env('INVENTORY_MONITOR_PENDING_MINUTES', 60),
    'processing_minutes' => (int) env('INVENTORY_MONITOR_PROCESSING_MINUTES', 15),
    'queue_minutes' => (int) env('INVENTORY_MONITOR_QUEUE_MINUTES', 15),
    'snapshot_grace_minutes' => (int) env('INVENTORY_MONITOR_SNAPSHOT_GRACE_MINUTES', 10),
    'reminder_minutes' => (int) env('INVENTORY_MONITOR_REMINDER_MINUTES', 1440), // 0 disables reminders.
    'delivery_retry_minutes' => (int) env('INVENTORY_MONITOR_DELIVERY_RETRY_MINUTES', 5),
    'delivery_batch' => 50,
    'queues' => ['inventory-ledger', 'inventory-events', 'inventory-receipts', 'inventory-sync', 'inventory-reconciliation'],
];
