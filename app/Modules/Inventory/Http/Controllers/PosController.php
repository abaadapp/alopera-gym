<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Gym\Models\Member;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\PosDevices;
use App\Modules\Inventory\Services\PosService;
use App\Modules\Inventory\Services\ProductAddons;
use App\Modules\Inventory\Services\ShopSettings;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The point of sale: whoever holds pos.sell sells; discounts need sales.discount, voiding needs sales.refund. On an
 * activated device the sale is the device's branch's (and records the device); the club may require one.
 */
class PosController extends Controller
{
    public function __construct(private readonly PosService $pos, private readonly BranchContext $branches, private readonly PosDevices $devices, private readonly TenantContext $tenants) {}

    public function index(Request $request, ProductAddons $addons, StockService $stock): Response
    {
        $device = $this->devices->current($request);
        if ($device) {
            $this->devices->touch($device, $request);
        }
        // The shelf the till sells from: the device's branch, else the user's (or the one the top bar shows).
        $branch = $device?->branch_id ?? $this->branches->assign() ?? $this->branches->mainId();
        $quantities = $stock->quantities($branch);
        $products = Product::with('category:id,name,sort_order')->where('is_active', true)->orderBy('name')->get();
        $offered = $addons->map($products, 'pos', stock: $quantities);
        $printer = $device?->load('peripherals')->printer();

        return Inertia::render('Inventory/Pos', [
            // Prices are shown here; the sale is priced again on the server from the database.
            'products' => $products->map(fn (Product $p) => $p->only('id', 'name', 'price', 'track_stock', 'barcode', 'sku') + [
                'stock_quantity' => $quantities[$p->id] ?? '0.000',
                'category' => $p->category?->name, 'image_url' => $p->imageUrl(), 'addons' => $offered[$p->id],
            ]),
            'categories' => $products->pluck('category')->filter()->unique('id')->sortBy([['sort_order', 'asc'], ['name', 'asc']])->pluck('name')->values(),
            'can' => ['discount' => $request->user()->can('sales.discount'), 'devices' => $request->user()->can('pos_devices.manage')],
            'lastSale' => session('sale_id'),
            'device' => $device ? [
                'name' => $device->name, 'branch' => $device->branch?->name,
                'auto_print' => (bool) ($printer?->auto_print ?? false), 'paper_width' => $printer?->paper_width ?? 80, 'printer' => $printer?->name,
            ] : null,
            'branch' => Branch::whereKey($branch)->value('name'),
            'requireDevice' => ShopSettings::of($this->tenants->require())['pos_require_device'],
        ]);
    }

    public function checkout(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:100'],
            'items.*.product_id' => ['required', TenantRule::exists('products')],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:10000'],
            'items.*.addons' => ['nullable', 'array', 'max:30'],
            'items.*.addons.*.addon_id' => ['required', 'integer', TenantRule::exists('product_addons')],
            'items.*.addons.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'payment_method' => ['required', 'in:cash,card'],
            'member_id' => ['nullable', TenantRule::exists('gym_members')],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'card_reference' => ['nullable', 'string', 'max:120', 'required_if:payment_method,card'],
            'client_key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'customer_name' => ['nullable', 'string', 'max:120'],
        ], ['card_reference.required_if' => 'أدخل رقم العملية من جهاز الشبكة.']);

        if ((float) ($data['discount'] ?? 0) > 0 && ! $request->user()->can('sales.discount')) {
            throw new BusinessRuleException('pos.discount_permission', 'الخصم يحتاج صلاحية «تطبيق الخصومات عند البيع».', 'discount');
        }

        $device = $this->devices->current($request);
        if ($device) {
            $this->devices->ensureBranch($device);
        } elseif (ShopSettings::of($this->tenants->require())['pos_require_device']) {
            throw new BusinessRuleException('pos.device_required', 'البيع من أجهزة نقاط البيع المفعّلة فقط. فعّل هذا الجهاز أولًا.');
        }

        $sale = $this->pos->checkout($data['items'], $data['payment_method'], isset($data['member_id']) ? Member::find($data['member_id']) : null, $data['discount'] ?? 0, $this->branches->assign(), $data['card_reference'] ?? null, $data['customer_name'] ?? null, $device, $data['client_key'] ?? null);

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
