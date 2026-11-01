<?php

namespace App\Modules\Reports\Reports;

use App\Core\Support\Money;
use App\Core\Tenancy\TenantContext;
use App\Models\User;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\AccountingPeriods;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Inventory\Models\SupplierBill;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;

/**
 * ضريبة القيمة المضافة: the tax the club charged and the tax it paid on purchases in a period, read from the VAT account
 * of the books (so it matches them to the fils) and laid beside the documents behind each line: invoices issued,
 * invoices voided, credit notes, supplier bills and manual entries. A difference between the documents and the books is
 * shown, not hidden.
 *
 * It assumes nothing about registration: it says what the settings hold (the rate and the tax number) and that it is
 * a working paper for the return, not a filed return.
 */
class TaxReport extends TabularReport
{
    private const SOURCES = [
        'invoice.issue' => 'ضريبة المبيعات (المخرجات)',
        'invoice.void' => 'ضريبة فواتير أُلغيت',
        'credit_note.issue' => 'ضريبة الإشعارات الدائنة',
        'credit_note.tax' => 'ضريبة الإشعارات الدائنة (تصحيح)',
        'supplier_bill.approve' => 'ضريبة المشتريات (المدخلات)',
        'supplier_bill.void' => 'ضريبة مشتريات أُلغيت',
        'manual' => 'قيود يدوية وتسويات',
    ];

    protected function build(ReportFilters $f, User $user): array
    {
        $from = $f->from->toDateString();
        $to = $f->to->toDateString();
        $tenant = app(TenantContext::class)->require();
        $vat = app(ChartOfAccounts::class)->account('vat_payable');

        // The books: every movement of the VAT account in the period, by what posted it.
        $lines = $this->in(JournalLine::query()->join('gym_journal_entries as e', 'e.id', '=', 'gym_journal_lines.journal_entry_id'), $f, 'e.branch_id')
            ->where('gym_journal_lines.account_id', $vat->id)->whereBetween('e.entry_date', [$from, $to])
            ->get(['e.source_type', 'e.source_event', 'e.reverses_id', 'gym_journal_lines.debit', 'gym_journal_lines.credit']);
        $bySource = [];
        foreach ($lines as $l) {
            $key = $l->source_type ? $l->source_type.'.'.$l->source_event : 'manual';
            $key = isset(self::SOURCES[$key]) ? $key : 'manual';
            $bySource[$key] = Money::add($bySource[$key] ?? '0', Money::sub($l->credit, $l->debit));
        }
        $net = Money::add('0', ...array_values($bySource));
        $output = Money::add($bySource['invoice.issue'] ?? '0', $bySource['invoice.void'] ?? '0', $bySource['credit_note.issue'] ?? '0', $bySource['credit_note.tax'] ?? '0');
        $input = Money::sub('0', Money::add($bySource['supplier_bill.approve'] ?? '0', $bySource['supplier_bill.void'] ?? '0'));

        // The documents behind them.
        $invoices = $this->in(Invoice::query(), $f)->whereNotIn('status', [Invoice::DRAFT])->whereBetween('issue_date', [$from, $to])->orderBy('issue_date')->orderBy('id')->get();
        $voided = $this->in(Invoice::query(), $f)->where('status', Invoice::VOID)->where('tax_total', '>', 0)->whereBetween('voided_at', [$f->from->startOfDay(), $f->to->endOfDay()])->orderBy('voided_at')->get();
        $notes = $this->in(CreditNote::query(), $f)->with('invoice:id,number')->whereBetween('created_at', [$f->from->startOfDay(), $f->to->endOfDay()])->orderBy('created_at')->get();
        // A bill enters the books on its date when approved, and leaves them on the day it is voided.
        $bills = $this->in(SupplierBill::query(), $f)->with('supplier:id,name')->whereIn('status', [SupplierBill::APPROVED, SupplierBill::VOID])->whereNotNull('approved_at')
            ->whereBetween('issue_date', [$from, $to])->orderBy('issue_date')->get();
        $billsVoided = $this->in(SupplierBill::query(), $f)->where('status', SupplierBill::VOID)->whereNotNull('approved_at')->where('tax', '>', 0)
            ->whereBetween('voided_at', [$f->from->startOfDay(), $f->to->endOfDay()])->get();

        $taxed = $invoices->filter(fn ($i) => Money::cmp($i->tax_total, 0) > 0);
        $untaxed = $invoices->filter(fn ($i) => Money::isZero($i->tax_total) && $i->status !== Invoice::VOID);
        // As the books have them: every invoice issued in the period (voided later or not) adds its tax, a void in the period
        // takes it back; credit notes and purchases take tax off; a purchase voided in the period puts it back.
        $documents = Money::sub(
            Money::add('0', ...$taxed->pluck('tax_total')->all(), ...$billsVoided->pluck('tax')->all()),
            Money::add('0', ...$voided->pluck('tax_total')->all(), ...$notes->pluck('tax_amount')->all(), ...$bills->pluck('tax')->all()),
        );
        $manual = $bySource['manual'] ?? '0';
        $gap = Money::sub(Money::sub($net, $manual), $documents);

        $rate = (float) $tenant->setting('vat_rate', 0);
        $number = trim((string) $tenant->setting('vat_number', ''));
        $through = app(AccountingPeriods::class)->lockedThrough();
        $notesLines = [
            ['tone' => 'gray', 'text' => 'تقرير مساعد لإعداد الإقرار الضريبي من الدفاتر، وليس إقرارًا رسميًا. راجع متطلبات جهة الضرائب ومحاسبك قبل التقديم.'],
            $rate > 0 || $number !== ''
                ? ['tone' => 'blue', 'text' => 'في الإعدادات: نسبة الضريبة '.rtrim(rtrim(number_format($rate, 2), '0'), '.').'%'.($number !== '' ? '، والرقم الضريبي '.$number : '، ولم يُدخل رقم ضريبي بعد').'.']
                : ['tone' => 'amber', 'text' => 'نسبة الضريبة في الإعدادات 0% ولا رقم ضريبي: النادي لا يحتسب ضريبة على فواتيره. الأرقام أدناه من المستندات التي حملت ضريبة فقط.'],
            $through && $through->gte($f->to)
                ? ['tone' => 'green', 'text' => 'الفترة كلها مقفلة محاسبيًا (حتى '.$through->format('d/m/Y').')؛ أرقامها نهائية.']
                : ['tone' => 'amber', 'text' => 'الفترة غير مقفلة محاسبيًا بعد؛ قد تتغير أرقامها بقيود جديدة. أقفلها من «الفترات المحاسبية» قبل اعتماد الإقرار.'],
            ...(Money::isZero($gap) ? [] : [['tone' => 'red', 'text' => 'فرق '.$gap.' بين المستندات والدفاتر في هذه الفترة. شغّل مزامنة الدفاتر أو راجع القيود اليدوية على حساب الضريبة قبل الاعتماد.']]),
        ];

        $baseOf = fn ($i) => Money::sub($i->total, $i->tax_total);

        return [
            'figures' => [
                ['ضريبة المخرجات (بعد الإلغاء والإشعارات)', $output, S::MONEY],
                ['ضريبة المدخلات (المشتريات)', $input, S::MONEY],
                ['صافي الضريبة المستحقة للفترة', $net, S::MONEY],
                ['المبيعات الخاضعة (قبل الضريبة)', Money::add('0', ...$taxed->reject(fn ($i) => $i->status === Invoice::VOID)->map($baseOf)->all()), S::MONEY],
                ['مبيعات بلا ضريبة', Money::add('0', ...$untaxed->pluck('total')->all()), S::MONEY],
                ['فرق المستندات عن الدفاتر', $gap, S::MONEY],
            ],
            'tables' => [
                new S('حسب البند (من الدفاتر)', [['البند', S::TEXT], ['أثره على الضريبة المستحقة', S::MONEY]],
                    collect(self::SOURCES)->filter(fn ($label, $key) => isset($bySource[$key]))->map(fn ($label, $key) => [$label, $bySource[$key]])->values()->push(['الصافي', $net])->all()),
                // The invoices that carried tax (those without are one figure above): voided ones too, their tax back below.
                new S('فواتير المبيعات الخاضعة للضريبة', [['الفاتورة', S::TEXT], ['التاريخ', S::TEXT], ['العميل', S::TEXT], ['الحالة', S::TEXT], ['المبلغ قبل الضريبة', S::MONEY], ['النسبة', S::PERCENT], ['الضريبة', S::MONEY], ['الإجمالي', S::MONEY]],
                    $taxed->values()->map(fn ($i) => [$i->number, $i->issue_date->toDateString(), $i->customer_name, $i->status === Invoice::VOID ? 'ملغاة' : 'صادرة', $baseOf($i), (float) $i->tax_rate, $i->tax_total, $i->total])->all()),
                new S('فواتير أُلغيت في الفترة', [['الفاتورة', S::TEXT], ['تاريخ الإلغاء', S::TEXT], ['السبب', S::TEXT], ['الضريبة المعكوسة', S::MONEY]],
                    $voided->map(fn ($i) => [$i->number, $i->voided_at?->toDateString(), $i->void_reason, $i->tax_total])->all()),
                new S('الإشعارات الدائنة', [['الإشعار', S::TEXT], ['التاريخ', S::TEXT], ['الفاتورة', S::TEXT], ['السبب', S::TEXT], ['المبلغ', S::MONEY], ['منه ضريبة', S::MONEY]],
                    $notes->map(fn ($n) => [$n->number, $n->created_at->toDateString(), $n->invoice?->number, $n->reason, $n->amount, $n->tax_amount])->all()),
                new S('مشتريات بضريبة (سندات الموردين)', [['السند', S::TEXT], ['فاتورة المورد', S::TEXT], ['التاريخ', S::TEXT], ['المورد', S::TEXT], ['الحالة', S::TEXT], ['المبلغ قبل الضريبة', S::MONEY], ['الضريبة', S::MONEY], ['الإجمالي', S::MONEY]],
                    $bills->filter(fn ($b) => Money::cmp($b->tax, 0) > 0)->map(fn ($b) => [$b->number, $b->reference, $b->issue_date->toDateString(), $b->supplier?->name, $b->status === SupplierBill::VOID ? 'ملغى' : 'معتمد', Money::sub($b->subtotal, $b->discount), $b->tax, $b->total])->values()->all()),
            ],
            'empty' => $lines->isEmpty() && $invoices->isEmpty(),
            'filters' => [['الرقم الضريبي', $number !== '' ? $number : 'غير مُدخل'], ['نسبة الضريبة في الإعدادات', $rate.'%'], ['ملاحظة', 'تقرير مساعد وليس إقرارًا رسميًا']],
            'options' => ['notes' => $notesLines],
        ];
    }
}
