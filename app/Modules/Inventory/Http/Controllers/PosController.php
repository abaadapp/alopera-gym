<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Support\BusinessRuleException;
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

/** The point of sale: whoever holds pos.sell sells; discounts need sales.discount, voiding needs sales.refund. */
class PosController extends Controller
{
    public function __construct(private readonly PosService $pos) {}

    public function index(): Response
    {
        return Inertia::render('Inventory/Pos', [
            'products' => Product::with('category:id,name')->where('is_active', true)->orderBy('name')->get()
                ->map(fn (Product $p) => $p->only('id', 'name', 'price', 'stock_quantity', 'track_stock', 'barcode', 'sku') + ['category' => $p->category?->name, 'image_url' => $p->imageUrl()]),
            'can' => ['discount' => request()->user()->can('sales.discount')],
            'lastSale' => session('sale_id'),
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
            'customer_name' => ['nullable', 'string', 'max:120'],
        ], ['card_reference.required_if' => 'أدخل رقم العملية من جهاز الشبكة.']);

        if ((float) ($data['discount'] ?? 0) > 0 && ! $request->user()->can('sales.discount')) {
            throw new BusinessRuleException('pos.discount_permission', 'الخصم يحتاج صلاحية «تطبيق الخصومات عند البيع».', 'discount');
        }

        $sale = $this->pos->checkout($data['items'], $data['payment_method'], isset($data['member_id']) ? Member::find($data['member_id']) : null, $data['discount'] ?? 0, $request->user()->branch_id, $data['card_reference'] ?? null, $data['customer_name'] ?? null);

        return back()->with('success', 'تمت عملية البيع '.$sale->number.' بمبلغ '.$sale->total)->with('sale_id', $sale->id);
    }

    public function void(Request $request, Sale $sale): RedirectResponse
    {
        SalesController::ensureBranch($request->user(), $sale);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'refund_method' => ['nullable', 'in:cash,card,bank_transfer,other'],
        ]);
        $this->pos->void($sale, $data['reason'], $data['refund_method'] ?? null);

        return back()->with('success', 'أُلغيت عملية البيع '.$sale->number.' وعاد المخزون وسُجّل رد المبلغ.');
    }
}
