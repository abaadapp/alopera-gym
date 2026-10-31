<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Phone;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\SupplierBill;
use App\Modules\Inventory\Models\SupplierPayment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The purchases section's suppliers: their details, what is bought from them, their orders, bills, payments and what
 * is owed. Seeing a supplier needs suppliers.view; their purchases and money also purchases.view — without it those
 * figures are not sent at all.
 */
class SupplierController extends Controller
{
    public const SORTS = ['name', 'orders', 'due'];

    public function index(Request $request): Response
    {
        $money = $request->user()->hasPermission('purchases.view');
        $q = $request->string('q')->trim()->toString();
        $status = $request->string('status')->toString();
        $sort = in_array($request->string('sort')->toString(), self::SORTS, true) ? $request->string('sort')->toString() : null;
        $dir = $request->string('dir')->toString() === 'desc' ? 'desc' : 'asc';
        $perPage = in_array($request->integer('per_page'), [20, 50, 100], true) ? $request->integer('per_page') : 20;

        $suppliers = Supplier::query()->withCount(['purchaseOrders', 'bills'])
            ->addSelect(['due' => SupplierBill::query()->selectRaw('coalesce(sum(total - paid_total), 0)')->whereColumn('supplier_id', 'suppliers.id')->where('status', SupplierBill::APPROVED)])
            ->when($q, fn ($w) => $w->where(fn ($x) => $x->where('name', 'ilike', '%'.$q.'%')->when(preg_replace('/\D/', '', $q), fn ($y, $digits) => $y->orWhere('phone', 'like', "%{$digits}%"))->orWhere('contact_name', 'ilike', '%'.$q.'%')->orWhere('email', 'ilike', '%'.$q.'%')->orWhere('tax_number', 'ilike', '%'.$q.'%')))
            ->when($status === 'active', fn ($w) => $w->where('is_active', true))
            ->when($status === 'inactive', fn ($w) => $w->where('is_active', false))
            ->when($status === 'due' && $money, fn ($w) => $w->whereHas('bills', fn ($b) => $b->where('status', SupplierBill::APPROVED)->whereColumn('paid_total', '<', 'total')))
            ->when($sort === 'orders', fn ($w) => $w->orderBy('purchase_orders_count', $dir))
            ->when($sort === 'due' && $money, fn ($w) => $w->orderBy('due', $dir))
            ->orderBy('name', $sort === 'name' ? $dir : 'asc')
            ->paginate($perPage)->withQueryString();

        return Inertia::render('Inventory/Purchases/Suppliers', [
            'suppliers' => $suppliers->through(fn (Supplier $s) => $s->only('id', 'name', 'contact_name', 'phone', 'email', 'address', 'tax_number', 'notes', 'is_active', 'payment_terms_days', 'purchase_orders_count') + [
                'due' => $money ? round((float) $s->due, 3) : null,
                'can_delete' => $s->purchase_orders_count === 0 && $s->bills_count === 0,
            ]),
            'summary' => [
                'total' => Supplier::count(),
                'with_orders' => Supplier::has('purchaseOrders')->count(),
                'active' => Supplier::where('is_active', true)->count(),
                'due' => $money ? round((float) SupplierBill::where('status', SupplierBill::APPROVED)->sum(DB::raw('total - paid_total')), 3) : null,
            ],
            'filters' => $request->only('q', 'status', 'sort', 'dir', 'per_page'),
        ]);
    }

    public function show(Request $request, Supplier $supplier): Response
    {
        $user = $request->user();
        $money = $user->hasPermission('purchases.view');
        $details = $supplier->only('id', 'name', 'contact_name', 'phone', 'email', 'address', 'tax_number', 'notes', 'is_active', 'payment_terms_days') + [
            'created_at' => $supplier->created_at?->toDateString(),
            'can_delete' => ! $supplier->purchaseOrders()->exists() && ! $supplier->bills()->exists(),
        ];
        $may = ['edit' => $user->hasPermission('suppliers.manage'), 'purchases' => $money, 'order' => $money && $user->hasPermission('purchases.manage'), 'bills' => $money];

        if (! $money) {
            return Inertia::render('Inventory/Purchases/SupplierShow', ['supplier' => $details, 'may' => $may]);
        }

        $orders = PurchaseOrder::where('supplier_id', $supplier->id)->with('branch:id,name')->withCount('items')->latest('id')->limit(50)->get();
        $bills = SupplierBill::where('supplier_id', $supplier->id)->latest('issue_date')->latest('id')->limit(50)->get();
        $payments = SupplierPayment::where('supplier_id', $supplier->id)->with('bill:id,number,reference', 'account:id,name')->latest('paid_on')->latest('id')->limit(50)->get();
        // What is bought from them: the products on their orders, with the last price paid.
        $products = DB::table('purchase_order_items as i')->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('o.tenant_id', $supplier->tenant_id)->where('o.supplier_id', $supplier->id)->where('o.status', '!=', PurchaseOrder::CANCELLED)
            ->groupBy('p.id', 'p.name')->selectRaw('p.id, p.name, sum(i.quantity * i.unit_factor) as quantity, max(o.order_date) as last_date, (array_agg(i.unit_cost order by o.order_date desc, i.id desc))[1] as last_cost')
            ->orderBy('p.name')->get();
        $counted = PurchaseOrder::where('supplier_id', $supplier->id)->whereNotIn('status', [PurchaseOrder::CANCELLED, PurchaseOrder::DRAFT]);

        // The record of dealings: orders, bills and payments in one line of dates, newest first.
        $log = collect()
            ->concat($orders->map(fn ($o) => ['kind' => 'order', 'date' => $o->order_date->toDateString(), 'at' => $o->created_at?->timestamp ?? 0, 'id' => $o->id, 'number' => $o->number, 'text' => 'أمر شراء — '.(PurchaseOrder::LABELS[$o->status] ?? $o->status), 'amount' => $o->total]))
            ->concat($bills->map(fn ($b) => ['kind' => 'bill', 'date' => $b->issue_date->toDateString(), 'at' => $b->created_at?->timestamp ?? 0, 'id' => $b->id, 'number' => $b->reference ?: $b->number, 'text' => 'سند مورد', 'amount' => $b->total]))
            ->concat($payments->map(fn ($p) => ['kind' => $p->reversed_at ? 'reversed' : 'payment', 'date' => $p->paid_on->toDateString(), 'at' => $p->created_at?->timestamp ?? 0, 'id' => $p->supplier_bill_id, 'number' => $p->number, 'text' => $p->reversed_at ? 'دفعة أُلغيت' : 'دفعة'.($p->account ? ' من '.$p->account->name : ''), 'amount' => $p->amount]))
            ->sortByDesc(fn ($e) => $e['date'].sprintf('%012d', $e['at']).['order' => 0, 'bill' => 1, 'payment' => 2, 'reversed' => 3][$e['kind']])->values()->take(50)->map(fn ($e) => collect($e)->except('at')->all());

        return Inertia::render('Inventory/Purchases/SupplierShow', [
            'supplier' => $details,
            'may' => $may,
            'orders' => $orders->map(fn ($o) => $o->only('id', 'number', 'status', 'total', 'items_count') + ['status_label' => PurchaseOrder::LABELS[$o->status] ?? $o->status, 'date' => $o->order_date->toDateString(), 'branch' => $o->branch?->name]),
            'bills' => $bills->map(fn ($b) => $b->only('id', 'number', 'reference', 'status', 'total', 'paid_total') + ['balance' => $b->balance(), 'state' => $b->paymentState(), 'issue_date' => $b->issue_date->toDateString(), 'due_date' => $b->due_date?->toDateString()]),
            'payments' => $payments->map(fn ($p) => $p->only('id', 'number', 'amount', 'reference') + ['date' => $p->paid_on->toDateString(), 'account' => $p->account?->name, 'bill_id' => $p->supplier_bill_id, 'bill' => $p->bill?->reference ?: $p->bill?->number, 'reversed' => $p->reversed_at !== null]),
            'products' => $products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'quantity' => round((float) $p->quantity, 3), 'last_cost' => $p->last_cost, 'last_date' => $p->last_date]),
            'log' => $log,
            'totals' => [
                'purchased' => round((float) $counted->sum('total'), 3),
                'orders' => $counted->count(),
                'paid' => round((float) $supplier->payments()->whereNull('reversed_at')->sum('amount'), 3),
                'due' => round((float) SupplierBill::where('supplier_id', $supplier->id)->where('status', SupplierBill::APPROVED)->sum(DB::raw('total - paid_total')), 3),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Supplier::create($this->validated($request));

        return back()->with('success', 'أُضيف المورد.');
    }

    public function update(Request $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->validated($request));

        return back()->with('success', 'حُفظ المورد.');
    }

    /** Only a supplier with nothing on record; otherwise stop using it (inactive). */
    public function destroy(Supplier $supplier): RedirectResponse
    {
        if ($supplier->purchaseOrders()->exists() || $supplier->bills()->exists()) {
            throw new BusinessRuleException('supplier.in_use', 'للمورد أوامر شراء أو سندات؛ أوقفه بدل حذفه حتى يبقى سجله.');
        }
        $supplier->delete();
        // Deleted from its own page, that page is gone: back to the list.
        $fromProfile = str_contains(url()->previous(), '/purchases/suppliers/'.$supplier->id);

        return ($fromProfile ? redirect()->route('club.purchases.suppliers') : back())->with('success', 'حُذف المورد.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:32'],
            'email' => ['nullable', 'email', 'max:160'],
            'address' => ['nullable', 'string', 'max:255'],
            'tax_number' => ['nullable', 'string', 'max:60'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['boolean'],
        ]);
        if (! empty($data['phone'])) {
            $data['phone'] = Phone::normalize($data['phone']);
        }

        return $data;
    }
}
