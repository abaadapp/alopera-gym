<?php

namespace App\Core\Papers;

use App\Models\Tenant;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * Who issues the paper: the club's name and contact lines, its tax and CR numbers, and its logo.
 *
 * A financial document keeps the identity it was issued with. `stamp()` is what an invoice saves when it is issued
 * (invoices.issuer); drawing it later reads that stamp, so renaming the club or changing its tax number does not
 * rewrite an invoice already sent. The logo is not part of the stamp: it is artwork, not a financial fact, and it is
 * always read from the club's current file.
 */
final class PaperIssuer
{
    private const KEYS = ['name', 'legal_name', 'address', 'city', 'phone', 'email', 'vat_number', 'cr_number', 'currency'];

    /** @return array<string, string> */
    public static function stamp(Tenant $tenant): array
    {
        return [
            'name' => (string) $tenant->name,
            'legal_name' => (string) ($tenant->legal_name ?? ''),
            'address' => (string) ($tenant->address ?? ''),
            'city' => (string) ($tenant->city ?? ''),
            'phone' => (string) ($tenant->phone ?? ''),
            'email' => (string) ($tenant->email ?? ''),
            'vat_number' => trim((string) $tenant->setting('vat_number', '')),
            'cr_number' => trim((string) $tenant->setting('cr_number', '')),
            'currency' => (string) ($tenant->currency ?: 'OMR'),
        ];
    }

    /**
     * The identity to print: the saved stamp when there is one, else the club as it is now.
     *
     * @param  array<string, mixed>|null  $stamped
     * @return array<string, string>
     */
    public static function resolve(Tenant $tenant, ?array $stamped = null): array
    {
        $live = self::stamp($tenant);
        if (! $stamped) {
            return $live;
        }

        return array_map('strval', array_replace($live, array_intersect_key($stamped, array_flip(self::KEYS))));
    }

    /**
     * The logo as a data: URI, so the same paper shows it in an isolated preview frame, a public link and the PDF
     * engine, none of which share the panel's session. Stored logos are WebP; they are converted to PNG, which every
     * one of the three reads.
     */
    public static function logo(Tenant $tenant): ?string
    {
        $path = $tenant->logo_path;
        if (! $path || ! Storage::disk('public')->exists($path)) {
            return null;
        }
        $disk = Storage::disk('public');

        return Cache::remember('paper-logo:'.$tenant->id.':'.md5($path.$disk->lastModified($path)), now()->addDay(), function () use ($disk, $path) {
            $bytes = $disk->get($path);
            if ($bytes === null || strlen($bytes) > 3_000_000) {
                return null;
            }
            $image = @imagecreatefromstring($bytes);
            if ($image === false) {
                return null;
            }
            imagesavealpha($image, true);
            ob_start();
            imagepng($image);
            $png = (string) ob_get_clean();
            imagedestroy($image);

            return 'data:image/png;base64,'.base64_encode($png);
        });
    }
}
