<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Audit\AuditLogger;
use App\Core\Papers\Papers;
use App\Core\Support\Money;
use App\Core\Support\Period;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\Member;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Models\SaleItem;
use App\Modules\Inventory\Services\PosDevices;
use App\Modules\Reports\Support\ReportDocument;
use App\Modules\Reports\Support\ReportSheet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The sales section: one list of every sale from both sources — the point of sale and website orders — with its period,
 * search, filters, sort and export; each sale's own page (its receipt, a void, a website order's preparation, hand-over
 * and cancellation); and the printed receipt. The summary, the best sellers and the split by source are the sales report
 * on the reports page. Counted sales are completed ones (paid and handed over); voided and cancelled ones are listed but
 * not summed. Whoever only handles website orders (orders.manage) sees those, and nothing from the till.
 */
class SalesController extends Controller
{
    public const SOURCE_LABELS = [Sale::POS => 'نقطة البيع', Sale::WEBSITE => 'الموقع الإلكتروني'];

    public const STATUS_LABELS = [Sale::PENDING => 'قيد التنفيذ', Sale::COMPLETED => 'مكتملة', Sale::VOIDED => 'ملغاة ومستردة', Sale::CANCELLED => 'ملغاة'];

    /** The list's states: a website order's step while it is open, then how the sale ended. */
    public const STATES = ['new' => 'جديد', 'preparing' => 'قيد التجهيز', 'ready' => 'جاهز للاستلام', 'completed' => 'مكتملة', 'voided' => 'ملغاة ومستردة', 'cancelled' => 'ملغاة'];

    public const METHOD_LABELS = Payment::METHOD_LABELS + ['pickup' => 'الدفع عند الاستلام'];

    /** The columns the list sorts on, and what each sorts by. */
    private const SORTS = ['number' => 'id', 'date' => 'created_at', 'total' => 'total'];

    public function index(Request $request): Response
    {
        $user = $request->user();
        $period = Period::fromRequest($request);
        $sales = $this->filtered($request, $period)->with(['cashier:id,name', 'branch:id,name', 'items', 'customer']);

        $sort = array_key_exists((string) $request->query('sort'), self::SORTS) ? (string) $request->query('sort') : null;
        $sales = $sort ? $sales->orderBy(self::SORTS[$sort], $request->query('dir') === 'asc' ? 'asc' : 'desc')->orderByDesc('id') : $sales->latest('id');

        // Across everything filtered, not the page in view: what was sold, what was cancelled, and the website's share.
        $totals = $this->filtered($request, $period)->toBase()->selectRaw(
            "count(*) as n,
             coalesce(sum(case when status = 'completed' then total end), 0) as sold,
             count(case when status in ('voided', 'cancelled') then 1 end) as cancelled,
             count(case when source = 'website' then 1 end) as website_n,
             coalesce(sum(case when source = 'website' and status = 'completed' then total end), 0) as website_sold"
        )->first();

        return Inertia::render('Sales/Index', [
            'sales' => $sales->paginate(25)->withQueryString()->through(fn (Sale $s) => self::row($s)),
            'period' => $period->toArray(),
            'filters' => $request->only('q', 'source', 'status', 'branch', 'method', 'sort', 'dir'),
            'totals' => [
                'count' => (int) $totals->n, 'sold' => Money::normalize($totals->sold), 'cancelled' => (int) $totals->cancelled,
                'website_count' => (int) $totals->website_n, 'website_sold' => Money::normalize($totals->website_sold),
            ],
            'options' => [
                'sources' => $this->websiteOnly($user) ? [] : self::SOURCE_LABELS,
                'states' => self::STATES,
                'methods' => array_intersect_key(self::METHOD_LABELS, array_flip(['cash', 'card', 'paymob', 'pickup', 'bank_transfer'])),
                // A branch filter for whoever sees the whole club with more than one branch; one tied to a branch sees theirs.
                'branches' => app(BranchContext::class)->id($user) === null && app(BranchContext::class)->choices(true, $user)->count() > 1
                    ? app(BranchContext::class)->choices(true, $user)->pluck('name', 'id') : [],
            ],
            'can' => [
                'refund' => $user->can('sales.refund'),
                'orders' => $user->can('orders.manage'),
                'export' => $user->can('reports.export'),
                'pos' => $user->can('pos.sell'),
                'websiteOnly' => $this->websiteOnly($user),
            ],
        ]);
    }

    /** The list as a spreadsheet: the same period, search and filters as the screen, every row (not one page). */
    public function export(Request $request, AuditLogger $audit, TenantContext $tenants): StreamedResponse
    {
        abort_unless($request->user()->can('reports.export'), 403);
        $format = $request->string('format')->toString() === 'xlsx' ? 'xlsx' : 'csv';
        $period = Period::fromRequest($request);
        $rows = $this->filtered($request, $period)->with(['cashier:id,name', 'branch:id,name', 'items', 'customer'])->latest('id')->limit(20000)->get();

        $sheet = new ReportSheet('عمليات البيع', [
            ['الرقم', ReportSheet::TEXT], ['التاريخ', ReportSheet::TEXT], ['العميل', ReportSheet::TEXT], ['الهاتف', ReportSheet::TEXT], ['المنتجات', ReportSheet::TEXT],
            ['المصدر', ReportSheet::TEXT], ['الموظف', ReportSheet::TEXT], ['الفرع', ReportSheet::TEXT], ['طريقة الدفع', ReportSheet::TEXT],
            ['الخصم', ReportSheet::MONEY], ['الإجمالي', ReportSheet::MONEY], ['الحالة', ReportSheet::TEXT],
        ], $rows->map(function (Sale $s) {
            $r = self::row($s);

            return [$r['number'], $r['at'], $r['buyer'], $r['phone'] ?? '', $s->items->map(fn (SaleItem $i) => $i->name.' × '.(float) $i->quantity)->implode('، '),
                $r['source_label'], $r['cashier'] ?? '', $r['branch'] ?? '', $r['method_label'], $r['discount'], $r['total'], $r['state_label']];
        })->all());

        $meta = [['الفترة', $period->label()]];
        foreach (['source' => self::SOURCE_LABELS, 'status' => self::STATES, 'method' => self::METHOD_LABELS] as $key => $labels) {
            if ($label = $labels[(string) $request->query($key)] ?? null) {
                $meta[] = [['source' => 'المصدر', 'status' => 'الحالة', 'method' => 'طريقة الدفع'][$key], $label];
            }
        }
        if ($q = $request->string('q')->trim()->toString()) {
            $meta[] = ['البحث', $q];
        }
        $meta[] = ['تاريخ الإصدار', now()->format('Y-m-d H:i')];
        $slug = 'sales-'.($period->bounded() ? ($period->from?->toDateString() ?? 'start').'_'.($period->to?->toDateString() ?? today()->toDateString()) : 'all');
        $audit->log('sales.exported', null, ['format' => $format] + $period->toArray());

        return (new ReportDocument($slug, 'عمليات البيع', $meta, [$sheet], $rows->isEmpty(), $tenants->require()->currency ?? 'OMR'))->download($format);
    }

    /** One sale: its lines, the buyer, the money, and what may be done with it from here. */
    public function show(Request $request, Sale $sale): Response
    {
        $user = $request->user();
        self::ensureBranch($user, $sale);
        abort_if($this->websiteOnly($user) && $sale->source !== Sale::WEBSITE, 403);
        $sale->load(['items.addons', 'customer', 'cashier:id,name', 'deliveredBy:id,name', 'branch:id,name', 'posDevice:id,name', 'invoice.payments.receiver:id,name']);
        $member = $sale->customer instanceof Member ? $sale->customer : null;

        return Inertia::render('Sales/Show', [
            'sale' => self::row($sale) + [
                'subtotal' => $sale->subtotal,
                'tax' => $sale->tax,
                'email' => $sale->customer_email ?? $member?->email,
                'notes' => $sale->notes,
                'device' => $sale->posDevice?->name,
                'reserved_until' => $sale->reserved_until?->format('Y-m-d H:i'),
                'delivered_at' => $sale->delivered_at?->format('Y-m-d H:i'),
                'delivered_by' => $sale->deliveredBy?->name,
                'voided_at' => $sale->voided_at?->format('Y-m-d H:i'),
                'cancelled_at' => $sale->cancelled_at?->format('Y-m-d H:i'),
                'cancel_reason' => $sale->cancel_reason,
                'member' => $member && $user->can('members.view') ? ['id' => $member->id, 'member_no' => $member->member_no] : null,
                'items' => $sale->items->map(fn (SaleItem $i) => $i->summary())->all(),
                'invoice' => $sale->invoice ? $sale->invoice->only('id', 'number', 'status', 'total', 'paid_total') + ['balance' => $sale->invoice->balance()] : null,
                'payments' => $sale->invoice?->payments->map(fn (Payment $p) => $p->only('id', 'receipt_number', 'kind', 'method', 'status', 'amount', 'reference') + [
                    'method_label' => Payment::METHOD_LABELS[$p->method] ?? $p->method, 'paid_at' => $p->paid_at?->format('Y-m-d H:i'), 'receiver' => $p->receiver?->name,
                ])->values()->all() ?? [],
            ],
            'can' => [
                'refund' => $user->can('sales.refund'),
                'orders' => $user->can('orders.manage'),
                'invoices' => $user->can('invoices.view'),
                'print' => $user->can('papers.print'),
            ],
            'printOrders' => (bool) (($printer = app(PosDevices::class)->current($request)?->load('peripherals')->printer())?->print_website_orders ?? false),
            // This device's receipt printer, for the printing layer (direct in the app, the dialog elsewhere).
            'printer' => $printer?->forPrinting(),
        ]);
    }

    /** The old addresses of the list (and of the summary, now the sales report): they open what replaced them. */
    public function legacyList(Request $request): RedirectResponse
    {
        return redirect()->route('club.sales.index', $request->query(), 301);
    }

    public function summary(Request $request): RedirectResponse
    {
        return redirect()->route('club.reports.show', ['report' => 'sales'] + $request->only('from', 'to', 'preset', 'range'));
    }

    /** طلبات الموقع: the same list, on website orders still open (the steps are on each order's page). */
    public function orders(Request $request): RedirectResponse
    {
        $tab = (string) $request->query('tab', 'open');

        return redirect()->route('club.sales.index', array_filter([
            'source' => Sale::WEBSITE,
            'status' => ['open' => 'open', 'completed' => 'completed', 'cancelled' => 'cancelled'][$tab] ?? null,
            'q' => $request->query('q'),
        ]));
    }

    public function reports(Request $request): RedirectResponse
    {
        return redirect()->route('club.reports.show', ['report' => 'sales'] + $request->query());
    }

    public function reportExport(Request $request): RedirectResponse
    {
        return redirect()->route('club.reports.export', ['report' => 'sales'] + $request->query());
    }

    /**
     * The sale's receipt: the thermal strip at the width of the printer here (this browser's device), else of the
     * device it was sold on, else the club's template setting; ?face=sheet gives the A4/A5 invoice instead.
     */
    public function receipt(Request $request, Sale $sale, PosDevices $devices): HttpResponse
    {
        abort_unless($request->user()->canAny(['pos.sell', 'sales.view', 'orders.manage']), 403);
        $this->ensureBranch($request->user(), $sale);
        if ($request->string('face')->toString() === 'sheet') {
            return Papers::for('sale', $sale)->respond($request);
        }
        // The paper of the printer here (this browser's device), else of the device it was sold on: a strip, or a
        // sheet when that printer feeds A4 or A5.
        $paper = ($devices->current($request) ?? $sale->posDevice)?->loadMissing('peripherals')->printer()?->paperSize();

        return Papers::for('sale', $sale, null, 'strip', $paper)->respond($request);
    }

    /** @return array<string, mixed> */
    public static function row(Sale $s): array
    {
        $state = $s->status === Sale::PENDING ? ($s->fulfillment && $s->fulfillment !== 'delivered' ? $s->fulfillment : 'new') : $s->status;

        return $s->only('id', 'number', 'source', 'status', 'fulfillment', 'total', 'discount', 'payment_method', 'invoice_id') + [
            'buyer' => $s->buyerName(),
            'phone' => $s->customer_phone ?? ($s->customer instanceof Member ? $s->customer->phone : null),
            'member_id' => $s->customer instanceof Member ? $s->customer->id : null,
            'at' => $s->created_at->format('Y-m-d H:i'),
            'cashier' => $s->source === Sale::WEBSITE ? null : $s->cashier?->name,
            'branch' => $s->relationLoaded('branch') ? $s->branch?->name : null,
            'paid' => $s->source === Sale::POS ? $s->status !== Sale::CANCELLED : $s->paid_at !== null,
            'items_count' => $s->relationLoaded('items') ? $s->items->count() : null,
            'items_preview' => $s->relationLoaded('items') ? $s->items->take(2)->map(fn (SaleItem $i) => $i->name.' × '.(float) $i->quantity)->implode('، ') : null,
            'state' => $state,
            'state_label' => self::STATES[$state] ?? $s->status,
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

    /** The list's rows before sorting: the user's sales in the period, by the search and the filters in the address. */
    private function filtered(Request $request, Period $period): Builder
    {
        $user = $request->user();
        $query = self::scopedFor($user);
        $period->apply($query, 'created_at');

        if ($this->websiteOnly($user)) {
            $query->where('source', Sale::WEBSITE);
        } elseif (in_array($request->query('source'), [Sale::POS, Sale::WEBSITE], true)) {
            $query->where('source', $request->query('source'));
        }

        match ((string) $request->query('status')) {
            'new' => $query->where('status', Sale::PENDING)->where(fn ($w) => $w->whereNull('fulfillment')->orWhereIn('fulfillment', ['new', 'delivered'])),
            'preparing', 'ready' => $query->where('status', Sale::PENDING)->where('fulfillment', $request->query('status')),
            // Every open website order (the old orders page's first tab), and the raw statuses of older links.
            'open', Sale::PENDING => $query->where('status', Sale::PENDING),
            Sale::COMPLETED, Sale::VOIDED, Sale::CANCELLED => $query->where('status', $request->query('status')),
            default => null,
        };

        if (is_numeric($request->query('branch')) && app(BranchContext::class)->id($user) === null) {
            $query->where('branch_id', (int) $request->query('branch'));
        }
        if (array_key_exists((string) $request->query('method'), self::METHOD_LABELS)) {
            $query->where('payment_method', $request->query('method'));
        }

        if ($term = $request->string('q')->trim()->toString()) {
            $digits = preg_replace('/\D/', '', $term);
            $query->where(fn ($w) => $w->where('number', 'ilike', "%{$term}%")
                ->orWhere('customer_name', 'ilike', "%{$term}%")
                ->when($digits !== '', fn ($x) => $x->orWhere('customer_phone', 'like', "%{$digits}%"))
                ->orWhereHas('cashier', fn ($c) => $c->where('name', 'ilike', "%{$term}%"))
                ->orWhereHasMorph('customer', [Member::class], fn ($m) => $m->where('full_name', 'ilike', "%{$term}%")
                    ->when($digits !== '', fn ($x) => $x->orWhere('phone', 'like', "%{$digits}%"))
                    ->orWhere('member_no', 'ilike', "%{$term}%")));
        }

        return $query;
    }

    /** Whoever handles website orders but does not follow sales sees the orders only. */
    private function websiteOnly(User $user): bool
    {
        return ! $user->can('sales.view');
    }
}
