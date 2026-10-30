<?php

namespace App\Modules\Reports\Reports;

use App\Core\Tenancy\BranchContext;
use App\Models\User;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet;

/**
 * One report of the reports page: what its page shows and what its file holds, both drawn from the same queries
 * for the same filters. Reports only read; the screens that change things stay in their own sections.
 *
 * Every query runs through tenant-scoped models and is narrowed to the chosen branch (BranchContext::narrow()).
 */
abstract class Report
{
    /**
     * The page's data. `empty` says there is nothing for these filters (the page says so; the file has headings only).
     *
     * @return array<string, mixed>&array{empty: bool}
     */
    abstract public function page(ReportFilters $f, User $user): array;

    /**
     * The file's tables, whether they hold anything, and lines describing the report's own filters.
     *
     * @return array{sheets: list<ReportSheet>, empty: bool, filters?: list<array{0: string, 1: string}>}
     */
    abstract public function export(ReportFilters $f, User $user): array;

    /**
     * @template T
     *
     * @param  T  $query
     * @return T
     */
    protected function in($query, ReportFilters $f, string $column = 'branch_id')
    {
        return app(BranchContext::class)->narrow($query, $f->branch, $column);
    }

    /** A sum as the books keep it: three decimals. */
    protected function money(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }

    /**
     * @param  array<string, int|float|string>  $current
     * @param  array<string, int|float|string>  $previous
     * @return array<string, array{current: int|float|string, previous: int|float|string, change: float|null}>
     */
    protected function compare(array $current, array $previous): array
    {
        $out = [];
        foreach ($current as $key => $value) {
            $prev = (float) ($previous[$key] ?? 0);
            $out[$key] = ['current' => $value, 'previous' => $previous[$key] ?? 0, 'change' => $prev == 0.0 ? null : round(((float) $value - $prev) / abs($prev) * 100, 1)];
        }

        return $out;
    }
}
