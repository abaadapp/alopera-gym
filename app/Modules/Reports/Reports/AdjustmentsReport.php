<?php

namespace App\Modules\Reports\Reports;

use App\Core\Support\Money;
use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Finance\Models\CashShift;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Models\PaymentAllocation;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;

/**
 * التسويات المالية: everything in the period that put a number right after the fact — invoices voided, credit notes,
 * refunds, payments cancelled, customer credit applied, cash drawer differences, and manual entries and reversals —
 * each with who did it and why, so a reviewer reads the corrections in one place.
 */
class AdjustmentsReport extends TabularReport
{
    protected function build(ReportFilters $f, User $user): array
    {
        [$start, $end] = [$f->from->startOfDay(), $f->to->endOfDay()];
        $rows = collect();
        $add = fn (string $kind, ?string $date, ?string $number, string $detail, $amount, ?string $by) => $rows->push([$kind, $date, $number, $detail, $amount === null ? null : Money::normalize($amount), $by]);

        $this->in(Invoice::query(), $f)->with('voider:id,name')->where('status', Invoice::VOID)->whereBetween('voided_at', [$start, $end])->get()
            ->each(fn ($i) => $add('إلغاء فاتورة', $i->voided_at?->format('Y-m-d H:i'), $i->number, (string) $i->void_reason, $i->total, $i->voider?->name));
        $this->in(CreditNote::query(), $f)->with('creator:id,name', 'invoice:id,number')->whereBetween('created_at', [$start, $end])->get()
            ->each(fn ($n) => $add('إشعار دائن', $n->created_at->format('Y-m-d H:i'), $n->number, 'على '.$n->invoice?->number.': '.$n->reason, $n->amount, $n->creator?->name));
        $this->in(Payment::query(), $f)->with('receiver:id,name')->where('kind', Payment::KIND_REFUND)->where('status', Payment::COMPLETED)->whereBetween('paid_at', [$start, $end])->get()
            ->each(fn ($p) => $add('رد مبلغ', $p->paid_at?->format('Y-m-d H:i'), $p->receipt_number, (Payment::METHOD_LABELS[$p->method] ?? $p->method).($p->reference ? ' · '.$p->reference : ''), $p->amount, $p->receiver?->name));
        $this->in(Payment::query(), $f)->where('status', Payment::CANCELLED)->whereBetween('cancelled_at', [$start, $end])->get()
            ->each(fn ($p) => $add('إلغاء دفعة', $p->cancelled_at?->format('Y-m-d H:i'), $p->receipt_number, (string) $p->cancel_reason, $p->amount, User::find($p->cancelled_by)?->name));
        PaymentAllocation::with('payment:id,receipt_number', 'invoice:id,number', 'allocator:id,name')->whereHas('invoice', fn ($q) => $this->in($q, $f))->whereBetween('created_at', [$start, $end])->get()
            ->each(fn ($a) => $add('تخصيص رصيد عميل', $a->created_at->format('Y-m-d H:i'), $a->payment?->receipt_number, 'إلى الفاتورة '.$a->invoice?->number, $a->amount, $a->allocator?->name));
        $this->in(CashShift::query(), $f)->with('closer:id,name')->where('status', CashShift::CLOSED)->where('difference', '!=', 0)->whereBetween('closed_at', [$start, $end])->get()
            ->each(fn ($s) => $add(Money::cmp($s->difference, 0) < 0 ? 'عجز صندوق' : 'زيادة صندوق', $s->closed_at?->format('Y-m-d H:i'), $s->number, (string) $s->close_note, $s->difference, $s->closer?->name));
        $this->in(JournalEntry::query(), $f)->with('author:id,name')->whereBetween('entry_date', [$f->from->toDateString(), $f->to->toDateString()])
            ->where(fn ($q) => $q->whereNull('source_type')->orWhere('source_type', 'account'))->get()
            ->each(fn ($e) => $add($e->reverses_id ? 'قيد عكسي' : ($e->source_type === 'account' ? 'رصيد افتتاحي أو تصحيحه' : 'قيد يدوي'), $e->entry_date->toDateString(), $e->number, $e->description, $e->total, $e->author?->name));

        $rows = $rows->sortBy(1)->values();
        $count = fn (string $kind) => $rows->where(0, $kind)->count();

        return [
            'figures' => [
                ['عمليات التسوية', $rows->count(), S::INT],
                ['فواتير أُلغيت', $count('إلغاء فاتورة'), S::INT],
                ['إشعارات دائنة', $count('إشعار دائن'), S::INT],
                ['دفعات أُلغيت', $count('إلغاء دفعة'), S::INT],
                ['قيود يدوية وعكسية', $count('قيد يدوي') + $count('قيد عكسي'), S::INT],
                ['فروقات الصندوق', $count('عجز صندوق') + $count('زيادة صندوق'), S::INT],
            ],
            'tables' => [new S('التسويات', [['النوع', S::TEXT], ['التاريخ', S::TEXT], ['المستند', S::TEXT], ['البيان أو السبب', S::TEXT], ['المبلغ', S::MONEY], ['بواسطة', S::TEXT]], $rows->all())],
            'empty' => $rows->isEmpty(),
        ];
    }
}
