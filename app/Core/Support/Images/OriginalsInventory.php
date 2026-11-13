<?php

namespace App\Core\Support\Images;

use App\Core\Support\ImageStore;
use Illuminate\Support\Facades\Storage;

/**
 * Which originals should be in the bucket: one for every display copy on this server whose name says its original is
 * in Spaces (v2r-…), at the key that name gives (ImageStore::parse). The display copies are on the droplet's public disk
 * and in every club archive, so after a club archive is restored this still says which originals its records need.
 */
final class OriginalsInventory
{
    /**
     * @return \Generator<string, string> original key => display copy path
     */
    public static function remote(?string $club = null): \Generator
    {
        foreach (Storage::disk('public')->allFiles($club ? 'tenants/'.$club : 'tenants') as $path) {
            $parts = ImageStore::parse($path);
            if ($parts && $parts['remote']) {
                yield $parts['original'] => $path;
            }
        }
    }

    /** The image type of an original from its key's extension, for the copy's Content-Type. */
    public static function mime(string $key): string
    {
        $ext = strtolower(pathinfo($key, PATHINFO_EXTENSION));

        return collect(ImageInspector::TYPES)->firstWhere(1, $ext)[0] ?? 'application/octet-stream';
    }
}
