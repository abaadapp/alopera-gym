<?php

namespace App\Modules\Payroll\Papers;

use App\Core\Papers\PaperBuilder;
use App\Core\Papers\PaperFormat as F;
use App\Core\Support\Money;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/**
 * كشف راتب — one employee's month. Confidential: opened only with payroll.payslips, and the bank line (bank name and
 * the last four characters of the account) only for someone who may read salaries and bank details as well.
 */
final class PayslipPaper extends PaperBuilder
{
    public static function find(int $id): ?Model
    {
        return PayrollItem::find($id);
    }

    public static function allows(User $user, Model $record): bool
    {
        return $user->hasPermission('payroll.payslips');
    }

    public static function filename(Model $record): string
    {
        return 'payslip-'.$record->run?->period?->format('Y-m').'-'.$record->id;
    }

    public static function document(Model $record, array $tpl, Tenant $tenant): array
    {
        /** @var PayrollItem $item */
        $item = $record->loadMissing(['run.approver:id,name', 'payout:id,number,paid_on', 'employee:id,branch_id,bank_name,iban,account_number', 'employee.branch:id,name']);
        $run = $item->run;
        $currency = self::currency($tenant);
        $bank = ($tpl['show_bank'] ?? true) && $item->payment_method === 'bank' && Auth::user()?->hasPermission('payroll.salaries');
        $account = $bank ? F::masked($item->employee?->iban ?: $item->employee?->account_number) : '';

        $rows = [['name' => 'الراتب الأساسي', 'total' => F::amount($item->basic)]];
        foreach ($item->allowance_lines ?? [] as $line) {
            $rows[] = ['name' => 'بدل '.($line['name'] ?? ''), 'total' => F::amount($line['amount'] ?? 0)];
        }
        // Allowances changed on the run line after it was opened: the difference is shown, so the lines add up.
        $listed = Money::add('0', ...array_map(fn ($l) => $l['amount'] ?? 0, $item->allowance_lines ?? []));
        if (Money::cmp($listed, $item->allowances) !== 0) {
            $rows[] = ['name' => 'تعديل البدلات في المسيرة', 'total' => F::amount(Money::sub($item->allowances, $listed))];
        }
        if (Money::cmp($item->overtime, 0) > 0) {
            $rows[] = ['name' => 'العمل الإضافي', 'notes' => [(float) $item->overtime_hours > 0 ? F::qty($item->overtime_hours).' ساعة' : null], 'total' => F::amount($item->overtime)];
        }
        if (Money::cmp($item->deductions, 0) > 0) {
            $rows[] = ['name' => 'الخصومات', 'notes' => [$item->deduction_notes], 'total' => F::minus($item->deductions)];
        }

        return [
            'title' => 'كشف راتب',
            'number' => $run->number,
            'status' => match (true) {
                $item->paid_at !== null => 'مصروف',
                $run->status === PayrollRun::DRAFT => 'مسودة — غير معتمدة',
                default => '',
            },
            'parties' => [['caption' => 'الموظف', 'lines' => [$item->employee_name, $item->job_title, $item->employee?->branch?->name]]],
            'meta' => self::fields([
                ['label' => 'الشهر', 'value' => $run->label()],
                ['label' => 'رقم المسيرة', 'value' => $run->number, 'ltr' => true],
                ['label' => 'طريقة الصرف', 'value' => $item->payment_method === 'cash' ? 'نقدًا' : 'تحويل بنكي'],
                $bank ? ['label' => 'البنك', 'value' => trim(($item->employee?->bank_name ?? '').' '.$account)] : null,
                ($tpl['show_attendance'] ?? true) && $item->attendance_days !== null ? ['label' => 'أيام الحضور', 'value' => (string) $item->attendance_days] : null,
                ($tpl['show_attendance'] ?? true) && $item->attendance_hours !== null ? ['label' => 'ساعات العمل', 'value' => F::qty($item->attendance_hours)] : null,
                $item->payout ? ['label' => 'تاريخ الصرف', 'value' => $item->payout->paid_on->format('Y-m-d').' · '.$item->payout->number, 'ltr' => true] : null,
            ]),
            'figure' => ['label' => 'صافي الراتب', 'value' => F::amount($item->net), 'currency' => F::currency($currency)],
            'table' => ['columns' => [['key' => 'name', 'label' => 'البيان'], ['key' => 'total', 'label' => 'المبلغ', 'align' => 'amt', 'width' => 26]], 'rows' => $rows],
            'totals' => [
                ['label' => 'إجمالي المستحقات', 'value' => F::amount($item->earnings())],
                ['label' => 'الخصومات', 'value' => F::minus($item->deductions)],
                ['label' => 'صافي الراتب', 'value' => F::amount($item->net), 'currency' => F::currency($currency), 'grand' => true],
            ],
            'panels' => ($tpl['show_notes'] ?? true) && filled($item->notes) ? [['caption' => 'ملاحظات', 'text' => $item->notes]] : [],
            'signatures' => ($tpl['show_signature'] ?? true) ? ['توقيع الموظف', 'المحاسب'] : [],
        ];
    }

    public static function sample(Tenant $tenant, array $tpl): array
    {
        $currency = self::currency($tenant);
        $month = now()->locale('ar')->translatedFormat('F Y');

        return [
            'title' => 'كشف راتب',
            'number' => 'PR-'.now()->format('Y-m'),
            'status' => '',
            'parties' => [['caption' => 'الموظف', 'lines' => ['موظف تجريبي', 'مدرب', 'الفرع الرئيسي']]],
            'meta' => self::fields([
                ['label' => 'الشهر', 'value' => $month],
                ['label' => 'رقم المسيرة', 'value' => 'PR-'.now()->format('Y-m'), 'ltr' => true],
                ['label' => 'طريقة الصرف', 'value' => 'تحويل بنكي'],
                ($tpl['show_bank'] ?? true) ? ['label' => 'البنك', 'value' => 'بنك مسقط •••• 4821'] : null,
                ($tpl['show_attendance'] ?? true) ? ['label' => 'أيام الحضور', 'value' => '24'] : null,
                ($tpl['show_attendance'] ?? true) ? ['label' => 'ساعات العمل', 'value' => '192'] : null,
            ]),
            'figure' => ['label' => 'صافي الراتب', 'value' => F::amount(455), 'currency' => F::currency($currency)],
            'table' => ['columns' => [['key' => 'name', 'label' => 'البيان'], ['key' => 'total', 'label' => 'المبلغ', 'align' => 'amt', 'width' => 26]], 'rows' => [
                ['name' => 'الراتب الأساسي', 'total' => F::amount(400)],
                ['name' => 'بدل سكن', 'total' => F::amount(50)],
                ['name' => 'العمل الإضافي', 'notes' => ['6 ساعات'], 'total' => F::amount(15)],
                ['name' => 'الخصومات', 'notes' => ['تأخير يومين'], 'total' => F::minus(10)],
            ]],
            'totals' => [
                ['label' => 'إجمالي المستحقات', 'value' => F::amount(465)],
                ['label' => 'الخصومات', 'value' => F::minus(10)],
                ['label' => 'صافي الراتب', 'value' => F::amount(455), 'currency' => F::currency($currency), 'grand' => true],
            ],
            'panels' => [],
            'signatures' => ($tpl['show_signature'] ?? true) ? ['توقيع الموظف', 'المحاسب'] : [],
        ];
    }
}
