<?php

namespace App\Modules\Payroll\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Support\NumberSequence;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Accounting\Services\Ledger;
use App\Modules\Gym\Models\StaffAttendance;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollPayout;
use App\Modules\Payroll\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * The payroll cycle:
 *
 *   open a month   active staff and their salaries are copied into lines (a later raise leaves this run alone)
 *   draft          overtime and deductions are entered; net = basic + allowances + overtime − deductions (3 dp)
 *   approve        posts once: Dr salaries & wages (earnings), Cr salary deductions, Cr salaries payable (net);
 *                  the run is locked from then on
 *   pay            any lines, from a cash or bank account: Dr salaries payable, Cr that account. Lines are
 *                  locked and re-read, so a repeated request pays nobody twice. When every line is paid the run
 *                  is «مصروفة».
 */
final class PayrollService
{
    public function __construct(private readonly Ledger $ledger, private readonly ChartOfAccounts $chart, private readonly AuditLogger $audit) {}

    public function open(string $month): PayrollRun
    {
        $period = CarbonImmutable::createFromFormat('!Y-m', $month);
        if (! $period || $period->format('Y-m') !== $month) {
            throw new BusinessRuleException('payroll.month', 'اختر شهرًا صحيحًا.', 'month');
        }
        if (PayrollRun::where('period', $period->toDateString())->exists()) {
            throw new BusinessRuleException('payroll.exists', 'توجد مسيرة لهذا الشهر بالفعل.', 'month');
        }

        $end = $period->endOfMonth();
        $employees = Employee::with('jobTitle')->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('hired_at')->orWhere('hired_at', '<=', $end->toDateString()))
            ->orderBy('name')->get()
            ->filter(fn (Employee $e) => Money::cmp($e->basic_salary, 0) > 0 || Money::cmp($e->allowancesTotal(), 0) > 0);

        if ($employees->isEmpty()) {
            throw new BusinessRuleException('payroll.no_staff', 'لا يوجد موظفون على رأس العمل برواتب مسجّلة. أضف الرواتب في ملفات الموظفين أولًا.', 'month');
        }

        $attendance = $this->attendance($employees, $period, $end);

        return DB::transaction(function () use ($period, $employees, $attendance) {
            $run = PayrollRun::create([
                'number' => NumberSequence::next('payroll_run'),
                'period' => $period->toDateString(),
                'status' => PayrollRun::DRAFT,
                'created_by' => Auth::guard('web')->id(),
            ]);

            foreach ($employees as $employee) {
                $allowances = $employee->allowancesTotal();
                $run->items()->create([
                    'employee_id' => $employee->id,
                    'employee_name' => $employee->name,
                    'job_title' => $employee->jobTitle?->name,
                    'basic' => $employee->basic_salary,
                    'allowances' => $allowances,
                    'allowance_lines' => $employee->allowances ?? [],
                    'net' => Money::add($employee->basic_salary, $allowances),
                    'payment_method' => $employee->payment_method,
                    'attendance_days' => $attendance[$employee->id]['days'] ?? null,
                    'attendance_hours' => $attendance[$employee->id]['hours'] ?? null,
                ]);
            }

            $this->recalculate($run);
            $this->audit->log('payroll.opened', $run, ['period' => $run->period->format('Y-m'), 'lines' => $employees->count()]);

            return $run;
        });
    }

    /** @param  array{overtime_hours?: mixed, overtime?: mixed, allowances?: mixed, deductions?: mixed, deduction_notes?: string|null, notes?: string|null}  $data */
    public function updateItem(PayrollItem $item, array $data): PayrollItem
    {
        return DB::transaction(function () use ($item, $data) {
            $run = $this->lockDraft($item->payroll_run_id);
            $item = PayrollItem::whereKey($item->id)->where('payroll_run_id', $run->id)->lockForUpdate()->firstOrFail();

            $item->fill([
                'overtime_hours' => $data['overtime_hours'] ?? $item->overtime_hours,
                'overtime' => Money::normalize($data['overtime'] ?? $item->overtime),
                'allowances' => Money::normalize($data['allowances'] ?? $item->allowances),
                'deductions' => Money::normalize($data['deductions'] ?? $item->deductions),
                'deduction_notes' => array_key_exists('deduction_notes', $data) ? $data['deduction_notes'] : $item->deduction_notes,
                'notes' => array_key_exists('notes', $data) ? $data['notes'] : $item->notes,
            ]);
            $this->guardDeductions($item);
            $item->net = Money::sub($item->earnings(), $item->deductions);
            $item->save();
            $this->recalculate($run);

            return $item;
        });
    }

    public function removeItem(PayrollItem $item): void
    {
        DB::transaction(function () use ($item) {
            $run = $this->lockDraft($item->payroll_run_id);
            PayrollItem::whereKey($item->id)->where('payroll_run_id', $run->id)->delete();
            $this->recalculate($run);
        });
    }

    public function discard(PayrollRun $run): void
    {
        DB::transaction(function () use ($run) {
            $run = $this->lockDraft($run->id);
            $run->items()->delete();
            $run->delete();
            $this->audit->log('payroll.discarded', null, ['number' => $run->number, 'period' => $run->period->format('Y-m')]);
        });
    }

    public function approve(PayrollRun $run): PayrollRun
    {
        return DB::transaction(function () use ($run) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if (! $run->isDraft()) {
                throw new BusinessRuleException('payroll.not_draft', 'هذه المسيرة معتمدة مسبقًا.');
            }
            $items = $run->items()->orderBy('employee_name')->get();
            if ($items->isEmpty() || Money::cmp($run->net, 0) <= 0) {
                throw new BusinessRuleException('payroll.empty', 'لا تُعتمد مسيرة بلا رواتب.');
            }
            $items->each(fn (PayrollItem $item) => $this->guardDeductions($item));

            $lines = $items->map(fn (PayrollItem $item) => ['account' => $this->chart->account('salaries_expense'), 'debit' => $item->earnings(), 'memo' => $item->employee_name])->all();
            $lines[] = ['account' => $this->chart->account('salary_deductions'), 'credit' => $run->deductions];
            $lines[] = ['account' => $this->chart->account('salaries_payable'), 'credit' => $run->net];

            // The month's salaries belong to that month: dated at its end, or today if it is not over yet.
            $this->ledger->post([
                'date' => $run->period->endOfMonth()->min(today()),
                'description' => "رواتب {$run->label()} — {$run->number}",
                'source_type' => 'payroll_run', 'source_id' => $run->id, 'event' => 'approve',
            ], $lines);

            $run->forceFill(['status' => PayrollRun::APPROVED, 'approved_by' => Auth::guard('web')->id(), 'approved_at' => now()])->save();
            $this->audit->log('payroll.approved', $run, ['net' => $run->net, 'lines' => $items->count()]);

            return $run;
        });
    }

    /**
     * Pay the chosen lines that are still unpaid, from one cash or bank account. Lines already paid (a double
     * click, two people at once) are skipped; if none is left, nothing is posted.
     *
     * @param  list<int>  $itemIds
     * @return array{payout: PayrollPayout, paid: int}
     */
    public function pay(PayrollRun $run, array $itemIds, Account $account, string $date, ?string $reference = null): array
    {
        if (! $account->isPostable() || ! in_array($account->kind, Account::PAYING_KINDS, true)) {
            throw new BusinessRuleException('payroll.account', 'تُصرف الرواتب من الصندوق أو من حساب بنكي فقط.', 'account_id');
        }

        return DB::transaction(function () use ($run, $itemIds, $account, $date, $reference) {
            $run = PayrollRun::whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($run->status === PayrollRun::DRAFT) {
                throw new BusinessRuleException('payroll.not_approved', 'اعتمد المسيرة قبل صرف رواتبها.');
            }

            $items = PayrollItem::where('payroll_run_id', $run->id)->whereIn('id', $itemIds)->whereNull('paid_at')
                ->orderBy('employee_name')->lockForUpdate()->get();
            if ($items->isEmpty()) {
                throw new BusinessRuleException('payroll.nothing_to_pay', 'لا توجد رواتب مستحقة في الاختيار — ربما صُرفت مسبقًا.');
            }

            $total = Money::add('0', ...$items->pluck('net')->all());
            $payout = PayrollPayout::create([
                'payroll_run_id' => $run->id,
                'number' => NumberSequence::next('payroll_payout'),
                'paid_on' => $date,
                'account_id' => $account->id,
                'total' => $total,
                'reference' => $reference,
                'created_by' => Auth::guard('web')->id(),
            ]);

            $lines = $items->filter(fn ($i) => Money::cmp($i->net, 0) > 0)
                ->map(fn (PayrollItem $item) => ['account' => $this->chart->account('salaries_payable'), 'debit' => $item->net, 'memo' => $item->employee_name])->values()->all();
            if ($lines !== []) {
                $lines[] = ['account' => $account, 'credit' => $total];
                $this->ledger->post([
                    'date' => $date,
                    'description' => "صرف رواتب {$run->label()} — {$payout->number}",
                    'source_type' => 'payroll_payout', 'source_id' => $payout->id, 'event' => 'pay',
                ], $lines);
            }

            PayrollItem::whereIn('id', $items->pluck('id'))->update(['paid_at' => now(), 'payroll_payout_id' => $payout->id]);
            if (! PayrollItem::where('payroll_run_id', $run->id)->whereNull('paid_at')->exists()) {
                $run->forceFill(['status' => PayrollRun::PAID])->save();
            }
            $this->audit->log('payroll.paid', $run, ['payout' => $payout->number, 'lines' => $items->count(), 'total' => $total, 'account' => $account->name]);

            return ['payout' => $payout, 'paid' => $items->count()];
        });
    }

    private function lockDraft(int $runId): PayrollRun
    {
        $run = PayrollRun::whereKey($runId)->lockForUpdate()->firstOrFail();
        if (! $run->isDraft()) {
            throw new BusinessRuleException('payroll.locked', 'المسيرة معتمدة ولا تُعدَّل. التصحيح يكون في مسيرة الشهر التالي أو بقيد محاسبي.');
        }

        return $run;
    }

    private function guardDeductions(PayrollItem $item): void
    {
        foreach (['overtime', 'allowances', 'deductions'] as $field) {
            if (Money::cmp($item->{$field}, 0) < 0) {
                throw new BusinessRuleException('payroll.negative', 'المبالغ لا تكون سالبة.', $field);
            }
        }
        if (Money::cmp($item->deductions, $item->earnings()) > 0) {
            throw new BusinessRuleException('payroll.deductions', "خصومات {$item->employee_name} أكبر من إجمالي المستحقات.", 'deductions');
        }
    }

    private function recalculate(PayrollRun $run): void
    {
        $items = PayrollItem::where('payroll_run_id', $run->id)->get();
        $run->forceFill([
            'gross' => Money::add('0', ...$items->map(fn (PayrollItem $i) => $i->earnings())->all()),
            'deductions' => Money::add('0', ...$items->pluck('deductions')->all()),
            'net' => Money::add('0', ...$items->pluck('net')->all()),
        ])->save();
    }

    /** Days present and hours worked in the month, from staff attendance of each employee's sign-in account. */
    private function attendance(Collection $employees, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $byUser = $employees->filter(fn (Employee $e) => $e->user_id)->keyBy('user_id');
        if ($byUser->isEmpty()) {
            return [];
        }

        $out = [];
        StaffAttendance::whereIn('user_id', $byUser->keys())->whereBetween('clock_in_at', [$from->startOfDay(), $to->endOfDay()])->get()
            ->groupBy('user_id')
            ->each(function ($rows, $userId) use ($byUser, &$out) {
                $out[$byUser[$userId]->id] = [
                    'days' => $rows->map(fn ($r) => $r->clock_in_at->toDateString())->unique()->count(),
                    'hours' => round($rows->sum(fn ($r) => $r->clock_out_at ? $r->clock_in_at->diffInMinutes($r->clock_out_at) : 0) / 60, 2),
                ];
            });

        return $out;
    }
}
