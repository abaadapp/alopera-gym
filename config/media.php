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
    // A 48MP phone photo is 8064×6048 (48.8 million pixels): MEDIA_MAX_PIXELS=50000000 lets it in. The server still
    // decodes only what its memory allows; anything larger is shown from the browser's display source.
    'max_side' => 20_000,
    'max_pixels' => (int) env('MEDIA_MAX_PIXELS', 40_000_000),

    // The most one request may carry (nginx client_max_body_size, 20m today): the website library sends images in
    // batches under it, and the product form says so before sending.
    'max_request_kb' => (int) env('MEDIA_MAX_REQUEST_KB', 20_480),

    // The thumbnail's longest side, in pixels.
    'thumb_side' => 360,

    // A display source the browser made (a HEIC or a very large photo): its longest side and size at most.
    'preview_max_side' => 2_400,
    'preview_max_kb' => 4_096,

    // An animated GIF up to this size is shown as it is (moving); a larger one is shown by its first frame.
    'animated_max_kb' => 4_096,

    // Where originals are kept: 'local' (storage/app/private, in the club's folder) or 'spaces' — a private
    // DigitalOcean Spaces bucket the browser uploads to directly, without the original passing through the app
    // (App\Core\Support\Images\SpacesOriginals). 'spaces' is used only when every setting below is set too. It is
    // off until the owner approves the restore plan in docs/MEDIA.md; images kept before stay where they are.
    'originals' => env('MEDIA_ORIGINALS', 'local'),

    'spaces' => [
        'key' => env('SPACES_KEY'),
        'secret' => env('SPACES_SECRET'),
        'region' => env('SPACES_REGION'),
        'bucket' => env('SPACES_BUCKET'),
        'endpoint' => env('SPACES_ENDPOINT'),
        // Uploads not yet used; the bucket's lifecycle rule removes them after a day.
        'prefix' => 'incoming',
        'ttl' => 600,
    ],
];
