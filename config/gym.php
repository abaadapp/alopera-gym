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
    // One image, as uploaded. Raising it needs the server to accept as much first: PHP upload_max_filesize and
    // post_max_size, and nginx client_max_body_size (deploy/nginx, 20m today) — see docs/MEDIA.md.
    'max_image_kb' => (int) env('GYM_MAX_IMAGE_KB', 4096),

    // Exports. An Excel file is built whole in memory (about 67 MB for 5,000 sale rows, 193 MB for 20,000, measured on
    // the report writer), so a list longer than this is refused in Excel with a message pointing to CSV, which is
    // streamed row by row and holds everything. Never cut a file short in silence.
    'exports' => [
        'xlsx_max_rows' => (int) env('GYM_EXPORT_XLSX_MAX_ROWS', 5000),
    ],
];
