<?php

return [
    // The gym whose website the bare address (/) opens: its web-address part (slug). Empty: the only gym on record.
    // The gym's own rules live in tenants.settings (see Tenant::DEFAULT_SETTINGS).
    'home_club' => env('GYM_HOME_CLUB'),

    'devices_rate_limit' => (int) env('DEVICES_RATE_LIMIT_PER_MINUTE', 120),

    'backup_disk' => env('BACKUP_DISK', 'local'),

    // Optional modules. A switched-off module keeps its tables and data, but its screens and routes are
    // unavailable and its permissions are not granted. Sales (products, point of sale, website shop and orders)
    // and inventory with purchasing (stock per branch, counts, transfers, purchase orders, supplier bills) are on.
    'modules' => [
        'sales' => (bool) env('GYM_SALES_MODULE', true),
        'inventory' => (bool) env('GYM_INVENTORY_MODULE', true),
    ],

    // Upload limits (kilobytes)
    'max_document_kb' => 10240,
    'max_image_kb' => 4096,
];
