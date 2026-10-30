<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Finance\Http\Controllers\InvoiceController;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * المستحقات والمتأخرات, as they stand today: what is owed on open invoices by how late it is, who owes the most, and
 * by kind of invoice. Collecting stays on the invoices and dues pages of the finance section.
 */
final class DuesReport extends Report
{
    public const BUCKETS = ['current' => 'غير مستحقة بعد', '1_30' => 'متأخرة 1–30 يومًا', '31_60' => 'متأخرة 31–60 يومًا', '60_plus' => 'أكثر من 60 يومًا'];

    private const STATUSES = [Invoice::ISSUED => 'غير مدفوعة', Invoice::PARTIALLY_PAID => 'مدفوعة جزئيًا'];

    public function page(ReportFilters $f, User $user): array
    {
        $open = $this->open($f);
        $today = CarbonImmutable::today();

        return [
            'aging' => $this->aging($open, $today),
            'total' => round($open->sum(fn (Invoice $i) => (float) $i->balance()), 3),
            'count' => $open->count(),
            'byType' => $this->byType($open),
            'debtors' => $this->debtors($open)->take(10)->values(),
            'invoices' => $open->take(300)->map(fn (Invoice $i) => InvoiceController::row($i) + ['days_overdue' => $this->late($i, $today)])->values(),
            'options' => ['overdue' => $f->boolean('overdue')],
            'empty' => $open->isEmpty(),
        ];
    }

    public function export(ReportFilters $f, User $user): array
    {
        $open = $this->open($f);
        $today = CarbonImmutable::today();
        $aging = $this->aging($open, $today);

        return [
            'empty' => $open->isEmpty(),
            'filters' => [['الفواتير', $f->boolean('overdue') ? 'المتأخرة فقط' : 'كل الفواتير المفتوحة']],
            'sheets' => [
                new S('أعمار المستحقات', [['الفئة', S::TEXT], ['عدد الفواتير', S::INT], ['المبلغ', S::MONEY]],
                    [...array_map(fn ($key) => [self::BUCKETS[$key], $aging[$key]['count'], $aging[$key]['amount']], array_keys(self::BUCKETS)),
                        ['الإجمالي', $open->count(), round($open->sum(fn (Invoice $i) => (float) $i->balance()), 3)]]),
                new S('الفواتير المفتوحة', [['الفاتورة', S::TEXT], ['العميل', S::TEXT], ['الهاتف', S::TEXT], ['النوع', S::TEXT], ['تاريخ الإصدار', S::TEXT], ['تاريخ الاستحقاق', S::TEXT], ['أيام التأخير', S::INT], ['الإجمالي', S::MONEY], ['المدفوع', S::MONEY], ['المتبقي', S::MONEY], ['الحالة', S::TEXT]],
                    $open->map(fn (Invoice $i) => [$i->number, $i->customer_name, $i->customer_phone, FinanceReport::INVOICE_TYPES[$i->type] ?? $i->type, $i->issue_date->toDateString(), $i->due_date?->toDateString(),
                        $this->late($i, $today), $i->total, $i->paid_total, $i->balance(), self::STATUSES[$i->status] ?? $i->status])->all()),
                new S('أعلى المدينين', [['العميل', S::TEXT], ['عدد الفواتير', S::INT], ['المتبقي', S::MONEY]], $this->debtors($open)->map(fn ($d) => [$d['name'], $d['count'], $d['balance']])->all()),
            ],
        ];
    }

    /** @return Collection<int, Invoice> */
    private function open(ReportFilters $f): Collection
    {
        return $this->in(Invoice::open(), $f)
            ->when($f->boolean('overdue'), fn ($q) => $q->whereNotNull('due_date')->whereDate('due_date', '<', today()))
            ->orderBy('due_date')->orderBy('id')->get();
    }

    private function late(Invoice $i, CarbonImmutable $today): int
    {
        return $i->due_date && $i->due_date->lt($today) ? (int) $i->due_date->diffInDays($today) : 0;
    }

    /** @return array<string, array{count: int, amount: float}> */
    private function aging(Collection $open, CarbonImmutable $today): array
    {
        $aging = array_map(fn () => ['count' => 0, 'amount' => 0.0], self::BUCKETS);
        foreach ($open as $invoice) {
            $days = $this->late($invoice, $today);
            $bucket = match (true) {
                $days === 0 => 'current',
                $days <= 30 => '1_30',
                $days <= 60 => '31_60',
                default => '60_plus',
            };
            $aging[$bucket]['count']++;
            $aging[$bucket]['amount'] += (float) $invoice->balance();
        }

        return array_map(fn ($b) => ['count' => $b['count'], 'amount' => round($b['amount'], 3)], $aging);
    }

    private function byType(Collection $open): array
    {
        return $open->groupBy('type')->map(fn ($group, $type) => ['label' => FinanceReport::INVOICE_TYPES[$type] ?? $type, 'count' => $group->count(), 'amount' => round($group->sum(fn (Invoice $i) => (float) $i->balance()), 3)])
            ->sortByDesc('amount')->values()->all();
    }

    /** Who owes the most: a member's invoices together, else by the name on the invoice. */
    private function debtors(Collection $open): Collection
    {
        return $open->groupBy(fn (Invoice $i) => $i->billable_type && $i->billable_id ? "{$i->billable_type}:{$i->billable_id}" : 'name:'.$i->customer_name)
            ->map(fn ($group) => ['name' => $group->first()->customer_name, 'count' => $group->count(), 'balance' => round($group->sum(fn (Invoice $i) => (float) $i->balance()), 3),
                'member_id' => $group->first()->billable_type === 'member' ? $group->first()->billable_id : null])
            ->sortByDesc('balance')->values();
    }
}
