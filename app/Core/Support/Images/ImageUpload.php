<?php

namespace App\Core\Support\Images;

use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;

/**
 * One image as the form sent it: the original file and, when the browser made one, a light display source beside it
 * (`photo` and `photo_preview`; for a list, `photos[2]` and `photos_preview[2]`). The browser makes that source for a
 * HEIC/HEIF file, which most servers cannot decode, and for a very large photo, so the server never has to open it.
 *
 * With originals kept in Spaces, the browser puts the original straight into the bucket and the form sends its
 * ticket instead (`photo_direct`), with the display source as usual: the original never passes through the app.
 */
final class ImageUpload
{
    public function __construct(
        public readonly ?UploadedFile $original,
        public readonly ?UploadedFile $preview = null,
        public readonly ?string $field = null,
        public readonly ?string $ticket = null,
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
        $name = $index === null ? $field : $field.'.'.$index;
        $original = $pick($field);
        if ($original) {
            return new self($original, $pick($field.'_preview'), $name);
        }
        $ticket = $request->input($field.'_direct'.($index === null ? '' : '.'.$index));

        return is_string($ticket) && $ticket !== '' ? new self(null, $pick($field.'_preview'), $name, $ticket) : null;
    }

    /** @return list<self> every image of a list field, each with its own display source */
    public static function all(Request $request, string $field): array
    {
        $files = $request->file($field);
        $tickets = $request->input($field.'_direct');
        $indexes = array_unique([...array_keys(is_array($files) ? $files : []), ...array_keys(is_array($tickets) ? $tickets : [])]);
        sort($indexes);
        $uploads = [];
        foreach ($indexes as $index) {
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

    /** The name the user's file had, for messages. */
    public function name(): string
    {
        return $this->original?->getClientOriginalName() ?? 'الصورة';
    }
}
