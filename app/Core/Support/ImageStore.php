<?php

namespace App\Core\Support;

use App\Core\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Stores public images (logo, website, trainers, programs) under the club's
 * folder, resized to a sensible maximum and re-encoded as WebP so the website
 * stays fast on mobile. Falls back to the original file if GD cannot read it.
 */
final class ImageStore
{
    public static function store(UploadedFile $file, string $folder, int $maxWidth = 1600, int $quality = 80): string
    {
        $tenantId = app(TenantContext::class)->require()->id;
        $dir = 'tenants/'.$tenantId.'/'.trim($folder, '/');
        $name = Str::random(24);

        $image = function_exists('imagecreatefromstring') ? @imagecreatefromstring((string) file_get_contents($file->getRealPath())) : false;

        if ($image === false || ! function_exists('imagewebp')) {
            return self::written($file->storeAs($dir, $name.'.'.strtolower($file->guessExtension() ?: 'jpg'), 'public'));
        }

        $width = imagesx($image);
        $height = imagesy($image);
        if ($width > $maxWidth) {
            $resized = imagescale($image, $maxWidth, (int) round($height * $maxWidth / $width));
            imagedestroy($image);
            $image = $resized;
        }
        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        ob_start();
        imagewebp($image, null, $quality);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);

        $path = $dir.'/'.$name.'.webp';

        return self::written(Storage::disk('public')->put($path, $bytes) ? $path : false);
    }

    /**
     * The path of a file that was really written, or an exception: a record must never point at a file that is not
     * there, so the caller keeps the old image and tells the user instead of reporting success.
     *
     * @throws \RuntimeException
     */
    private static function written(string|false $path): string
    {
        if ($path === false || $path === '' || ! Storage::disk('public')->exists($path)) {
            throw new \RuntimeException('could not write the image');
        }

        return $path;
    }

    /**
     * Stores $file in place of $old and calls $save to record it. The old file is removed only after the save
     * worked; if the save fails, the new file is removed and the old one stays, so an edit never loses an image.
     *
     * @param  callable(string): void  $save
     *
     * @throws \RuntimeException when the new image could not be written
     */
    public static function replace(?string $old, UploadedFile $file, string $folder, int $maxWidth, callable $save): string
    {
        $path = self::store($file, $folder, $maxWidth);
        try {
            $save($path);
        } catch (\Throwable $e) {
            self::delete($path);

            throw $e;
        }
        if ($old && $old !== $path) {
            self::delete($old);
        }

        return $path;
    }

    /** Larger than this many pixels is refused before decoding (a small file can unpack to gigabytes). */
    public const MAX_PIXELS = 40_000_000;

    /**
     * Stores an image the website shows and says what it is: the same WebP re-encoding as store(), but it refuses a
     * file GD cannot read instead of keeping it as uploaded, turns phone photos upright (EXIF orientation), keeps
     * transparency, and scales down only so the longest side fits $maxSide — the proportions never change.
     * Nothing is written unless the whole image could be encoded, so a failed upload leaves what was there.
     *
     * @return array{path: string, width: int, height: int, mime: string, size: int}
     *
     * @throws \RuntimeException when the file is not an image this server can read
     */
    public static function storeMeasured(UploadedFile $file, string $folder, int $maxSide = 2400, int $quality = 82): array
    {
        $source = (string) $file->getRealPath();
        $info = @getimagesize($source);
        if (! $info || ! in_array($info[2] ?? null, [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new \RuntimeException('not a supported image');
        }
        if ($info[0] < 1 || $info[1] < 1 || $info[0] * $info[1] > self::MAX_PIXELS) {
            throw new \RuntimeException('image too large to process');
        }
        if (! function_exists('imagewebp')) {
            throw new \RuntimeException('webp encoding unavailable');
        }
        $image = @imagecreatefromstring((string) file_get_contents($source));
        if ($image === false) {
            throw new \RuntimeException('unreadable image');
        }

        imagepalettetotruecolor($image);
        $image = self::upright($image, $info[2] === IMAGETYPE_JPEG ? $source : null);
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $maxSide / max($width, $height));
        if ($scale < 1) {
            $newWidth = max(1, (int) round($width * $scale));
            $newHeight = max(1, (int) round($height * $scale));
            $resized = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($resized, false);
            imagesavealpha($resized, true);
            imagefill($resized, 0, 0, imagecolorallocatealpha($resized, 0, 0, 0, 127));
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
            imagedestroy($image);
            [$image, $width, $height] = [$resized, $newWidth, $newHeight];
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);

        ob_start();
        $ok = imagewebp($image, null, $quality);
        $bytes = (string) ob_get_clean();
        imagedestroy($image);
        if (! $ok || $bytes === '') {
            throw new \RuntimeException('encoding failed');
        }

        $tenantId = app(TenantContext::class)->require()->id;
        $path = 'tenants/'.$tenantId.'/'.trim($folder, '/').'/'.Str::random(24).'.webp';
        if (! Storage::disk('public')->put($path, $bytes)) {
            throw new \RuntimeException('could not write the file');
        }

        return ['path' => $path, 'width' => $width, 'height' => $height, 'mime' => 'image/webp', 'size' => strlen($bytes)];
    }

    /** Applies a JPEG's EXIF orientation so a phone photo is stored the way it was taken. */
    private static function upright(\GdImage $image, ?string $jpegPath): \GdImage
    {
        if ($jpegPath === null || ! function_exists('exif_read_data')) {
            return $image;
        }
        $orientation = (int) ((@exif_read_data($jpegPath) ?: [])['Orientation'] ?? 1);
        $turned = match ($orientation) {
            3 => imagerotate($image, 180, 0),
            6 => imagerotate($image, -90, 0),
            8 => imagerotate($image, 90, 0),
            default => null,
        };
        if ($turned instanceof \GdImage) {
            imagedestroy($image);

            return $turned;
        }

        return $image;
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
