<?php

/*
 * Only what differs from the package defaults (vendor/inertiajs/inertia-laravel/config/inertia.php);
 * the rest is merged in from there.
 *
 * The pages live in resources/js/Pages. The default "js/pages" only resolves on case-insensitive disks
 * (macOS), so on Linux (CI, the server) assertInertia could not find a single page.
 */
return [
    'pages' => [
        'ensure_pages_exist' => false,
        'paths' => [resource_path('js/Pages')],
        'extensions' => ['tsx', 'ts'],
    ],
];
