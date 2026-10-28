<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Gym\Models\Member;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\PosService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Simple front-desk point of sale. */
class PosController extends Controller
{
    public function __construct(private readonly PosService $pos) {}

    public function index(): Response
    {
        return Inertia::render('Inventory/Pos', [
            'products' => Product::with('category:id,name')->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Product $p) => $p->only('id', 'name', 'price', 'stock_quantity', 'track_stock', 'barcode', 'sku') + ['category' => $p->category?->name, 'image_url' => $p->imageUrl()]),
        ]);
    }

    public function checkout(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', TenantRule::exists('products')],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:10000'],
            'payment_method' => ['required', 'in:cash,card'],
            'member_id' => ['nullable', TenantRule::exists('gym_members')],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'card_reference' => ['nullable', 'string', 'max:120', 'required_if:payment_method,card'],
        ], ['card_reference.required_if' => 'أدخلي رقم العملية من جهاز الشبكة.']);

        $sale = $this->pos->checkout($data['items'], $data['payment_method'], isset($data['member_id']) ? Member::find($data['member_id']) : null, $data['discount'] ?? 0, $request->user()->branch_id, $data['card_reference'] ?? null);

        return back()->with('success', 'تمت عملية البيع '.$sale->number.' بمبلغ '.$sale->total);
    }

    public function sales(Request $request): Response
    {
        return Inertia::render('Inventory/Sales', [
            'sales' => Sale::with(['cashier:id,name', 'items'])->latest('id')
                ->when($request->date('date'), fn ($q, $d) => $q->whereDate('created_at', $d))
                ->paginate(30)->withQueryString()
                ->through(fn (Sale $s) => $s->only('id', 'number', 'status', 'total', 'discount', 'payment_method', 'invoice_id') + [
                    'cashier' => $s->cashier?->name, 'at' => $s->created_at->format('Y-m-d H:i'),
                    'items' => $s->items->map(fn ($i) => $i->only('name', 'quantity', 'line_total')),
                ]),
            'filters' => $request->only('date'),
        ]);
    }

    public function void(Request $request, Sale $sale): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $this->pos->void($sale, $data['reason']);

        return back()->with('success', 'تم إلغاء عملية البيع وإرجاع المخزون ورد المبلغ.');
    }
}
