<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Papers\Papers;
use App\Core\Support\Money;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Http\Controllers\Concerns\ScopesBranches;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\SupplierBill;
use App\Modules\Inventory\Services\PurchaseService;
use App\Modules\Inventory\Services\SupplierBillService;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The purchases section: the register of what was bought (orders, and supplier bills without an order — so nothing
 * is counted twice), purchase orders from draft to received, their print-out, and receiving goods against them.
 */
class PurchaseOrderController extends Controller
{
    use ScopesBranches;

    public function __construct(private readonly PurchaseService $purchases) {}

    /** قائمة المشتريات: a month (or all) of orders and of bills that have no order. */
    public function register(Request $request): Response
    {
        $branch = $this->listBranch($request);
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month')) ? $request->query('month') : ($request->query('month') === 'all' ? 'all' : now()->format('Y-m'));
        [$from, $to] = $month === 'all' ? [null, null] : [CarbonImmutable::parse($month.'-01'), CarbonImmutable::parse($month.'-01')->endOfMonth()];
        $supplier = ctype_digit((string) $request->query('supplier')) ? (int) $request->query('supplier') : null;
        $narrow = fn ($q) => app(BranchContext::class)->narrow($q, $branch);

        $orders = $narrow(PurchaseOrder::query())->with(['supplier:id,name', 'branch:id,name', 'bills' => fn ($q) => $q->whereIn('status', [SupplierBill::PENDING, SupplierBill::APPROVED])])
            ->where('status', '!=', PurchaseOrder::CANCELLED)
            ->when($from, fn ($q) => $q->whereBetween('order_date', [$from, $to]))->when($supplier, fn ($q, $s) => $q->where('supplier_id', $s))->get();
        $received = DB::table('goods_receipts')->whereIn('purchase_order_id', $orders->pluck('id'))->where('status', GoodsReceipt::APPROVED)->groupBy('purchase_order_id')->selectRaw('purchase_order_id, sum(total) as t')->pluck('t', 'purchase_order_id');
        $bills = $narrow(SupplierBill::query())->with(['supplier:id,name'])->whereNull('purchase_order_id')->whereIn('status', [SupplierBill::PENDING, SupplierBill::APPROVED])
            ->when($from, fn ($q) => $q->whereBetween('issue_date', [$from, $to]))->when($supplier, fn ($q, $s) => $q->where('supplier_id', $s))->get();

        $rows = $orders->map(fn (PurchaseOrder $o) => [
            'key' => 'po-'.$o->id, 'source' => 'order', 'id' => $o->id, 'date' => $o->order_date->toDateString(), 'reference' => $o->number,
            'supplier' => $o->supplier?->name, 'branch' => $o->branch?->name, 'status' => $o->status, 'status_label' => PurchaseOrder::LABELS[$o->status] ?? $o->status,
            'total' => $o->total, 'received' => Money::normalize($received[$o->id] ?? 0),
            'paid' => $o->bills->where('status', SupplierBill::APPROVED)->sum(fn ($b) => (float) $b->paid_total), 'due' => $o->bills->sum(fn ($b) => (float) $b->balance()),
            'billed' => $o->bills->isNotEmpty(),
        ])->concat($bills->map(fn (SupplierBill $b) => [
            'key' => 'sb-'.$b->id, 'source' => 'bill', 'id' => $b->id, 'date' => $b->issue_date->toDateString(), 'reference' => $b->reference,
            'supplier' => $b->supplier?->name, 'branch' => null, 'status' => $b->status, 'status_label' => $b->status === SupplierBill::APPROVED ? 'سند معتمد' : 'سند بانتظار الاعتماد',
            'total' => $b->total, 'received' => null, 'paid' => (float) $b->paid_total, 'due' => (float) $b->balance(), 'billed' => true,
        ]))->sortByDesc('date')->values();

        return Inertia::render('Inventory/Purchases/Register', [
            'rows' => $rows,
            'summary' => [
                'total' => round($rows->sum(fn ($r) => (float) $r['total']), 3),
                'count' => $rows->count(),
                'due' => round((float) SupplierBill::where('status', SupplierBill::APPROVED)->sum(DB::raw('total - paid_total')), 3),
            ],
            'filters' => ['month' => $month, 'supplier' => $supplier ? (string) $supplier : ''],
            'months' => collect(range(0, 11))->map(fn ($i) => now()->startOfMonth()->subMonths($i)->format('Y-m'))->all(),
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
            'branch' => $this->branchFilter($request, $branch),
        ]);
    }

    public function index(Request $request): Response
    {
        $branch = $this->listBranch($request);
        $status = array_key_exists((string) $request->query('status'), PurchaseOrder::LABELS) ? $request->query('status') : '';
        $q = $request->string('q')->trim()->toString();
        $base = fn () => app(BranchContext::class)->narrow(PurchaseOrder::query(), $branch)
            ->when($q, fn ($w) => $w->where(fn ($x) => $x->where('number', 'ilike', '%'.$q.'%')->orWhere('supplier_reference', 'ilike', '%'.$q.'%')->orWhereHas('supplier', fn ($s) => $s->where('name', 'ilike', '%'.$q.'%'))));
        $counts = $base()->toBase()->selectRaw("count(*) as total, count(*) filter (where status in ('draft','ordered','partial')) as open, count(*) filter (where status = 'received') as received, coalesce(sum(total) filter (where status in ('ordered','partial')), 0) as awaiting")->first();

        return Inertia::render('Inventory/Purchases/Orders', [
            'orders' => $base()->with(['supplier:id,name', 'branch:id,name'])->withCount('items')
                ->when($status, fn ($w, $s) => $w->where('status', $s))->latest('id')->paginate(25)->withQueryString()
                ->through(fn (PurchaseOrder $o) => $o->only('id', 'number', 'status', 'total', 'items_count') + [
                    'status_label' => PurchaseOrder::LABELS[$o->status] ?? $o->status, 'supplier' => $o->supplier?->name, 'branch' => $o->branch?->name,
                    'order_date' => $o->order_date->toDateString(), 'expected_on' => $o->expected_on?->toDateString(),
                ]),
            'summary' => ['total' => (int) $counts->total, 'open' => (int) $counts->open, 'received' => (int) $counts->received, 'awaiting' => round((float) $counts->awaiting, 3)],
            // Products at or below their reorder level: a starting point for the next order.
            'reorder' => Product::where('track_stock', true)->where('is_active', true)->lowStock()->orderBy('name')->limit(8)->get(['id', 'name', 'stock_quantity', 'reorder_level'])
                ->map(fn ($p) => $p->only('id', 'name', 'stock_quantity', 'reorder_level') + ['suggested' => max(1, (int) ceil(2 * (float) $p->reorder_level - (float) $p->stock_quantity))]),
            'filters' => ['status' => $status, 'q' => $q],
            'labels' => PurchaseOrder::LABELS,
            'branch' => $this->branchFilter($request, $branch),
        ]);
    }

    public function create(Request $request): Response
    {
        $lines = [];
        if ($request->query('from') === 'reorder') {
            $lines = Product::where('track_stock', true)->where('is_active', true)->lowStock()->orderBy('name')->get()
                ->map(fn ($p) => ['product_id' => $p->id, 'quantity' => max(1, (int) ceil(2 * (float) $p->reorder_level - (float) $p->stock_quantity)), 'unit_cost' => $p->cost, 'unit' => null, 'unit_factor' => 1])->all();
        }

        return $this->form(null, $lines);
    }

    public function edit(PurchaseOrder $purchaseOrder): Response|RedirectResponse
    {
        if ($purchaseOrder->status !== PurchaseOrder::DRAFT) {
            return redirect()->route('club.purchases.show', $purchaseOrder)->with('error', 'لا يُعدّل إلا أمر الشراء المسودة.');
        }

        return $this->form($purchaseOrder->load('items'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $order = $this->purchases->create(Supplier::findOrFail($data['supplier_id']), $data['items'], $data);
        if ($request->boolean('approve') && $request->user()->can('purchases.approve')) {
            $this->purchases->approve($order);
        }

        return redirect()->route('club.purchases.show', $order)->with('success', 'أُنشئ أمر الشراء '.$order->number.'.');
    }

    public function update(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $data = $this->validated($request);
        $this->purchases->update($purchaseOrder, Supplier::findOrFail($data['supplier_id']), $data['items'], $data);
        if ($request->boolean('approve') && $request->user()->can('purchases.approve')) {
            $this->purchases->approve($purchaseOrder);
        }

        return redirect()->route('club.purchases.show', $purchaseOrder)->with('success', 'حُفظ أمر الشراء '.$purchaseOrder->number.'.');
    }

    public function show(PurchaseOrder $purchaseOrder): Response
    {
        $o = $purchaseOrder->load(['supplier', 'branch:id,name', 'creator:id,name', 'approver:id,name', 'items.product:id,name,sku', 'receipts.receiver:id,name', 'receipts.approver:id,name', 'bills']);
        $pending = DB::table('goods_receipt_lines as l')->join('goods_receipts as r', 'r.id', '=', 'l.goods_receipt_id')->where('r.purchase_order_id', $o->id)->where('r.status', GoodsReceipt::PENDING)
            ->groupBy('l.purchase_order_item_id')->selectRaw('l.purchase_order_item_id, sum(l.quantity) as q')->pluck('q', 'purchase_order_item_id');

        return Inertia::render('Inventory/Purchases/OrderShow', [
            'order' => $this->present($o) + [
                'items' => $o->items->map(fn ($i) => $i->only('id', 'quantity', 'received_quantity', 'unit', 'unit_factor', 'unit_cost', 'line_total') + [
                    'product' => $i->product?->name, 'sku' => $i->product?->sku, 'pending' => round((float) ($pending[$i->id] ?? 0), 3), 'remaining' => $i->remaining(),
                ]),
                'receipts' => $o->receipts->map(fn (GoodsReceipt $r) => $r->only('id', 'number', 'status', 'total', 'delivery_reference') + ['received_on' => $r->received_on->toDateString(), 'by' => $r->receiver?->name, 'approver' => $r->approver?->name]),
                'bills' => $o->bills->map(fn (SupplierBill $b) => $b->only('id', 'number', 'reference', 'status', 'total', 'paid_total') + ['balance' => $b->balance(), 'issue_date' => $b->issue_date->toDateString()]),
                'received_value' => SupplierBillService::receivedValue($o),
                'timeline' => array_values(array_filter([
                    ['label' => 'أُنشئ', 'by' => $o->creator?->name, 'at' => $o->created_at->format('Y-m-d H:i')],
                    $o->approved_at ? ['label' => 'اعتُمد وأُرسل للمورد', 'by' => $o->approver?->name, 'at' => $o->approved_at->format('Y-m-d H:i')] : null,
                    ...$o->receipts->map(fn ($r) => ['label' => 'سند استلام '.$r->number.' ('.match ($r->status) {
                        'approved' => 'معتمد', 'rejected' => 'مرفوض', default => 'بانتظار الاعتماد'
                    }.')', 'by' => $r->receiver?->name, 'at' => $r->created_at->format('Y-m-d H:i')])->all(),
                    $o->cancelled_at ? ['label' => 'أُلغي'.($o->cancel_reason ? ': '.$o->cancel_reason : ''), 'by' => null, 'at' => $o->cancelled_at->format('Y-m-d H:i')] : null,
                ])),
            ],
            'can' => ['approve' => request()->user()->can('purchases.approve'), 'receive' => request()->user()->can('purchases.receive'), 'manage' => request()->user()->can('purchases.manage')],
        ]);
    }

    /** A printable order (preview and print); writes nothing. */
    /** The purchase order paper, as sent to the supplier (the paper engine). */
    public function print(Request $request, PurchaseOrder $purchaseOrder): HttpResponse
    {
        return Papers::for('purchase_order', $purchaseOrder)->page($request->boolean('print', true));
    }

    public function approve(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->purchases->approve($purchaseOrder);

        return back()->with('success', 'اعتُمد أمر الشراء '.$purchaseOrder->number.'.');
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $this->purchases->cancel($purchaseOrder, $data['reason']);

        return back()->with('success', 'أُلغي أمر الشراء.');
    }

    public function close(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $this->purchases->close($purchaseOrder, $data['reason']);

        return back()->with('success', 'أُغلق أمر الشراء؛ لن يُنتظر الباقي.');
    }

    /** Record goods that arrived (approved at once when the user may approve and asks to). */
    public function receive(Request $request, PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $data = $request->validate([
            'lines' => ['required', 'array'],
            'lines.*.quantity' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'lines.*.note' => ['nullable', 'string', 'max:200'],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'delivery_reference' => ['nullable', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:500'],
            'approve' => ['boolean'],
            'client_key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);
        $now = $request->boolean('approve') && $request->user()->can('purchases.approve');
        $receipt = $this->purchases->receive($purchaseOrder, $data['lines'], $data, $now);

        return back()->with('success', $receipt->status === GoodsReceipt::APPROVED ? 'استُلمت البضاعة ('.$receipt->number.') وزاد مخزون الفرع.' : 'سُجّل سند الاستلام '.$receipt->number.' بانتظار الاعتماد؛ لا يتغير المخزون قبل اعتماده.');
    }

    private function form(?PurchaseOrder $order, array $lines = []): Response
    {
        return Inertia::render('Inventory/Purchases/OrderForm', [
            'order' => $order ? $order->only('id', 'number', 'supplier_id', 'branch_id', 'discount', 'shipping', 'tax_rate', 'notes', 'supplier_reference', 'payment_terms_days') + [
                'order_date' => $order->order_date->toDateString(), 'expected_on' => $order->expected_on?->toDateString(),
                'items' => $order->items->map(fn ($i) => $i->only('product_id', 'quantity', 'unit_cost', 'unit', 'unit_factor')),
            ] : null,
            'lines' => $lines,
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name', 'payment_terms_days']),
            'products' => Product::where('is_active', true)->orderBy('name')->get(['id', 'name', 'sku', 'cost', 'track_stock']),
            'branches' => app(BranchContext::class)->choices(),
            'defaultBranch' => app(BranchContext::class)->id() ?? app(BranchContext::class)->mainId(),
            'taxRate' => (float) app(TenantContext::class)->require()->setting('vat_rate', 0),
            'units' => ['حبة', 'علبة', 'كرتون', 'صندوق', 'كيس', 'ربطة', 'كيلو', 'لتر'],
            'canApprove' => request()->user()->can('purchases.approve'),
        ]);
    }

    /** @return array<string, mixed> */
    private function present(PurchaseOrder $o): array
    {
        return $o->only('id', 'number', 'status', 'subtotal', 'discount', 'shipping', 'tax_rate', 'tax', 'total', 'notes', 'supplier_reference', 'payment_terms_days', 'cancel_reason') + [
            'status_label' => PurchaseOrder::LABELS[$o->status] ?? $o->status,
            'supplier' => $o->supplier?->only('id', 'name', 'phone', 'email', 'tax_number', 'address'),
            'branch' => $o->branch?->name,
            'order_date' => $o->order_date->toDateString(), 'expected_on' => $o->expected_on?->toDateString(),
        ];
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'supplier_id' => ['required', TenantRule::exists('suppliers')->whereNull('deleted_at')],
            'branch_id' => ['required', ...app(BranchContext::class)->rule()],
            'order_date' => ['required', 'date'],
            'expected_on' => ['nullable', 'date', 'after_or_equal:order_date'],
            'supplier_reference' => ['nullable', 'string', 'max:60'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'shipping' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'tax_rate' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:200'],
            'items.*.product_id' => ['required', TenantRule::exists('products')->whereNull('deleted_at')],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:1000000'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0', 'max:9999999'],
            'items.*.unit' => ['nullable', 'string', 'max:30'],
            'items.*.unit_factor' => ['nullable', 'numeric', 'min:0.001', 'max:10000'],
            'client_key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
        ], ['expected_on.after_or_equal' => 'تاريخ التسليم المتوقع بعد تاريخ الطلب.']);
    }
}
