<?php

namespace App\Core\Branding;

use App\Core\Papers\PaperSize;

/**
 * The club's one logo. public/brand holds the file the owner approved, byte for byte, and the sizes scaled from it; every
 * screen and paper takes one of these, never an upload, so no old logo can reach a document. It is drawn at its own
 * proportions and never cropped: the sizes here are the file's 4:3, set in points so the print page and the PDF agree.
 */
final class OfficialLogo
{
    public const ORIGINAL = 'brand/alopera-gym-logo.png';

    public const SCREEN = 'brand/alopera-gym-logo-screen.png';

    /** A receipt printer has one ink: the same logo in black, with no background of its own. */
    public const STRIPS = [
        PaperSize::T80 => 'brand/alopera-gym-logo-thermal-80.png',
        PaperSize::T58 => 'brand/alopera-gym-logo-thermal-58.png',
    ];

    /** The logo's gold: what a paper's top line and stamp are drawn in, so they sit beside it. */
    public const COLOR = '#9f7d3b';

    /** Height in points on each paper; a strip's leaves room for the name to be read at a printer's 203 dots an inch. */
    private const HEIGHTS = [PaperSize::A4 => 66.0, PaperSize::A5 => 54.0, PaperSize::T80 => 72.3, PaperSize::T58 => 57.4];

    private const RATIO = 1448 / 1086;

    public static function url(): string
    {
        return asset(self::SCREEN);
    }

    /** The file for a paper: an address for a browser, a path for the PDF engine, which has neither host nor session. */
    public static function forPaper(string $size, bool $pdf): string
    {
        $file = self::STRIPS[$size] ?? self::ORIGINAL;

        return $pdf ? public_path($file) : asset($file);
    }

    /** @return array{width: float, height: float} in points */
    public static function box(string $size): array
    {
        $height = self::HEIGHTS[$size] ?? self::HEIGHTS[PaperSize::A4];

        return ['width' => round($height * self::RATIO, 1), 'height' => $height];
    }
}
