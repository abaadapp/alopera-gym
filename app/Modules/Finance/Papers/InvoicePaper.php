<?php

namespace App\Modules\Finance\Papers;

use App\Core\Papers\PaperBuilder;
use App\Core\Papers\PaperFormat as F;
use App\Core\Papers\PaperIssuer;
use App\Core\Papers\PaperLabels;
use App\Core\Papers\PaperTypes;
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
 *
 * The editor's live paper is drawn here too, from the engine's unsaved invoice: no number yet («مسودة»), and before
 * the first line an empty table with its totals at zero. An invoice chosen in English in the editor prints English
 * first, left to right, wherever it is shown (tailor()).
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
        return 'invoice-'.($record->number ?: 'draft');
    }

    public static function tailor(Model $record, array $tpl): array
    {
        return ['lang' => PaperTypes::face((string) $tpl['lang'], $record->paper_lang ?? null)] + $tpl;
    }

    public static function issuer(Model $record, Tenant $tenant): array
    {
        return PaperIssuer::resolve($tenant, $record->issuer ?? null);
    }

    public static function document(Model $record, array $tpl, Tenant $tenant): array
    {
        /** @var Invoice $invoice */
        $invoice = $record->loadMissing(['items', 'branch:id,name', 'creator:id,name', 'payments' => fn ($q) => $q->where('status', Payment::COMPLETED)->orderBy('id')]);
        $lang = (string) ($tpl['lang'] ?? 'ar_en');
        $w = fn (string $arabic) => PaperLabels::word($arabic, $lang);
        $code = self::currency($tenant, $invoice->currency);
        $currency = PaperLabels::ltr($lang) ? $code : F::currency($code);
        $issuer = self::issuer($invoice, $tenant);
        $member = $invoice->billable_type === 'member' ? Member::withTrashed()->find($invoice->billable_id) : null;
        $number = $invoice->number ?: $w('مسودة');

        $taxed = Money::cmp($invoice->tax_total, 0) > 0 && ($issuer['vat_number'] ?? '') !== '';
        $discounted = $invoice->items->contains(fn ($i) => Money::cmp($i->discount, 0) > 0);
        $balance = $invoice->status === Invoice::VOID ? '0' : $invoice->balance();

        $columns = [['key' => 'name', 'label' => 'البيان'], ['key' => 'qty', 'label' => 'الكمية', 'align' => 'num', 'width' => 12], ['key' => 'unit', 'label' => 'سعر الوحدة', 'align' => 'amt', 'width' => 17]];
        if ($discounted) {
            $columns[] = ['key' => 'discount', 'label' => 'الخصم', 'align' => 'amt', 'width' => 15];
        }
        $columns[] = ['key' => 'total', 'label' => 'المبلغ', 'align' => 'amt', 'width' => 20];

        $totals = [['label' => 'المجموع الفرعي', 'value' => F::amount($invoice->subtotal)]];
        if (Money::cmp($invoice->discount_total, 0) > 0) {
            $totals[] = ['label' => 'الخصم', 'value' => F::minus($invoice->discount_total)];
        }
        if (Money::cmp($invoice->tax_total, 0) > 0) {
            $totals[] = ['label' => 'ضريبة القيمة المضافة', 'hint' => F::rate($invoice->tax_rate), 'value' => F::amount($invoice->tax_total)];
        }
        $totals[] = ['label' => 'الإجمالي', 'value' => F::amount($invoice->total), 'currency' => $currency, 'grand' => true];
        if (Money::cmp($invoice->credited_total, 0) > 0) {
            $totals[] = ['label' => 'الإشعارات الدائنة', 'value' => F::minus($invoice->credited_total)];
            $totals[] = ['label' => 'صافي الفاتورة', 'value' => F::amount($invoice->netTotal())];
        }
        $totals[] = ['label' => 'المدفوع', 'value' => F::amount($invoice->paid_total)];
        $totals[] = ['label' => 'المتبقي', 'value' => F::amount($balance), 'currency' => $currency, 'grand' => Money::cmp($balance, 0) > 0];

        $panels = [];
        // Money that counts only: the page showing the invoice may have loaded its pending, rejected or cancelled payments too.
        $paid = $invoice->payments->where('status', Payment::COMPLETED)->sortBy('id')->values();
        if (($tpl['show_payments'] ?? false) && $paid->isNotEmpty()) {
            $panels[] = ['caption' => 'المدفوعات', 'rows' => $paid->map(fn (Payment $p) => [
                'label' => $p->kind === Payment::KIND_REFUND ? $w('رد مبلغ') : $w(Payment::METHOD_LABELS[$p->method] ?? $p->method),
                'sub' => trim($p->paid_at?->format('Y-m-d').($p->receipt_number ? ' · '.$p->receipt_number : '')),
                'value' => ($p->kind === Payment::KIND_REFUND ? '− ' : '').F::amount($p->amount),
            ])->all()];
        }
        if (($tpl['show_notes'] ?? true) && filled($invoice->notes)) {
            $panels[] = ['caption' => 'ملاحظات', 'text' => $invoice->notes];
        }
        // Where and how to pay, while something may still be owed on it (the editor's draft included).
        if (in_array($invoice->status, [Invoice::ISSUED, Invoice::PARTIALLY_PAID], true) && filled($tpl['payment_note'] ?? null)) {
            $panels[] = ['caption' => 'تعليمات الدفع', 'text' => $w((string) $tpl['payment_note'])];
        }
        if (in_array($invoice->status, [Invoice::ISSUED, Invoice::PARTIALLY_PAID], true) && ($bank = self::bank($tenant, $w))) {
            $panels[] = $bank;
        }
        if ($invoice->status === Invoice::VOID && filled($invoice->void_reason)) {
            $panels[] = ['caption' => 'ملاحظات', 'text' => $w('أُلغيت الفاتورة').': '.$invoice->void_reason];
        }

        // The terms the editor chose («مستحق فورًا», «صافي 30 يومًا»); an older invoice, the days between its dates.
        $days = $invoice->payment_terms_days ?? ($invoice->due_date && $invoice->due_date->gt($invoice->issue_date) ? (int) $invoice->issue_date->diffInDays($invoice->due_date) : null);

        return [
            'title' => $taxed ? 'فاتورة ضريبية' : 'فاتورة',
            'issuer_caption' => 'البائع',
            'number' => $number,
            'status' => self::stamp($invoice, $lang),
            'parties' => [['caption' => $member ? 'العضو' : 'العميل', 'lines' => [
                // The editor's paper before a customer is chosen: the caption over a dash, not an empty corner. The
                // engine's own name for a walk-in prints in the paper's language; a typed name prints as typed.
                match ((string) $invoice->customer_name) {
                    '' => '—', 'عميل نقدي' => $w('عميل نقدي'), default => $invoice->customer_name
                },
                $member?->member_no ? $w('رقم العضوية').': '.$member->member_no : null,
                ($tpl['show_phone'] ?? false) ? $invoice->customer_phone : null,
            ]]],
            'meta' => self::fields([
                ['label' => 'رقم الفاتورة', 'value' => $number, 'ltr' => true],
                ['label' => 'تاريخ الإصدار', 'value' => $invoice->issue_date->format('Y-m-d'), 'ltr' => true],
                $invoice->due_date ? ['label' => 'تاريخ الاستحقاق', 'value' => $invoice->due_date->format('Y-m-d'), 'ltr' => true] : null,
                ['label' => 'شروط الدفع', 'value' => F::terms($days, $lang)],
                ['label' => 'نوع الفاتورة', 'value' => $w(self::TYPES[$invoice->type] ?? '')],
                ($tpl['show_branch'] ?? false) ? ['label' => 'الفرع', 'value' => $invoice->branch?->name] : null,
                ($tpl['show_employee'] ?? false) ? ['label' => 'أصدرها', 'value' => $invoice->creator?->name] : null,
            ]),
            'figure' => Money::cmp($balance, 0) > 0
                ? ['label' => 'الرصيد المستحق', 'value' => F::amount($balance), 'currency' => $currency]
                : ['label' => 'الإجمالي', 'value' => F::amount($invoice->netTotal()), 'currency' => $currency],
            'table' => ['columns' => $columns, 'empty' => $w('لا بنود على هذه الفاتورة'), 'rows' => $invoice->items->map(fn ($i) => [
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

    /**
     * What is stamped under the figure: how much of it is paid, then whether it is past due — both when both hold, so
     * a partly paid invoice that is also late says so instead of one hiding the other. A plain issued invoice that is
     * not yet due carries no stamp: it is the normal state.
     */
    public static function stamp(Invoice $invoice, string $lang = 'ar_en'): string
    {
        $paid = match ($invoice->status) {
            Invoice::VOID => 'ملغاة',
            Invoice::CREDITED => 'أُلغيت قيمتها بإشعار دائن',
            Invoice::PAID => 'مدفوعة',
            Invoice::PARTIALLY_PAID => 'مدفوعة جزئيًا',
            Invoice::ISSUED => $invoice->isOverdue() ? 'غير مدفوعة' : '',
            default => '',
        };

        return implode(' · ', array_map(fn ($word) => PaperLabels::word($word, $lang), array_filter([$paid, $invoice->isOverdue() ? 'متأخرة السداد' : ''])));
    }

    public static function sample(Tenant $tenant, array $tpl): array
    {
        $lang = (string) ($tpl['lang'] ?? 'ar_en');
        $w = fn (string $arabic) => PaperLabels::word($arabic, $lang);
        $code = self::currency($tenant);
        $currency = PaperLabels::ltr($lang) ? $code : F::currency($code);
        $start = today();
        $end = today()->addMonths(3)->subDay();
        $number = 'INV-'.today()->format('Y').'-000123';

        return [
            'title' => 'فاتورة',
            'issuer_caption' => 'البائع',
            'number' => $number,
            'status' => $w('مدفوعة جزئيًا'),
            'parties' => [['caption' => 'العضو', 'lines' => ['عضو تجريبي', $w('رقم العضوية').': M-00042', ($tpl['show_phone'] ?? false) ? '91234567' : null]]],
            'meta' => self::fields([
                ['label' => 'رقم الفاتورة', 'value' => $number, 'ltr' => true],
                ['label' => 'تاريخ الإصدار', 'value' => $start->format('Y-m-d'), 'ltr' => true],
                ['label' => 'تاريخ الاستحقاق', 'value' => $start->copy()->addDays(7)->format('Y-m-d'), 'ltr' => true],
                ['label' => 'شروط الدفع', 'value' => F::terms(7, $lang)],
                ['label' => 'نوع الفاتورة', 'value' => $w('عضوية')],
                ($tpl['show_branch'] ?? false) ? ['label' => 'الفرع', 'value' => 'الفرع الرئيسي'] : null,
                ($tpl['show_employee'] ?? false) ? ['label' => 'أصدرها', 'value' => 'موظف الاستقبال'] : null,
            ]),
            'figure' => ['label' => 'الرصيد المستحق', 'value' => F::amount(40), 'currency' => $currency],
            'table' => ['columns' => [['key' => 'name', 'label' => 'البيان'], ['key' => 'qty', 'label' => 'الكمية', 'align' => 'num', 'width' => 12], ['key' => 'unit', 'label' => 'سعر الوحدة', 'align' => 'amt', 'width' => 17], ['key' => 'total', 'label' => 'المبلغ', 'align' => 'amt', 'width' => 20]], 'rows' => [
                ['name' => 'اشتراك — باقة ثلاثة أشهر (من '.$start->format('Y-m-d').' إلى '.$end->format('Y-m-d').')', 'qty' => '1', 'unit' => F::amount(75), 'total' => F::amount(75)],
                ['name' => 'رسوم التسجيل', 'qty' => '1', 'unit' => F::amount(5), 'total' => F::amount(5)],
            ]],
            'totals' => [
                ['label' => 'المجموع الفرعي', 'value' => F::amount(80)],
                ['label' => 'الإجمالي', 'value' => F::amount(80), 'currency' => $currency, 'grand' => true],
                ['label' => 'المدفوع', 'value' => F::amount(40)],
                ['label' => 'المتبقي', 'value' => F::amount(40), 'currency' => $currency, 'grand' => true],
            ],
            'panels' => array_values(array_filter([
                ($tpl['show_payments'] ?? false) ? ['caption' => 'المدفوعات', 'rows' => [['label' => $w('نقدًا'), 'sub' => $start->format('Y-m-d').' · R-000311', 'value' => F::amount(40)]]] : null,
                ($tpl['show_notes'] ?? true) ? ['caption' => 'ملاحظات', 'text' => 'يُرجى سداد المتبقي قبل تاريخ الاستحقاق.'] : null,
                filled($tpl['payment_note'] ?? null) ? ['caption' => 'تعليمات الدفع', 'text' => $w((string) $tpl['payment_note'])] : null,
                self::bank($tenant, $w),
            ])),
            'signatures' => ($tpl['show_signature'] ?? false) ? ['المحاسب', 'المستلم'] : [],
        ];
    }

    /**
     * The club's bank account, from its settings, for a customer paying by transfer; none when nothing is filled in.
     *
     * @param  callable(string): string  $w
     * @return array{caption: string, rows: list<array{label: string, value: string}>}|null
     */
    private static function bank(Tenant $tenant, callable $w): ?array
    {
        $rows = array_values(array_filter([
            ['label' => $w('البنك'), 'value' => trim((string) $tenant->setting('bank_name', ''))],
            ['label' => $w('اسم الحساب'), 'value' => trim((string) $tenant->setting('bank_account_name', ''))],
            ['label' => $w('رقم الحساب'), 'value' => trim((string) $tenant->setting('bank_iban', ''))],
        ], fn ($r) => $r['value'] !== ''));

        // Words, not amounts: they wrap, and on a receipt each value takes the strip's whole width under its label.
        return $rows === [] ? null : ['caption' => 'التحويل البنكي', 'rows' => $rows, 'text' => null, 'words' => true];
    }
}
