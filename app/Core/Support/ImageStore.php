<?php

namespace App\Core\Support;

use App\Core\Support\Images\ImageFacts;
use App\Core\Support\Images\ImageInspector;
use App\Core\Support\Images\ImageRejected;
use App\Core\Support\Images\ImageUpload;
use App\Core\Support\Images\SpacesOriginals;
use App\Core\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * The club's uploaded images (members, trainers, programs, products, posts, the website library, the logo).
 *
 * Each upload is kept three ways, all under the club's own folder:
 *  - the original, byte for byte (checked with SHA-256 after writing), on the private originals disk
 *    (tenants/{id}/originals/{folder}/v2-{ulid}.{ext}); it is never served publicly and is only downloaded;
 *  - the display copy the screens, the website, the PDFs and the prints show, made once at upload and never again:
 *    WebP (PNG for a logo), scaled to fit, upright, transparency kept (tenants/{id}/{folder}/v2-{ulid}.{ext}.webp);
 *  - a thumbnail beside it for lists and cards (…/v2-{ulid}.{ext}.thumb.webp).
 * The record keeps the display copy's path, as before, so every reader and every older image keeps working; images
 * stored before this (any other name) have no original or thumbnail and are shown as they are.
 *
 * An upload counts only when all of it was written and checked: otherwise every piece written is removed and an
 * ImageRejected (a message for the user) is thrown, so no record ever points at a missing file.
 */
final class ImageStore
{
    /** Larger than this many pixels is refused before decoding (a small file can unpack to gigabytes). */
    public const MAX_PIXELS = 40_000_000;

    // v2- : original on the local private disk; v2r- : original in the Spaces bucket (SpacesOriginals).
    private const NAME = '#^(?<dir>tenants/(?<tenant>[^/]+)/(?<folder>.+))/(?<id>v2(?<remote>r?)-[0-9a-z]{26})\.(?<ext>jpg|png|gif|webp|avif|heic|heif)\.(?<view>webp|png|gif)$#D';

    /** Stores an image for a record and returns its display path (scaled to $maxWidth across). */
    public static function store(UploadedFile|ImageUpload $upload, string $folder, int $maxWidth = 1600, int $quality = 80): string
    {
        return self::keep(ImageUpload::wrap($upload), $folder, $maxWidth, $maxWidth * 4, $quality, 'webp')['path'];
    }

    /**
     * Stores a website image and says what its display copy is: the longest side fits $maxSide, the proportions
     * never change.
     *
     * @return array{path: string, width: int, height: int, mime: string, size: int}
     *
     * @throws ImageRejected
     */
    public static function storeMeasured(UploadedFile|ImageUpload $upload, string $folder, int $maxSide = 2400, int $quality = 82): array
    {
        return self::keep(ImageUpload::wrap($upload), $folder, $maxSide, $maxSide, $quality, 'webp');
    }

    /** Stores a logo: the display copy is a PNG (lossless, transparent) that the screens, prints and PDF engine read. */
    public static function storeLogo(UploadedFile|ImageUpload $upload, string $folder, int $maxSide): string
    {
        return self::keep(ImageUpload::wrap($upload), $folder, $maxSide, $maxSide, 100, 'png')['path'];
    }

    /**
     * Stores $upload in place of $old and calls $save to record it. The old files are removed only after the save
     * worked; if the save fails, the new files are removed and the old ones stay, so an edit never loses an image.
     *
     * @param  callable(string): void  $save
     *
     * @throws ImageRejected when the new image could not be stored
     */
    public static function replace(?string $old, UploadedFile|ImageUpload $upload, string $folder, int $maxWidth, callable $save): string
    {
        $path = self::store($upload, $folder, $maxWidth);
        self::commit($old, $path, $save);

        return $path;
    }

    /**
     * Records a stored image with $save, then removes $old; a failed save removes the new image instead.
     *
     * @param  callable(string): void  $save
     */
    public static function commit(?string $old, string $new, callable $save): void
    {
        try {
            $save($new);
        } catch (\Throwable $e) {
            self::delete($new);

            throw $e;
        }
        if ($old && $old !== $new) {
            self::delete($old);
        }
    }

    /** Removes an image: its display copy, its thumbnail and its original. */
    public static function delete(?string $path): void
    {
        if (! $path) {
            return;
        }
        $parts = self::parse($path);
        Storage::disk('public')->delete(array_filter([$path, $parts['thumb'] ?? null]));
        if ($parts && $parts['remote']) {
            // A failed removal leaves an unused object in the bucket (found by its key later); never a missing one.
            if (! SpacesOriginals::delete($parts['original'])) {
                Log::warning('image original not removed from Spaces', ['key' => $parts['original']]);
            }
        } elseif ($parts) {
            Storage::disk(self::originalsDisk())->delete($parts['original']);
        }
    }

    /** The address of the display copy (through the CDN when one is set). */
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }
        $url = Storage::disk('public')->url($path);
        $cdn = rtrim((string) config('media.cdn_url'), '/');

        return $cdn !== '' ? $cdn.(parse_url($url, PHP_URL_PATH) ?: '/'.ltrim($path, '/')) : $url;
    }

    /** The address of the thumbnail for a list or a card; an older image without one gives its display copy. */
    public static function thumbUrl(?string $path): ?string
    {
        $parts = $path ? self::parse($path) : null;

        return self::url($parts['thumb'] ?? $path);
    }

    /** Whether the image was stored with its original kept (images stored before this were not). */
    public static function hasOriginal(?string $path): bool
    {
        $parts = $path !== null ? self::parse($path) : null;

        // An original in the bucket was checked when it was stored; asking again would cost a request per image.
        return $parts !== null && ($parts['remote'] || Storage::disk(self::originalsDisk())->exists($parts['original']));
    }

    /**
     * Sends the original as a download (an older image without one: the stored file). The path must be the current
     * club's; anything else is not found.
     */
    public static function download(?string $path, string $name): Response
    {
        $tenantId = app(TenantContext::class)->require()->id;
        abort_unless($path && str_starts_with($path, 'tenants/'.$tenantId.'/'), 404);
        $parts = self::parse($path);
        $name = trim(preg_replace('/[\\\\\/:*?"<>|\x00-\x1F]+/u', ' ', $name) ?: 'image');
        if ($parts && $parts['remote']) {
            // From the bucket itself, through a 60-second signed address: the file does not pass through the app.
            $mime = collect(ImageInspector::TYPES)->firstWhere(1, $parts['ext'])[0] ?? 'application/octet-stream';

            return redirect()->away(SpacesOriginals::downloadUrl($parts['original'], $name.'.'.$parts['ext'], $mime))
                ->withHeaders(['Cache-Control' => 'private, no-store']);
        }
        // The original when it was kept; otherwise the stored display copy is the best there is.
        [$disk, $file] = $parts && Storage::disk(self::originalsDisk())->exists($parts['original'])
            ? [self::originalsDisk(), $parts['original']] : ['public', $path];
        abort_unless(Storage::disk($disk)->exists($file), 404);
        $ext = pathinfo($file, PATHINFO_EXTENSION);
        $mime = collect(ImageInspector::TYPES)->firstWhere(1, $ext)[0] ?? 'application/octet-stream';

        return Storage::disk($disk)->download($file, $name.'.'.$ext, [
            'Content-Type' => $mime,
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * The pieces of an image stored by this class: where its original and thumbnail are.
     *
     * @return array{original: string, thumb: string, ext: string, remote: bool}|null
     */
    public static function parse(string $path): ?array
    {
        if (! preg_match(self::NAME, $path, $m)) {
            return null;
        }

        return [
            'original' => 'tenants/'.$m['tenant'].'/originals/'.$m['folder'].'/'.$m['id'].'.'.$m['ext'],
            'thumb' => $m['dir'].'/'.$m['id'].'.'.$m['ext'].'.thumb.webp',
            'ext' => $m['ext'],
            'remote' => $m['remote'] === 'r',
        ];
    }

    public static function originalsDisk(): string
    {
        return (string) config('media.originals_disk', 'local');
    }

    /**
     * @return array{path: string, width: int, height: int, mime: string, size: int}
     *
     * @throws ImageRejected
     */
    private static function keep(ImageUpload $upload, string $folder, int $maxWidth, int $maxHeight, int $quality, string $view): array
    {
        $folder = trim($folder, '/');
        $remote = SpacesOriginals::enabled();
        $head = null;
        $ticket = null;
        try {
            if ($upload->ticket !== null) {
                // Put in the bucket by the browser: only its size, its recorded hash and its first 256 KB are read.
                $ticket = SpacesOriginals::open($upload->ticket, $folder, $upload->field);
                $object = SpacesOriginals::head($ticket['k']);
                if (! $object || $object['size'] !== (int) $ticket['n'] || $object['sha256'] !== $ticket['s']) {
                    throw new ImageRejected('لم يكتمل رفع الصورة. ارفعها مرة أخرى.');
                }
                $head = (string) tempnam(sys_get_temp_dir(), 'head-');
                file_put_contents($head, SpacesOriginals::firstBytes($ticket['k']));
                $facts = ImageInspector::inspectHead($head, $object['size']);
            } else {
                $facts = ImageInspector::inspect((string) $upload->original?->getRealPath());
            }
        } catch (ImageRejected $e) {
            if ($head) {
                @unlink($head);
            }

            throw $e->on($upload->field);
        }

        try {
            $stored = self::keepChecked($upload, $facts, $folder, $maxWidth, $maxHeight, $quality, $view, $remote, $ticket);
        } finally {
            if ($head) {
                @unlink($head);
            }
        }
        if ($ticket) {
            // The browser's upload is now kept under its own name; a failure above leaves it for another try (the
            // form keeps the ticket) and the bucket's lifecycle rule removes it after a day.
            SpacesOriginals::delete($ticket['k']);
        }

        return $stored;
    }

    /**
     * @param  array{k: string, s: string, n: int, f: string, o: string}|null  $ticket
     * @return array{path: string, width: int, height: int, mime: string, size: int}
     *
     * @throws ImageRejected
     */
    private static function keepChecked(ImageUpload $upload, ImageFacts $facts, string $folder, int $maxWidth, int $maxHeight, int $quality, string $view, bool $remote, ?array $ticket): array
    {
        $source = $ticket ? null : (string) $upload->original?->getRealPath();
        $tenantId = app(TenantContext::class)->require()->id;
        $name = ($remote ? 'v2r-' : 'v2-').Str::lower((string) Str::ulid()).'.'.$facts->ext;
        $written = [];

        try {
            $original = 'tenants/'.$tenantId.'/originals/'.$folder.'/'.$name;
            if ($remote) {
                $sha256 = $ticket['s'] ?? hash_file('sha256', (string) $source);
                $ok = $ticket ? SpacesOriginals::copy($ticket['k'], $original) : SpacesOriginals::put($original, (string) $source, $sha256, $facts->mime);
                $written[] = ['spaces', $original];
                $kept = $ok ? SpacesOriginals::head($original) : null;
                if (! $kept || $kept['size'] !== $facts->bytes || $kept['sha256'] !== $sha256) {
                    throw new ImageRejected('تعذر حفظ الصورة الأصلية كما هي، فلم يُحفظ شيء. حاول مرة أخرى.');
                }
            } else {
                self::putVerified(self::originalsDisk(), $original, (string) $source);
                $written[] = [self::originalsDisk(), $original];
            }

            $image = self::displaySource($upload, $facts, $source);
            // A moving GIF is shown as it is: re-encoding would keep only its first frame. Its bytes are the original
            // itself, or the browser's copy of it when that is the very same file (same SHA-256 as the ticket).
            $gif = $source ?? ($ticket && $upload->preview && hash_file('sha256', (string) $upload->preview->getRealPath()) === $ticket['s'] ? (string) $upload->preview->getRealPath() : null);
            if ($facts->type === 'gif' && $facts->animated && $gif && $facts->bytes <= (int) config('media.animated_max_kb') * 1024) {
                $display = 'tenants/'.$tenantId.'/'.$folder.'/'.$name.'.gif';
                self::putVerified('public', $display, $gif);
                [$width, $height, $mime, $size] = [$facts->width, $facts->height, 'image/gif', $facts->bytes];
            } else {
                [$image, $width, $height] = self::fit($image, $maxWidth, $maxHeight);
                $bytes = self::encode($image, $view, $quality);
                $display = 'tenants/'.$tenantId.'/'.$folder.'/'.$name.'.'.$view;
                self::put('public', $display, $bytes);
                [$mime, $size] = [$view === 'png' ? 'image/png' : 'image/webp', strlen($bytes)];
            }
            $written[] = ['public', $display];

            $side = (int) config('media.thumb_side', 360);
            [$thumb] = self::fit($image, $side, $side);
            $thumbPath = 'tenants/'.$tenantId.'/'.$folder.'/'.$name.'.thumb.webp';
            self::put('public', $thumbPath, self::encode($thumb, 'webp', 75));
            $written[] = ['public', $thumbPath];
        } catch (\Throwable $e) {
            foreach ($written as [$disk, $path]) {
                $disk === 'spaces' ? SpacesOriginals::delete($path) : Storage::disk($disk)->delete($path);
            }
            if (! $e instanceof ImageRejected) {
                report($e);
            }

            throw ($e instanceof ImageRejected ? $e : new ImageRejected('تعذر حفظ الصورة، فلم يُحفظ شيء. حاول مرة أخرى.', 0, $e))->on($upload->field);
        }

        return ['path' => $display, 'width' => $width, 'height' => $height, 'mime' => $mime, 'size' => $size];
    }

    /**
     * The picture the display copy and the thumbnail are made from: the browser's display source, or the original
     * when the app has it ($source null: the original is only in the bucket, so the display source is required).
     */
    private static function displaySource(ImageUpload $upload, ImageFacts $facts, ?string $source): \GdImage
    {
        if ($upload->preview) {
            $path = (string) $upload->preview->getRealPath();
            $preview = ImageInspector::inspect($path, (int) config('media.preview_max_kb') * 1024);
            $plain = in_array($preview->type, ['jpeg', 'png', 'webp'], true) && ! $preview->animated;
            if (! ($plain || ($preview->type === 'gif' && $facts->type === 'gif'))
                || max($preview->width, $preview->height) > (int) config('media.preview_max_side')
                || ! $facts->sameShapeAs($preview->width, $preview->height)) {
                throw new ImageRejected('نسخة العرض التي جهزها المتصفح لا تطابق الصورة. حاول رفعها مرة أخرى.');
            }
            $image = self::decode($preview, $path);
            if ($image) {
                return $image;
            }
        }
        if ($source === null) {
            throw new ImageRejected('لم تصل نسخة العرض من المتصفح. ارفع الصورة مرة أخرى.');
        }
        if (! self::decodable($facts)) {
            throw new ImageRejected(in_array($facts->type, ['heic', 'heif'], true)
                ? 'لم يستطع هذا المتصفح تجهيز صورة HEIC/HEIF للعرض، ولا يقرؤها الخادم. حدّث المتصفح أو صدّرها JPG ثم ارفعها.'
                : 'الصورة كبيرة جدًا على معالجة الخادم. ارفعها من متصفح حديث ليجهز نسخة العرض تلقائيًا، أو صغّر أبعادها.');
        }
        $image = self::decode($facts, $source);
        if (! $image) {
            throw new ImageRejected('تعذرت قراءة الصورة. قد يكون الملف تالفًا.');
        }

        return $facts->type === 'jpeg' ? self::upright($image, $source) : $image;
    }

    /** GD reads this type here, and there is memory to hold it decoded (about 5 bytes a pixel with the copies). */
    private static function decodable(ImageFacts $facts): bool
    {
        $reader = ['jpeg' => 'imagecreatefromjpeg', 'png' => 'imagecreatefrompng', 'gif' => 'imagecreatefromgif',
            'webp' => 'imagecreatefromwebp', 'avif' => 'imagecreatefromavif'][$facts->type] ?? null;
        if (! $reader || ! function_exists($reader) || ($facts->type === 'webp' && $facts->animated)) {
            return false;
        }
        $limit = self::bytes((string) ini_get('memory_limit'));

        return $limit < 0 || $facts->width * $facts->height * 5 + 16 * 1_048_576 <= $limit - memory_get_usage(true);
    }

    private static function decode(ImageFacts $facts, string $path): ?\GdImage
    {
        $image = match ($facts->type) {
            'jpeg' => @imagecreatefromjpeg($path),
            'png' => @imagecreatefrompng($path),
            'gif' => @imagecreatefromgif($path),
            'webp' => @imagecreatefromwebp($path),
            'avif' => function_exists('imagecreatefromavif') ? @imagecreatefromavif($path) : false,
            default => false,
        };
        if (! $image instanceof \GdImage) {
            return null;
        }
        imagepalettetotruecolor($image);

        return $image;
    }

    /** @return array{0: \GdImage, 1: int, 2: int} the image scaled down to fit, proportions kept (never cropped) */
    private static function fit(\GdImage $image, int $maxWidth, int $maxHeight): array
    {
        [$width, $height] = [imagesx($image), imagesy($image)];
        $scale = min(1, $maxWidth / $width, $maxHeight / $height);
        if ($scale >= 1) {
            return [$image, $width, $height];
        }
        $w = max(1, (int) round($width * $scale));
        $h = max(1, (int) round($height * $scale));
        if ($scale < 0.5 && $width * $height > 8_000_000) {
            // A large step down from a large photo: a fast scale to twice the size first, then the careful one from
            // that much smaller picture (a quarter less time on a 12MP photo for 12 MB more memory; the browser's
            // display source, at most 2400px, never takes this path).
            $half = imagescale($image, $w * 2, $h * 2, IMG_BILINEAR_FIXED);
            if ($half instanceof \GdImage) {
                [$image, $width, $height] = [$half, $w * 2, $h * 2];
            }
        }
        $scaled = imagecreatetruecolor($w, $h);
        imagealphablending($scaled, false);
        imagesavealpha($scaled, true);
        imagefill($scaled, 0, 0, imagecolorallocatealpha($scaled, 0, 0, 0, 127));
        imagecopyresampled($scaled, $image, 0, 0, 0, 0, $w, $h, $width, $height);

        return [$scaled, $w, $h];
    }

    private static function encode(\GdImage $image, string $view, int $quality): string
    {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        ob_start();
        $ok = $view === 'png' ? imagepng($image, null, 9) : (function_exists('imagewebp') && imagewebp($image, null, $quality));
        $bytes = (string) ob_get_clean();
        if (! $ok || $bytes === '') {
            throw new ImageRejected('تعذر تجهيز نسخة العرض من الصورة. حاول مرة أخرى.');
        }

        return $bytes;
    }

    /** Applies a JPEG's EXIF orientation so a phone photo is shown the way it was taken. */
    private static function upright(\GdImage $image, string $jpegPath): \GdImage
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }
        $orientation = (int) ((@exif_read_data($jpegPath) ?: [])['Orientation'] ?? 1);
        $turned = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };

        return $turned instanceof \GdImage ? $turned : $image;
    }

    /** Copies a file to a disk without reading it into memory, then reads it back and compares SHA-256. */
    private static function putVerified(string $disk, string $path, string $source): void
    {
        $expected = hash_file('sha256', $source);
        $stream = fopen($source, 'rb');
        $ok = $stream !== false && Storage::disk($disk)->writeStream($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }
        if (! $ok || self::sha256($disk, $path) !== $expected) {
            Storage::disk($disk)->delete($path);

            throw new ImageRejected('تعذر حفظ الصورة الأصلية كما هي، فلم يُحفظ شيء. حاول مرة أخرى.');
        }
    }

    public static function sha256(string $disk, string $path): ?string
    {
        $stream = Storage::disk($disk)->exists($path) ? Storage::disk($disk)->readStream($path) : null;
        if (! is_resource($stream)) {
            return null;
        }
        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }

    private static function put(string $disk, string $path, string $bytes): void
    {
        if (! Storage::disk($disk)->put($path, $bytes) || Storage::disk($disk)->size($path) !== strlen($bytes)) {
            Storage::disk($disk)->delete($path);

            throw new ImageRejected('تعذر حفظ الصورة، فلم يُحفظ شيء. حاول مرة أخرى.');
        }
    }

    private static function bytes(string $limit): int
    {
        $limit = trim($limit);
        if ($limit === '' || $limit === '-1') {
            return -1;
        }
        $value = (int) $limit;

        return match (strtolower(substr($limit, -1))) {
            'g' => $value * 1_073_741_824,
            'm' => $value * 1_048_576,
            'k' => $value * 1024,
            default => $value,
        };
    }
}
