<?php

return [
    // Platform-wide defaults. Club-level rules live in tenants.settings (see Tenant::DEFAULT_SETTINGS).
    'platform_currency' => env('PLATFORM_CURRENCY', 'OMR'),
    'platform_admin_email' => env('PLATFORM_ADMIN_EMAIL'),
    'platform_sales_email' => env('PLATFORM_SALES_EMAIL'),

    // Days a club keeps working after its platform subscription lapses.
    'subscription_grace_days' => 7,

    'devices_rate_limit' => (int) env('DEVICES_RATE_LIMIT_PER_MINUTE', 120),

    'backup_disk' => env('BACKUP_DISK', 'local'),

    // Optional modules. A switched-off module keeps its tables and data, but its screens and routes are
    // unavailable and its permissions are not granted. Sales (products, point of sale, website shop and orders)
    // and inventory with purchasing (stock per branch, counts, transfers, purchase orders, supplier bills) are on.
    'modules' => [
        'sales' => (bool) env('GYM_SALES_MODULE', true),
        'inventory' => (bool) env('GYM_INVENTORY_MODULE', true),
        // The platform owner's screens (/platform) and the platform's marketing page (/) are switched off: clubs are
        // managed from the command line (php artisan clubs:list), and / opens the staff sign-in. Code and data stay.
        'platform_panel' => (bool) env('GYM_PLATFORM_PANEL', false),
        'marketing_site' => (bool) env('GYM_MARKETING_SITE', false),
    ],

    // Upload limits (kilobytes)
    'max_document_kb' => 10240,
    'max_image_kb' => 4096,
];
