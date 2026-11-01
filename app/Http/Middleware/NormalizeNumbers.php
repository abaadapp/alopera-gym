<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\TransformsRequest;

/**
 * A figure typed on an Arabic keyboard is the figure: «١٢٫٥٠٠» is read as 12.500, «٩٦٨٩١٢٣٤٥٦٧» as 96891234567. Only a
 * value that is a number in full is changed (Arabic-Indic or Persian digits, the Arabic decimal and thousands marks);
 * names, notes and anything else stay exactly as typed, and passwords are never touched.
 */
final class NormalizeNumbers extends TransformsRequest
{
    private const EXCEPT = ['password', 'password_confirmation', 'current_password'];

    private const DIGITS = [
        '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
        '٫' => '.', '٬' => '',
    ];

    protected function transform($key, $value)
    {
        if (! is_string($value) || in_array($key, self::EXCEPT, true) || ! preg_match('/[٠-٩۰-۹٫٬]/u', $value)) {
            return $value;
        }
        $ascii = strtr($value, self::DIGITS);

        return preg_match('/^\s*[+-]?\d*(\.\d*)?\s*$/', $ascii) ? trim($ascii) : $value;
    }
}
