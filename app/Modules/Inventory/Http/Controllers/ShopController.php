<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Auth\PermissionRegistry;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Support\Phone;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Gym\Models\Member;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Models\SaleItem;
use App\Modules\Inventory\Services\OrderService;
use App\Modules\Inventory\Services\ProductAddons;
use App\Modules\Inventory\Services\ShopSettings;
use App\Modules\Payments\Services\OnlinePaymentService;
use App\Modules\Website\Http\RendersSite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The shop on the club's own website: the products the club chose to show there, a cart kept in the browser,
 * and orders that land in the panel under «طلبات الموقع الإلكتروني». An order's status page is reached only
 * through its unguessable link.
 */
class ShopController extends Controller
{
    use RendersSite;

    public function __construct(
        private readonly TenantContext $tenants,
        private readonly ShopSettings $shop,
        private readonly OrderService $orders,
        private readonly OnlinePaymentService $online,
        private readonly ProductAddons $addons,
    ) {}

    public function index(Request $request): Response
    {
        $this->ensureShop();
        $products = Product::onWebsite()->with('category:id,name')->orderBy('name')->get();
        $addons = $this->addons->map($products, 'website', true);

        return $this->page('Site/Shop', [
            'products' => $products->map(fn (Product $p) => $this->product($p, $addons[$p->id])),
            'categories' => ProductCategory::whereIn('id', $products->pluck('product_category_id')->filter()->unique())->ordered()->pluck('name'),
            'currency' => $this->tenants->require()->currency,
        ], 'المتجر', 'منتجات النادي وأسعارها، اطلبها أونلاين واستلمها من النادي.');
    }

    public function show(Product $product): Response
    {
        $this->ensureShop();
        abort_unless($product->is_active && $product->show_on_website, 404);

        return $this->page('Site/ShopProduct', [
            'product' => $this->product($product->load('category:id,name'), $this->addons->map([$product], 'website', true)[$product->id])
                + ['description' => $product->description, 'photos' => $product->imageUrls()],
            'currency' => $this->tenants->require()->currency,
        ], $product->name, $product->description ? mb_substr($product->description, 0, 160) : null, $product->imageUrl());
    }

    public function cart(): Response
    {
        $this->ensureShop();
        $tenant = $this->tenants->require();
        $member = Auth::guard('member')->user();

        $products = Product::onWebsite()->orderBy('name')->get();
        $addons = $this->addons->map($products, 'website', true);

        return $this->page('Site/ShopCart', [
            'products' => $products->map(fn (Product $p) => $this->product($p, $addons[$p->id])),
            'methods' => $this->shop->paymentMethods($tenant),
            'branches' => Branch::where('is_active', true)->orderByDesc('is_main')->orderBy('name')->get(['id', 'name']),
            'pickupNote' => ShopSettings::of($tenant)['pickup_note'],
            'windowMinutes' => ShopSettings::of($tenant)['payment_window_minutes'],
            'prefill' => $member instanceof Member ? ['name' => $member->full_name, 'phone' => $member->phone, 'email' => $member->email] : null,
            'currency' => $tenant->currency,
        ], 'سلة المشتريات');
    }

    public function store(Request $request): HttpResponse
    {
        $this->ensureShop();
        $tenant = $this->tenants->require();
        $methods = $this->shop->paymentMethods($tenant);
        if ($methods === []) {
            throw new BusinessRuleException('order.closed', 'الطلب من الموقع غير متاح حاليًا. تواصل مع النادي.');
        }

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1', 'max:30'],
            'items.*.product_id' => ['required', 'integer', TenantRule::exists('products')],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:50'],
            'items.*.addons' => ['nullable', 'array', 'max:20'],
            'items.*.addons.*.addon_id' => ['required', 'integer', TenantRule::exists('product_addons')],
            'items.*.addons.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'name' => ['required', 'string', 'min:2', 'max:120'],
            'phone' => ['required', 'string', 'min:7', 'max:32'],
            'email' => ['nullable', 'email', 'max:160'],
            'notes' => ['nullable', 'string', 'max:500'],
            'payment_method' => ['required', 'in:'.implode(',', $methods)],
            'branch_id' => ['nullable', 'integer', TenantRule::exists('branches')],
            'website' => ['prohibited'], // honeypot: people leave it empty
        ]);

        $member = Auth::guard('member')->user();
        $branchId = $data['branch_id'] ?? Branch::where('is_active', true)->orderByDesc('is_main')->value('id');

        $sale = $this->orders->place(
            self::lines($data['items']),
            ['name' => $data['name'], 'phone' => Phone::normalize($data['phone']), 'email' => $data['email'] ?? null, 'notes' => $data['notes'] ?? null],
            $data['payment_method'],
            $member instanceof Member ? $member : null,
            $branchId,
        );

        if ($sale->payment_method === 'paymob' && ! Money::isZero($sale->total)) {
            return $this->checkout($sale);
        }

        return redirect()->route('site.shop.order', ['club' => $tenant->slug, 'token' => $sale->public_token])
            ->with('success', 'تم استلام طلبك رقم '.$sale->number.'. سنتواصل معك عند تجهيزه.');
    }

    public function order(string $token): Response
    {
        $sale = $this->find($token);
        $sale->load(['items.addons', 'invoice:id,status,total,paid_total,public_token']);
        $tenant = $this->tenants->require();

        return $this->page('Site/ShopOrder', [
            'order' => [
                'number' => $sale->number,
                'token' => $sale->public_token,
                'at' => $sale->created_at->format('Y-m-d H:i'),
                'status' => $sale->status,
                'step' => $sale->fulfillment,
                'paid' => $sale->paid_at !== null,
                'method' => $sale->payment_method,
                'total' => $sale->total,
                'subtotal' => $sale->subtotal,
                'tax' => $sale->tax,
                'name' => $sale->customer_name,
                'reserved_until' => $sale->reserved_until?->format('Y-m-d H:i'),
                'can_pay' => $this->canPay($sale),
                'items' => $sale->items->map(fn (SaleItem $i) => $i->summary()),
            ],
            'pickupNote' => ShopSettings::of($tenant)['pickup_note'],
            'currency' => $tenant->currency,
        ], 'طلب '.$sale->number);
    }

    public function pay(string $token): HttpResponse
    {
        $sale = $this->find($token);
        if (! $this->canPay($sale)) {
            throw new BusinessRuleException('order.not_payable', 'لا يمكن الدفع لهذا الطلب الآن: إما مدفوع، أو ملغى، أو انتهت مهلة الدفع.');
        }

        return $this->checkout($sale);
    }

    /** Open Paymob's page; if it can't be opened, the order stays and can be paid from its page within the window. */
    private function checkout(Sale $sale): HttpResponse
    {
        $tenant = $this->tenants->require();
        try {
            $intent = $this->online->start(Invoice::findOrFail($sale->invoice_id), 'shop');
        } catch (BusinessRuleException $e) {
            return redirect()->route('site.shop.order', ['club' => $tenant->slug, 'token' => $sale->public_token])
                ->with('error', $e->getMessage().' طلبك محفوظ، ويمكنك المحاولة مرة أخرى من هذه الصفحة.');
        }

        return Inertia::location($intent->checkout_url);
    }

    private function canPay(Sale $sale): bool
    {
        return $sale->status === Sale::PENDING && $sale->paid_at === null && $sale->payment_method === 'paymob'
            && $sale->reserved_until?->isFuture() && $this->online->available();
    }

    private function find(string $token): Sale
    {
        abort_unless(PermissionRegistry::moduleEnabled('sales') && preg_match('/^[A-Za-z0-9]{40}$/', $token), 404);

        return Sale::where('source', Sale::WEBSITE)->where('public_token', $token)->firstOrFail();
    }

    private function ensureShop(): void
    {
        abort_unless(PermissionRegistry::moduleEnabled('sales') && ShopSettings::of($this->tenants->require())['shop_enabled'], 404);
    }

    /**
     * The basket as lines: the same product with the same add-ons is one line (quantities added up); with other
     * add-ons it is a line of its own. Prices are not taken from the browser.
     *
     * @param  list<array{product_id: int|string, quantity: int|string, addons?: list<array{addon_id: int|string, quantity: int|string}>}>  $items
     * @return list<array{product_id: int, quantity: int, addons: list<array{addon_id: int, quantity: int}>}>
     */
    public static function lines(array $items): array
    {
        $lines = [];
        foreach ($items as $item) {
            $addons = collect($item['addons'] ?? [])->map(fn ($a) => ['addon_id' => (int) $a['addon_id'], 'quantity' => (int) $a['quantity']])->sortBy('addon_id')->values()->all();
            $key = (int) $item['product_id'].'|'.collect($addons)->map(fn ($a) => $a['addon_id'].'x'.$a['quantity'])->join(',');
            $lines[$key] ??= ['product_id' => (int) $item['product_id'], 'quantity' => 0, 'addons' => $addons];
            $lines[$key]['quantity'] += (int) $item['quantity'];
        }

        return array_values($lines);
    }

    /**
     * @param  list<array<string, mixed>>  $addons  the product's add-ons on the website (ProductAddons::map)
     * @return array<string, mixed>
     */
    private function product(Product $p, array $addons = []): array
    {
        $available = $p->available();

        return [
            'id' => $p->id,
            'name' => $p->name,
            'price' => $p->price,
            'category' => $p->category?->name,
            'image_url' => $p->imageUrl(),
            'in_stock' => $available === null || $available >= 1,
            // Only the limit a customer needs, not the club's stock figure.
            'max' => $available === null ? 50 : (int) min(50, floor($available)),
            'addons' => array_map(fn ($a) => array_diff_key($a, ['available' => true]), $addons),
        ];
    }
}
