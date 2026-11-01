<?php

/*
 * Only what differs from the package defaults (vendor/inertiajs/inertia-laravel/config/inertia.php);
 * the rest is merged in from there.
 *
 * The pages live in resources/js/Pages. The default "js/pages" only resolves on case-insensitive disks
 * (macOS), so on Linux (CI, the server) assertInertia could not find a single page.
 *
 * The pages kept in the browser's history are encrypted with a key held in the session: once someone signs out (a
 * shared reception computer), «back» cannot bring their screens up again. It needs a secure page (HTTPS, or the
 * machine itself in development).
 */
return [
    'history' => [
        'encrypt' => (bool) env('INERTIA_ENCRYPT_HISTORY', true),
    ],

    'pages' => [
        'ensure_pages_exist' => false,
        'paths' => [resource_path('js/Pages')],
        'extensions' => ['tsx', 'ts'],
    ],
];
