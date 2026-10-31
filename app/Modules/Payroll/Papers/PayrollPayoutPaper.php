<?php

namespace App\Modules\Payroll\Papers;

use App\Core\Papers\PaperBuilder;
use App\Core\Papers\PaperFormat as F;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollPayout;
use Illuminate\Database\Eloquent\Model;

/**
 * سند صرف رواتب — one payout: from which cash or bank account, on which day, to whom and how much. It records the
 * payment in the books; the transfer itself is made at the bank, and the paper says so.
 */
final class PayrollPayoutPaper extends PaperBuilder
{
    public static function find(int $id): ?Model
    {
        return PayrollPayout::find($id);
    }

    public static function allows(User $user, Model $record): bool
    {
        return $user->hasPermission('payroll.payslips') || $user->hasPermission('payroll.pay');
    }

    public static function filename(Model $record): string
    {
        return 'salary-payout-'.$record->number;
    }

    public static function document(Model $record, array $tpl, Tenant $tenant): array
    {
        /** @var PayrollPayout $payout */
        $payout = $record->loadMissing(['run', 'account:id,name', 'author:id,name', 'items' => fn ($q) => $q->orderBy('employee_name')]);
        $currency = self::currency($tenant);

        return [
            'title' => 'سند صرف رواتب',
            'number' => $payout->number,
            'status' => '',
            'parties' => [['caption' => 'المستفيد', 'lines' => ['موظفو النادي — رواتب '.$payout->run->label(), $payout->items->count().' موظف']]],
            'meta' => self::fields([
                ['label' => 'رقم السند', 'value' => $payout->number, 'ltr' => true],
                ['label' => 'تاريخ الصرف', 'value' => $payout->paid_on->format('Y-m-d'), 'ltr' => true],
                ['label' => 'الحساب', 'value' => $payout->account?->name],
                ['label' => 'المرجع', 'value' => (string) ($payout->reference ?? ''), 'ltr' => true],
                ['label' => 'الشهر', 'value' => $payout->run->label()],
                ['label' => 'رقم المسيرة', 'value' => $payout->run->number, 'ltr' => true],
                ['label' => 'أصدرها', 'value' => $payout->author?->name],
            ]),
            'figure' => ['label' => 'إجمالي الصرف', 'value' => F::amount($payout->total), 'currency' => F::currency($currency)],
            'table' => ['columns' => [['key' => 'name', 'label' => 'الموظف'], ['key' => 'method', 'label' => 'طريقة الصرف', 'align' => 'num', 'width' => 18], ['key' => 'net', 'label' => 'الصافي', 'align' => 'amt', 'width' => 22]], 'rows' => $payout->items->map(fn (PayrollItem $i) => [
                'name' => $i->employee_name,
                'notes' => [$i->job_title],
                'method' => $i->payment_method === 'cash' ? 'نقدًا' : 'تحويل بنكي',
                'net' => F::amount($i->net),
            ])->all()],
            'totals' => [['label' => 'إجمالي الصرف', 'value' => F::amount($payout->total), 'currency' => F::currency($currency), 'grand' => true]],
            'panels' => ($tpl['show_notes'] ?? true) ? [['caption' => 'ملاحظات', 'text' => 'سُجّل هذا الصرف في الحسابات من «'.($payout->account?->name ?? '').'». التحويل البنكي نفسه يُنفَّذ في البنك ولا ينفّذه النظام.']] : [],
            'signatures' => ($tpl['show_signature'] ?? true) ? ['المحاسب', 'المعتمد', 'المستلم'] : [],
        ];
    }

    public static function sample(Tenant $tenant, array $tpl): array
    {
        $currency = self::currency($tenant);
        $month = now()->locale('ar')->translatedFormat('F Y');

        return [
            'title' => 'سند صرف رواتب',
            'number' => 'SP-000007',
            'status' => '',
            'parties' => [['caption' => 'المستفيد', 'lines' => ['موظفو النادي — رواتب '.$month, '2 موظف']]],
            'meta' => self::fields([
                ['label' => 'رقم السند', 'value' => 'SP-000007', 'ltr' => true],
                ['label' => 'تاريخ الصرف', 'value' => today()->format('Y-m-d'), 'ltr' => true],
                ['label' => 'الحساب', 'value' => 'الحساب البنكي الرئيسي'],
                ['label' => 'الشهر', 'value' => $month],
            ]),
            'figure' => ['label' => 'إجمالي الصرف', 'value' => F::amount(805), 'currency' => F::currency($currency)],
            'table' => ['columns' => [['key' => 'name', 'label' => 'الموظف'], ['key' => 'method', 'label' => 'طريقة الصرف', 'align' => 'num', 'width' => 18], ['key' => 'net', 'label' => 'الصافي', 'align' => 'amt', 'width' => 22]], 'rows' => [
                ['name' => 'موظف تجريبي', 'notes' => ['مدرب'], 'method' => 'تحويل بنكي', 'net' => F::amount(455)],
                ['name' => 'موظفة استقبال', 'notes' => ['موظف استقبال'], 'method' => 'نقدًا', 'net' => F::amount(350)],
            ]],
            'totals' => [['label' => 'إجمالي الصرف', 'value' => F::amount(805), 'currency' => F::currency($currency), 'grand' => true]],
            'panels' => ($tpl['show_notes'] ?? true) ? [['caption' => 'ملاحظات', 'text' => 'سُجّل هذا الصرف في الحسابات. التحويل البنكي نفسه يُنفَّذ في البنك ولا ينفّذه النظام.']] : [],
            'signatures' => ($tpl['show_signature'] ?? true) ? ['المحاسب', 'المعتمد', 'المستلم'] : [],
        ];
    }
}
