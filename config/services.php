<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
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

    // WhatsApp Business Platform (Meta Cloud API). Club credentials are stored
    // per tenant; these values are platform-wide.
    'whatsapp' => [
        // log: nothing leaves the server (development, tests, demos). meta: real sending, through the club's vendor
        // (Meta's Cloud API or 360dialog, chosen on the WhatsApp settings page).
        'driver' => env('WHATSAPP_DRIVER', 'log'), // log|meta
        'graph_url' => 'https://graph.facebook.com',
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v21.0'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'd360_url' => env('WHATSAPP_360DIALOG_URL', 'https://waba-v2.360dialog.io'),
        // Files sent and received in chats: a private disk, served only through the panel.
        'media_disk' => env('WHATSAPP_MEDIA_DISK', 'local'),
        'media_max_mb' => (int) env('WHATSAPP_MEDIA_MAX_MB', 16),
        // How long webhook bodies are kept after processing (they hold customers' messages).
        'webhook_retention_days' => (int) env('WHATSAPP_WEBHOOK_RETENTION_DAYS', 14),
    ],

    'paymob' => [
        'base_url' => env('PAYMOB_BASE_URL', 'https://oman.paymob.com'),
        'intent_ttl_seconds' => 3600,
    ],

];
