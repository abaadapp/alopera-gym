<?php

namespace App\Modules\Reports\Reports;

use App\Core\Support\Money;
use App\Models\User;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;

/**
 * قائمة الدخل وميزان المراجعة, from the journal: revenue and expenses for the period beside the comparison period,
 * and every account's balance up to the period's last day. For one branch, its own entries (the main branch also
 * holds the entries without one); the books themselves are not changed.
 */
final class StatementsReport extends Report
{
    private const CONTRA = ['sales_returns', 'salary_deductions'];

    public function __construct(private readonly FinanceReports $books) {}

    public function page(ReportFilters $f, User $user): array
    {
        $view = $f->choice('view', ['income', 'trial'], 'income');
        $income = $this->income($f);
        $trial = $this->trial($f);

        return ['view' => $view, 'income' => $income, 'trial' => $trial, 'empty' => $view === 'trial' ? $trial['rows'] === [] : $income['empty']];
    }

    public function export(ReportFilters $f, User $user): array
    {
        $income = $this->income($f);
        $trial = $this->trial($f);
        $line = fn (string $section, array $r) => [$section, $r['code'], $r['name'].($r['contra'] ? ' (تُخصم)' : ''), $r['amount'], $r['previous']];

        $rows = [];
        foreach ($income['revenue'] as $r) {
            $rows[] = $line('الإيرادات', $r);
        }
        $rows[] = ['', '', 'إجمالي الإيرادات', $income['total_revenue'], $income['previous']['total_revenue']];
        foreach ($income['expenses'] as $r) {
            $rows[] = $line('المصروفات', $r);
        }
        $rows[] = ['', '', 'إجمالي المصروفات', $income['total_expenses'], $income['previous']['total_expenses']];
        $rows[] = ['', '', 'صافي النتيجة', $income['net'], $income['previous']['net']];

        $trialRows = array_map(fn ($r) => [$r['code'], $r['name'], $r['debit'], $r['credit'], $r['balance'], $r['side'] === 'debit' ? 'مدين' : 'دائن'], $trial['rows']);
        $trialRows[] = ['', 'الإجمالي', $trial['total_debit'], $trial['total_credit'], '', ''];

        return [
            'empty' => $income['empty'] && $trial['rows'] === [],
            'filters' => [['ميزان المراجعة', 'الأرصدة حتى '.$f->to->toDateString()]],
            'sheets' => [
                new S('قائمة الدخل', [['القسم', S::TEXT], ['الرمز', S::TEXT], ['الحساب', S::TEXT], ['الفترة الحالية', S::MONEY], ['فترة المقارنة', S::MONEY]], $rows),
                new S('ميزان المراجعة', [['الرمز', S::TEXT], ['الحساب', S::TEXT], ['مدين', S::MONEY], ['دائن', S::MONEY], ['الرصيد', S::MONEY], ['طبيعة الحساب', S::TEXT]], $trialRows),
            ],
        ];
    }

    /** The period's statement, each account beside its amount in the comparison period. */
    private function income(ReportFilters $f): array
    {
        $now = $this->books->incomeStatement($f->from->toDateString(), $f->to->toDateString(), $f->branch);
        $before = $this->books->incomeStatement($f->prevFrom->toDateString(), $f->prevTo->toDateString(), $f->branch);

        $rows = function (string $group) use ($now, $before): array {
            $previous = collect($before[$group])->keyBy(fn ($r) => $r['account']->id);
            $current = collect($now[$group])->keyBy(fn ($r) => $r['account']->id);
            $accounts = $current->map(fn ($r) => $r['account'])->union($previous->map(fn ($r) => $r['account']))->sortBy('code');

            return $accounts->map(fn ($a) => [
                'code' => $a->code, 'name' => $a->name, 'contra' => in_array($a->system_key, self::CONTRA, true),
                'amount' => $current->get($a->id)['amount'] ?? '0.000', 'previous' => $previous->get($a->id)['amount'] ?? '0.000',
            ])->values()->all();
        };
        $revenue = $rows('revenue');
        $expenses = $rows('expenses');

        return [
            'revenue' => $revenue, 'expenses' => $expenses,
            'total_revenue' => $now['total_revenue'], 'total_expenses' => $now['total_expenses'], 'net' => $now['net'],
            'previous' => ['total_revenue' => $before['total_revenue'], 'total_expenses' => $before['total_expenses'], 'net' => $before['net']],
            'comparison' => $this->compare(
                ['total_revenue' => $now['total_revenue'], 'total_expenses' => $now['total_expenses'], 'net' => $now['net']],
                ['total_revenue' => $before['total_revenue'], 'total_expenses' => $before['total_expenses'], 'net' => $before['net']],
            ),
            'empty' => collect([...$revenue, ...$expenses])->every(fn ($r) => Money::isZero($r['amount'])),
        ];
    }

    private function trial(ReportFilters $f): array
    {
        $trial = $this->books->trialBalance($f->to->toDateString(), $f->branch);

        return [
            'rows' => array_map(fn ($r) => ['code' => $r['account']->code, 'name' => $r['account']->name, 'type' => $r['account']->type, 'debit' => $r['debit'], 'credit' => $r['credit'], 'balance' => $r['balance'], 'side' => $r['account']->normal_side], $trial['rows']),
            'total_debit' => $trial['total_debit'], 'total_credit' => $trial['total_credit'],
        ];
    }
}
