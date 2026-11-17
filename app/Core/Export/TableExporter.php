<?php

namespace App\Core\Export;

use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV (UTF-8 with BOM so Excel shows Arabic correctly) or XLSX downloads.
 *
 * Each column has a kind, given by the caller from what the column holds (never guessed from how a value looks):
 * MONEY is a number with three decimals (rials and baisa), INT a whole number, NUMBER a number; every other column is
 * TEXT and stays text as written (a phone, a member number, «123.456» typed in a description). Text that a spreadsheet
 * would take for a formula (=, +, -, @ first) is kept from becoming one: in CSV with a leading apostrophe, the only way
 * a CSV can say so; in Excel as a text cell marked with Excel's own «quote prefix», so nothing extra shows in the cell.
 */
final class TableExporter
{
    public const TEXT = 'text';

    public const MONEY = 'money';

    public const INT = 'int';

    public const NUMBER = 'number';

    /**
     * @param  list<string>  $headings
     * @param  iterable<array<int, mixed>>  $rows
     * @param  array<int, string>  $kinds  column index => kind (TEXT when not given)
     */
    public static function download(string $filename, array $headings, iterable $rows, string $format = 'csv', array $kinds = []): StreamedResponse
    {
        $filename = preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename).'-'.now()->format('Ymd-His');

        if ($format === 'xlsx') {
            return response()->streamDownload(function () use ($headings, $rows, $kinds) {
                $book = new Spreadsheet;
                $sheet = $book->getActiveSheet();
                $sheet->setRightToLeft(true);
                foreach (array_values($headings) as $i => $heading) {
                    self::text($sheet, $i + 1, 1, $heading);
                }
                $sheet->getStyle('1:1')->getFont()->setBold(true);
                $r = 2;
                foreach ($rows as $row) {
                    foreach (array_values($row) as $i => $value) {
                        self::write($sheet, $i + 1, $r, $value, $kinds[$i] ?? self::TEXT);
                    }
                    $r++;
                }
                // A number format per column, set once for its rows rather than cell by cell.
                foreach ($kinds as $i => $kind) {
                    $code = match ($kind) {
                        self::MONEY => '#,##0.000',
                        self::INT => '#,##0',
                        default => null,
                    };
                    if ($code && $r > 2) {
                        $letter = Coordinate::stringFromColumnIndex($i + 1);
                        $sheet->getStyle($letter.'2:'.$letter.($r - 1))->getNumberFormat()->setFormatCode($code);
                    }
                }
                foreach (range(1, count($headings)) as $col) {
                    $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
                }
                (new Xlsx($book))->save('php://output');
            }, $filename.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        return response()->streamDownload(function () use ($headings, $rows, $kinds) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headings, escape: '');
            foreach ($rows as $row) {
                $cells = [];
                foreach (array_values($row) as $i => $value) {
                    $kind = $kinds[$i] ?? self::TEXT;
                    $cells[] = $kind === self::MONEY && is_numeric($value) ? number_format((float) $value, 3, '.', '') : self::cell($value);
                }
                fputcsv($out, $cells, escape: '');
            }
            fclose($out);
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** One Excel cell of its column's kind: a number only in a number column, text everywhere else. */
    private static function write(Worksheet $sheet, int $col, int $row, mixed $value, string $kind): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if ($kind !== self::TEXT && is_numeric($value)) {
            $sheet->setCellValueExplicit([$col, $row], $kind === self::INT ? (int) $value : round((float) $value, 3), DataType::TYPE_NUMERIC);

            return;
        }
        self::text($sheet, $col, $row, $value);
    }

    /**
     * A text cell in Excel, as written. One that starts like a formula is a text cell with Excel's quote prefix: Excel
     * shows it as typed, without a visible apostrophe, and keeps it text even when the cell is edited.
     */
    public static function text(Worksheet $sheet, int|string $col, int $row, mixed $value): void
    {
        $text = $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i') : (string) $value;
        $sheet->setCellValueExplicit(is_int($col) ? [$col, $row] : $col.$row, $text, DataType::TYPE_STRING);
        if (self::risky($text)) {
            $sheet->getStyle(is_int($col) ? [$col, $row] : $col.$row)->setQuotePrefix(true);
        }
    }

    /** Neutralise spreadsheet formula injection in CSV (=, +, -, @ at the start of a text cell). */
    public static function cell(mixed $value): mixed
    {
        if (is_string($value) && self::risky($value) && ! is_numeric($value)) {
            return "'".$value;
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i') : $value;
    }

    private static function risky(string $text): bool
    {
        return $text !== '' && in_array($text[0], ['=', '+', '-', '@', "\t", "\r"], true);
    }
}
