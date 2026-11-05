<?php

namespace App\Modules\Reports\Reports;

use App\Core\Support\Money;
use App\Models\User;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\Member;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;
use Illuminate\Support\Collection;

/**
 * كشف حساب العضو: one member's invoices, payments, refunds, credit notes and credit applied, in date order with a running
 * balance — what they owe (positive) or are owed (negative) — from the balance carried in at the start of the period.
 * Drafts and cancelled or rejected payments are not on it; a voided invoice is, with its reversal on the day it was voided.
 */
class MemberStatementReport extends TabularReport
{
    protected function build(ReportFilters $f, User $user): array
    {
        $member = ($id = $f->integer('member_id')) ? Member::withTrashed()->find($id) : null;
        if (! $member) {
            return ['figures' => [], 'tables' => [], 'empty' => true, 'options' => ['member' => null, 'notes' => [['tone' => 'blue', 'text' => 'اختر العضو لعرض كشف حسابه.']]]];
        }

        $moves = $this->moves($member, $f);
        $before = $moves->filter(fn ($m) => $m['date'] < $f->from->toDateString());
        $within = $moves->filter(fn ($m) => $m['date'] >= $f->from->toDateString() && $m['date'] <= $f->to->toDateString())->values();
        $opening = Money::add('0', ...$before->map(fn ($m) => Money::sub($m['debit'], $m['credit']))->all());

        $balance = $opening;
        $rows = [['', $f->from->toDateString(), 'رصيد أول المدة', null, null, $opening]];
        foreach ($within as $m) {
            $balance = Money::add($balance, Money::sub($m['debit'], $m['credit']));
            $rows[] = [$m['number'], $m['date'], $m['label'], Money::isZero($m['debit']) ? null : $m['debit'], Money::isZero($m['credit']) ? null : $m['credit'], $balance];
        }
        $debits = Money::add('0', ...$within->pluck('debit')->all());
        $credits = Money::add('0', ...$within->pluck('credit')->all());

        return [
            'figures' => [
                ['رصيد أول المدة', $opening, S::MONEY],
                ['المطالبات (مدين)', $debits, S::MONEY],
                ['المدفوعات والتخفيضات (دائن)', $credits, S::MONEY],
                [Money::cmp($balance, 0) < 0 ? 'الرصيد آخر المدة (له)' : 'الرصيد آخر المدة (عليه)', $balance, S::MONEY],
            ],
            'tables' => [new S('كشف الحساب', [['المستند', S::TEXT], ['التاريخ', S::TEXT], ['البيان', S::TEXT], ['مدين', S::MONEY], ['دائن', S::MONEY], ['الرصيد', S::MONEY]], $rows)],
            'empty' => $within->isEmpty() && Money::isZero($opening),
            'filters' => [['العضو', $member->full_name.' ('.$member->member_no.')']],
            'options' => ['member' => ['id' => $member->id, 'name' => $member->full_name, 'member_no' => $member->member_no]],
        ];
    }

    /** @return Collection<int, array{date: string, number: string, label: string, debit: string, credit: string, order: int}> */
    private function moves(Member $member, ReportFilters $f): Collection
    {
        $invoices = $this->in(Invoice::where(['billable_type' => 'member', 'billable_id' => $member->id]), $f)->where('status', '!=', Invoice::DRAFT)->get();
        $ids = $invoices->pluck('id');
        $moves = collect();
        foreach ($invoices as $i) {
            $moves->push(['date' => $i->issue_date->toDateString(), 'number' => $i->number, 'label' => 'فاتورة', 'debit' => Money::normalize($i->total), 'credit' => '0', 'order' => 1]);
            if ($i->status === Invoice::VOID) {
                $moves->push(['date' => ($i->voided_at ?? $i->updated_at)->toDateString(), 'number' => $i->number, 'label' => 'إلغاء الفاتورة'.($i->void_reason ? ': '.$i->void_reason : ''), 'debit' => '0', 'credit' => Money::normalize($i->total), 'order' => 2]);
            }
        }
        CreditNote::whereIn('invoice_id', $ids)->get()->each(fn ($n) => $moves->push(['date' => $n->created_at->toDateString(), 'number' => $n->number, 'label' => 'إشعار دائن: '.$n->reason, 'debit' => '0', 'credit' => Money::normalize($n->amount), 'order' => 3]));
        Payment::whereIn('invoice_id', $ids)->where('status', Payment::COMPLETED)->get()->each(function (Payment $p) use ($moves) {
            $date = ($p->confirmed_at ?? $p->paid_at ?? $p->created_at)->toDateString();
            $method = Payment::METHOD_LABELS[$p->method] ?? $p->method;
            $moves->push($p->kind === Payment::KIND_REFUND
                ? ['date' => $date, 'number' => $p->receipt_number ?? '#'.$p->id, 'label' => 'رد مبلغ — '.$method, 'debit' => Money::normalize($p->amount), 'credit' => '0', 'order' => 5]
                : ['date' => $date, 'number' => $p->receipt_number ?? '#'.$p->id, 'label' => 'دفعة — '.$method, 'debit' => '0', 'credit' => Money::normalize($p->amount), 'order' => 4]);
        });
        // Credit of one payment applied to another invoice of theirs moves nothing here: the payment already counts in
        // full, and the balance (owed less credit) stays right.

        return $moves->sortBy([['date', 'asc'], ['order', 'asc']])->values();
    }
}
