<?php

namespace App\Core\Support\Images;

/** What an uploaded image really is, read from its own bytes (never from its name or the browser's claim). */
final class ImageFacts
{
    public function __construct(
        public readonly string $type,
        public readonly string $mime,
        public readonly string $ext,
        public readonly int $width,
        public readonly int $height,
        public readonly bool $animated,
        public readonly int $bytes,
    ) {}

    /** Every current browser shows it as it is (HEIC/HEIF need a display copy for most of them). */
    public function browserShows(): bool
    {
        return in_array($this->type, ['jpeg', 'png', 'gif', 'webp', 'avif'], true);
    }

    /** Same shape as another image, either way up (a phone photo turned by its EXIF orientation counts). */
    public function sameShapeAs(int $width, int $height, float $tolerance = 0.03): bool
    {
        if ($width < 1 || $height < 1) {
            return false;
        }
        $mine = $this->width / $this->height;

        return abs($mine - $width / $height) <= $mine * $tolerance || abs($mine - $height / $width) <= $mine * $tolerance;
    }
}
