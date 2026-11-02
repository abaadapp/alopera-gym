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
     * A run read left to right: from a Latin letter or a digit (or the @, # or + before it) to the last letter or digit
     * before the next Arabic word, whole — a date, a number, an English phrase, a handle, an e-mail, a link, a phone
     * number with its groups. After an Arabic word the bidi rules read «2027-01-31» as three numbers and print
     * «31-01-2027», put the @ of «@alopera.gym» after it, and turn «+968 9123 4567» into «968+ 4567 9123». The Arabic
     * comma, semicolon, question mark and tatweel end a run like an Arabic letter.
     */
    private const LTR_RUN = '/([@#+]?[A-Za-z0-9](?:[^\p{Arabic}\x{060C}\x{061B}\x{061F}\x{0640}\r\n]*[A-Za-z0-9])?)/u';

    /**
     * Text a person typed, ready for a paper: escaped, without what the paper's font cannot draw, each left-to-right
     * run kept as written in a <bdo>, and each line break a <br> (the PDF engine ignores white-space: pre-line, so a
     * footer of two lines came out as one on the PDF and the receipt printer, though two in the print page).
     *
     * $dir is the direction of the element the text sits in: a left-to-right element reads its runs right already.
     */
    public static function bidi(?string $text, string $dir = 'rtl'): HtmlString
    {
        $lines = preg_split('/\R/u', self::printable($text)) ?: [];

        return new HtmlString(implode('<br>', array_map(function (string $line) use ($dir) {
            if ($dir === 'ltr') {
                return e($line);
            }
            // Split on the raw text, then escape: escaping first would make «&amp;» a run of its own. A lone word or
            // number reads right as it is; only a run with a separator in it (- / : . @ + a space) is reordered.
            $parts = preg_split(self::LTR_RUN, $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$line];

            return implode('', array_map(fn (string $part, int $i) => $i % 2 === 1 && preg_match('/[^A-Za-z0-9]/', $part) ? '<bdo dir="ltr">'.e($part).'</bdo>' : e($part), $parts, array_keys($parts)));
        }, $lines)));
    }

    /**
     * What the paper's font draws. Emoji, pictographs and the joiners and selectors that build them come out as empty
     * boxes on the PDF and on the receipt printer, so the paper leaves them out; the setting keeps what the club typed.
     */
    public static function printable(?string $text): string
    {
        $kept = preg_replace('/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE00}-\x{FE0F}\x{200D}\x{20E3}\x{E0020}-\x{E007F}]/u', '', (string) $text) ?? (string) $text;

        // The space a symbol stood in is not left doubled, nor at the end of a line.
        return trim((string) preg_replace(['/[ \t]{2,}/u', '/[ \t]+(?=\R)/u'], [' ', ''], $kept));
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

    public static function terms(?int $days, string $lang = 'ar_en'): string
    {
        if ($days === null) {
            return '';
        }
        if (PaperLabels::ltr($lang)) {
            return $days === 0 ? 'Due on receipt' : 'Net '.$days.' days';
        }

        return $days === 0 ? 'مستحق فورًا' : 'صافي '.$days.' يومًا';
    }
}
