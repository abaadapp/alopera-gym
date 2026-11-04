<?php

namespace App\Core\Papers;

use App\Core\Audit\AuditLogger;
use App\Core\Branding\OfficialLogo;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

/**
 * One document, ready to draw. The screen preview, the print page, the PDF and the public link all come from html()
 * of the same Paper, so they cannot differ in a line, a total or a font size.
 */
final class Paper
{
    /**
     * @param  array<string, mixed>  $doc  what to print (see papers/sheet.blade.php)
     * @param  array<string, mixed>  $tpl  the club's settings for this paper
     * @param  array<string, string>  $issuer  the club identity printed on it
     * @param  Model|null  $record  the document drawn (none for a sample or a test page)
     */
    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $type,
        public readonly array $doc,
        public readonly array $tpl,
        public readonly array $issuer,
        public readonly string $size,
        public readonly string $filename,
        public readonly ?Model $record = null,
    ) {}

    public function isStrip(): bool
    {
        return PaperSize::isStrip($this->size);
    }

    public function html(bool $autoprint = false, bool $pdf = false): string
    {
        return view($this->isStrip() ? 'papers.strip' : 'papers.sheet', [
            'doc' => $this->doc,
            'tpl' => $this->tpl,
            'issuer' => $this->issuer,
            'logo' => ($this->tpl['show_logo'] ?? true) ? OfficialLogo::forPaper($this->size, $pdf) : null,
            'logoBox' => OfficialLogo::box($this->size),
            'tokens' => $this->isStrip()
                ? PaperTheme::thermal(PaperTheme::tokens(OfficialLogo::COLOR, (string) ($this->tpl['font'] ?? 'normal')))
                : PaperTheme::tokens(OfficialLogo::COLOR, (string) ($this->tpl['font'] ?? 'normal')),
            'size' => $this->size,
            'strip' => $this->isStrip(),
            'lang' => (string) ($this->tpl['lang'] ?? 'ar_en'),
            'fontBase' => $pdf ? public_path('fonts/papers') : asset('fonts/papers'),
            'autoprint' => $autoprint,
            'pdf' => $pdf,
        ])->render();
    }

    /** The print page: the paper alone, with the browser's print dialog when $autoprint. */
    public function page(bool $autoprint = true): Response
    {
        return new Response($this->html($autoprint), 200, ['Content-Type' => 'text/html; charset=utf-8', 'Cache-Control' => 'private, no-store']);
    }

    /** What a screen needs to show the paper beside a document. */
    public function toArray(): array
    {
        return ['html' => $this->html(), 'size' => $this->size, 'type' => $this->type, 'filename' => $this->filename.'.pdf'];
    }

    /**
     * The PDF, drawn by the PDF engine from the same HTML. A sheet is its pages with the running footer (club, number,
     * page x / y); a thermal strip is one page as long as the receipt itself, the paper a till's printer feeds (and what
     * the club's app turns into dots for a network printer).
     */
    public function pdf(bool $download = false): Response
    {
        $bytes = $this->quietly(fn () => $this->isStrip() ? $this->stripBytes() : $this->sheetBytes());

        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$this->filename.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    /**
     * The print page, or with ?format=pdf the PDF of the same paper: every print door of the panel answers both, so a
     * phone's app prints any paper through the system's own print dialog from the one address the page already has.
     */
    public function respond(Request $request, bool $print = true): Response
    {
        if ($request->string('format')->toString() === 'pdf') {
            abort_unless($request->user()?->can('papers.print'), 403, 'ملف PDF يحتاج صلاحية «طباعة المستندات».');
            $this->printed('pdf', $request->boolean('download'));

            return $this->pdf($request->boolean('download'));
        }

        $autoprint = $request->has('print') ? $request->boolean('print') : $print;
        if ($autoprint) {
            $this->printed('page');
        }

        return $this->page($autoprint);
    }

    /**
     * Who printed which document, in the audit trail: a sheet sent to the print dialog or taken as a PDF. Reading the
     * paper on screen is not printing; a till's receipt strip has the print log of its device instead.
     */
    public function printed(string $format, bool $download = false): void
    {
        if ($this->record === null || $this->isStrip()) {
            return;
        }

        app(AuditLogger::class)->log('paper.printed', $this->record, [
            'paper' => $this->type, 'number' => $this->doc['number'] ?? null, 'size' => $this->size, 'format' => $format, 'download' => $download,
        ]);
    }

    private function sheetBytes(): string
    {
        $p = PaperSize::of($this->size);
        $ltr = PaperLabels::ltr((string) ($this->tpl['lang'] ?? 'ar_en'));
        $mpdf = $this->engine([$p['width'], $p['height']], $p['top'], $p['side'], $p['bottom']);
        $mpdf->SetTitle($this->doc['title'].' '.$this->doc['number']);
        $mpdf->SetCreator($this->issuer['name'] ?? '');
        $second = PaperTheme::SECOND;
        $mpdf->SetHTMLFooter('<table width="100%" style="font-family:ibmplexsansarabic;font-size:8pt;color:'.$second.';border-collapse:collapse"><tr>'
            .'<td style="text-align:'.($ltr ? 'left' : 'right').'">'.e($this->issuer['name'] ?? '').' · <span dir="ltr">'.e($this->doc['number']).'</span></td>'
            .'<td style="text-align:'.($ltr ? 'right' : 'left').'" dir="ltr">{PAGENO} / {nbpg}</td></tr></table>');
        $this->writeAnchored($mpdf, $this->html(pdf: true), $p);

        return $mpdf->Output('', 'S');
    }

    /**
     * The paper's closing lines at the foot of its last page, where the print page's flexible spacer puts them: the PDF
     * engine has no flex, so the gap is measured when drawing. A close that does not fit starts a page of its own rather
     * than being cut in two.
     *
     * @param  array{width: float, height: float|null, top: float, side: float, bottom: float}  $p
     */
    private function writeAnchored(Mpdf $mpdf, string $html, array $p): void
    {
        $parts = explode(self::CLOSE_MARK, $html, 2);
        $mpdf->WriteHTML($parts[0]);
        if (! isset($parts[1])) {
            return;
        }

        $height = $this->closeHeight($html, $parts[1], $p);
        $room = $mpdf->h - $mpdf->bMargin - $mpdf->y;
        if ($height > 0.0 && $height > $room) {
            $mpdf->AddPage();
            $room = $mpdf->h - $mpdf->bMargin - $mpdf->y;
        }
        $gap = $room - $height - self::CLOSE_SLACK;
        if ($height > 0.0 && $gap > self::MIN_GAP && $gap < $room) {
            $mpdf->WriteHTML('<div style="height:'.round($gap, 2).'mm; font-size:0; line-height:0"></div>');
        }
        $mpdf->WriteHTML($parts[1]);
    }

    /** @param  array{width: float, height: float|null, top: float, side: float, bottom: float}  $p */
    private function closeHeight(string $html, string $close, array $p): float
    {
        if (trim(strip_tags($close)) === '') {
            return 0.0;
        }
        preg_match('#<style[^>]*>.*?</style>#si', $html, $css);
        // Under a first block: at the very top of a page the engine drops a margin that it keeps in the paper itself.
        $lead = 10.0;
        $probe = $this->engine([$p['width'], self::PROBE], 0, $p['side'], 0);
        $probe->WriteHTML(($css[0] ?? '').'<div style="height:'.$lead.'mm"></div>'.$close);
        $height = $probe->page > 1 ? 0.0 : (float) $probe->y - $lead;

        return $height > 0.0 && $height < (float) $p['height'] ? $height : 0.0;
    }

    /**
     * The strip: drawn once on a probe two metres long to measure it, then on a page of exactly that height. A receipt
     * longer than the probe (hundreds of lines) is pages of two metres that the printer feeds as one strip; its table
     * head is then a plain first row, so it is not repeated in the middle of the lines at every seam.
     */
    private function stripBytes(): string
    {
        $html = $this->html(pdf: true);
        $probe = $this->stripEngine(self::PROBE);
        $probe->WriteHTML($html);
        $used = (max(1, (int) $probe->page) - 1) * self::PROBE + (float) $probe->y;
        $height = max(self::MIN_STRIP, round($used + self::STRIP_MARGIN, 1));

        if ($height > self::PROBE) {
            $mpdf = $this->stripEngine(self::PROBE);
            $mpdf->WriteHTML((string) preg_replace('#<(/?)thead\b#i', '<$1tbody', $html));

            return $this->stripOutput($mpdf);
        }

        // The engine keeps some room of its own at a page's foot (a block it will not split, a row's padding): what
        // still spills onto a second page lengthens the strip by that much, so a short receipt is always one page.
        for ($try = 0; ; $try++) {
            $mpdf = $this->stripEngine($height);
            $mpdf->WriteHTML($html);
            if ((int) $mpdf->page <= 1 || $try === 2) {
                return $this->stripOutput($mpdf);
            }
            $height = min(self::PROBE, round(((int) $mpdf->page - 1) * $height + (float) $mpdf->y + self::STRIP_MARGIN, 1));
        }
    }

    private function stripOutput(Mpdf $mpdf): string
    {
        $mpdf->SetTitle($this->doc['title'].' '.$this->doc['number']);
        $mpdf->SetCreator($this->issuer['name'] ?? '');

        return $mpdf->Output('', 'S');
    }

    /** Where a sheet's template parts its body from its closing lines. */
    private const CLOSE_MARK = '<!--paper:close-->';

    /** A gap too small to see, which would only risk an extra page. */
    private const MIN_GAP = 2.0;

    /** Left under a close at the page's foot: to the exact millimetre, the engine rounds it onto a page of its own. */
    private const CLOSE_SLACK = 1.5;

    /** The probe and the longest page of a strip, in millimetres (the PDF format stops at 200 inches). */
    private const PROBE = 2000;

    /** The shortest strip printed: less comes out as a scrap nobody can hold. */
    private const MIN_STRIP = 60;

    /** Room under the last line before the cut. */
    private const STRIP_MARGIN = 4;

    private function stripEngine(float $height): Mpdf
    {
        $p = PaperSize::of($this->size);

        return $this->engine([$p['width'], $height], $p['top'], $p['side'], $p['bottom'] - 1);
    }

    /** @param  array{0: float, 1: float|null}  $format */
    private function engine(array $format, float $top, float $side, float $bottom): Mpdf
    {
        $fonts = (new FontVariables)->getDefaults()['fontdata'];
        $dirs = (new ConfigVariables)->getDefaults()['fontDir'];
        $tmp = storage_path('app/mpdf');
        if (! is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }

        return new Mpdf([
            'mode' => 'utf-8',
            'format' => $format,
            'margin_top' => $top,
            'margin_left' => $side,
            'margin_right' => $side,
            'margin_bottom' => $bottom,
            'margin_footer' => 6,
            'fontDir' => [...$dirs, public_path('fonts/papers')],
            'fontdata' => $fonts + ['ibmplexsansarabic' => ['R' => 'IBMPlexSansArabic-Regular.ttf', 'B' => 'IBMPlexSansArabic-Bold.ttf', 'useOTL' => 0xFF, 'useKashida' => 75]],
            'default_font' => 'ibmplexsansarabic',
            'directionality' => PaperLabels::ltr((string) ($this->tpl['lang'] ?? 'ar_en')) ? 'ltr' : 'rtl',
            'tempDir' => $tmp,
        ]);
    }

    /**
     * The engine warns while reading a few empty feature tables of the Arabic font the first time it caches it; the
     * shaping it produces is correct, so those warnings are not turned into errors here.
     *
     * @template T
     *
     * @param  callable(): T  $draw
     * @return T
     */
    private function quietly(callable $draw): mixed
    {
        $level = error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
        try {
            return $draw();
        } finally {
            error_reporting($level);
        }
    }
}
