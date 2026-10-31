<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Inventory\Http\Controllers\Concerns\ScopesBranches;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\SupplierBill;
use App\Modules\Inventory\Models\SupplierPayment;
use App\Modules\Inventory\Services\PurchaseService;
use App\Modules\Inventory\Services\SupplierBillService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Supplier bills (سندات الموردين) and their payments, and goods receipt notes (listed under the inventory section,
 * approved here). Attachments are kept on the private disk and downloaded through this controller.
 */
class SupplierBillController extends Controller
{
    use ScopesBranches;

    public function __construct(private readonly SupplierBillService $bills) {}

    public function index(Request $request): Response
    {
        $branch = $this->listBranch($request);
        $state = in_array($request->query('state'), ['pending', 'unpaid', 'partial', 'paid', 'overdue', 'void'], true) ? $request->query('state') : '';
        $q = $request->string('q')->trim()->toString();
        $supplier = ctype_digit((string) $request->query('supplier')) ? (int) $request->query('supplier') : null;
        $base = fn () => app(BranchContext::class)->narrow(SupplierBill::query(), $branch)
            ->when($q, fn ($w) => $w->where(fn ($x) => $x->where('reference', 'ilike', '%'.$q.'%')->orWhere('number', 'ilike', '%'.$q.'%')->orWhereHas('supplier', fn ($s) => $s->where('name', 'ilike', '%'.$q.'%'))))
            ->when($supplier, fn ($w, $s) => $w->where('supplier_id', $s));
        $summary = $base()->toBase()->selectRaw("count(*) filter (where status in ('pending','approved')) as total, count(*) filter (where status = 'pending') as pending,
            coalesce(sum(total - paid_total) filter (where status = 'approved'), 0) as due,
            coalesce(sum(total - paid_total) filter (where status = 'approved' and due_date < current_date and total > paid_total), 0) as overdue,
            count(*) filter (where status = 'approved' and due_date < current_date and total > paid_total) as overdue_count")->first();

        $list = $base()->with(['supplier:id,name', 'order:id,number'])
            ->when($state === 'pending', fn ($w) => $w->where('status', SupplierBill::PENDING))
            ->when($state === 'void', fn ($w) => $w->whereIn('status', [SupplierBill::VOID, SupplierBill::REJECTED]))
            ->when($state === 'paid', fn ($w) => $w->where('status', SupplierBill::APPROVED)->whereColumn('paid_total', '>=', 'total'))
            ->when($state === 'unpaid', fn ($w) => $w->where('status', SupplierBill::APPROVED)->where('paid_total', 0))
            ->when($state === 'partial', fn ($w) => $w->where('status', SupplierBill::APPROVED)->where('paid_total', '>', 0)->whereColumn('paid_total', '<', 'total'))
            ->when($state === 'overdue', fn ($w) => $w->where('status', SupplierBill::APPROVED)->whereColumn('paid_total', '<', 'total')->where('due_date', '<', today()))
            ->latest('issue_date')->latest('id')->paginate(25)->withQueryString()
            ->through(fn (SupplierBill $b) => $this->row($b));

        return Inertia::render('Inventory/Purchases/Bills', [
            'bills' => $list,
            'summary' => array_map(fn ($v) => is_numeric($v) ? round((float) $v, 3) : $v, (array) $summary),
            'filters' => ['state' => $state, 'q' => $q, 'supplier' => $supplier ? (string) $supplier : ''],
            'suppliers' => Supplier::orderBy('name')->get(['id', 'name', 'payment_terms_days']),
            // Orders that can still take a bill: approved, of a supplier, not billed yet.
            'orders' => PurchaseOrder::whereIn('status', [PurchaseOrder::ORDERED, PurchaseOrder::PARTIAL, PurchaseOrder::RECEIVED, PurchaseOrder::CLOSED])
                ->whereDoesntHave('bills', fn ($b) => $b->whereNotIn('status', [SupplierBill::VOID, SupplierBill::REJECTED]))
                ->with('supplier:id,name')->latest('id')->limit(200)->get()
                ->map(fn (PurchaseOrder $o) => $o->only('id', 'number', 'supplier_id', 'subtotal', 'discount', 'shipping', 'tax', 'total') + ['received' => SupplierBillService::receivedValue($o)]),
            'accounts' => $request->user()->can('purchases.pay') ? Account::money()->postable()->whereIn('kind', Account::PAYING_KINDS)->orderBy('code')->get(['id', 'name', 'kind']) : [],
            'taxRate' => (float) app(TenantContext::class)->require()->setting('vat_rate', 0),
            'preselect' => ctype_digit((string) $request->query('order')) ? (int) $request->query('order') : null,
            'can' => ['create' => $request->user()->can('purchases.manage'), 'approve' => $request->user()->can('purchases.approve'), 'pay' => $request->user()->can('purchases.pay')],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', TenantRule::exists('suppliers')->whereNull('deleted_at')],
            'purchase_order_id' => ['nullable', TenantRule::exists('purchase_orders')],
            'reference' => ['required', 'string', 'max:60'],
            'issue_date' => ['required', 'date', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'subtotal' => ['required', 'numeric', 'min:0.001', 'max:9999999'],
            'discount' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'tax' => ['nullable', 'numeric', 'min:0', 'max:9999999'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'attachment' => ['nullable', 'file', 'mimes:pdf,jpg,jpeg,png,webp', 'max:'.config('gym.max_document_kb', 10240)],
            'approve' => ['boolean'],
        ], ['due_date.after_or_equal' => 'تاريخ الاستحقاق بعد تاريخ الإصدار.']);
        if ($file = $request->file('attachment')) {
            $data['attachment_path'] = $file->storeAs('tenants/'.$request->user()->tenant_id.'/supplier-bills', Str::random(32).'.'.$file->guessExtension(), 'local');
            $data['attachment_name'] = Str::limit($file->getClientOriginalName(), 200, '');
        }
        $bill = $this->bills->create(Supplier::findOrFail($data['supplier_id']), $data);
        if ($request->boolean('approve') && $request->user()->can('purchases.approve')) {
            $this->bills->approve($bill);
        }

        return back()->with('success', 'سُجّل سند المورد '.$bill->number.($bill->fresh()->status === SupplierBill::APPROVED ? ' واعتُمد' : ' بانتظار الاعتماد').'.');
    }

    public function show(SupplierBill $supplierBill): Response
    {
        $b = $supplierBill->load(['supplier', 'order:id,number,total,status', 'payments.account:id,name', 'payments.creator:id,name', 'creator:id,name', 'approver:id,name']);

        return Inertia::render('Inventory/Purchases/BillShow', [
            'bill' => $this->row($b) + [
                'subtotal' => $b->subtotal, 'discount' => $b->discount, 'tax' => $b->tax, 'notes' => $b->notes, 'reason' => $b->reason,
                'by' => $b->creator?->name, 'approver' => $b->approver?->name, 'approved_at' => $b->approved_at?->format('Y-m-d H:i'),
                'order' => $b->order ? $b->order->only('id', 'number', 'total') + ['received' => SupplierBillService::receivedValue($b->order)] : null,
                'payments' => $b->payments->map(fn (SupplierPayment $p) => $p->only('id', 'number', 'amount', 'reference', 'reverse_reason') + [
                    'paid_on' => $p->paid_on->toDateString(), 'account' => $p->account?->name, 'by' => $p->creator?->name, 'reversed' => $p->reversed_at !== null,
                ]),
            ],
            'accounts' => request()->user()->can('purchases.pay') ? Account::money()->postable()->whereIn('kind', Account::PAYING_KINDS)->orderBy('code')->get(['id', 'name', 'kind']) : [],
            'can' => ['approve' => request()->user()->can('purchases.approve'), 'pay' => request()->user()->can('purchases.pay')],
        ]);
    }

    public function decide(Request $request, SupplierBill $supplierBill, string $decision): RedirectResponse
    {
        $reason = in_array($decision, ['reject', 'void'], true) ? $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason'] : '';
        match ($decision) {
            'approve' => $this->bills->approve($supplierBill),
            'reject' => $this->bills->reject($supplierBill, $reason),
            'void' => $this->bills->void($supplierBill, $reason),
            default => abort(404),
        };

        return back()->with('success', match ($decision) {
            'approve' => 'اعتُمد السند وسُجّل الالتزام للمورد.', 'reject' => 'رُفض السند.', default => 'أُلغي السند وعُكس قيده.'
        });
    }

    public function pay(Request $request, SupplierBill $supplierBill): RedirectResponse
    {
        $data = $request->validate([
            'account_id' => ['required', TenantRule::exists('gym_accounts')],
            'amount' => ['required', 'numeric', 'min:0.001', 'max:9999999'],
            'paid_on' => ['required', 'date', 'before_or_equal:today'],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:255'],
            'client_key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
        ]);
        $payment = $this->bills->pay($supplierBill, Account::findOrFail($data['account_id']), $data['amount'], $data['paid_on'], $data['reference'] ?? null, $data['notes'] ?? null, $data['client_key'] ?? null);

        return back()->with('success', 'سُجّل السداد '.$payment->number.' بمبلغ '.$payment->amount.'.');
    }

    public function reversePayment(Request $request, SupplierPayment $supplierPayment): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']]);
        $this->bills->reversePayment($supplierPayment, $data['reason']);

        return back()->with('success', 'عُكست الدفعة وعاد المبلغ مستحقًا على السند.');
    }

    public function attachment(SupplierBill $supplierBill): StreamedResponse
    {
        abort_unless($supplierBill->attachment_path && Storage::disk('local')->exists($supplierBill->attachment_path), 404);

        return Storage::disk('local')->download($supplierBill->attachment_path, $supplierBill->attachment_name ?: 'attachment');
    }

    // ── Goods receipt notes (the inventory section's tab) ────────────────────

    public function receipts(Request $request): Response
    {
        $branch = $this->listBranch($request);
        $status = in_array($request->query('status'), [GoodsReceipt::PENDING, GoodsReceipt::APPROVED, GoodsReceipt::REJECTED], true) ? $request->query('status') : '';
        $base = fn () => app(BranchContext::class)->narrow(GoodsReceipt::query(), $branch);

        return Inertia::render('Inventory/Stock/Receipts', [
            'receipts' => $base()->with(['supplier:id,name', 'order:id,number', 'branch:id,name', 'receiver:id,name'])->withCount('lines')
                ->when($status, fn ($q, $s) => $q->where('status', $s))->latest('id')->paginate(25)->withQueryString()
                ->through(fn (GoodsReceipt $r) => $r->only('id', 'number', 'status', 'total', 'lines_count', 'reason', 'delivery_reference') + [
                    'received_on' => $r->received_on->toDateString(), 'supplier' => $r->supplier?->name, 'order' => $r->order?->only('id', 'number'),
                    'branch' => $r->branch?->name, 'by' => $r->receiver?->name,
                ]),
            'pending' => $base()->where('status', GoodsReceipt::PENDING)->count(),
            'filters' => ['status' => $status],
            'branch' => $this->branchFilter($request, $branch),
            'can' => ['approve' => $request->user()->can('purchases.approve')],
        ]);
    }

    public function showReceipt(GoodsReceipt $goodsReceipt): Response
    {
        $r = $goodsReceipt->load(['supplier:id,name', 'order:id,number', 'branch:id,name', 'receiver:id,name', 'approver:id,name', 'lines.product:id,name,sku']);

        return Inertia::render('Inventory/Stock/ReceiptShow', [
            'receipt' => $r->only('id', 'number', 'status', 'total', 'notes', 'reason', 'delivery_reference') + [
                'received_on' => $r->received_on->toDateString(), 'supplier' => $r->supplier?->name, 'order' => $r->order?->only('id', 'number'),
                'branch' => $r->branch?->name, 'by' => $r->receiver?->name, 'approver' => $r->approver?->name, 'approved_at' => $r->approved_at?->format('Y-m-d H:i'),
                'lines' => $r->lines->map(fn ($l) => $l->only('ordered_quantity', 'quantity', 'unit_factor', 'unit_cost', 'line_total', 'note') + ['product' => $l->product?->name, 'sku' => $l->product?->sku, 'stock_quantity' => round((float) $l->quantity * (float) $l->unit_factor, 3)]),
            ],
            'can' => ['approve' => request()->user()->can('purchases.approve')],
        ]);
    }

    public function decideReceipt(Request $request, GoodsReceipt $goodsReceipt, string $decision, PurchaseService $purchases): RedirectResponse
    {
        if ($decision === 'reject') {
            $purchases->rejectReceipt($goodsReceipt, $request->validate(['reason' => ['required', 'string', 'min:3', 'max:255']])['reason']);

            return back()->with('success', 'رُفض سند الاستلام؛ لم يتغير المخزون.');
        }
        abort_unless($decision === 'approve', 404);
        $purchases->approveReceipt($goodsReceipt);

        return back()->with('success', 'اعتُمد سند الاستلام '.$goodsReceipt->number.' وزاد مخزون الفرع.');
    }

    /** @return array<string, mixed> */
    private function row(SupplierBill $b): array
    {
        return $b->only('id', 'number', 'reference', 'status', 'total', 'paid_total', 'attachment_name') + [
            'balance' => $b->balance(), 'state' => $b->paymentState(), 'supplier' => $b->supplier?->name, 'supplier_id' => $b->supplier_id,
            'order' => $b->order?->only('id', 'number'), 'issue_date' => $b->issue_date->toDateString(), 'due_date' => $b->due_date?->toDateString(),
            'has_attachment' => (bool) $b->attachment_path,
        ];
    }
}
