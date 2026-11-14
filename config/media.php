<?php

/*
 * Uploaded images (App\Core\Support\ImageStore). With MEDIA_STORAGE=originals each upload is kept three ways:
 *  - the original, byte for byte, on a private disk (it may carry the camera's location), for download only;
 *  - a light display copy (WebP, PNG for a logo) on the public disk, made once at upload;
 *  - a small thumbnail beside it for lists and cards.
 * Nothing here is switched on by default beyond the local disks; Spaces, the CDN and direct upload stay off until set.
 */
return [
    // How each new upload is kept; images kept before stay as they are, under their own names, in either mode:
    //  - 'optimized-only' (the default, approved by the owner with the release of PR #158): the browser makes the light
    //    copy before sending (a phone sends tens of KB, not megabytes) and the server keeps only that copy and its
    //    thumbnail, never the heavy original. A download then gives that copy, never called "the original";
    //  - 'originals': the three pieces above. On the droplet each original also counts in all 14 nightly club
    //    archives (docs/MEDIA.md), so it is the way back, not the way to run.
    'storage' => env('MEDIA_STORAGE', 'optimized-only'),

    // The display copy's longest side, by use (a smaller picture is never enlarged, nothing is ever cropped). A folder
    // not listed keeps the size its caller gives. The logo keeps its own (App\Core\Branding\OfficialLogo).
    'sides' => [
        'members' => (int) env('MEDIA_SIDE_MEMBERS', 800),
        'products' => (int) env('MEDIA_SIDE_PRODUCTS', 1200),
        'trainers' => (int) env('MEDIA_SIDE_TRAINERS', 1600),
        'programs' => (int) env('MEDIA_SIDE_PROGRAMS', 1600),
        'posts' => (int) env('MEDIA_SIDE_POSTS', 1920),
        'site' => (int) env('MEDIA_SIDE_SITE', 1920),
    ],

    // WebP quality of photos (the browser's and the server's), and of thumbnails.
    'quality' => (int) env('MEDIA_QUALITY', 82),
    'thumb_quality' => (int) env('MEDIA_THUMB_QUALITY', 75),

    // optimized-only: the largest file a person may pick. The browser makes the light copy from it; a file this large
    // is never sent (what is sent still has to fit gym.max_image_kb).
    'pick_max_kb' => (int) env('MEDIA_PICK_MAX_KB', 40_960),

    // optimized-only: a JPEG, PNG or WebP that is already light (at most this many bits a pixel, no larger than its
    // use needs, no camera data: EXIF, XMP, text) is kept as it came, not compressed a second time. Anything else is
    // made light by the server, and loses its camera data on the way.
    'keep_bits_per_pixel' => 4,

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

    // Settings of their own (MEDIA_SPACES_*), never the SPACES_* that scripts/backup-database.sh reads: the originals
    // never share the database copies' bucket. The endpoint is the region's own (https://fra1.digitaloceanspaces.com),
    // never the CDN one, which takes signed uploads only up to 8100 KiB.
    'spaces' => [
        'key' => env('MEDIA_SPACES_KEY'),
        'secret' => env('MEDIA_SPACES_SECRET'),
        'region' => env('MEDIA_SPACES_REGION'),
        'bucket' => env('MEDIA_SPACES_BUCKET'),
        'endpoint' => env('MEDIA_SPACES_ENDPOINT'),
        // Uploads not yet used; the bucket's lifecycle rule removes them after a day.
        'prefix' => 'incoming',
        'ttl' => 600,
    ],

    // The independent copy of the originals (docs/MEDIA.md, restore plan): another bucket, in another region or with
    // another provider, under a key of its own. Used only by media:originals-copy and media:originals-check, by hand or
    // when scheduled after approval; nothing in the app writes to it.
    'copy' => [
        'key' => env('MEDIA_COPY_KEY'),
        'secret' => env('MEDIA_COPY_SECRET'),
        'region' => env('MEDIA_COPY_REGION'),
        'bucket' => env('MEDIA_COPY_BUCKET'),
        'endpoint' => env('MEDIA_COPY_ENDPOINT'),
    ],
];
