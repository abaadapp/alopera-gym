<?php

namespace App\Core\Papers;

/**
 * The sizes a paper is drawn on: cut sheets (A4, A5) and thermal strips (80 and 58 mm). Each carries its margins, in
 * millimetres, so the screen, the browser's print dialog and the PDF all lay the page out the same way.
 */
final class PaperSize
{
    public const A4 = 'A4';

    public const A5 = 'A5';

    public const T80 = '80mm';

    public const T58 = '58mm';

    public const PRESETS = [
        self::A4 => ['strip' => false, 'width' => 210.0, 'height' => 297.0, 'top' => 12.0, 'side' => 13.0, 'bottom' => 18.0],
        self::A5 => ['strip' => false, 'width' => 148.0, 'height' => 210.0, 'top' => 8.0, 'side' => 10.0, 'bottom' => 13.0],
        self::T80 => ['strip' => true, 'width' => 80.0, 'height' => null, 'top' => 3.0, 'side' => 3.0, 'bottom' => 3.0],
        self::T58 => ['strip' => true, 'width' => 58.0, 'height' => null, 'top' => 3.0, 'side' => 2.0, 'bottom' => 3.0],
    ];

    /** Millimetres the line under a printed sheet takes, the same in the PDF's margin and the browser's page. */
    public const FOOT_LINE = 7.0;

    /** @return array{strip: bool, width: float, height: float|null, top: float, side: float, bottom: float} */
    public static function of(?string $key): array
    {
        return self::PRESETS[$key ?? ''] ?? self::PRESETS[self::A4];
    }

    public static function isStrip(?string $key): bool
    {
        return self::of($key)['strip'];
    }

    /** @return list<string> */
    public static function sheets(): array
    {
        return [self::A4, self::A5];
    }

    /** @return list<string> */
    public static function strips(): array
    {
        return [self::T80, self::T58];
    }

    /**
     * The viewport a phone lays the page out in. A sheet asks for its own width (A4 is 794 CSS pixels) so a phone
     * shows the whole page scaled to its screen, as a document, instead of a corner of it with a sideways scroll; a
     * strip is narrower than any phone and keeps the device width. Desktops and the panel's frame ignore it.
     */
    public static function viewport(string $key): string
    {
        $p = self::of($key);

        return $p['strip'] ? 'width=device-width, initial-scale=1' : 'width='.(int) ceil($p['width'] / 25.4 * 96);
    }

    /**
     * The page box: @page for printing, and on screen a box of the paper's real size (210 mm wide for A4, margins
     * included as padding), so a preview shows exactly what will print.
     */
    public static function css(string $key, bool $pdf = false): string
    {
        $p = self::of($key);
        $mm = fn (float $v) => rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.').'mm';
        $pad = $mm($p['top']).' '.$mm($p['side']).' '.$mm($p['bottom']);
        // The PDF sets its club-and-number line in the bottom margin; a browser sets it in the page, so its page gives
        // that line the same room and a paper that fills one PDF page fills one printed page.
        $print = $mm($p['top']).' '.$mm($p['side']).' '.$mm($p['bottom'] - ($p['height'] !== null ? self::FOOT_LINE : 0));
        $size = $p['height'] !== null ? 'size: '.$mm($p['width']).' '.$mm($p['height']).';' : 'size: '.$mm($p['width']).' auto;';
        // Two millimetres short of the page: a box exactly as tall can round over and print a blank page after it.
        $inner = $p['height'] !== null ? 'min-height: '.$mm($p['height'] - $p['top'] - $p['bottom'] + self::FOOT_LINE - 2).';' : '';
        $outer = $p['height'] !== null ? 'min-height: '.$mm($p['height']).';' : '';

        // The PDF engine takes the page size and margins from its own settings; an @page rule there would also drop
        // its running footer (club, number, page x / y).
        $page = $pdf ? '' : "@page { {$size} margin: {$print}; }";
        // On A5 that line, which only repeats the head, is what would push a full sheet onto a second page.
        $foot = $key === self::A5 ? '.docfoot { display: none; }' : '';

        return <<<CSS
            @media print {
                {$page}
                html, body { margin: 0; padding: 0; }
                .paper { width: auto; padding: 0; {$inner} }
                {$foot}
            }
            @media screen {
                html, body { margin: 0; padding: 0; }
                .paper { width: {$mm($p['width'])}; {$outer} padding: {$pad}; margin: 0 auto; background: #fff; box-sizing: border-box; }
            }
        CSS;
    }
}
