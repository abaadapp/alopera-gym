<?php

namespace App\Modules\Reports\Reports;

use App\Core\Support\Money;
use App\Core\Tenancy\BranchContext;
use App\Models\User;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * مسيرات الرواتب: the months' runs in the period, what each costs and how much of it is paid, and one run's sheet.
 * Figures are summed from the runs' lines (the runs' own totals are the same sums), so a branch gets its own staff's
 * share. Salary figures are for payroll.reports; bank numbers never appear. Preparing, approving and paying stay in
 * the payroll section.
 */
final class PayrollReport extends Report
{
    public const STATUSES = [PayrollRun::DRAFT => 'مسودة', PayrollRun::APPROVED => 'معتمدة', PayrollRun::PAID => 'مصروفة'];

    public const METHODS = ['cash' => 'نقدًا', 'bank' => 'تحويل بنكي'];

    private const FIGURES = [
        'runs' => ['المسيرات', S::INT], 'gross' => ['إجمالي الاستحقاقات', S::MONEY], 'deductions' => ['الخصومات', S::MONEY],
        'net' => ['صافي الرواتب', S::MONEY], 'paid' => ['المصروف', S::MONEY], 'unpaid' => ['لم يُصرف بعد', S::MONEY],
    ];

    public function page(ReportFilters $f, User $user): array
    {
        $runs = $this->runs($f, $f->from, $f->to);
        $selected = $runs->firstWhere('id', $f->integer('run')) ?? $runs->first();

        return [
            'comparison' => $this->compare($this->totals($runs), $this->totals($this->runs($f, $f->prevFrom, $f->prevTo))),
            'runs' => $runs->values(),
            'run' => $selected,
            'items' => $selected ? $this->items($f)->where('payroll_run_id', $selected['id'])->orderBy('employee_name')->get()->map(fn (PayrollItem $i) => $this->item($i))->values() : [],
            'options' => ['run' => $selected['id'] ?? null],
            'empty' => $runs->isEmpty(),
        ];
    }

    public function export(ReportFilters $f, User $user): array
    {
        $runs = $this->runs($f, $f->from, $f->to);
        $labels = $runs->pluck('label', 'id');

        return [
            'empty' => $runs->isEmpty(),
            'sheets' => [
                S::summary($this->compare($this->totals($runs), $this->totals($this->runs($f, $f->prevFrom, $f->prevTo))), self::FIGURES),
                new S('المسيرات', [['المسيرة', S::TEXT], ['الرقم', S::TEXT], ['الحالة', S::TEXT], ['الموظفون', S::INT], ['الاستحقاقات', S::MONEY], ['الخصومات', S::MONEY], ['الصافي', S::MONEY], ['المصروف', S::MONEY], ['المتبقي', S::MONEY]],
                    $runs->map(fn ($r) => [$r['label'], $r['number'], self::STATUSES[$r['status']] ?? $r['status'], $r['employees'], $r['gross'], $r['deductions'], $r['net'], $r['paid'], $r['unpaid']])->all()),
                new S('الرواتب', [['المسيرة', S::TEXT], ['الموظف', S::TEXT], ['المسمى', S::TEXT], ['أيام الحضور', S::INT], ['الأساسي', S::MONEY], ['البدلات', S::MONEY], ['ساعات إضافية', S::NUMBER], ['الإضافي', S::MONEY], ['الخصومات', S::MONEY], ['سبب الخصم', S::TEXT], ['الصافي', S::MONEY], ['طريقة الصرف', S::TEXT], ['الصرف', S::TEXT]],
                    $this->items($f)->whereIn('payroll_run_id', $runs->pluck('id'))->orderBy('payroll_run_id')->orderBy('employee_name')->get()
                        ->map(fn (PayrollItem $i) => [$labels[$i->payroll_run_id] ?? '', $i->employee_name, $i->job_title, $i->attendance_days, $i->basic, $i->allowances, $i->overtime_hours, $i->overtime, $i->deductions, $i->deduction_notes, $i->net,
                            self::METHODS[$i->payment_method] ?? $i->payment_method, $i->paid_at ? 'صُرف '.$i->paid_at->toDateString() : 'لم يُصرف'])->all()),
            ],
        ];
    }

    /** The lines of the chosen branch's staff (a line without an employee file counts as the main branch's). */
    private function items(ReportFilters $f): Builder
    {
        $main = $f->branch !== null && $f->branch === app(BranchContext::class)->mainId();

        return PayrollItem::query()->when($f->branch, fn ($q) => $q->where(fn ($w) => $w->whereHas('employee', fn ($e) => $this->in($e, $f))
            ->when($main, fn ($m) => $m->orWhereNull('employee_id'))));
    }

    /** The runs of the months in the period, each summed from its lines. */
    private function runs(ReportFilters $f, CarbonImmutable $from, CarbonImmutable $to): Collection
    {
        $runs = PayrollRun::whereBetween('period', [$from->startOfMonth()->toDateString(), $to->toDateString()])->orderByDesc('period')->get();
        $lines = $this->items($f)->whereIn('payroll_run_id', $runs->pluck('id'))->get()->groupBy('payroll_run_id');

        return $runs->map(function (PayrollRun $r) use ($lines) {
            $items = $lines->get($r->id, collect());
            $paid = $items->whereNotNull('paid_at');

            return [
                'id' => $r->id, 'number' => $r->number, 'label' => $r->label(), 'period' => $r->period->format('Y-m'), 'status' => $r->status,
                'employees' => $items->count(), 'paid_count' => $paid->count(),
                'gross' => Money::add('0', ...$items->map(fn (PayrollItem $i) => $i->earnings())->all()),
                'deductions' => Money::add('0', ...$items->pluck('deductions')->all()),
                'net' => Money::add('0', ...$items->pluck('net')->all()),
                'paid' => Money::add('0', ...$paid->pluck('net')->all()),
                'unpaid' => Money::add('0', ...$items->whereNull('paid_at')->pluck('net')->all()),
            ];
        })->filter(fn ($r) => $r['employees'] > 0 || ! $f->branch)->values();
    }

    private function totals(Collection $runs): array
    {
        return [
            'runs' => $runs->count(),
            'gross' => Money::add('0', ...$runs->pluck('gross')->all()),
            'deductions' => Money::add('0', ...$runs->pluck('deductions')->all()),
            'net' => Money::add('0', ...$runs->pluck('net')->all()),
            'paid' => Money::add('0', ...$runs->pluck('paid')->all()),
            'unpaid' => Money::add('0', ...$runs->pluck('unpaid')->all()),
        ];
    }

    private function item(PayrollItem $i): array
    {
        return [
            'id' => $i->id, 'name' => $i->employee_name, 'job_title' => $i->job_title, 'attendance_days' => $i->attendance_days,
            'basic' => $i->basic, 'allowances' => $i->allowances, 'overtime' => $i->overtime, 'deductions' => $i->deductions, 'net' => $i->net,
            'method' => self::METHODS[$i->payment_method] ?? $i->payment_method, 'paid_at' => $i->paid_at?->toDateString(),
        ];
    }
}
