<?php

namespace App\Modules\Reports\Reports;

use App\Core\Support\Money;
use App\Core\Tenancy\BranchContext;
use App\Models\User;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollPayout;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * صرف الرواتب: the salaries paid in the period, payout by payout and from which account, and what approved runs
 * still owe today. Amounts are summed from the paid lines (a payout's total is the same sum), so a branch gets its
 * own staff's share. The account is named, never its number. Paying stays in the payroll section.
 */
final class PayoutsReport extends Report
{
    private const FIGURES = ['paid' => ['المصروف في الفترة', S::MONEY], 'payouts' => ['دفعات الصرف', S::INT], 'employees' => ['موظفون صُرف لهم', S::INT]];

    public function page(ReportFilters $f, User $user): array
    {
        $payouts = $this->payouts($f, $f->from, $f->to);

        return [
            'comparison' => $this->compare($this->totals($payouts), $this->totals($this->payouts($f, $f->prevFrom, $f->prevTo))),
            'payouts' => $payouts->values(),
            'byAccount' => $this->byAccount($payouts),
            'waiting' => Money::add('0', ...$this->lines($f)->whereNull('paid_at')->whereHas('run', fn ($q) => $q->where('status', PayrollRun::APPROVED))->pluck('net')->all()),
            'empty' => $payouts->isEmpty(),
        ];
    }

    public function export(ReportFilters $f, User $user): array
    {
        $payouts = $this->payouts($f, $f->from, $f->to);
        $numbers = $payouts->pluck('number', 'id');

        return [
            'empty' => $payouts->isEmpty(),
            'sheets' => [
                S::summary($this->compare($this->totals($payouts), $this->totals($this->payouts($f, $f->prevFrom, $f->prevTo))), self::FIGURES),
                new S('دفعات الصرف', [['رقم الدفعة', S::TEXT], ['التاريخ', S::TEXT], ['المسيرة', S::TEXT], ['من حساب', S::TEXT], ['الموظفون', S::INT], ['المبلغ', S::MONEY], ['المرجع', S::TEXT], ['سجّلها', S::TEXT]],
                    $payouts->map(fn ($p) => [$p['number'], $p['paid_on'], $p['period'], $p['account'], $p['employees'], $p['total'], $p['reference'], $p['by']])->all()),
                new S('تفاصيل الصرف', [['رقم الدفعة', S::TEXT], ['الموظف', S::TEXT], ['المسمى', S::TEXT], ['الصافي', S::MONEY], ['طريقة الصرف', S::TEXT]],
                    $this->lines($f)->whereIn('payroll_payout_id', $payouts->pluck('id'))->orderBy('payroll_payout_id')->orderBy('employee_name')->get()
                        ->map(fn (PayrollItem $i) => [$numbers[$i->payroll_payout_id] ?? '', $i->employee_name, $i->job_title, $i->net, PayrollReport::METHODS[$i->payment_method] ?? $i->payment_method])->all()),
                new S('حسب الحساب', [['الحساب', S::TEXT], ['الدفعات', S::INT], ['المبلغ', S::MONEY]], array_map(fn ($a) => [$a['account'], $a['count'], $a['total']], $this->byAccount($payouts))),
            ],
        ];
    }

    private function lines(ReportFilters $f): Builder
    {
        $main = $f->branch !== null && $f->branch === app(BranchContext::class)->mainId();

        return PayrollItem::query()->when($f->branch, fn ($q) => $q->where(fn ($w) => $w->whereHas('employee', fn ($e) => $this->in($e, $f))
            ->when($main, fn ($m) => $m->orWhereNull('employee_id'))));
    }

    private function payouts(ReportFilters $f, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $payouts = PayrollPayout::with(['account:id,name', 'author:id,name', 'run:id,period'])
            ->whereBetween('paid_on', [$from->toDateString(), $to->toDateString()])->orderByDesc('paid_on')->orderByDesc('id')->get();
        $lines = $this->lines($f)->whereIn('payroll_payout_id', $payouts->pluck('id'))->get(['id', 'payroll_payout_id', 'employee_id', 'net'])->groupBy('payroll_payout_id');

        return $payouts->map(function (PayrollPayout $p) use ($lines) {
            $items = $lines->get($p->id, collect());

            return [
                'id' => $p->id, 'number' => $p->number, 'paid_on' => $p->paid_on->toDateString(), 'period' => $p->run?->label(), 'account' => $p->account?->name,
                'reference' => $p->reference, 'by' => $p->author?->name, 'employees' => $items->count(), 'employee_ids' => $items->pluck('employee_id')->filter()->all(),
                'total' => Money::add('0', ...$items->pluck('net')->all()),
            ];
        })->filter(fn ($p) => $p['employees'] > 0)->values();
    }

    private function totals(Collection $payouts): array
    {
        return [
            'paid' => Money::add('0', ...$payouts->pluck('total')->all()),
            'payouts' => $payouts->count(),
            'employees' => $payouts->flatMap(fn ($p) => $p['employee_ids'])->unique()->count(),
        ];
    }

    private function byAccount(Collection $payouts): array
    {
        return $payouts->groupBy(fn ($p) => $p['account'] ?? '—')
            ->map(fn ($g, $account) => ['account' => $account, 'count' => $g->count(), 'total' => Money::add('0', ...$g->pluck('total')->all())])
            ->sortByDesc(fn ($a) => (float) $a['total'])->values()->all();
    }
}
