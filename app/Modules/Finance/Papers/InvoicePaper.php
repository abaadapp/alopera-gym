<?php

namespace App\Modules\Finance\Papers;

use App\Core\Papers\PaperBuilder;
use App\Core\Papers\PaperFormat as F;
use App\Core\Papers\PaperIssuer;
use App\Core\Support\Money;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\Member;
use Illuminate\Database\Eloquent\Model;

/**
 * فاتورة العميل — memberships (new, renewal, upgrade), personal training, product sales, website orders, other
 * services and manual invoices all print from the invoice the system already issued; nothing here issues one.
 *
 * Lines print as they were issued: a membership line already names the plan and its period, a training line the
 * number of sessions, a product line its add-ons. Nothing medical or personal beyond the name, the member number and
 * (if the club wants it) the phone is printed.
 */
final class InvoicePaper extends PaperBuilder
{
    public const TYPES = ['membership' => 'عضوية', 'pt' => 'تدريب شخصي', 'pos' => 'مبيعات المنتجات', 'other' => 'خدمات أخرى'];

    public static function find(int $id): ?Model
    {
        return Invoice::find($id);
    }

    public static function allows(User $user, Model $record): bool
    {
        return $user->hasPermission('invoices.view');
    }

    public static function latest(User $user): ?Model
    {
        return Invoice::where('status', '!=', Invoice::DRAFT)->latest('id')->first();
    }

    public static function filename(Model $record): string
    {
        return 'invoice-'.$record->number;
    }

    public static function issuer(Model $record, Tenant $tenant): array
    {
        return PaperIssuer::resolve($tenant, $record->issuer ?? null);
    }

    public static function document(Model $record, array $tpl, Tenant $tenant): array
    {
        /** @var Invoice $invoice */
        $invoice = $record->loadMissing(['items', 'branch:id,name', 'creator:id,name', 'payments' => fn ($q) => $q->where('status', Payment::COMPLETED)->orderBy('id')]);
        $currency = self::currency($tenant, $invoice->currency);
        $issuer = self::issuer($invoice, $tenant);
        $member = $invoice->billable_type === 'member' ? Member::withTrashed()->find($invoice->billable_id) : null;

        $taxed = Money::cmp($invoice->tax_total, 0) > 0 && ($issuer['vat_number'] ?? '') !== '';
        $discounted = $invoice->items->contains(fn ($i) => Money::cmp($i->discount, 0) > 0);
        $balance = $invoice->status === Invoice::VOID ? '0' : $invoice->balance();

        $columns = [['key' => 'name', 'label' => 'البيان'], ['key' => 'qty', 'label' => 'الكمية', 'align' => 'num', 'width' => 12], ['key' => 'unit', 'label' => 'سعر الوحدة', 'align' => 'amt', 'width' => 16]];
        if ($discounted) {
            $columns[] = ['key' => 'discount', 'label' => 'الخصم', 'align' => 'amt', 'width' => 14];
        }
        $columns[] = ['key' => 'total', 'label' => 'المبلغ', 'align' => 'amt', 'width' => 18];

        $totals = [['label' => 'المجموع الفرعي', 'value' => F::amount($invoice->subtotal)]];
        if (Money::cmp($invoice->discount_total, 0) > 0) {
            $totals[] = ['label' => 'الخصم', 'value' => F::minus($invoice->discount_total)];
        }
        if (Money::cmp($invoice->tax_total, 0) > 0) {
            $totals[] = ['label' => 'ضريبة القيمة المضافة', 'hint' => F::rate($invoice->tax_rate), 'value' => F::amount($invoice->tax_total)];
        }
        $totals[] = ['label' => 'الإجمالي', 'value' => F::amount($invoice->total), 'currency' => F::currency($currency), 'grand' => true];
        if (Money::cmp($invoice->credited_total, 0) > 0) {
            $totals[] = ['label' => 'الإشعارات الدائنة', 'value' => F::minus($invoice->credited_total)];
            $totals[] = ['label' => 'صافي الفاتورة', 'value' => F::amount($invoice->netTotal())];
        }
        $totals[] = ['label' => 'المدفوع', 'value' => F::amount($invoice->paid_total)];
        $totals[] = ['label' => 'المتبقي', 'value' => F::amount($balance), 'currency' => F::currency($currency), 'grand' => Money::cmp($balance, 0) > 0];

        $panels = [];
        if (($tpl['show_payments'] ?? false) && $invoice->payments->isNotEmpty()) {
            $panels[] = ['caption' => 'المدفوعات', 'rows' => $invoice->payments->map(fn (Payment $p) => [
                'label' => $p->kind === Payment::KIND_REFUND ? 'رد مبلغ' : (Payment::METHOD_LABELS[$p->method] ?? $p->method),
                'sub' => trim($p->paid_at?->format('Y-m-d').($p->receipt_number ? ' · '.$p->receipt_number : '')),
                'value' => ($p->kind === Payment::KIND_REFUND ? '− ' : '').F::amount($p->amount),
            ])->all()];
        }
        if (($tpl['show_notes'] ?? true) && filled($invoice->notes)) {
            $panels[] = ['caption' => 'ملاحظات', 'text' => $invoice->notes];
        }
        if ($invoice->status === Invoice::VOID && filled($invoice->void_reason)) {
            $panels[] = ['caption' => 'ملاحظات', 'text' => 'أُلغيت الفاتورة: '.$invoice->void_reason];
        }

        $terms = $invoice->due_date && $invoice->due_date->gt($invoice->issue_date) ? F::terms((int) $invoice->issue_date->diffInDays($invoice->due_date)) : '';

        return [
            'title' => $taxed ? 'فاتورة ضريبية' : 'فاتورة',
            'issuer_caption' => 'البائع',
            'number' => $invoice->number,
            'status' => self::stamp($invoice),
            'parties' => [['caption' => $member ? 'العضو' : 'العميل', 'lines' => [
                $invoice->customer_name,
                $member?->member_no ? 'رقم العضوية: '.$member->member_no : null,
                ($tpl['show_phone'] ?? false) ? $invoice->customer_phone : null,
            ]]],
            'meta' => self::fields([
                ['label' => 'رقم الفاتورة', 'value' => $invoice->number, 'ltr' => true],
                ['label' => 'تاريخ الإصدار', 'value' => $invoice->issue_date->format('Y-m-d'), 'ltr' => true],
                $invoice->due_date ? ['label' => 'تاريخ الاستحقاق', 'value' => $invoice->due_date->format('Y-m-d'), 'ltr' => true] : null,
                ['label' => 'شروط الدفع', 'value' => $terms],
                ['label' => 'نوع الفاتورة', 'value' => self::TYPES[$invoice->type] ?? ''],
                ($tpl['show_branch'] ?? false) ? ['label' => 'الفرع', 'value' => $invoice->branch?->name] : null,
                ($tpl['show_employee'] ?? false) ? ['label' => 'أصدرها', 'value' => $invoice->creator?->name] : null,
            ]),
            'figure' => Money::cmp($balance, 0) > 0
                ? ['label' => 'الرصيد المستحق', 'value' => F::amount($balance), 'currency' => F::currency($currency)]
                : ['label' => 'الإجمالي', 'value' => F::amount($invoice->netTotal()), 'currency' => F::currency($currency)],
            'table' => ['columns' => $columns, 'empty' => 'لا بنود على هذه الفاتورة', 'rows' => $invoice->items->map(fn ($i) => [
                'name' => $i->description,
                'qty' => F::qty($i->quantity),
                'unit' => F::amount($i->unit_price),
                'discount' => Money::cmp($i->discount, 0) > 0 ? F::minus($i->discount) : '—',
                'total' => F::amount($i->line_total),
            ])->all()],
            'totals' => $totals,
            'panels' => $panels,
            'signatures' => ($tpl['show_signature'] ?? false) ? ['المحاسب', 'المستلم'] : [],
        ];
    }

    /** What is stamped under the figure. A plain issued invoice carries no stamp: it is the normal state. */
    public static function stamp(Invoice $invoice): string
    {
        return match (true) {
            $invoice->status === Invoice::VOID => 'ملغاة',
            $invoice->status === Invoice::CREDITED => 'أُلغيت قيمتها بإشعار دائن',
            $invoice->status === Invoice::PAID => 'مدفوعة',
            $invoice->isOpen() && $invoice->due_date !== null && $invoice->due_date->lt(today()) => 'متأخرة السداد',
            $invoice->status === Invoice::PARTIALLY_PAID => 'مدفوعة جزئيًا',
            default => '',
        };
    }

    public static function sample(Tenant $tenant, array $tpl): array
    {
        $currency = self::currency($tenant);
        $start = today();
        $end = today()->addMonths(3)->subDay();

        return [
            'title' => 'فاتورة',
            'issuer_caption' => 'البائع',
            'number' => 'INV-'.today()->format('Y').'-000123',
            'status' => 'مدفوعة جزئيًا',
            'parties' => [['caption' => 'العضو', 'lines' => ['عضو تجريبي', 'رقم العضوية: M-00042', ($tpl['show_phone'] ?? false) ? '91234567' : null]]],
            'meta' => self::fields([
                ['label' => 'رقم الفاتورة', 'value' => 'INV-'.today()->format('Y').'-000123', 'ltr' => true],
                ['label' => 'تاريخ الإصدار', 'value' => $start->format('Y-m-d'), 'ltr' => true],
                ['label' => 'تاريخ الاستحقاق', 'value' => $start->copy()->addDays(7)->format('Y-m-d'), 'ltr' => true],
                ['label' => 'نوع الفاتورة', 'value' => 'عضوية'],
                ($tpl['show_branch'] ?? false) ? ['label' => 'الفرع', 'value' => 'الفرع الرئيسي'] : null,
                ($tpl['show_employee'] ?? false) ? ['label' => 'أصدرها', 'value' => 'موظف الاستقبال'] : null,
            ]),
            'figure' => ['label' => 'الرصيد المستحق', 'value' => F::amount(40), 'currency' => F::currency($currency)],
            'table' => ['columns' => [['key' => 'name', 'label' => 'البيان'], ['key' => 'qty', 'label' => 'الكمية', 'align' => 'num', 'width' => 12], ['key' => 'unit', 'label' => 'سعر الوحدة', 'align' => 'amt', 'width' => 16], ['key' => 'total', 'label' => 'المبلغ', 'align' => 'amt', 'width' => 18]], 'rows' => [
                ['name' => 'اشتراك — باقة ثلاثة أشهر (من '.$start->format('Y-m-d').' إلى '.$end->format('Y-m-d').')', 'qty' => '1', 'unit' => F::amount(75), 'total' => F::amount(75)],
                ['name' => 'رسوم التسجيل', 'qty' => '1', 'unit' => F::amount(5), 'total' => F::amount(5)],
            ]],
            'totals' => [
                ['label' => 'المجموع الفرعي', 'value' => F::amount(80)],
                ['label' => 'الإجمالي', 'value' => F::amount(80), 'currency' => F::currency($currency), 'grand' => true],
                ['label' => 'المدفوع', 'value' => F::amount(40)],
                ['label' => 'المتبقي', 'value' => F::amount(40), 'currency' => F::currency($currency), 'grand' => true],
            ],
            'panels' => array_values(array_filter([
                ($tpl['show_payments'] ?? false) ? ['caption' => 'المدفوعات', 'rows' => [['label' => 'نقدًا', 'sub' => $start->format('Y-m-d').' · R-000311', 'value' => F::amount(40)]]] : null,
                ($tpl['show_notes'] ?? true) ? ['caption' => 'ملاحظات', 'text' => 'يُرجى سداد المتبقي قبل تاريخ الاستحقاق.'] : null,
            ])),
            'signatures' => ($tpl['show_signature'] ?? false) ? ['المحاسب', 'المستلم'] : [],
        ];
    }
}
