<?php

return [
    // Platform-wide defaults. Club-level rules live in tenants.settings (see Tenant::DEFAULT_SETTINGS).
    'platform_currency' => env('PLATFORM_CURRENCY', 'OMR'),
    'platform_admin_email' => env('PLATFORM_ADMIN_EMAIL'),

    // Days a club keeps working after its platform subscription lapses.
    'subscription_grace_days' => 7,

    'devices_rate_limit' => (int) env('DEVICES_RATE_LIMIT_PER_MINUTE', 120),

    'backup_disk' => env('BACKUP_DISK', 'local'),

    // Upload limits (kilobytes)
    'max_document_kb' => 10240,
    'max_image_kb' => 4096,
];
