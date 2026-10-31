<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Phone;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\SupplierBill;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/** The purchases section's suppliers: their details, what is bought from them, their orders, bills and what is owed. */
class SupplierController extends Controller
{
    public function index(Request $request): Response
    {
        $q = $request->string('q')->trim()->toString();
        $suppliers = Supplier::query()->withCount('purchaseOrders')
            ->when($q, fn ($w) => $w->where(fn ($x) => $x->where('name', 'ilike', '%'.$q.'%')->orWhere('phone', 'like', '%'.$q.'%')->orWhere('contact_name', 'ilike', '%'.$q.'%')))
            ->orderBy('name')->paginate(30)->withQueryString();
        $due = SupplierBill::where('status', SupplierBill::APPROVED)->whereIn('supplier_id', $suppliers->pluck('id'))->groupBy('supplier_id')->selectRaw('supplier_id, sum(total - paid_total) as d')->pluck('d', 'supplier_id');

        return Inertia::render('Inventory/Purchases/Suppliers', [
            'suppliers' => $suppliers->through(fn (Supplier $s) => $s->only('id', 'name', 'contact_name', 'phone', 'email', 'address', 'tax_number', 'notes', 'is_active', 'payment_terms_days', 'purchase_orders_count') + ['due' => round((float) ($due[$s->id] ?? 0), 3)]),
            'summary' => ['total' => Supplier::count(), 'with_orders' => Supplier::has('purchaseOrders')->count(), 'due' => round((float) SupplierBill::where('status', SupplierBill::APPROVED)->sum(DB::raw('total - paid_total')), 3)],
            'filters' => ['q' => $q],
        ]);
    }

    public function show(Supplier $supplier): Response
    {
        $orders = PurchaseOrder::where('supplier_id', $supplier->id)->with('branch:id,name')->latest('id')->limit(50)->get();
        $bills = SupplierBill::where('supplier_id', $supplier->id)->latest('issue_date')->limit(50)->get();
        // What is bought from them: the products on their orders, with the last price paid.
        $products = DB::table('purchase_order_items as i')->join('purchase_orders as o', 'o.id', '=', 'i.purchase_order_id')->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('o.supplier_id', $supplier->id)->where('o.status', '!=', PurchaseOrder::CANCELLED)
            ->groupBy('p.id', 'p.name')->selectRaw('p.id, p.name, sum(i.quantity * i.unit_factor) as quantity, max(o.order_date) as last_date, (array_agg(i.unit_cost order by o.order_date desc, i.id desc))[1] as last_cost')
            ->orderBy('p.name')->get();

        return Inertia::render('Inventory/Purchases/SupplierShow', [
            'supplier' => $supplier->only('id', 'name', 'contact_name', 'phone', 'email', 'address', 'tax_number', 'notes', 'is_active', 'payment_terms_days'),
            'orders' => $orders->map(fn ($o) => $o->only('id', 'number', 'status', 'total') + ['status_label' => PurchaseOrder::LABELS[$o->status] ?? $o->status, 'date' => $o->order_date->toDateString(), 'branch' => $o->branch?->name]),
            'bills' => $bills->map(fn ($b) => $b->only('id', 'number', 'reference', 'status', 'total', 'paid_total') + ['balance' => $b->balance(), 'state' => $b->paymentState(), 'issue_date' => $b->issue_date->toDateString(), 'due_date' => $b->due_date?->toDateString()]),
            'products' => $products->map(fn ($p) => ['id' => $p->id, 'name' => $p->name, 'quantity' => round((float) $p->quantity, 3), 'last_cost' => $p->last_cost, 'last_date' => $p->last_date]),
            'totals' => [
                'purchased' => round((float) $orders->whereNotIn('status', [PurchaseOrder::CANCELLED, PurchaseOrder::DRAFT])->sum('total'), 3),
                'due' => round($bills->sum(fn ($b) => (float) $b->balance()), 3),
                'paid' => round((float) $supplier->payments()->whereNull('reversed_at')->sum('amount'), 3),
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

        return back()->with('success', 'حُذف المورد.');
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
