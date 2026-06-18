<?php

return [
    'sitemap_url' => env('SITE_AUDIT_SITEMAP_URL', 'https://leighavenue.co.za/sitemap.xml'),
    'queue' => env('SITE_AUDIT_QUEUE', env('DB_QUEUE', 'default')),
    'request_timeout_seconds' => (int) env('SITE_AUDIT_REQUEST_TIMEOUT_SECONDS', 20),
    'check_timeout_seconds' => (int) env('SITE_AUDIT_CHECK_TIMEOUT_SECONDS', 15),
    'check_connect_timeout_seconds' => (int) env('SITE_AUDIT_CHECK_CONNECT_TIMEOUT_SECONDS', 10),
    'checks_per_minute' => (int) env('SITE_AUDIT_CHECKS_PER_MINUTE', 20),
    'rate_limit_retry_attempts' => (int) env('SITE_AUDIT_RATE_LIMIT_RETRY_ATTEMPTS', 2),
    'rate_limit_retry_delay_ms' => (int) env('SITE_AUDIT_RATE_LIMIT_RETRY_DELAY_MS', 10000),
    'slow_threshold_ms' => (int) env('SITE_AUDIT_SLOW_THRESHOLD_MS', 3000),
    'user_agent' => env('SITE_AUDIT_USER_AGENT', 'LeighAvenue-SiteAuditBot/1.0'),
];
