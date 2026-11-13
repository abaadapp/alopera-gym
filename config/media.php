<?php

/*
 * Uploaded images (App\Core\Support\ImageStore). Each upload is kept three ways:
 *  - the original, byte for byte, on a private disk (it may carry the camera's location), for download only;
 *  - a light display copy (WebP, PNG for a logo) on the public disk, made once at upload;
 *  - a small thumbnail beside it for lists and cards.
 * Nothing here is switched on by default beyond the local disks; Spaces, the CDN and direct upload stay off until set.
 */
return [
    // Where originals are kept. 'local' is storage/app/private, inside the club's folder that club archives collect.
    'originals_disk' => env('MEDIA_ORIGINALS_DISK', 'local'),

    // A pull CDN in front of the public copies, e.g. https://cdn.example.com (it fetches {APP_URL}/storage/...).
    'cdn_url' => env('MEDIA_CDN_URL'),

    // Hard limits checked from the file's header, before any image library opens it.
    'max_side' => 20_000,
    'max_pixels' => 40_000_000,

    // The thumbnail's longest side, in pixels.
    'thumb_side' => 360,

    // A display source the browser made (a HEIC or a very large photo): its longest side and size at most.
    'preview_max_side' => 2_400,
    'preview_max_kb' => 4_096,

    // An animated GIF up to this size is shown as it is (moving); a larger one is shown by its first frame.
    'animated_max_kb' => 4_096,

    // Direct upload from the browser to a DigitalOcean Spaces bucket, through a short-lived signed address. Off unless
    // MEDIA_DIRECT_UPLOAD=true and the bucket's settings are all set.
    'direct_upload' => [
        'enabled' => (bool) env('MEDIA_DIRECT_UPLOAD', false),
        'key' => env('SPACES_KEY'),
        'secret' => env('SPACES_SECRET'),
        'region' => env('SPACES_REGION'),
        'bucket' => env('SPACES_BUCKET'),
        'endpoint' => env('SPACES_ENDPOINT'),
        'prefix' => 'incoming',
        'ttl' => 600,
    ],
];
