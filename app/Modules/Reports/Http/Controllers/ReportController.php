<?php

namespace App\Modules\Reports\Http\Controllers;

use App\Core\Auth\PermissionRegistry;
use App\Core\Export\TableExporter;
use App\Http\Controllers\Controller;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Membership;
use App\Modules\Inventory\Models\SaleItem;
use App\Modules\Reports\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

class ReportController extends Controller
{
    public function index(Request $request, ReportService $reports): Response
    {
        [$from, $to] = $this->range($request);
        [$compareFrom, $compareTo, $compare] = $this->comparison($request, $from, $to);

        return Inertia::render('Reports/Index', [
            'report' => $reports->period($from, $to, $request->user()->can('reports.financial'), $compareFrom, $compareTo),
            'filters' => [
                'from' => $from->toDateString(), 'to' => $to->toDateString(), 'preset' => $request->string('preset')->toString(),
                'compare' => $compare, 'compare_from' => $compareFrom->toDateString(), 'compare_to' => $compareTo->toDateString(),
            ],
            'canFinancial' => $request->user()->can('reports.financial'),
            'canExport' => $request->user()->can('reports.export'),
        ]);
    }

    public function export(Request $request, string $report): HttpResponse
    {
        [$from, $to] = $this->range($request);
        $format = $request->string('format')->toString() === 'xlsx' ? 'xlsx' : 'csv';
        $end = $to->endOfDay();

        if (in_array($report, ['revenue', 'expenses', 'sales'], true)) {
            abort_unless($request->user()->can('reports.financial'), 403);
        }
        abort_if($report === 'sales' && ! PermissionRegistry::moduleEnabled('inventory'), 404);

        return match ($report) {
            'memberships' => TableExporter::download('memberships', ['الرقم', 'المشتركة', 'الباقة', 'النوع', 'الحالة', 'من', 'إلى', 'الإجمالي', 'تاريخ التفعيل'],
                (function () use ($from, $end) {
                    foreach (Membership::with('member:id,full_name,member_no')->whereBetween('created_at', [$from, $end])->orderBy('id')->lazy(500) as $m) {
                        yield [$m->member?->member_no, $m->member?->full_name, $m->plan_snapshot['name'] ?? '', Membership::KIND_LABELS[$m->kind] ?? $m->kind, Membership::STATUS_LABELS[$m->status] ?? $m->status, $m->starts_on->toDateString(), $m->ends_on->toDateString(), $m->total, $m->activated_at?->format('Y-m-d H:i')];
                    }
                })(), $format),
            'revenue' => TableExporter::download('revenue', ['الإيصال', 'التاريخ', 'النوع', 'الطريقة', 'المبلغ', 'الفاتورة', 'العميل', 'المرجع'],
                (function () use ($from, $end) {
                    foreach (Payment::with('invoice:id,number,customer_name')->completed()->whereBetween('paid_at', [$from, $end])->orderBy('paid_at')->lazy(500) as $p) {
                        yield [$p->receipt_number, $p->paid_at?->format('Y-m-d H:i'), Payment::KIND_LABELS[$p->kind] ?? $p->kind, Payment::METHOD_LABELS[$p->method] ?? $p->method, $p->kind === 'refund' ? -1 * (float) $p->amount : (float) $p->amount, $p->invoice?->number, $p->invoice?->customer_name, $p->reference];
                    }
                })(), $format),
            'classes' => TableExporter::download('classes', ['التاريخ', 'الوقت', 'البرنامج', 'المدربة', 'السعة', 'المحجوز', 'الحالة'],
                (function () use ($from, $end) {
                    foreach (ClassSession::with(['program:id,name', 'trainer:id,name'])->whereBetween('starts_at', [$from, $end])->orderBy('starts_at')->lazy(500) as $s) {
                        yield [$s->starts_at->toDateString(), $s->starts_at->format('H:i'), $s->program?->name, $s->trainer?->name, $s->capacity, $s->booked_count, ClassSession::STATUS_LABELS[$s->status] ?? $s->status];
                    }
                })(), $format),
            'sales' => TableExporter::download('sales', ['التاريخ', 'رقم العملية', 'المنتج', 'الكمية', 'سعر الوحدة', 'الإجمالي', 'التكلفة'],
                (function () use ($from, $end) {
                    foreach (SaleItem::with('sale:id,number,created_at,status')->whereHas('sale', fn ($q) => $q->where('status', 'completed')->whereBetween('created_at', [$from, $end]))->orderBy('id')->lazy(500) as $i) {
                        yield [$i->sale?->created_at->format('Y-m-d H:i'), $i->sale?->number, $i->name, $i->quantity, $i->unit_price, $i->line_total, round((float) $i->quantity * (float) $i->unit_cost, 3)];
                    }
                })(), $format),
            'expenses' => TableExporter::download('expenses', ['الرقم', 'التاريخ', 'التصنيف', 'الوصف', 'المبلغ'],
                (function () use ($from, $to) {
                    foreach (Expense::with('category:id,name')->whereBetween('expense_date', [$from, $to])->orderBy('expense_date')->lazy(500) as $e) {
                        yield [$e->number, $e->expense_date->toDateString(), $e->category?->name, $e->description, $e->amount];
                    }
                })(), $format),
            default => redirect()->route('club.members.export', ['format' => $format]),
        };
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(Request $request): array
    {
        $today = CarbonImmutable::today();

        [$from, $to] = match ($request->string('preset')->toString()) {
            'today' => [$today, $today],
            'week' => [$today->startOfWeek(CarbonImmutable::SATURDAY), $today],
            'last_month' => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            'quarter' => [$today->firstOfQuarter(), $today],
            'year' => [$today->startOfYear(), $today],
            // a malformed date in the URL falls back to the default instead of failing
            default => [
                rescue(fn () => CarbonImmutable::parse($request->string('from')->toString() ?: $today->startOfMonth()->toDateString())->startOfDay(), $today->startOfMonth(), false),
                rescue(fn () => CarbonImmutable::parse($request->string('to')->toString() ?: $today->toDateString())->startOfDay(), $today, false),
            ],
        };

        if ($from->gt($to)) {
            [$from, $to] = [$to, $from];
        }

        // keep reports to at most two years per query
        return [$from->max($to->subYears(2)), $to];
    }

    /**
     * The period every figure is compared with. Default: the same number of days just before.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable, 2: string}
     */
    private function comparison(Request $request, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $days = (int) $from->diffInDays($to) + 1;
        $previous = [$from->subDays($days), $from->subDay(), 'previous'];

        return match ($request->string('compare')->toString()) {
            'month' => [$from->subMonthNoOverflow(), $to->subMonthNoOverflow(), 'month'],
            'year' => [$from->subYearNoOverflow(), $to->subYearNoOverflow(), 'year'],
            'custom' => rescue(function () use ($request) {
                $a = CarbonImmutable::parse($request->string('compare_from')->toString())->startOfDay();
                $b = CarbonImmutable::parse($request->string('compare_to')->toString())->startOfDay();
                [$a, $b] = $a->gt($b) ? [$b, $a] : [$a, $b];

                return [$a->max($b->subYears(2)), $b, 'custom'];
            }, $previous, false),
            default => $previous,
        };
    }
}
