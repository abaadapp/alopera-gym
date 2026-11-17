<?php

namespace App\Modules\Reports\Reports;

use App\Models\User;
use App\Modules\Inventory\Http\Controllers\SalesController;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Reports\Support\ReportFilters;
use App\Modules\Reports\Support\ReportSheet as S;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * تقرير المبيعات: completed sales (paid and handed over) from the point of sale and the website, day by day, by
 * product (with its add-ons), add-on, payment method, source and member of staff, with voided and cancelled ones
 * counted apart.
 *
 * Each sale is dated by what happened to it, in the club's time (Asia/Muscat):
 * - a completed sale when it was completed: at the till, the moment of sale; a website order, when it was handed over
 *   (delivered_at), not when it was placed nor when it was paid;
 * - a cancelled order or a voided sale when it was cancelled or voided (cancelled_at, voided_at);
 * - an order still waiting is no sale and in no figure.
 * A sale's status is the one it has now: one voided since is a cancellation of the month it was voided, not a sale of
 * the month it was made. This is the sales count, not the money collected (see the finance reports) nor revenue.
 */
final class SalesReport extends Report
{
    /** When a completed sale was completed: handed over (a website order) or sold at the till (no handing over). */
    private const DONE_AT = 'coalesce(sales.delivered_at, sales.created_at)';

    /** When a voided sale or a cancelled order was voided or cancelled. */
    private const ENDED_AT = 'coalesce(sales.voided_at, sales.cancelled_at, sales.created_at)';

    private const FIGURES = [
        'total' => ['إجمالي المبيعات', S::MONEY], 'count' => ['عدد العمليات', S::INT], 'average' => ['متوسط العملية', S::MONEY],
        'voided_total' => ['الملغاة والمستردة', S::MONEY], 'voided_count' => ['عدد الملغاة والمستردة', S::INT],
    ];

    public function page(ReportFilters $f, User $user): array
    {
        $comparison = $this->compare($this->headline($f, $f->from, $f->to), $this->headline($f, $f->prevFrom, $f->prevTo));

        return [
            'comparison' => $comparison,
            'days' => $this->days($f),
            'products' => $this->products($f, 50),
            'addons' => $this->addons($f),
            'methods' => $this->grouped($f, 'payment_method', SalesController::METHOD_LABELS),
            'sources' => $this->grouped($f, 'source', SalesController::SOURCE_LABELS),
            'staff' => $this->staff($f),
            'empty' => (int) $comparison['count']['current'] === 0 && (int) $comparison['voided_count']['current'] === 0,
        ];
    }

    public function export(ReportFilters $f, User $user): array
    {
        $comparison = $this->compare($this->headline($f, $f->from, $f->to), $this->headline($f, $f->prevFrom, $f->prevTo));
        $line = [['العمليات', S::INT], ['المبلغ', S::MONEY]];

        return [
            'empty' => (int) $comparison['count']['current'] === 0 && (int) $comparison['voided_count']['current'] === 0,
            'sheets' => [
                S::summary($comparison, self::FIGURES),
                new S('المبيعات اليومية', [['التاريخ', S::TEXT], ...$line], array_map(fn ($d) => [$d['day'], $d['count'], $d['total']], $this->days($f))),
                new S('حسب المنتج', [['المنتج', S::TEXT], ['الكمية', S::NUMBER], ['المبلغ مع الإضافات', S::MONEY]], array_map(fn ($p) => [$p['name'], $p['quantity'], $p['total']], $this->products($f, 500))),
                new S('الإضافات', [['الإضافة', S::TEXT], ['الكمية', S::NUMBER], ['المبلغ', S::MONEY]], array_map(fn ($a) => [$a['name'], $a['quantity'], $a['total']], $this->addons($f))),
                new S('حسب طريقة الدفع', [['طريقة الدفع', S::TEXT], ...$line], array_map(fn ($r) => [$r['label'], $r['count'], $r['total']], $this->grouped($f, 'payment_method', SalesController::METHOD_LABELS))),
                new S('حسب المصدر', [['المصدر', S::TEXT], ...$line], array_map(fn ($r) => [$r['label'], $r['count'], $r['total']], $this->grouped($f, 'source', SalesController::SOURCE_LABELS))),
                new S('حسب الموظف', [['الموظف', S::TEXT], ...$line], array_map(fn ($r) => [$r['name'], $r['count'], $r['total']], $this->staff($f))),
                new S('العمليات', [['الرقم', S::TEXT], ['التاريخ', S::TEXT], ['تاريخ الطلب', S::TEXT], ['المصدر', S::TEXT], ['العميل', S::TEXT], ['طريقة الدفع', S::TEXT], ['الخصم', S::MONEY], ['الإجمالي', S::MONEY], ['الحالة', S::TEXT], ['الموظف', S::TEXT]],
                    (function () use ($f) {
                        // The sales the figures count (completed in the period) and the cancellations (in the period), each
                        // dated as counted; an order still waiting is in neither.
                        $rows = $this->sales($f)->with(['cashier:id,name', 'customer'])
                            ->where(fn ($q) => $q->where(fn ($q) => $this->completedBetween($q, $f->from, $f->to))
                                ->orWhere(fn ($q) => $this->endedBetween($q, $f->from, $f->to)))
                            ->orderBy('id');
                        foreach ($rows->lazy(500) as $s) {
                            $at = $s->status === Sale::COMPLETED ? ($s->delivered_at ?? $s->created_at) : ($s->voided_at ?? $s->cancelled_at ?? $s->created_at);
                            yield [$s->number, $at->format('Y-m-d H:i'), $s->created_at->format('Y-m-d H:i'), SalesController::SOURCE_LABELS[$s->source] ?? $s->source, $s->buyerName(),
                                SalesController::METHOD_LABELS[$s->payment_method] ?? $s->payment_method, $s->discount, $s->total, SalesController::STATUS_LABELS[$s->status] ?? $s->status, $s->cashier?->name];
                        }
                    })()),
            ],
        ];
    }

    private function sales(ReportFilters $f): Builder
    {
        return $this->in(Sale::query(), $f, 'sales.branch_id');
    }

    private function done(ReportFilters $f, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): Builder
    {
        return $this->completedBetween($this->sales($f), $from ?? $f->from, $to ?? $f->to);
    }

    /** Completed, and completed between the two days (whole days, Muscat time). */
    private function completedBetween(Builder $query, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $query->where('sales.status', Sale::COMPLETED)->whereRaw(self::DONE_AT.' between ? and ?', [$from->startOfDay(), $to->endOfDay()]);
    }

    /** Voided or cancelled, and voided or cancelled between the two days. */
    private function endedBetween(Builder $query, CarbonImmutable $from, CarbonImmutable $to): Builder
    {
        return $query->whereIn('sales.status', [Sale::VOIDED, Sale::CANCELLED])->whereRaw(self::ENDED_AT.' between ? and ?', [$from->startOfDay(), $to->endOfDay()]);
    }

    /** @return array<string, int|string> */
    private function headline(ReportFilters $f, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $done = $this->done($f, $from, $to)->toBase()->selectRaw('count(*) as n, coalesce(sum(total), 0) as total')->first();
        $voided = $this->endedBetween($this->sales($f), $from, $to)
            ->toBase()->selectRaw('count(*) as n, coalesce(sum(total), 0) as total')->first();

        return [
            'total' => $this->money($done->total), 'count' => (int) $done->n, 'average' => $done->n ? $this->money($done->total / $done->n) : '0.000',
            'voided_total' => $this->money($voided->total), 'voided_count' => (int) $voided->n,
        ];
    }

    private function days(ReportFilters $f): array
    {
        return $this->done($f)->toBase()->selectRaw('date('.self::DONE_AT.') as day, count(*) as n, sum(total) as total')->groupBy('day')->orderBy('day')->get()
            ->map(fn ($r) => ['day' => (string) $r->day, 'count' => (int) $r->n, 'total' => $this->money($r->total)])->all();
    }

    private function products(ReportFilters $f, int $limit): array
    {
        return DB::table('sale_items')->whereIn('sale_id', $this->done($f)->select('sales.id'))
            ->select('name', DB::raw('sum(quantity) as qty'), DB::raw('sum(line_total) as total'))
            ->groupBy('name')->orderByDesc('total')->limit($limit)->get()
            ->map(fn ($r) => ['name' => $r->name, 'quantity' => (float) $r->qty, 'total' => $this->money($r->total)])->all();
    }

    /** The add-ons sold with those lines (already inside each line's amount), by name as sold. */
    private function addons(ReportFilters $f): array
    {
        return DB::table('sale_item_addons as a')->join('sale_items as i', 'i.id', '=', 'a.sale_item_id')
            ->whereIn('i.sale_id', $this->done($f)->select('sales.id'))
            ->select('a.name', DB::raw('sum(a.quantity * i.quantity) as qty'), DB::raw('sum(a.line_total) as total'))
            ->groupBy('a.name')->orderByDesc('total')->get()
            ->map(fn ($r) => ['name' => $r->name, 'quantity' => (float) $r->qty, 'total' => $this->money($r->total)])->all();
    }

    private function grouped(ReportFilters $f, string $column, array $labels): array
    {
        return $this->done($f)->toBase()->select($column, DB::raw('count(*) as n'), DB::raw('sum(total) as total'))->groupBy($column)->orderByDesc('total')->get()
            ->map(fn ($r) => ['label' => $labels[$r->{$column}] ?? $r->{$column}, 'count' => (int) $r->n, 'total' => $this->money($r->total)])->all();
    }

    private function staff(ReportFilters $f): array
    {
        return $this->done($f)->toBase()->leftJoin('users', 'users.id', '=', 'sales.cashier_id')
            ->select(DB::raw("coalesce(users.name, '—') as name"), DB::raw('count(*) as n'), DB::raw('sum(sales.total) as total'))->groupBy('users.name')->orderByDesc('total')->get()
            ->map(fn ($r) => ['name' => $r->name, 'count' => (int) $r->n, 'total' => $this->money($r->total)])->all();
    }
}
