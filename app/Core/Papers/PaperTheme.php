<?php

namespace App\Core\Papers;

/**
 * The look of every paper: one white sheet, slate ink, a hairline for rules, and the club's colour in three places
 * only — a thin line across the top, the status stamp and nothing else. Sizes are in points and scale together with
 * the font setting, so a «large» paper grows evenly instead of only its body text.
 */
final class PaperTheme
{
    public const INK = '#354058';

    public const SECOND = '#8898b3';

    public const RULE = '#cbd5e1';

    public const HAIRLINE = 0.74;

    public const SIZES = [
        'xs' => 10.0,
        'sm' => 11.0,
        'base' => 12.0,
        'md' => 13.0,
        'currency' => 14.0,
        'display' => 28.8,
        'field_line' => 14.6,
        'field_gap' => 21.6,
        'section_gap' => 15.0,
        'logo' => 40.0,
    ];

    public const FONTS = ['small' => 0.9, 'normal' => 1.0, 'large' => 1.14];

    public static function scale(string $font): float
    {
        return self::FONTS[$font] ?? 1.0;
    }

    /** @return array<string, string|float> */
    public static function tokens(?string $brand, string $font = 'normal'): array
    {
        $primary = self::hex($brand) ?? '#111827';

        return [
            'primary' => $primary,
            // The stamp is read on white: the club colour darkened until it prints legibly.
            'primary_ink' => self::readable($primary),
            'text' => self::INK,
            'second' => self::SECOND,
            'muted' => self::mix(self::INK, '#ffffff', 0.42),
            'rule' => self::RULE,
            'scale' => self::scale($font),
        ];
    }

    private static function hex(?string $value): ?string
    {
        return is_string($value) && preg_match('/^#[0-9a-fA-F]{6}$/', $value) ? strtolower($value) : null;
    }

    /** @return array{0: int, 1: int, 2: int} */
    private static function rgb(string $hex): array
    {
        return [hexdec(substr($hex, 1, 2)), hexdec(substr($hex, 3, 2)), hexdec(substr($hex, 5, 2))];
    }

    private static function luminance(string $hex): float
    {
        $c = array_map(function (int $v) {
            $s = $v / 255;

            return $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    private static function readable(string $hex): string
    {
        $color = $hex;
        for ($i = 0; $i < 20 && (1.05) / (self::luminance($color) + 0.05) < 6.0; $i++) {
            $color = self::mix($color, '#000000', 0.08);
        }

        return $color;
    }

    /** $a moved towards $b by $amount (0..1). */
    private static function mix(string $a, string $b, float $amount): string
    {
        [$r1, $g1, $b1] = self::rgb($a);
        [$r2, $g2, $b2] = self::rgb($b);

        return sprintf('#%02x%02x%02x', (int) round($r1 + ($r2 - $r1) * $amount), (int) round($g1 + ($g2 - $g1) * $amount), (int) round($b1 + ($b2 - $b1) * $amount));
    }
}
