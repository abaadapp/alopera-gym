<?php

namespace App\Core\Support;

/**
 * Amounts are stored as decimal(12,3) — the Omani rial has 1000 baisa.
 * Arithmetic and comparisons go through integer minor units to avoid float drift.
 */
final class Money
{
    public const SCALE = 1000;

    public static function toMinor(string|int|float|null $amount): int
    {
        return (int) round(((float) $amount) * self::SCALE);
    }

    public static function fromMinor(int $minor): string
    {
        return number_format($minor / self::SCALE, 3, '.', '');
    }

    public static function normalize(string|int|float|null $amount): string
    {
        return self::fromMinor(self::toMinor($amount));
    }

    public static function add(string|int|float|null ...$amounts): string
    {
        return self::fromMinor(array_sum(array_map([self::class, 'toMinor'], $amounts)));
    }

    public static function sub(string|int|float|null $a, string|int|float|null $b): string
    {
        return self::fromMinor(self::toMinor($a) - self::toMinor($b));
    }

    public static function mul(string|int|float|null $amount, float|int $factor): string
    {
        return self::fromMinor((int) round(self::toMinor($amount) * $factor));
    }

    public static function percent(string|int|float|null $amount, float|int $percent): string
    {
        return self::mul($amount, $percent / 100);
    }

    public static function cmp(string|int|float|null $a, string|int|float|null $b): int
    {
        return self::toMinor($a) <=> self::toMinor($b);
    }

    public static function isZero(string|int|float|null $amount): bool
    {
        return self::toMinor($amount) === 0;
    }

    public static function max(string|int|float|null $a, string|int|float|null $b): string
    {
        return self::cmp($a, $b) >= 0 ? self::normalize($a) : self::normalize($b);
    }
}
