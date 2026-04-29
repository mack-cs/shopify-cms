<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'shopify' => [
        'shop' => env('SHOPIFY_SHOP'),
        'admin_access_token' => env('SHOPIFY_ADMIN_ACCESS_TOKEN'),
        'api_version' => env('SHOPIFY_API_VERSION', '2026-01'),
        'secret_id' => env('AWS_SHOPIFY_SECRET_ID', 'prod/leighavenue/shopify'),
        'secret_cache_key' => env('AWS_SHOPIFY_SECRET_CACHE_KEY', 'shopify.admin_access_token'),
        'secret_cache_ttl' => env('AWS_SHOPIFY_SECRET_CACHE_TTL', 900),
        'secret_region' => env('AWS_SHOPIFY_SECRET_REGION', env('AWS_DEFAULT_REGION', 'us-east-1')),
        'secret_version' => env('AWS_SHOPIFY_SECRET_VERSION', 'latest'),
        'secret_profile' => env('AWS_SHOPIFY_SECRET_PROFILE'),
    ],

];
