<?php

namespace App\Core\Auth;

use Closure;

/**
 * Demo and test accounts use reserved domains (RFC 2606 / RFC 6761) that can
 * never belong to a real person. In production these addresses are refused
 * wherever an account signs in or is created, so the published demo
 * credentials cannot open a live system even if demo rows reach its database.
 */
final class DemoAccounts
{
    private const RESERVED_TLDS = ['test', 'example', 'invalid', 'localhost'];

    private const RESERVED_DOMAINS = ['example.com', 'example.net', 'example.org'];

    public static function isReservedEmail(?string $email): bool
    {
        $at = strrpos((string) $email, '@');
        $domain = $at === false ? '' : rtrim(strtolower(substr((string) $email, $at + 1)), '.');
        if ($domain === '') {
            return false;
        }

        $labels = explode('.', $domain);
        if (in_array(end($labels), self::RESERVED_TLDS, true)) {
            return true;
        }

        foreach (self::RESERVED_DOMAINS as $reserved) {
            if ($domain === $reserved || str_ends_with($domain, '.'.$reserved)) {
                return true;
            }
        }

        return false;
    }

    public static function blocked(?string $email): bool
    {
        return app()->isProduction() && self::isReservedEmail($email);
    }

    /** Validation rule: no reserved (demo) email domains in production. */
    public static function rule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (self::blocked(is_string($value) ? $value : null)) {
                $fail('لا يمكن استخدام نطاق بريد تجريبي في بيئة الإنتاج.');
            }
        };
    }
}
