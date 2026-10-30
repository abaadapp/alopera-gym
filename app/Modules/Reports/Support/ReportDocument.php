<?php

namespace App\Modules\Reports\Support;

use App\Core\Export\TableExporter;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * An exported report: its name, the lines that say what it covers (period, comparison, branch, filters, when it was
 * made) and its tables. In Excel each table is a sheet that opens with those lines; in CSV (UTF-8 with BOM, so Excel
 * shows Arabic) the lines come first and the tables follow one after another. Amounts carry three decimals and the
 * currency in their heading; a report with no data still gives a valid file of headings and says so in its lines.
 *
 * The file name holds only the report's key and its dates: no names of members, staff or the club.
 */
final class ReportDocument
{
    private const CURRENCIES = ['OMR' => 'ر.ع', 'SAR' => 'ر.س', 'AED' => 'د.إ', 'KWD' => 'د.ك', 'QAR' => 'ر.ق', 'BHD' => 'د.ب'];

    /**
     * @param  list<array{0: string, 1: string}>  $meta
     * @param  list<ReportSheet>  $sheets
     */
    public function __construct(
        public readonly string $slug,
        public readonly string $title,
        public readonly array $meta,
        public readonly array $sheets,
        public readonly bool $empty,
        private readonly string $currency = 'OMR',
    ) {}

    public function download(string $format): StreamedResponse
    {
        $name = preg_replace('/[^A-Za-z0-9_.-]/', '_', $this->slug).'-'.now()->format('Ymd-His');
        $headers = ['X-Report-Empty' => $this->empty ? '1' : '0', 'Cache-Control' => 'no-store, private'];

        if ($format === 'xlsx') {
            return response()->streamDownload(fn () => (new Xlsx($this->book()))->save('php://output'), $name.'.xlsx',
                $headers + ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        return response()->streamDownload(function () {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            $put = fn (array $row) => fputcsv($out, $row, escape: '');
            $put(['التقرير', TableExporter::cell($this->title)]);
            foreach ($this->meta as [$label, $value]) {
                $put([$label, TableExporter::cell((string) $value)]);
            }
            foreach ($this->sheets as $sheet) {
                $put([]);
                if (count($this->sheets) > 1) {
                    $put([TableExporter::cell($sheet->title)]);
                }
                $put(array_map(fn ($c) => $this->heading($c[0], $c[1]), $sheet->columns));
                foreach ($sheet->rows as $row) {
                    $cells = [];
                    foreach (array_values($row) as $i => $cell) {
                        [$value, $kind] = $this->split($cell, $sheet->columns[$i][1] ?? ReportSheet::TEXT);
                        $cells[] = $this->csv($value, $kind);
                    }
                    $put($cells);
                }
            }
            fclose($out);
        }, $name.'.csv', $headers + ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    private function book(): Spreadsheet
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        $names = [];

        foreach ($this->sheets as $sheet) {
            $ws = $book->createSheet();
            $ws->setTitle($this->sheetName($sheet->title, $names));
            $ws->setRightToLeft(true);

            $ws->setCellValueExplicit('A1', TableExporter::cell($this->title), DataType::TYPE_STRING);
            $ws->getStyle('A1')->getFont()->setBold(true)->setSize(14);
            $r = 2;
            foreach ($this->meta as [$label, $value]) {
                $ws->setCellValueExplicit("A{$r}", $label, DataType::TYPE_STRING);
                $ws->setCellValueExplicit("B{$r}", TableExporter::cell((string) $value), DataType::TYPE_STRING);
                $ws->getStyle("A{$r}")->getFont()->setBold(true);
                $r++;
            }
            if (count($this->sheets) > 1) {
                $ws->setCellValueExplicit("A{$r}", TableExporter::cell($sheet->title), DataType::TYPE_STRING);
                $ws->getStyle("A{$r}")->getFont()->setBold(true)->setSize(12);
                $r++;
            }
            $r++;

            $head = $r;
            foreach ($sheet->columns as $i => [$label, $kind]) {
                $ws->setCellValueExplicit([$i + 1, $head], $this->heading($label, $kind), DataType::TYPE_STRING);
            }
            $last = max(1, count($sheet->columns));
            $range = 'A'.$head.':'.Coordinate::stringFromColumnIndex($last).$head;
            $style = $ws->getStyle($range);
            $style->getFont()->setBold(true);
            $style->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('F2F2F0');
            $style->getBorders()->getBottom()->setBorderStyle(Border::BORDER_THIN)->getColor()->setRGB('D4D4D4');
            $ws->freezePane('A'.($head + 1));

            $r = $head + 1;
            foreach ($sheet->rows as $row) {
                foreach (array_values($row) as $i => $cell) {
                    [$value, $kind] = $this->split($cell, $sheet->columns[$i][1] ?? ReportSheet::TEXT);
                    $this->write($ws, $i + 1, $r, $value, $kind);
                }
                $r++;
            }
            foreach (range(1, $last) as $col) {
                $ws->getColumnDimensionByColumn($col)->setAutoSize(true);
            }
        }
        $book->setActiveSheetIndex(0);

        return $book;
    }

    private function write($ws, int $col, int $row, mixed $value, string $kind): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if ($kind === ReportSheet::TEXT || ! is_numeric($value)) {
            $ws->setCellValueExplicit([$col, $row], (string) TableExporter::cell((string) $value), DataType::TYPE_STRING);

            return;
        }
        $ws->setCellValueExplicit([$col, $row], $kind === ReportSheet::INT ? (int) $value : round((float) $value, 3), DataType::TYPE_NUMERIC);
        $format = match ($kind) {
            ReportSheet::MONEY => '#,##0.000',
            ReportSheet::INT => '#,##0',
            ReportSheet::PERCENT => '0.0"%"',
            default => null,
        };
        if ($format) {
            $ws->getStyle([$col, $row])->getNumberFormat()->setFormatCode($format);
        }
    }

    private function csv(mixed $value, string $kind): mixed
    {
        if ($value === null) {
            return '';
        }
        if ($kind === ReportSheet::TEXT || ! is_numeric($value)) {
            return TableExporter::cell((string) $value);
        }

        return match ($kind) {
            ReportSheet::MONEY => number_format((float) $value, 3, '.', ''),
            ReportSheet::INT => (string) (int) $value,
            ReportSheet::PERCENT => number_format((float) $value, 1, '.', ''),
            default => (string) round((float) $value, 3),
        };
    }

    /** @return array{0: mixed, 1: string} */
    private function split(mixed $cell, string $kind): array
    {
        return is_array($cell) && array_key_exists('__kind', $cell) ? [$cell['value'], $cell['__kind']] : [$cell, $kind];
    }

    private function heading(string $label, string $kind): string
    {
        return match ($kind) {
            ReportSheet::MONEY => $label.' ('.(self::CURRENCIES[$this->currency] ?? $this->currency).')',
            ReportSheet::PERCENT => $label.' (%)',
            default => $label,
        };
    }

    /** @param list<string> $used */
    private function sheetName(string $title, array &$used): string
    {
        $base = mb_substr(trim(str_replace(['[', ']', ':', '*', '?', '/', '\\'], ' ', $title)), 0, 28) ?: 'جدول';
        $name = $base;
        for ($n = 2; in_array($name, $used, true); $n++) {
            $name = $base.' '.$n;
        }
        $used[] = $name;

        return $name;
    }
}
