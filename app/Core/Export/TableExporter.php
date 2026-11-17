<?php

namespace App\Core\Export;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * CSV (UTF-8 with BOM so Excel shows Arabic correctly) or XLSX downloads.
 */
final class TableExporter
{
    /**
     * @param  list<string>  $headings
     * @param  iterable<array<int, mixed>>  $rows
     */
    public static function download(string $filename, array $headings, iterable $rows, string $format = 'csv'): StreamedResponse
    {
        $filename = preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename).'-'.now()->format('Ymd-His');

        if ($format === 'xlsx') {
            return response()->streamDownload(function () use ($headings, $rows) {
                $book = new Spreadsheet;
                $sheet = $book->getActiveSheet();
                $sheet->setRightToLeft(true);
                $sheet->fromArray($headings, null, 'A1');
                $sheet->getStyle('1:1')->getFont()->setBold(true);
                $r = 2;
                foreach ($rows as $row) {
                    foreach (array_values($row) as $i => $value) {
                        self::write($sheet, $i + 1, $r, self::cell($value));
                    }
                    $r++;
                }
                foreach (range(1, count($headings)) as $col) {
                    $sheet->getColumnDimensionByColumn($col)->setAutoSize(true);
                }
                (new Xlsx($book))->save('php://output');
            }, $filename.'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
        }

        return response()->streamDownload(function () use ($headings, $rows) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, $headings, escape: '');
            foreach ($rows as $row) {
                fputcsv($out, array_map([self::class, 'cell'], array_values($row)), escape: '');
            }
            fclose($out);
        }, $filename.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /**
     * One Excel cell, of the kind its value is: an amount as stored (decimal(12,3), "25.500") a number shown with its three
     * decimals; a PHP number a number; any other text (a phone, a member number, a name) text as written, never turned
     * into a number that drops a leading 0 or + or the rials' decimals.
     */
    private static function write(Worksheet $sheet, int $col, int $row, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (is_string($value) && preg_match('/^-?\d+\.\d{3}$/', $value)) {
            $sheet->setCellValueExplicit([$col, $row], (float) $value, DataType::TYPE_NUMERIC);
            $sheet->getStyle([$col, $row])->getNumberFormat()->setFormatCode('#,##0.000');

            return;
        }
        if (is_int($value) || is_float($value)) {
            $sheet->setCellValueExplicit([$col, $row], $value, DataType::TYPE_NUMERIC);

            return;
        }
        $sheet->setCellValueExplicit([$col, $row], (string) $value, DataType::TYPE_STRING);
    }

    /** Neutralise spreadsheet formula injection (=, +, -, @ at the start of a text cell). */
    public static function cell(mixed $value): mixed
    {
        if (is_string($value) && $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($value)) {
            return "'".$value;
        }

        return $value instanceof \DateTimeInterface ? $value->format('Y-m-d H:i') : $value;
    }
}
