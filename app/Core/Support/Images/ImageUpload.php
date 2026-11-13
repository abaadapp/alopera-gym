<?php

namespace App\Core\Support\Images;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * One image as the form sent it: the original file and, when the browser made one, a light display source beside it
 * (`photo` and `photo_preview`; for a list, `photos[2]` and `photos_preview[2]`). The browser makes that source for a
 * HEIC/HEIF file, which most servers cannot decode, and for a very large photo, so the server never has to open it.
 */
final class ImageUpload
{
    public function __construct(
        public readonly UploadedFile $original,
        public readonly ?UploadedFile $preview = null,
        public readonly ?string $field = null,
    ) {}

    public static function from(Request $request, string $field, ?int $index = null): ?self
    {
        $pick = function (string $key) use ($request, $index): ?UploadedFile {
            $file = $request->file($key);
            if ($index !== null) {
                $file = is_array($file) ? ($file[$index] ?? null) : null;
            }

            return $file instanceof UploadedFile ? $file : null;
        };
        $original = $pick($field);

        return $original ? new self($original, $pick($field.'_preview'), $index === null ? $field : $field.'.'.$index) : null;
    }

    /** @return list<self> every file of a list field, each with its own display source */
    public static function all(Request $request, string $field): array
    {
        $files = $request->file($field);
        if (! is_array($files)) {
            return [];
        }
        $uploads = [];
        foreach (array_keys($files) as $index) {
            if ($upload = self::from($request, $field, (int) $index)) {
                $uploads[] = $upload;
            }
        }

        return $uploads;
    }

    public static function wrap(UploadedFile|self $upload): self
    {
        return $upload instanceof self ? $upload : new self($upload);
    }
}
