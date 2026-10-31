<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet;

/**
 * A report made of a few figures and tables (the inventory and purchasing reports): the page and the file are drawn
 * from the same build, so what is read on screen is what the file holds. The page shows the first rows of a long
 * table; the file has them all.
 */
abstract class TabularReport extends Report
{
    protected const PAGE_ROWS = 300;

    /**
     * @return array{figures: list<array{0: string, 1: int|float|string, 2: string}>, tables: list<ReportSheet>, empty: bool, filters?: list<array{0: string, 1: string}>, options?: array<string, mixed>}
     */
    abstract protected function build(ReportFilters $f, User $user): array;

    public function page(ReportFilters $f, User $user): array
    {
        $b = $this->build($f, $user);

        return [
            'figures' => array_map(fn ($x) => ['label' => $x[0], 'value' => $x[1], 'kind' => $x[2]], $b['figures']),
            'tables' => array_map(function (ReportSheet $s) {
                $rows = [];
                $total = 0;
                foreach ($s->rows as $row) {
                    $total++;
                    if ($total <= static::PAGE_ROWS) {
                        $rows[] = array_map(fn ($c) => is_array($c) ? $c['value'] : $c, $row);
                    }
                }

                return ['title' => $s->title, 'columns' => array_map(fn ($c) => ['label' => $c[0], 'kind' => $c[1]], $s->columns), 'rows' => $rows, 'total' => $total];
            }, $b['tables']),
            'options' => $b['options'] ?? [],
            'empty' => $b['empty'],
        ];
    }

    public function export(ReportFilters $f, User $user): array
    {
        $b = $this->build($f, $user);
        $summary = new ReportSheet('الملخص', [['المؤشر', ReportSheet::TEXT], ['القيمة', ReportSheet::NUMBER]], array_map(fn ($x) => [$x[0], ReportSheet::cell($x[1], $x[2])], $b['figures']));

        return ['sheets' => [$summary, ...$b['tables']], 'empty' => $b['empty'], 'filters' => $b['filters'] ?? []];
    }
}
