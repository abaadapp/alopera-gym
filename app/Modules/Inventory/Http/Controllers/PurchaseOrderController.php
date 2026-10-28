<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\PurchaseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PurchaseOrderController extends Controller
{
    public function __construct(private readonly PurchaseService $purchases)
    {
    }

    public function index(Request $request): Response
    {
        return Inertia::render('Inventory/Purchases/Index', [
            'orders' => PurchaseOrder::with('supplier:id,name')->withCount('items')
                ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
                ->latest('id')->paginate(25)->withQueryString()
                ->through(fn (PurchaseOrder $o) => $o->only('id', 'number', 'status', 'total', 'items_count') + [
                    'supplier' => $o->supplier?->name, 'order_date' => $o->order_date->toDateString(), 'received_at' => $o->received_at?->format('Y-m-d'),
                ]),
            'suppliers' => Supplier::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'products' => Product::where('is_active', true)->orderBy('name')->get(['id', 'name', 'cost', 'stock_quantity']),
            'filters' => $request->only('status'),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', TenantRule::exists('suppliers')],
            'order_date' => ['nullable', 'date'],
            'expected_on' => ['nullable', 'date'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', TenantRule::exists('products')],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001'],
            'items.*.unit_cost' => ['required', 'numeric', 'min:0'],
        ]);

        $order = $this->purchases->create(Supplier::findOrFail($data['supplier_id']), $data['items'], $data);

        return redirect()->route('club.purchases.show', $order)->with('success', 'تم إنشاء أمر الشراء '.$order->number);
    }

    public function show(PurchaseOrder $purchaseOrder): Response
    {
        $purchaseOrder->load(['supplier', 'items.product:id,name,sku']);

        return Inertia::render('Inventory/Purchases/Show', [
            'order' => $purchaseOrder->only('id', 'number', 'status', 'total', 'notes') + [
                'supplier' => $purchaseOrder->supplier?->only('id', 'name', 'phone'),
                'order_date' => $purchaseOrder->order_date->toDateString(), 'expected_on' => $purchaseOrder->expected_on?->toDateString(),
                'received_at' => $purchaseOrder->received_at?->format('Y-m-d H:i'),
                'items' => $purchaseOrder->items->map(fn ($i) => $i->only('id', 'quantity', 'unit_cost', 'line_total') + ['product' => $i->product?->name, 'sku' => $i->product?->sku]),
            ],
        ]);
    }

    public function receive(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->purchases->receive($purchaseOrder);

        return back()->with('success', 'تم استلام البضاعة وتحديث المخزون.');
    }

    public function cancel(PurchaseOrder $purchaseOrder): RedirectResponse
    {
        $this->purchases->cancel($purchaseOrder);

        return back()->with('success', 'تم إلغاء أمر الشراء.');
    }
}
