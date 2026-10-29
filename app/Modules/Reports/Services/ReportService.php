<?php

namespace App\Modules\Reports\Services;

use App\Core\Auth\PermissionRegistry;
use App\Core\Tenancy\TenantContext;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\CheckIn;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipEvent;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\SaleItem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * All dashboard and report figures. Every query runs through tenant-scoped
 * models, so a report can only ever count the current club's rows.
 */
final class ReportService
{
    /** @return array<string, mixed> */
    /**
     * The dashboard's figures, for one branch when $branch is given (its records and those tied to no branch).
     */
    public function dashboard(?int $branch = null): array
    {
        $b = fn ($query, string $column = 'branch_id') => $branch ? $query->where(fn ($w) => $w->where($column, $branch)->orWhereNull($column)) : $query;
        $today = CarbonImmutable::today();
        $monthStart = $today->startOfMonth();

        $active = $b(Membership::where('status', Membership::ACTIVE))->distinct('member_id')->count('member_id');
        $frozen = $b(Membership::where('status', Membership::FROZEN))->distinct('member_id')->count('member_id');
        $expiringSoon = $b(Membership::with('member:id,full_name,phone,member_no'))
            ->expiringWithin(7, $today)
            ->orderBy('ends_on')
            ->limit(10)
            ->get()
            ->map(fn (Membership $m) => [
                'id' => $m->id,
                'member_id' => $m->member_id,
                'member' => $m->member?->full_name,
                'phone' => $m->member?->phone,
                'plan' => $m->plan_snapshot['name'] ?? '',
                'ends_on' => $m->ends_on->toDateString(),
                'days_left' => $m->daysRemaining($today),
            ]);

        $expired = $b(Member::query())->whereHas('memberships', fn ($q) => $q->where('status', Membership::EXPIRED))
            ->whereDoesntHave('memberships', fn ($q) => $q->whereIn('status', [Membership::ACTIVE, Membership::FROZEN, Membership::SCHEDULED]))
            ->count();

        $todaySessions = $b(ClassSession::with(['program:id,name,color', 'trainer:id,name']))
            ->whereDate('starts_at', $today)
            ->where('status', '!=', ClassSession::CANCELLED)
            ->orderBy('starts_at')
            ->get()
            ->map(fn (ClassSession $s) => [
                'id' => $s->id,
                'program' => $s->program?->name,
                'color' => $s->program?->color,
                'trainer' => $s->trainer?->name,
                'time' => $s->starts_at->format('H:i'),
                'booked' => $s->booked_count,
                'capacity' => $s->capacity,
            ]);

        return [
            'kpis' => [
                'active_members' => $active,
                'frozen_members' => $frozen,
                'expired_members' => $expired,
                'expiring_7_days' => $b(Membership::expiringWithin(7, $today))->count(),
                'new_members_month' => $b(Member::where('joined_on', '>=', $monthStart))->count(),
                'renewals_month' => $b(Membership::query())->where('kind', Membership::KIND_RENEWAL)->whereNotNull('activated_at')->where('activated_at', '>=', $monthStart)->count(),
                'cancellations_month' => $b(Membership::query())->where('status', Membership::CANCELLED)->where('cancelled_at', '>=', $monthStart)->where(fn ($q) => $q->whereNull('cancel_reason')->orWhereNotIn('cancel_reason', ['upgraded', 'invoice_voided']))->count(),
                'revenue_month' => $this->netCollected($monthStart, $today->endOfDay(), $branch),
                'revenue_today' => $this->netCollected($today, $today->endOfDay(), $branch),
                'outstanding' => (string) $b(Invoice::open())->sum(DB::raw('total - credited_total - paid_total')),
                'overdue_invoices' => $b(Invoice::overdue())->count(),
                'pending_transfers' => $b(Payment::where('status', Payment::PENDING))->count(),
                'checkins_today' => $b(CheckIn::where('result', 'allowed'))->where('checked_in_at', '>=', $today)->count(),
                'low_stock' => PermissionRegistry::moduleEnabled('sales') ? Product::where('is_active', true)->lowStock()->count() : 0,
            ],
            'expiring' => $expiringSoon,
            'today_sessions' => $todaySessions,
            'revenue_trend' => $this->dailyRevenue($today->subDays(29), $today, $branch),
        ];
    }

    /** Net money collected in a period (payments less refunds), for one branch when given. */
    public function collected(CarbonImmutable $from, CarbonImmutable $to, ?int $branch = null): string
    {
        return $this->netCollected($from, $to, $branch);
    }

    private function netCollected(CarbonImmutable $from, CarbonImmutable $to, ?int $branch = null): string
    {
        $row = Payment::completed()->whereBetween('paid_at', [$from, $to])
            ->when($branch, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $branch)->orWhereNull('branch_id')))
            ->selectRaw("coalesce(sum(case when kind = 'refund' then -amount else amount end), 0) as net")
            ->first();

        return number_format((float) $row->net, 3, '.', '');
    }

    /** @return list<array{date: string, amount: float}> */
    public function dailyRevenue(CarbonImmutable $from, CarbonImmutable $to, ?int $branch = null): array
    {
        $rows = Payment::completed()->whereBetween('paid_at', [$from->startOfDay(), $to->endOfDay()])
            ->when($branch, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $branch)->orWhereNull('branch_id')))
            ->selectRaw("date(paid_at) as d, sum(case when kind = 'refund' then -amount else amount end) as net")
            ->groupBy('d')->pluck('net', 'd');

        $out = [];
        for ($d = $from; $d->lte($to); $d = $d->addDay()) {
            $out[] = ['date' => $d->toDateString(), 'amount' => round((float) ($rows[$d->toDateString()] ?? 0), 3)];
        }

        return $out;
    }

    /**
     * Full report for a period, each headline figure compared with another period:
     * by default the same number of days just before it.
     *
     * @return array<string, mixed>
     */
    public function period(CarbonImmutable $from, CarbonImmutable $to, bool $financial = true, ?CarbonImmutable $prevFrom = null, ?CarbonImmutable $prevTo = null): array
    {
        if ($prevFrom === null || $prevTo === null) {
            $days = (int) $from->diffInDays($to) + 1;
            $prevTo = $from->subDay();
            $prevFrom = $prevTo->subDays($days - 1);
        }

        $current = $this->headline($from, $to, $financial);
        $previous = $this->headline($prevFrom, $prevTo, $financial);

        $comparison = [];
        foreach ($current as $key => $value) {
            $prev = (float) ($previous[$key] ?? 0);
            $comparison[$key] = [
                'current' => $value,
                'previous' => $previous[$key] ?? 0,
                'change' => $prev == 0.0 ? null : round(((float) $value - $prev) / abs($prev) * 100, 1),
            ];
        }

        return [
            'range' => ['from' => $from->toDateString(), 'to' => $to->toDateString(), 'previous_from' => $prevFrom->toDateString(), 'previous_to' => $prevTo->toDateString()],
            'comparison' => $comparison,
            'plans' => $this->byPlan($from, $to, $financial),
            'programs' => $this->byProgram($from, $to),
            'trainers' => $this->byTrainer($from, $to),
            'revenue_by_method' => $financial ? $this->revenueBy('method', $from, $to) : [],
            'revenue_by_type' => $financial ? $this->revenueByType($from, $to) : [],
            'expenses_by_category' => $financial ? $this->expensesByCategory($from, $to) : [],
            'products' => $financial && PermissionRegistry::moduleEnabled('sales') ? $this->productSales($from, $to) : [],
            'trend' => $financial ? $this->dailyRevenue($from, $to) : [],
        ];
    }

    /** @return array<string, int|float|string> */
    private function headline(CarbonImmutable $from, CarbonImmutable $to, bool $financial): array
    {
        $end = $to->endOfDay();
        $events = MembershipEvent::whereBetween('created_at', [$from, $end])
            ->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type');

        $activated = Membership::whereBetween('activated_at', [$from, $end])
            ->selectRaw('kind, count(*) as total')->groupBy('kind')->pluck('total', 'kind');

        $sessions = ClassSession::whereBetween('starts_at', [$from, $end])->where('status', '!=', ClassSession::CANCELLED);
        $sessionCount = (clone $sessions)->count();
        $capacity = (int) (clone $sessions)->sum('capacity');
        $bookings = Booking::whereHas('session', fn ($q) => $q->whereBetween('starts_at', [$from, $end]))
            ->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');
        $attended = (int) ($bookings[Booking::ATTENDED] ?? 0);
        $noShow = (int) ($bookings[Booking::NO_SHOW] ?? 0);
        $live = $attended + $noShow + (int) ($bookings[Booking::BOOKED] ?? 0);

        $row = [
            'new_memberships' => (int) ($activated[Membership::KIND_NEW] ?? 0),
            'renewals' => (int) ($activated[Membership::KIND_RENEWAL] ?? 0),
            'upgrades' => (int) ($activated[Membership::KIND_UPGRADE] ?? 0),
            'cancellations' => (int) ($events['cancelled'] ?? 0),
            'freezes' => (int) ($events['frozen'] ?? 0),
            'expirations' => (int) ($events['expired'] ?? 0),
            'new_members' => Member::whereBetween('joined_on', [$from, $to])->count(),
            'checkins' => CheckIn::where('result', 'allowed')->whereBetween('checked_in_at', [$from, $end])->count(),
            'sessions' => $sessionCount,
            'bookings' => $live,
            'attendance_rate' => ($attended + $noShow) > 0 ? round($attended / ($attended + $noShow) * 100, 1) : 0,
            'occupancy_rate' => $capacity > 0 ? round($live / $capacity * 100, 1) : 0,
        ];

        if ($financial) {
            $revenue = (float) $this->netCollected($from, $end);
            $expenses = (float) Expense::whereBetween('expense_date', [$from, $to])->sum('amount');
            // net of credit notes: what the invoices are worth after partial refunds and reductions
            $issued = (float) Invoice::whereBetween('issue_date', [$from, $to])->where('status', '!=', Invoice::VOID)->sum(DB::raw('total - credited_total'));
            $collectedOnIssued = (float) Invoice::whereBetween('issue_date', [$from, $to])->where('status', '!=', Invoice::VOID)->sum('paid_total');

            $row += [
                'revenue' => round($revenue, 3),
                'expenses' => round($expenses, 3),
                'net' => round($revenue - $expenses, 3),
                'invoiced' => round($issued, 3),
                'collection_rate' => $issued > 0 ? round($collectedOnIssued / $issued * 100, 1) : 0,
                'refunds' => round((float) Payment::completed()->where('kind', Payment::KIND_REFUND)->whereBetween('paid_at', [$from, $end])->sum('amount'), 3),
                'credit_notes' => round((float) CreditNote::whereBetween('created_at', [$from, $end])->sum('amount'), 3),
                'product_sales' => PermissionRegistry::moduleEnabled('sales')
                    ? round((float) SaleItem::whereHas('sale', fn ($q) => $q->where('status', 'completed')->whereBetween('created_at', [$from, $end]))->sum('line_total'), 3)
                    : 0,
            ];
        }

        return $row;
    }

    /** @return list<array<string, mixed>> */
    private function byPlan(CarbonImmutable $from, CarbonImmutable $to, bool $financial): array
    {
        return Membership::whereBetween('activated_at', [$from, $to->endOfDay()])
            ->selectRaw("membership_plan_id, plan_snapshot->>'name' as name, count(*) as sold, sum(case when kind = 'renewal' then 1 else 0 end) as renewals, sum(total) as revenue")
            ->groupBy('membership_plan_id', DB::raw("plan_snapshot->>'name'"))
            ->orderByDesc('sold')
            ->get()
            ->map(fn ($r) => [
                'plan' => $r->name,
                'sold' => (int) $r->sold,
                'renewals' => (int) $r->renewals,
                'revenue' => $financial ? round((float) $r->revenue, 3) : null,
            ])->all();
    }

    /** Programs ranked by demand. @return list<array<string, mixed>> */
    public function byProgram(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('gym_class_sessions as s')
            ->join('gym_programs as p', 'p.id', '=', 's.program_id')
            ->leftJoin('gym_bookings as b', function ($j) {
                $j->on('b.class_session_id', '=', 's.id')->where('b.status', '!=', Booking::CANCELLED);
            })
            ->where('s.tenant_id', app(TenantContext::class)->require()->id)
            ->whereBetween('s.starts_at', [$from, $to->endOfDay()])
            ->where('s.status', '!=', ClassSession::CANCELLED)
            ->groupBy('p.id', 'p.name', 'p.color')
            ->selectRaw("p.name, p.color, count(distinct s.id) as sessions, count(b.id) as bookings,
                sum(case when b.status = 'attended' then 1 else 0 end) as attended,
                sum(case when b.status = 'no_show' then 1 else 0 end) as no_show")
            ->orderByDesc('bookings')
            ->get()
            ->map(fn ($r) => [
                'program' => $r->name,
                'color' => $r->color,
                'sessions' => (int) $r->sessions,
                'bookings' => (int) $r->bookings,
                'attended' => (int) $r->attended,
                'no_show' => (int) $r->no_show,
                'attendance_rate' => ($r->attended + $r->no_show) > 0 ? round($r->attended / ($r->attended + $r->no_show) * 100, 1) : 0,
                'avg_per_session' => $r->sessions > 0 ? round($r->bookings / $r->sessions, 1) : 0,
            ])->all();
    }

    /** @return list<array<string, mixed>> */
    private function byTrainer(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return DB::table('gym_class_sessions as s')
            ->join('gym_trainers as t', 't.id', '=', 's.trainer_id')
            ->leftJoin('gym_bookings as b', function ($j) {
                $j->on('b.class_session_id', '=', 's.id')->where('b.status', '!=', Booking::CANCELLED);
            })
            ->where('s.tenant_id', app(TenantContext::class)->require()->id)
            ->whereBetween('s.starts_at', [$from, $to->endOfDay()])
            ->where('s.status', '!=', ClassSession::CANCELLED)
            ->groupBy('t.id', 't.name')
            ->selectRaw("t.name, count(distinct s.id) as sessions, count(b.id) as bookings, sum(case when b.status = 'attended' then 1 else 0 end) as attended")
            ->orderByDesc('bookings')
            ->get()
            ->map(fn ($r) => ['trainer' => $r->name, 'sessions' => (int) $r->sessions, 'bookings' => (int) $r->bookings, 'attended' => (int) $r->attended])
            ->all();
    }

    /** @return list<array{key: string, amount: float}> */
    private function revenueBy(string $column, CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Payment::completed()->whereBetween('paid_at', [$from, $to->endOfDay()])
            ->selectRaw("$column as k, sum(case when kind = 'refund' then -amount else amount end) as net")
            ->groupBy('k')->orderByDesc('net')->get()
            ->map(fn ($r) => ['key' => (string) $r->k, 'amount' => round((float) $r->net, 3)])->all();
    }

    /** @return list<array{key: string, amount: float}> */
    private function revenueByType(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Payment::completed()->whereBetween('payments.paid_at', [$from, $to->endOfDay()])
            ->join('invoices', 'invoices.id', '=', 'payments.invoice_id')
            ->selectRaw("invoices.type as k, sum(case when payments.kind = 'refund' then -payments.amount else payments.amount end) as net")
            ->groupBy('invoices.type')->orderByDesc('net')->get()
            ->map(fn ($r) => ['key' => (string) $r->k, 'amount' => round((float) $r->net, 3)])->all();
    }

    /** @return list<array{key: string, amount: float}> */
    private function expensesByCategory(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return Expense::whereBetween('expense_date', [$from, $to])
            ->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->selectRaw("coalesce(expense_categories.name, 'غير مصنف') as k, sum(expenses.amount) as total")
            ->groupBy('k')->orderByDesc('total')->get()
            ->map(fn ($r) => ['key' => (string) $r->k, 'amount' => round((float) $r->total, 3)])->all();
    }

    /** @return list<array<string, mixed>> */
    private function productSales(CarbonImmutable $from, CarbonImmutable $to): array
    {
        return SaleItem::whereHas('sale', fn ($q) => $q->where('status', 'completed')->whereBetween('created_at', [$from, $to->endOfDay()]))
            ->selectRaw('product_id, name, sum(quantity) as qty, sum(line_total) as revenue, sum(quantity * unit_cost) as cost')
            ->groupBy('product_id', 'name')->orderByDesc('revenue')->limit(20)->get()
            ->map(fn ($r) => [
                'product' => $r->name,
                'quantity' => round((float) $r->qty, 3),
                'revenue' => round((float) $r->revenue, 3),
                'profit' => round((float) $r->revenue - (float) $r->cost, 3),
            ])->all();
    }
}
