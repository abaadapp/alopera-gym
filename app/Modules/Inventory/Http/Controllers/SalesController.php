<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Papers\Papers;
use App\Core\Tenancy\BranchContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Finance\Models\Payment;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Models\SaleItem;
use App\Modules\Inventory\Services\PosDevices;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The sales section: summary, the list of sales from both sources and the printed receipt (the sales report is on
 * the reports page).
 * Counted sales are completed ones (paid and handed over); voided and cancelled ones are listed but not summed.
 */
class SalesController extends Controller
{
    public const SOURCE_LABELS = [Sale::POS => 'نقطة البيع', Sale::WEBSITE => 'الموقع الإلكتروني'];

    public const STATUS_LABELS = [Sale::PENDING => 'قيد التنفيذ', Sale::COMPLETED => 'مكتملة', Sale::VOIDED => 'ملغاة ومستردة', Sale::CANCELLED => 'ملغاة'];

    public const METHOD_LABELS = Payment::METHOD_LABELS + ['pickup' => 'الدفع عند الاستلام'];

    public function summary(Request $request): Response
    {
        [$from, $to] = $this->period($request);
        $done = $this->scoped($request)->where('status', Sale::COMPLETED)->whereBetween('created_at', [$from, $to->endOfDay()]);

        $totals = (clone $done)->toBase()->selectRaw('count(*) as n, coalesce(sum(total), 0) as total')->first();
        $today = $this->scoped($request)->where('status', Sale::COMPLETED)->whereDate('created_at', today())->sum('total');
        $bySource = (clone $done)->toBase()->select('source', DB::raw('count(*) as n'), DB::raw('sum(total) as total'))->groupBy('source')->get()
            ->map(fn ($r) => ['source' => $r->source, 'label' => self::SOURCE_LABELS[$r->source] ?? $r->source, 'count' => (int) $r->n, 'total' => $this->m($r->total)]);

        return Inertia::render('Sales/Summary', [
            'period' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'stats' => [
                'total' => $this->m($totals->total),
                'count' => (int) $totals->n,
                'average' => $totals->n ? $this->m($totals->total / $totals->n) : '0.000',
                'today' => $this->m($today),
                'open_orders' => $this->scoped($request)->where('source', Sale::WEBSITE)->where('status', Sale::PENDING)->count(),
                'low_stock' => Product::where('is_active', true)->lowStock()->count(),
                'on_website' => Product::onWebsite()->count(),
            ],
            'bySource' => $bySource,
            'topProducts' => $this->topProducts($request, $from, $to, 5),
            'recent' => $this->scoped($request)->with(['customer', 'cashier:id,name'])->latest('id')->limit(8)->get()->map(fn (Sale $s) => $this->row($s)),
        ]);
    }

    public function index(Request $request): Response
    {
        $sales = $this->scoped($request)->with(['cashier:id,name', 'deliveredBy:id,name', 'items.addons', 'customer'])
            ->when($request->string('q')->trim()->toString(), fn ($q, $t) => $q->where(fn ($w) => $w->where('number', 'ilike', "%{$t}%")->orWhere('customer_name', 'ilike', "%{$t}%")->orWhere('customer_phone', 'ilike', "%{$t}%")))
            ->when(in_array($request->query('source'), [Sale::POS, Sale::WEBSITE], true), fn ($q) => $q->where('source', $request->query('source')))
            ->when(array_key_exists((string) $request->query('status'), self::STATUS_LABELS), fn ($q) => $q->where('status', $request->query('status')))
            ->when($request->date('from'), fn ($q, $d) => $q->whereDate('created_at', '>=', $d))
            ->when($request->date('to'), fn ($q, $d) => $q->whereDate('created_at', '<=', $d))
            ->latest('id')->paginate(30)->withQueryString()
            ->through(fn (Sale $s) => $this->row($s) + ['items' => $s->items->map(fn (SaleItem $i) => $i->summary())]);

        return Inertia::render('Inventory/Sales', [
            'sales' => $sales,
            'filters' => $request->only('q', 'source', 'status', 'from', 'to'),
            'labels' => ['sources' => self::SOURCE_LABELS, 'statuses' => self::STATUS_LABELS],
            'can' => ['refund' => $request->user()->can('sales.refund')],
        ]);
    }

    /** The sales report lives on the reports page now (ReportCatalog «sales»); an old link opens it. */
    public function reports(Request $request): RedirectResponse
    {
        return redirect()->route('club.reports.show', ['report' => 'sales'] + $request->query());
    }

    public function export(Request $request): RedirectResponse
    {
        return redirect()->route('club.reports.export', ['report' => 'sales'] + $request->query());
    }

    /** A sale's printable receipt: for whoever sells or follows sales. */
    /**
     * The sale's receipt: the thermal strip at the width of the printer here (this browser's device), else of the
     * device it was sold on, else the club's template setting; ?face=sheet gives the A4/A5 invoice instead.
     */
    public function receipt(Request $request, Sale $sale, PosDevices $devices): HttpResponse
    {
        abort_unless($request->user()->canAny(['pos.sell', 'sales.view', 'orders.manage']), 403);
        $this->ensureBranch($request->user(), $sale);
        if ($request->string('face')->toString() === 'sheet') {
            return Papers::for('sale', $sale)->page($request->boolean('print', true));
        }
        $width = ($devices->current($request) ?? $sale->posDevice)?->loadMissing('peripherals')->printer()?->paper_width;

        return Papers::for('sale', $sale, null, 'strip', $width ? $width.'mm' : null)->page($request->boolean('print', true));
    }

    /** @return array<string, mixed> */
    public static function row(Sale $s): array
    {
        return $s->only('id', 'number', 'source', 'status', 'fulfillment', 'total', 'discount', 'payment_method', 'invoice_id') + [
            'buyer' => $s->buyerName(),
            'at' => $s->created_at->format('Y-m-d H:i'),
            'cashier' => $s->cashier?->name,
            'paid' => $s->source === Sale::POS ? $s->status !== Sale::CANCELLED : $s->paid_at !== null,
            'source_label' => self::SOURCE_LABELS[$s->source] ?? $s->source,
            'status_label' => self::STATUS_LABELS[$s->status] ?? $s->status,
            'method_label' => self::METHOD_LABELS[$s->payment_method] ?? $s->payment_method,
        ];
    }

    /** Sales this user may see: all of the club's, or only their branch's when they are assigned to one. */
    public static function scopedFor(User $user): Builder
    {
        // The user's branch when tied to one, otherwise the branch chosen in the top bar (or all of them).
        return app(BranchContext::class)->apply(Sale::query(), 'branch_id', $user);
    }

    /** The route guard (EnforceBranchAccess) already does this for a sale named in the address; kept for callers. */
    public static function ensureBranch(User $user, Sale $sale): void
    {
        abort_unless(app(BranchContext::class)->allows($user, $sale->owningBranchId()), 404);
    }

    private function scoped(Request $request): Builder
    {
        return self::scopedFor($request->user());
    }

    /** @return list<array<string, mixed>> */
    private function topProducts(Request $request, CarbonImmutable $from, CarbonImmutable $to, int $limit): array
    {
        $ids = $this->scoped($request)->where('status', Sale::COMPLETED)->whereBetween('created_at', [$from, $to->endOfDay()])->select('id');

        return DB::table('sale_items')->whereIn('sale_id', $ids)
            ->select('name', DB::raw('sum(quantity) as qty'), DB::raw('sum(line_total) as total'))
            ->groupBy('name')->orderByDesc('total')->limit($limit)->get()
            ->map(fn ($r) => ['name' => $r->name, 'quantity' => (float) $r->qty, 'total' => $this->m($r->total)])->all();
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function period(Request $request): array
    {
        $from = $request->date('from')?->toImmutable() ?? CarbonImmutable::today()->startOfMonth();
        $to = $request->date('to')?->toImmutable() ?? CarbonImmutable::today();

        return $from->greaterThan($to) ? [$to->startOfDay(), $from->startOfDay()] : [$from->startOfDay(), $to->startOfDay()];
    }

    private function m(mixed $value): string
    {
        return number_format((float) $value, 3, '.', '');
    }
}
