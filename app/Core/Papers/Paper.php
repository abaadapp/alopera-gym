<?php

namespace App\Core\Papers;

use App\Models\Tenant;
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
     */
    public function __construct(
        public readonly Tenant $tenant,
        public readonly string $type,
        public readonly array $doc,
        public readonly array $tpl,
        public readonly array $issuer,
        public readonly string $size,
        public readonly string $filename,
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
            'logo' => ($this->tpl['show_logo'] ?? true) ? PaperIssuer::logo($this->tenant) : null,
            'tokens' => PaperTheme::tokens($this->tenant->primary_color, (string) ($this->tpl['font'] ?? 'normal')),
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
     * The PDF, drawn by the PDF engine from the same HTML. A sheet only: a thermal strip has no page height and is
     * printed from the browser.
     */
    public function pdf(bool $download = false): Response
    {
        if ($this->isStrip()) {
            return (new self($this->tenant, $this->type, $this->doc, $this->tpl, $this->issuer, (string) ($this->tpl['paper'] ?? PaperSize::A4), $this->filename))->pdf($download);
        }
        $p = PaperSize::of($this->size);
        $fonts = (new FontVariables)->getDefaults()['fontdata'];
        $dirs = (new ConfigVariables)->getDefaults()['fontDir'];
        $tmp = storage_path('app/mpdf');
        if (! is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }

        // The engine warns while reading a few empty feature tables of the Arabic font the first time it caches it;
        // the shaping it produces is correct, so those warnings are not turned into errors here.
        $level = error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
        try {
            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => [$p['width'], $p['height']],
                'margin_top' => $p['top'],
                'margin_left' => $p['side'],
                'margin_right' => $p['side'],
                'margin_bottom' => $p['bottom'],
                'margin_footer' => 6,
                'fontDir' => [...$dirs, public_path('fonts/papers')],
                'fontdata' => $fonts + ['ibmplexsansarabic' => ['R' => 'IBMPlexSansArabic-Regular.ttf', 'B' => 'IBMPlexSansArabic-Bold.ttf', 'useOTL' => 0xFF, 'useKashida' => 75]],
                'default_font' => 'ibmplexsansarabic',
                'directionality' => 'rtl',
                'tempDir' => $tmp,
            ]);
            $mpdf->SetTitle($this->doc['title'].' '.$this->doc['number']);
            $mpdf->SetCreator($this->issuer['name'] ?? '');
            $second = PaperTheme::SECOND;
            $mpdf->SetHTMLFooter('<table width="100%" style="font-family:ibmplexsansarabic;font-size:8pt;color:'.$second.';border-collapse:collapse"><tr>'
                .'<td style="text-align:right">'.e($this->issuer['name'] ?? '').' · <span dir="ltr">'.e($this->doc['number']).'</span></td>'
                .'<td style="text-align:left" dir="ltr">{PAGENO} / {nbpg}</td></tr></table>');
            $mpdf->WriteHTML($this->html(pdf: true));
            $bytes = $mpdf->Output('', 'S');
        } finally {
            error_reporting($level);
        }

        return new Response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($download ? 'attachment' : 'inline').'; filename="'.$this->filename.'.pdf"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
