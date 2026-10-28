<?php

namespace App\Modules\Payments\Paymob;

/**
 * Paymob transaction-callback HMAC (SHA-512).
 *
 * The values of these fields from `obj`, concatenated in exactly this order
 * with no separator, signed with the merchant's HMAC secret. Booleans are the
 * strings "true"/"false". (`error_occured` is spelled that way by Paymob.)
 */
final class PaymobSignature
{
    public const FIELDS = [
        'amount_cents', 'created_at', 'currency', 'error_occured',
        'has_parent_transaction', 'id', 'integration_id', 'is_3d_secure',
        'is_auth', 'is_capture', 'is_refunded', 'is_standalone_payment',
        'is_voided', 'order.id', 'owner', 'pending',
        'source_data.pan', 'source_data.sub_type', 'source_data.type', 'success',
    ];

    /** @param array<string, mixed> $obj */
    public static function compute(array $obj, string $secret): string
    {
        $flat = '';

        foreach (self::FIELDS as $path) {
            $value = data_get($obj, $path);
            $flat .= match (true) {
                is_bool($value) => $value ? 'true' : 'false',
                $value === null => '',
                default => (string) $value,
            };
        }

        return hash_hmac('sha512', $flat, $secret);
    }

    /** @param array<string, mixed> $obj */
    public static function verify(array $obj, ?string $given, ?string $secret): bool
    {
        if (! $secret || ! is_string($given) || $given === '') {
            return false;
        }

        return hash_equals(self::compute($obj, $secret), strtolower($given));
    }
}
