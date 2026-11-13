<?php

namespace App\Core\Support\Images;

/**
 * Reads what a file really is from its first bytes, without decoding it: JPEG, PNG, GIF, WebP, AVIF, HEIC or HEIF,
 * its size in pixels and whether it moves. A file that only claims to be an image (a script renamed .jpg, an HTML page,
 * an archive) is refused, and so is one too large to handle safely, before any image library opens it.
 */
final class ImageInspector
{
    /** type => [mime, extension] */
    public const TYPES = [
        'jpeg' => ['image/jpeg', 'jpg'],
        'png' => ['image/png', 'png'],
        'gif' => ['image/gif', 'gif'],
        'webp' => ['image/webp', 'webp'],
        'avif' => ['image/avif', 'avif'],
        'heic' => ['image/heic', 'heic'],
        'heif' => ['image/heif', 'heif'],
    ];

    /** The extensions a file picker may offer (the content decides, these only help the picker). */
    public const EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'avif', 'heic', 'heif'];

    private const HEAD = 262_144;

    private const HEIC_BRANDS = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis'];

    /** @throws ImageRejected */
    public static function inspect(string $path, ?int $maxBytes = null): ImageFacts
    {
        $bytes = is_file($path) ? (int) @filesize($path) : 0;
        if ($bytes < 1) {
            throw new ImageRejected('الملف فارغ أو تعذرت قراءته.');
        }
        $maxBytes ??= (int) config('gym.max_image_kb') * 1024;
        if ($bytes > $maxBytes) {
            throw new ImageRejected('حجم الصورة أكبر من المسموح ('.self::megabytes($maxBytes).' ميجابايت).');
        }

        $head = self::head($path);
        $type = self::sniff($head);
        if ($type === null) {
            throw new ImageRejected('هذا الملف ليس صورة. الصيغ المقبولة: JPG وPNG وWebP وHEIC وHEIF وAVIF وGIF.');
        }

        [$width, $height] = in_array($type, ['heic', 'heif', 'avif'], true)
            ? (self::isoSize($head) ?? self::measured($path))
            : self::measured($path);
        if ($width < 1 || $height < 1) {
            throw new ImageRejected('تعذرت قراءة أبعاد الصورة. قد يكون الملف تالفًا.');
        }
        $maxSide = (int) config('media.max_side', 20_000);
        if ($width > $maxSide || $height > $maxSide || $width * $height > (int) config('media.max_pixels', 40_000_000)) {
            throw new ImageRejected('أبعاد الصورة أكبر من المسموح ('.$width.'×'.$height.').');
        }

        return new ImageFacts($type, self::TYPES[$type][0], self::TYPES[$type][1], $width, $height, self::animated($type, $path, $head), $bytes);
    }

    /** The type the bytes say, or null for anything that is not one of the accepted images. */
    public static function sniff(string $head): ?string
    {
        return match (true) {
            str_starts_with($head, "\xFF\xD8\xFF") => 'jpeg',
            str_starts_with($head, "\x89PNG\r\n\x1A\n") => 'png',
            str_starts_with($head, 'GIF87a'), str_starts_with($head, 'GIF89a') => 'gif',
            strlen($head) >= 16 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP' => 'webp',
            strlen($head) >= 16 && substr($head, 4, 4) === 'ftyp' => self::isoType($head),
            default => null,
        };
    }

    /** AVIF, HEIC or HEIF from an ISO-BMFF file's brands; anything else in that container (MP4, MOV…) is null. */
    private static function isoType(string $head): ?string
    {
        $size = unpack('N', substr($head, 0, 4))[1];
        if ($size < 16 || $size > 4096 || $size > strlen($head)) {
            return null;
        }
        $major = substr($head, 8, 4);
        $brands = [$major];
        for ($at = 16; $at + 4 <= $size; $at += 4) {
            $brands[] = substr($head, $at, 4);
        }

        return match (true) {
            in_array($major, ['avif', 'avis'], true) => 'avif',
            in_array($major, self::HEIC_BRANDS, true) => 'heic',
            array_intersect(['avif', 'avis'], $brands) !== [] => 'avif',
            array_intersect(self::HEIC_BRANDS, $brands) !== [] => 'heic',
            array_intersect(['mif1', 'msf1'], $brands) !== [] => 'heif',
            default => null,
        };
    }

    /**
     * The largest image size an ISO-BMFF image declares ('ispe' boxes: the main picture, its tiles and thumbnails),
     * turned when an 'irot' box says the picture is shown on its side.
     *
     * @return array{0: int, 1: int}|null
     */
    private static function isoSize(string $head): ?array
    {
        $best = null;
        for ($at = strpos($head, 'ispe'); $at !== false; $at = strpos($head, 'ispe', $at + 4)) {
            if ($at < 4 || $at + 16 > strlen($head) || unpack('N', substr($head, $at - 4, 4))[1] !== 20) {
                continue;
            }
            [$w, $h] = [unpack('N', substr($head, $at + 8, 4))[1], unpack('N', substr($head, $at + 12, 4))[1]];
            if ($best === null || $w * $h > $best[0] * $best[1]) {
                $best = [$w, $h];
            }
        }
        $rot = strpos($head, 'irot');
        if ($best && $rot !== false && $rot >= 4 && unpack('N', substr($head, $rot - 4, 4))[1] === 9 && (ord($head[$rot + 4] ?? "\0") & 1) === 1) {
            $best = [$best[1], $best[0]];
        }

        return $best;
    }

    /** @return array{0: int, 1: int} */
    private static function measured(string $path): array
    {
        $info = @getimagesize($path);

        return $info ? [(int) $info[0], (int) $info[1]] : [0, 0];
    }

    private static function animated(string $type, string $path, string $head): bool
    {
        return match ($type) {
            'webp' => substr($head, 12, 4) === 'VP8X' && (ord($head[20] ?? "\0") & 0x02) === 0x02,
            'avif' => substr($head, 8, 4) === 'avis',
            'gif' => self::gifFrames($path) > 1,
            default => false,
        };
    }

    /** Counts a GIF's frames (graphic control blocks) while reading it in pieces, up to two. */
    private static function gifFrames(string $path): int
    {
        $handle = @fopen($path, 'rb');
        if (! $handle) {
            return 0;
        }
        $frames = 0;
        $carry = '';
        while (! feof($handle) && $frames < 2) {
            $chunk = $carry.fread($handle, 65_536);
            $frames += substr_count($chunk, "\x00\x21\xF9\x04");
            $carry = substr($chunk, -3);
        }
        fclose($handle);

        return $frames;
    }

    private static function head(string $path): string
    {
        $handle = @fopen($path, 'rb');
        $head = $handle ? (string) fread($handle, self::HEAD) : '';
        if ($handle) {
            fclose($handle);
        }

        return $head;
    }

    private static function megabytes(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1_048_576, 1), '0'), '.');
    }
}
