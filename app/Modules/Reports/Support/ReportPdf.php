<?php

namespace App\Modules\Reports\Support;

use App\Core\Branding\OfficialLogo;
use App\Core\Papers\PaperSize;
use App\Core\Tenancy\TenantContext;
use Mpdf\Config\ConfigVariables;
use Mpdf\Config\FontVariables;
use Mpdf\Mpdf;

/**
 * A report as a PDF: the same lines (period, branch, filters, when it was made) and the same tables as its Excel file,
 * right to left, in the papers' Arabic font. Wide tables are printed on a landscape page. A very long table is cut at
 * MAX_ROWS with a line saying so (the Excel file holds it all). The document papers keep their own engine.
 */
final class ReportPdf
{
    public const MAX_ROWS = 3000;

    private const CURRENCIES = ['OMR' => 'ر.ع', 'SAR' => 'ر.س', 'AED' => 'د.إ', 'KWD' => 'د.ك', 'QAR' => 'ر.ق', 'BHD' => 'د.ب'];

    /**
     * @param  list<array{0: string, 1: string}>  $meta
     * @param  list<ReportSheet>  $sheets
     */
    public function __construct(
        private readonly string $title,
        private readonly array $meta,
        private readonly array $sheets,
        private readonly string $currency,
        private readonly ?string $club,
    ) {}

    public function bytes(): string
    {
        $wide = collect($this->sheets)->contains(fn (ReportSheet $s) => count($s->columns) > 6);
        $tmp = storage_path('app/mpdf');
        if (! is_dir($tmp)) {
            mkdir($tmp, 0775, true);
        }
        // As the papers do: the engine warns while caching a few empty tables of the Arabic font; its shaping is right.
        $level = error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
        try {
            $mpdf = new Mpdf([
                'mode' => 'utf-8',
                'format' => $wide ? 'A4-L' : 'A4',
                'margin_top' => 14, 'margin_left' => 12, 'margin_right' => 12, 'margin_bottom' => 16, 'margin_footer' => 6,
                'fontDir' => [...(new ConfigVariables)->getDefaults()['fontDir'], public_path('fonts/papers')],
                'fontdata' => (new FontVariables)->getDefaults()['fontdata'] + ['ibmplexsansarabic' => ['R' => 'IBMPlexSansArabic-Regular.ttf', 'B' => 'IBMPlexSansArabic-Bold.ttf', 'useOTL' => 0xFF, 'useKashida' => 75]],
                'default_font' => 'ibmplexsansarabic',
                'directionality' => 'rtl',
                'tempDir' => $tmp,
            ]);
            $mpdf->SetTitle($this->title);
            $mpdf->SetCreator((string) $this->club);
            $mpdf->SetHTMLFooter('<table width="100%" style="font-size:8pt;color:#6b7280"><tr><td style="text-align:right">'.e((string) $this->club).' · '.e($this->title).'</td>'
                .'<td style="text-align:left" dir="ltr">{PAGENO} / {nbpg}</td></tr></table>');
            $mpdf->WriteHTML($this->html());

            return $mpdf->Output('', 'S');
        } finally {
            error_reporting($level);
        }
    }

    public function html(): string
    {
        $css = 'body{font-family:ibmplexsansarabic;font-size:9pt;color:#111}h1{font-size:15pt;margin:0 0 2pt}h2{font-size:11pt;margin:12pt 0 4pt}'
            .'.club{color:#6b7280;font-size:9pt}table.top{width:100%;margin-bottom:6pt}table.top td{vertical-align:top;padding:0}'
            .'table.meta td{padding:1pt 6pt 1pt 0;font-size:8.5pt;vertical-align:top}table.meta td.k{color:#6b7280}'
            .'table.data{width:100%;border-collapse:collapse;margin-top:4pt}table.data th{background:#f2f2f0;font-weight:bold;text-align:right;padding:4pt;border-bottom:0.6pt solid #d4d4d4;font-size:8.5pt}'
            .'table.data td{padding:3pt 4pt;border-bottom:0.3pt solid #ececec;font-size:8.5pt}.note{color:#92400e;font-size:8pt;margin-top:3pt}';
        $out = '<html dir="rtl"><head><style>'.$css.'</style></head><body>';
        // In the page, not a running header: once, at the head of the first page.
        $tenant = app(TenantContext::class)->get();
        $box = OfficialLogo::box(PaperSize::A5, $tenant);
        $out .= '<table class="top"><tr><td>'.($this->club ? '<div class="club">'.e($this->club).'</div>' : '').'<h1>'.e($this->title).'</h1></td>'
            .'<td style="width:'.$box['width'].'pt;text-align:left"><img src="'.e(OfficialLogo::forPaper(PaperSize::A4, true, $tenant)).'" alt="" style="width:'.$box['width'].'pt;height:'.$box['height'].'pt"></td></tr></table>';
        $out .= '<table class="meta">';
        foreach ($this->meta as [$label, $value]) {
            $out .= '<tr><td class="k">'.e($label).'</td>'.$this->cell((string) $value).'</tr>';
        }
        $out .= '</table>';

        foreach ($this->sheets as $sheet) {
            if (count($this->sheets) > 1) {
                $out .= '<h2>'.e($sheet->title).'</h2>';
            }
            $numeric = array_map(fn ($c) => $c[1] !== ReportSheet::TEXT, $sheet->columns);
            $out .= '<table class="data"><thead><tr>';
            foreach ($sheet->columns as $i => [$label, $kind]) {
                $out .= '<th style="text-align:'.($numeric[$i] ? 'left' : 'right').'">'.e($this->heading($label, $kind)).'</th>';
            }
            $out .= '</tr></thead><tbody>';
            $n = 0;
            $total = 0;
            foreach ($sheet->rows as $row) {
                $total++;
                if ($n >= self::MAX_ROWS) {
                    continue;
                }
                $n++;
                $out .= '<tr>';
                foreach (array_values($row) as $i => $cell) {
                    $kind = $sheet->columns[$i][1] ?? ReportSheet::TEXT;
                    [$value, $kind] = is_array($cell) && array_key_exists('__kind', $cell) ? [$cell['value'], $cell['__kind']] : [$cell, $kind];
                    $text = $this->format($value, $kind);
                    $out .= $kind === ReportSheet::TEXT || ! is_numeric($value) ? $this->cell($text) : '<td style="text-align:left" dir="ltr">'.e($text).'</td>';
                }
                $out .= '</tr>';
            }
            if ($total === 0) {
                $out .= '<tr><td colspan="'.max(1, count($sheet->columns)).'" style="color:#6b7280">لا توجد بيانات.</td></tr>';
            }
            $out .= '</tbody></table>';
            if ($total > self::MAX_ROWS) {
                $out .= '<div class="note">الجدول أطول من '.self::MAX_ROWS.' صف؛ عُرض أولها هنا، والجدول كاملًا في ملف Excel.</div>';
            }
        }

        return $out.'</body></html>';
    }

    /**
     * A text cell whose dates keep their order in a right-to-left table: a cell that is only a date (and time) is set left
     * to right as a whole; dates inside words are set left to right on their own.
     */
    private function cell(string $value): string
    {
        $date = '\d{4}-\d{2}-\d{2}(?: \d{2}:\d{2})?';
        if (preg_match('/^'.$date.'$/', $value)) {
            return '<td dir="ltr" style="text-align:right">'.e($value).'</td>';
        }

        return '<td>'.preg_replace('/('.$date.')/u', '<span dir="ltr">$1</span>', e($value)).'</td>';
    }

    private function format(mixed $value, string $kind): string
    {
        if ($value === null || $value === '') {
            return '';
        }
        if ($kind === ReportSheet::TEXT || ! is_numeric($value)) {
            return (string) $value;
        }

        return match ($kind) {
            ReportSheet::MONEY => number_format((float) $value, 3),
            ReportSheet::INT => number_format((int) $value),
            ReportSheet::PERCENT => number_format((float) $value, 1).'%',
            default => (string) round((float) $value, 3),
        };
    }

    private function heading(string $label, string $kind): string
    {
        return match ($kind) {
            ReportSheet::MONEY => $label.' ('.(self::CURRENCIES[$this->currency] ?? $this->currency).')',
            ReportSheet::PERCENT => $label.' (%)',
            default => $label,
        };
    }
}
