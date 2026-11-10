<?php

return [
    // Reuse the application's existing database queue and its Supervisor worker.
    'connection' => env('ALT_TEXT_EXPORT_QUEUE_CONNECTION', 'database'),
    'queue' => env('ALT_TEXT_EXPORT_QUEUE', env('DB_QUEUE', 'default')),
    'disk' => env('ALT_TEXT_EXPORT_DISK', 'local'),
    'retention_hours' => 24,
    'batch_size' => 10,
    'download_timeout' => 15,
    'max_image_bytes' => 50 * 1024 * 1024,
];
