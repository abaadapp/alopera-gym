<?php

namespace App\Core\Papers;

use Illuminate\Support\HtmlString;

/**
 * How numbers read on a paper. Western digits everywhere; amounts with three decimals in table cells without the
 * currency (it would repeat on every line), and with it where the eye lands: the grand total, the balance, the figure.
 */
final class PaperFormat
{
    public const CURRENCIES = ['OMR' => 'ر.ع', 'SAR' => 'ر.س', 'AED' => 'د.إ', 'KWD' => 'د.ك', 'QAR' => 'ر.ق', 'BHD' => 'د.ب'];

    public static function amount(mixed $value): string
    {
        return number_format((float) $value, 3, '.', ',');
    }

    public static function money(mixed $value, string $currency): string
    {
        return self::amount($value).' '.self::currency($currency);
    }

    public static function minus(mixed $value): string
    {
        return '− '.self::amount($value);
    }

    public static function currency(string $currency): string
    {
        return self::CURRENCIES[$currency] ?? $currency;
    }

    /** «3», not «3.000». */
    public static function qty(mixed $value): string
    {
        $n = (float) $value;

        return $n == (int) $n ? (string) (int) $n : rtrim(rtrim(number_format($n, 3, '.', ''), '0'), '.');
    }

    /** «5%», «7.5%». */
    public static function rate(mixed $value): string
    {
        return '('.self::qty($value).'%)';
    }

    /** The last four characters of a bank number, the rest hidden. */
    public static function masked(?string $value): string
    {
        $value = preg_replace('/\s+/', '', (string) $value);

        return $value === '' ? '' : '•••• '.mb_substr($value, -4);
    }

    /**
     * Text a person typed, escaped, with every date or number run that contains a dash or slash kept left-to-right.
     * After an Arabic word the bidi rules read «2027-01-31» as three Arabic numbers and print «31-01-2027»; the
     * <bdo> keeps it as written, on screen and in the PDF alike.
     */
    public static function bidi(?string $text): HtmlString
    {
        $safe = e((string) $text);

        return new HtmlString((string) preg_replace('/\d+(?:[\-\/:]\d+)+/', '<bdo dir="ltr">$0</bdo>', $safe));
    }

    /**
     * The direction of a piece of text, from its first letter: «Protein shake» reads left to right, «اشتراك شهري» and
     * «3 أيام» right to left (and text with no letter at all follows the paper). Written out instead of dir="auto",
     * which the PDF engine reads as left to right and so scrambles an Arabic line around its dates.
     */
    public static function dir(?string $text): string
    {
        return preg_match('/\p{L}/u', (string) $text, $m) && ! preg_match('/[\p{Arabic}\p{Hebrew}]/u', $m[0]) ? 'ltr' : 'rtl';
    }

    public static function terms(?int $days): string
    {
        if ($days === null) {
            return '';
        }

        return $days === 0 ? 'مستحق فورًا' : 'صافي '.$days.' يومًا';
    }
}
