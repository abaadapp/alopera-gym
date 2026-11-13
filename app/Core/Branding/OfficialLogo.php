<?php

namespace App\Core\Branding;

use App\Core\Papers\PaperSize;
use App\Core\Support\Images\ImageUpload;
use App\Core\Support\ImageStore;
use App\Models\Tenant;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/**
 * The club's logo. public/brand holds the file the owner approved, byte for byte, and the sizes scaled from it; it is
 * the logo until the owner uploads another in the club's settings (`logo_file`), which then takes its place on every
 * screen and paper. An upload saved before (the old `logo_path` column) is never read, so no old logo comes back.
 * A logo is drawn at its own proportions and never cropped; the sizes here are in points so the print page and the PDF
 * agree.
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

    /** The room a logo has across: a wide one is set narrower and keeps its proportions. */
    private const WIDTHS = [PaperSize::A4 => 140.0, PaperSize::A5 => 96.0, PaperSize::T80 => 146.0, PaperSize::T58 => 106.0];

    private const RATIO = 1448 / 1086;

    /** The longest side an uploaded logo is kept at: sharp on A4 at print resolution. */
    private const UPLOAD_MAX = 1600;

    /** The club's own upload, when it has one and its file is there. */
    public static function uploaded(?Tenant $tenant): ?string
    {
        $file = $tenant?->setting('logo_file');

        return is_string($file) && $file !== '' && Storage::disk('public')->exists($file) ? $file : null;
    }

    public static function url(?Tenant $tenant = null): string
    {
        $file = self::uploaded($tenant);

        return $file ? (string) ImageStore::url($file) : asset(self::SCREEN);
    }

    /**
     * The file for a paper: an address for a browser, a path for the PDF engine, which has neither host nor session.
     * The approved logo has a black copy for each receipt width; an upload prints as it is, as the reference's does.
     */
    public static function forPaper(string $size, bool $pdf, ?Tenant $tenant = null): string
    {
        if ($file = self::uploaded($tenant)) {
            return $pdf ? Storage::disk('public')->path($file) : Storage::disk('public')->url($file);
        }
        $file = self::STRIPS[$size] ?? self::ORIGINAL;

        return $pdf ? public_path($file) : asset($file);
    }

    /** @return array{width: float, height: float} in points */
    public static function box(string $size, ?Tenant $tenant = null): array
    {
        $ratio = self::RATIO;
        if ($file = self::uploaded($tenant)) {
            $dims = @getimagesize(Storage::disk('public')->path($file));
            $ratio = $dims && $dims[1] > 0 ? $dims[0] / $dims[1] : $ratio;
        }
        $height = self::HEIGHTS[$size] ?? self::HEIGHTS[PaperSize::A4];
        $width = $height * $ratio;
        $room = self::WIDTHS[$size] ?? self::WIDTHS[PaperSize::A4];
        if ($width > $room) {
            [$width, $height] = [$room, $room / $ratio];
        }

        return ['width' => round($width, 1), 'height' => round($height, 1)];
    }

    /** The paper's accent: the approved logo's gold, or the club's own colour beside a logo of its own. */
    public static function color(Tenant $tenant): string
    {
        return self::uploaded($tenant) ? (string) $tenant->primary_color : self::COLOR;
    }

    /**
     * Keeps an uploaded logo: its original byte for byte, and a PNG copy (transparency and all, at most UPLOAD_MAX
     * across, never cropped or stretched) that the screens, the print page and the PDF engine read. Returns the PNG's
     * path; the previous upload stays until the caller has saved the new one (ImageStore::commit).
     */
    public static function store(UploadedFile|ImageUpload $file, Tenant $tenant): string
    {
        return ImageStore::storeLogo($file, 'brand', self::UPLOAD_MAX);
    }

    /** Back to the approved logo: the upload's file goes, nothing else. */
    public static function forget(Tenant $tenant): void
    {
        if ($file = self::uploaded($tenant)) {
            ImageStore::delete($file);
        }
    }
}
