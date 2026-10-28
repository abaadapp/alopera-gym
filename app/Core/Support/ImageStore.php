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
            return $file->storeAs($dir, $name.'.'.strtolower($file->guessExtension() ?: 'jpg'), 'public');
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
        Storage::disk('public')->put($path, $bytes);

        return $path;
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('public')->delete($path);
        }
    }
}
