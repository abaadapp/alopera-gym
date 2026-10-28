<?php

namespace App\Core\Support;

/**
 * Phone numbers are stored as international digits without "+" (96891234567),
 * which is also the format the WhatsApp Cloud API expects.
 */
final class Phone
{
    public const DEFAULT_COUNTRY_CODE = '968';

    public static function normalize(?string $phone, string $countryCode = self::DEFAULT_COUNTRY_CODE): string
    {
        // Arabic-Indic and Persian digits typed on phone keyboards
        $phone = strtr((string) $phone, [
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        ]);
        $digits = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($digits, '00')) {
            $digits = substr($digits, 2);
        }

        // A local Omani number (8 digits) gets the country code.
        if (strlen($digits) === 8 && $countryCode === self::DEFAULT_COUNTRY_CODE) {
            $digits = $countryCode.$digits;
        }

        return $digits;
    }

    public static function display(?string $phone): string
    {
        $digits = (string) $phone;

        return str_starts_with($digits, self::DEFAULT_COUNTRY_CODE) && strlen($digits) === 11
            ? '+968 '.substr($digits, 3, 4).' '.substr($digits, 7)
            : ($digits !== '' ? '+'.$digits : '');
    }
}
