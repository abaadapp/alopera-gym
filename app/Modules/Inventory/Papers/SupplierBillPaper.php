<?php

namespace App\Modules\Inventory\Papers;

use App\Core\Papers\PaperBuilder;
use App\Core\Papers\PaperFormat as F;
use App\Core\Support\Money;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Inventory\Models\SupplierBill;
use App\Modules\Inventory\Models\SupplierPayment;
use Illuminate\Database\Eloquent\Model;

/** فاتورة المورد — what the club owes a supplier: its reference and dates, its amounts, and what has been paid. */
final class SupplierBillPaper extends PaperBuilder
{
    public const STATUSES = [SupplierBill::PENDING => 'بانتظار الاعتماد', SupplierBill::APPROVED => 'معتمد', SupplierBill::REJECTED => 'مرفوض', SupplierBill::VOID => 'ملغى'];

    public static function find(int $id): ?Model
    {
        return SupplierBill::find($id);
    }

    public static function allows(User $user, Model $record): bool
    {
        return $user->hasPermission('purchases.view');
    }

    public static function latest(User $user): ?Model
    {
        return SupplierBill::latest('id')->first();
    }

    public static function filename(Model $record): string
    {
        return 'supplier-bill-'.$record->number;
    }

    public static function document(Model $record, array $tpl, Tenant $tenant): array
    {
        /** @var SupplierBill $bill */
        $bill = $record->loadMissing(['supplier', 'order:id,number', 'payments.account:id,name']);
        $currency = self::currency($tenant);
        $payments = $bill->payments->whereNull('reversed_at');

        $totals = [['label' => 'المجموع الفرعي', 'value' => F::amount($bill->subtotal)]];
        if (Money::cmp($bill->discount, 0) > 0) {
            $totals[] = ['label' => 'الخصم', 'value' => F::minus($bill->discount)];
        }
        if (Money::cmp($bill->tax, 0) > 0) {
            $totals[] = ['label' => 'الضريبة', 'value' => F::amount($bill->tax)];
        }
        $totals[] = ['label' => 'الإجمالي', 'value' => F::amount($bill->total), 'currency' => F::currency($currency), 'grand' => true];
        $totals[] = ['label' => 'المسدد', 'value' => F::amount($bill->paid_total)];
        $totals[] = ['label' => 'المتبقي', 'value' => F::amount($bill->balance()), 'currency' => F::currency($currency), 'grand' => Money::cmp($bill->balance(), 0) > 0];

        $panels = [];
        if (($tpl['show_payments'] ?? true) && $payments->isNotEmpty()) {
            $panels[] = ['caption' => 'المدفوعات', 'rows' => $payments->map(fn (SupplierPayment $p) => [
                'label' => $p->account?->name ?? '',
                'sub' => $p->number.' · '.$p->paid_on?->format('Y-m-d'),
                'value' => F::amount($p->amount),
            ])->values()->all()];
        }
        if (($tpl['show_notes'] ?? true) && filled($bill->notes)) {
            $panels[] = ['caption' => 'ملاحظات', 'text' => $bill->notes];
        }

        return [
            'title' => 'فاتورة مورد',
            'number' => (string) $bill->reference,
            'status' => $bill->status === SupplierBill::APPROVED ? (Money::isZero($bill->balance()) ? 'مسددة' : '') : (self::STATUSES[$bill->status] ?? ''),
            'parties' => [['caption' => 'المورد', 'lines' => [$bill->supplier?->name, $bill->supplier?->tax_number ? 'الرقم الضريبي: '.$bill->supplier->tax_number : null, $bill->supplier?->phone]]],
            'meta' => self::fields([
                ['label' => 'رقم السند', 'value' => $bill->number, 'ltr' => true],
                ['label' => 'تاريخ الإصدار', 'value' => $bill->issue_date?->format('Y-m-d'), 'ltr' => true],
                ['label' => 'تاريخ الاستحقاق', 'value' => $bill->due_date?->format('Y-m-d'), 'ltr' => true],
                ['label' => 'أمر الشراء', 'value' => (string) ($bill->order?->number ?? ''), 'ltr' => true],
                ['label' => 'الحالة', 'value' => self::STATUSES[$bill->status] ?? $bill->status],
            ]),
            'figure' => Money::cmp($bill->balance(), 0) > 0
                ? ['label' => 'الرصيد المستحق', 'value' => F::amount($bill->balance()), 'currency' => F::currency($currency)]
                : ['label' => 'الإجمالي', 'value' => F::amount($bill->total), 'currency' => F::currency($currency)],
            'table' => null,
            'totals' => $totals,
            'panels' => $panels,
            'signatures' => [],
        ];
    }

    public static function sample(Tenant $tenant, array $tpl): array
    {
        $currency = self::currency($tenant);

        return [
            'title' => 'فاتورة مورد',
            'number' => 'INV-7781',
            'status' => '',
            'parties' => [['caption' => 'المورد', 'lines' => ['مورد المكملات', '92345678']]],
            'meta' => self::fields([
                ['label' => 'رقم السند', 'value' => 'SB-000009', 'ltr' => true],
                ['label' => 'تاريخ الإصدار', 'value' => today()->format('Y-m-d'), 'ltr' => true],
                ['label' => 'تاريخ الاستحقاق', 'value' => today()->addDays(30)->format('Y-m-d'), 'ltr' => true],
                ['label' => 'أمر الشراء', 'value' => 'PO-000031', 'ltr' => true],
                ['label' => 'الحالة', 'value' => 'معتمد'],
            ]),
            'figure' => ['label' => 'الرصيد المستحق', 'value' => F::amount(30), 'currency' => F::currency($currency)],
            'table' => null,
            'totals' => [
                ['label' => 'المجموع الفرعي', 'value' => F::amount(60)],
                ['label' => 'الإجمالي', 'value' => F::amount(60), 'currency' => F::currency($currency), 'grand' => true],
                ['label' => 'المسدد', 'value' => F::amount(30)],
                ['label' => 'المتبقي', 'value' => F::amount(30), 'currency' => F::currency($currency), 'grand' => true],
            ],
            'panels' => ($tpl['show_payments'] ?? true) ? [['caption' => 'المدفوعات', 'rows' => [['label' => 'الصندوق', 'sub' => 'SPY-000004 · '.today()->format('Y-m-d'), 'value' => F::amount(30)]]]] : [],
            'signatures' => [],
        ];
    }
}
