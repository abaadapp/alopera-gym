<?php

namespace App\Modules\Inventory\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * A network printer's address: an address on the club's local network and nothing else.
 *
 * The club's app refuses at print time every address that is not local (INVALID_PRINTER_ADDRESS, mobile/PROTOCOL.md
 * §4). Accepting any text here would let «192.168.1.50:9100» or «http://192.168.1.50» be saved and then stop the first
 * receipt with a message nobody links to what was typed days before. So the rule is the apps' rule, case for case, and
 * the same vectors check it (mobile/shared/fixtures/printing-vectors.json → address): nothing is accepted here that the
 * app refuses, and nothing refused that it accepts.
 */
class PrinterAddress implements ValidationRule
{
    /** Would the app take it as a printer's address? (after trimming spaces) */
    public static function allows(string $address): bool
    {
        $address = trim($address);

        return self::privateIpv4($address) || self::privateIpv6($address) || self::mdns($address);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! self::allows($value)) {
            $fail('عنوان الطابعة غير صالح — اكتب عنوانها على الشبكة المحلية وحده، مثل 192.168.1.50، والمنفذ في حقله.');
        }
    }

    /** Four decimal parts without leading zeros, in 10/8, 172.16/12, 192.168/16 or 169.254/16. */
    private static function privateIpv4(string $address): bool
    {
        if (! preg_match('/^(0|[1-9]\d{0,2})\.(0|[1-9]\d{0,2})\.(0|[1-9]\d{0,2})\.(0|[1-9]\d{0,2})$/', $address, $m)) {
            return false;
        }
        foreach (array_slice($m, 1) as $part) {
            if ((int) $part > 255) {
                return false;
            }
        }
        [$a, $b] = [(int) $m[1], (int) $m[2]];

        return $a === 10 || ($a === 172 && $b >= 16 && $b <= 31) || ($a === 192 && $b === 168) || ($a === 169 && $b === 254);
    }

    /** A unique local address (fc00::/7) or a link-local one (fe80::/10). */
    private static function privateIpv6(string $address): bool
    {
        if (! str_contains($address, ':') || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }
        $bytes = inet_pton($address);
        if ($bytes === false) {
            return false;
        }
        [$first, $second] = [ord($bytes[0]), ord($bytes[1])];

        return ($first & 0xFE) === 0xFC || ($first === 0xFE && ($second & 0xC0) === 0x80);
    }

    /** An mDNS name ending in .local: labels of [a-z0-9-] that neither start nor end with a hyphen. */
    private static function mdns(string $address): bool
    {
        $name = strtolower($address);
        if (! str_ends_with($name, '.local')) {
            return false;
        }
        foreach (explode('.', substr($name, 0, -strlen('.local'))) as $label) {
            if (! preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $label)) {
                return false;
            }
        }

        return true;
    }
}
