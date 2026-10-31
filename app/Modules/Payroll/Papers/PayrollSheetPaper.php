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

/** كشف مسيرة الرواتب — the whole month on one paper, for approval and the file. Confidential (payroll.payslips). */
final class PayrollSheetPaper extends PaperBuilder
{
    public static function find(int $id): ?Model
    {
        return PayrollRun::find($id);
    }

    public static function allows(User $user, Model $record): bool
    {
        return $user->hasPermission('payroll.payslips');
    }

    public static function filename(Model $record): string
    {
        return 'payroll-'.$record->period->format('Y-m');
    }

    public static function document(Model $record, array $tpl, Tenant $tenant): array
    {
        /** @var PayrollRun $run */
        $run = $record->loadMissing(['items' => fn ($q) => $q->orderBy('employee_name'), 'approver:id,name']);
        $currency = self::currency($tenant);
        $attendance = (bool) ($tpl['show_attendance'] ?? false);

        $columns = [['key' => 'name', 'label' => 'الموظف']];
        if ($attendance) {
            $columns[] = ['key' => 'days', 'label' => 'أيام الحضور', 'align' => 'num', 'width' => 9];
        }
        array_push($columns,
            ['key' => 'basic', 'label' => 'الأساسي', 'align' => 'amt', 'width' => 12],
            ['key' => 'allowances', 'label' => 'البدلات', 'align' => 'amt', 'width' => 11],
            ['key' => 'overtime', 'label' => 'الإضافي', 'align' => 'amt', 'width' => 11],
            ['key' => 'deductions', 'label' => 'الخصومات', 'align' => 'amt', 'width' => 11],
            ['key' => 'net', 'label' => 'الصافي', 'align' => 'amt', 'width' => 13],
        );

        $sum = fn (string $field) => F::amount(Money::add('0', ...$run->items->pluck($field)->all()));

        return [
            'title' => 'كشف مسيرة الرواتب',
            'number' => $run->number,
            'status' => match ($run->status) {
                PayrollRun::DRAFT => 'مسودة — غير معتمدة',
                PayrollRun::PAID => 'مصروفة',
                default => 'معتمدة',
            },
            'parties' => [],
            'meta' => self::fields([
                ['label' => 'الشهر', 'value' => $run->label()],
                ['label' => 'رقم المسيرة', 'value' => $run->number, 'ltr' => true],
                ['label' => 'الحالة', 'value' => PayrollRun::STATUSES[$run->status] ?? $run->status],
                ['label' => 'عدد الموظفين', 'value' => (string) $run->items->count()],
                $run->approved_at ? ['label' => 'اعتمدها', 'value' => trim(($run->approver?->name ?? '').' · '.$run->approved_at->format('Y-m-d'))] : null,
            ]),
            'figure' => ['label' => 'صافي المسيرة', 'value' => F::amount($run->net), 'currency' => F::currency($currency)],
            'table' => [
                'columns' => $columns,
                'rows' => $run->items->map(fn (PayrollItem $i) => [
                    'name' => $i->employee_name,
                    'notes' => [trim(($i->job_title ?? '').($i->paid_at ? ' · صُرف '.$i->paid_at->format('Y-m-d') : ''), ' ·')],
                    'days' => $i->attendance_days !== null ? (string) $i->attendance_days : '—',
                    'basic' => F::amount($i->basic),
                    'allowances' => F::amount($i->allowances),
                    'overtime' => Money::cmp($i->overtime, 0) > 0 ? F::amount($i->overtime) : '—',
                    'deductions' => Money::cmp($i->deductions, 0) > 0 ? F::amount($i->deductions) : '—',
                    'net' => F::amount($i->net),
                ])->all(),
                'sum' => ['name' => 'الإجمالي', 'days' => '', 'basic' => $sum('basic'), 'allowances' => $sum('allowances'), 'overtime' => $sum('overtime'), 'deductions' => $sum('deductions'), 'net' => $sum('net')],
                'empty' => 'لا موظفين في المسيرة',
            ],
            'totals' => [
                ['label' => 'إجمالي المستحقات', 'value' => F::amount($run->gross)],
                ['label' => 'الخصومات', 'value' => F::minus($run->deductions)],
                ['label' => 'صافي المسيرة', 'value' => F::amount($run->net), 'currency' => F::currency($currency), 'grand' => true],
            ],
            'panels' => [],
            'signatures' => ($tpl['show_signature'] ?? true) ? ['المحاسب', 'المعتمد'] : [],
        ];
    }

    public static function sample(Tenant $tenant, array $tpl): array
    {
        $currency = self::currency($tenant);
        $attendance = (bool) ($tpl['show_attendance'] ?? false);
        $columns = [['key' => 'name', 'label' => 'الموظف']];
        if ($attendance) {
            $columns[] = ['key' => 'days', 'label' => 'أيام الحضور', 'align' => 'num', 'width' => 9];
        }
        array_push($columns,
            ['key' => 'basic', 'label' => 'الأساسي', 'align' => 'amt', 'width' => 12],
            ['key' => 'allowances', 'label' => 'البدلات', 'align' => 'amt', 'width' => 11],
            ['key' => 'overtime', 'label' => 'الإضافي', 'align' => 'amt', 'width' => 11],
            ['key' => 'deductions', 'label' => 'الخصومات', 'align' => 'amt', 'width' => 11],
            ['key' => 'net', 'label' => 'الصافي', 'align' => 'amt', 'width' => 13],
        );

        return [
            'title' => 'كشف مسيرة الرواتب',
            'number' => 'PR-'.now()->format('Y-m'),
            'status' => 'معتمدة',
            'parties' => [],
            'meta' => self::fields([
                ['label' => 'الشهر', 'value' => now()->locale('ar')->translatedFormat('F Y')],
                ['label' => 'رقم المسيرة', 'value' => 'PR-'.now()->format('Y-m'), 'ltr' => true],
                ['label' => 'الحالة', 'value' => 'معتمدة'],
                ['label' => 'عدد الموظفين', 'value' => '2'],
            ]),
            'figure' => ['label' => 'صافي المسيرة', 'value' => F::amount(805), 'currency' => F::currency($currency)],
            'table' => ['columns' => $columns, 'rows' => [
                ['name' => 'موظف تجريبي', 'notes' => ['مدرب'], 'days' => '24', 'basic' => F::amount(400), 'allowances' => F::amount(50), 'overtime' => F::amount(15), 'deductions' => F::amount(10), 'net' => F::amount(455)],
                ['name' => 'موظفة استقبال', 'notes' => ['موظف استقبال'], 'days' => '22', 'basic' => F::amount(320), 'allowances' => F::amount(30), 'overtime' => '—', 'deductions' => '—', 'net' => F::amount(350)],
            ], 'sum' => ['name' => 'الإجمالي', 'days' => '', 'basic' => F::amount(720), 'allowances' => F::amount(80), 'overtime' => F::amount(15), 'deductions' => F::amount(10), 'net' => F::amount(805)]],
            'totals' => [
                ['label' => 'إجمالي المستحقات', 'value' => F::amount(815)],
                ['label' => 'الخصومات', 'value' => F::minus(10)],
                ['label' => 'صافي المسيرة', 'value' => F::amount(805), 'currency' => F::currency($currency), 'grand' => true],
            ],
            'panels' => [],
            'signatures' => ($tpl['show_signature'] ?? true) ? ['المحاسب', 'المعتمد'] : [],
        ];
    }
}
