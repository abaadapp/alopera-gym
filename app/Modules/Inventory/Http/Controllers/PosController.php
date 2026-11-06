<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Tenancy\BranchContext;
use App\Core\Tenancy\TenantContext;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Http\GymPresenter;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Inventory\Models\PosDevice;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\PosCheckout;
use App\Modules\Inventory\Services\PosDevices;
use App\Modules\Inventory\Services\PosService;
use App\Modules\Inventory\Services\ProductAddons;
use App\Modules\Inventory\Services\ShopSettings;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The point of sale: whoever holds pos.sell sells products; memberships need memberships.sell and training packages
 * pt.sell; discounts need sales.discount, voiding sales.refund. On an activated device the sale is the device's
 * branch's (and records the device); the club may require one. Every operation records who, where and on what.
 */
class PosController extends Controller
{
    public function __construct(private readonly PosService $pos, private readonly BranchContext $branches, private readonly PosDevices $devices, private readonly TenantContext $tenants) {}

    public function index(Request $request, ProductAddons $addons, StockService $stock, OnlinePaymentService $online): Response
    {
        $user = $request->user();
        $device = $this->devices->current($request);
        if ($device) {
            $this->devices->touch($device, $request);
        }
        // The branch the till sells from: the device's, else the user's (or the one the top bar shows), else the user's
        // only branch. None (a club-wide user with several, «all branches» shown): the page asks for one before selling.
        $chosen = $device?->branch_id ?? $this->branches->assign() ?? $this->onlyBranch();
        $branch = $chosen ?? $this->branches->mainId();
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
            // Memberships and training packages are sold here too, through the same services as at reception.
            'plans' => $user->can('memberships.sell') ? MembershipPlan::where('is_active', true)->orderBy('sort')->orderBy('price')->get()
                ->map(fn (MembershipPlan $p) => ['id' => $p->id, 'name' => $p->name, 'price' => $p->price, 'signup_fee' => $p->signup_fee, 'duration' => $p->durationLabel(), 'sessions' => $p->sessions_limit]) : [],
            'packages' => $user->can('pt.sell') ? PtPackage::with('trainer:id,name')->where('is_active', true)->orderBy('price')->get()
                ->map(fn (PtPackage $p) => ['id' => $p->id, 'name' => $p->name, 'price' => $p->price, 'sessions' => $p->sessions_count, 'trainer' => $p->trainer?->name]) : [],
            'can' => [
                'discount' => $user->can('sales.discount'), 'devices' => $user->can('pos_devices.manage'), 'refund' => $user->can('sales.refund'),
                'memberships' => $user->can('memberships.sell'), 'pt' => $user->can('pt.sell'), 'print' => $user->can('papers.print'),
                // Paymob at the counter: a link for the customer to pay a membership or package on their phone.
                'paymob' => $online->available(),
            ],
            'lastSale' => session('sale_id'),
            'lastResult' => session('pos_result'),
            // The till and its receipt printer, as the printing layer needs them to pick a way to print.
            'device' => $device ? ['id' => $device->id, 'name' => $device->name, 'branch' => $device->branch?->name, 'printer' => $printer?->forPrinting()] : null,
            'branch' => $chosen ? Branch::whereKey($chosen)->value('name') : null,
            'branchId' => $chosen,
            'branches' => $chosen ? [] : $this->branches->choices()->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name])->values(),
            'requireDevice' => ShopSettings::of($this->tenants->require())['pos_require_device'],
        ]);
    }

    public function checkout(Request $request, PosCheckout $checkout): RedirectResponse
    {
        $data = $request->validate([
            'items' => ['nullable', 'array', 'max:100'],
            'items.*.product_id' => ['required', TenantRule::exists('products')],
            'items.*.quantity' => ['required', 'numeric', 'min:0.001', 'max:10000'],
            'items.*.addons' => ['nullable', 'array', 'max:30'],
            'items.*.addons.*.addon_id' => ['required', 'integer', TenantRule::exists('product_addons')],
            'items.*.addons.*.quantity' => ['required', 'integer', 'min:1', 'max:20'],
            'memberships' => ['nullable', 'array', 'max:5'],
            'memberships.*.plan_id' => ['required', TenantRule::exists('gym_membership_plans')],
            'packages' => ['nullable', 'array', 'max:5'],
            'packages.*.package_id' => ['required', TenantRule::exists('gym_pt_packages')],
            'payment_method' => ['required', 'in:cash,card,paymob'],
            'member_id' => ['nullable', TenantRule::exists('gym_members')],
            'discount' => ['nullable', 'numeric', 'min:0'],
            'card_reference' => ['nullable', 'string', 'max:120', 'required_if:payment_method,card'],
            'client_key' => ['nullable', 'string', 'max:64', 'regex:/^[A-Za-z0-9-]+$/'],
            'customer_name' => ['nullable', 'string', 'max:120'],
            'branch_id' => ['nullable', 'integer', TenantRule::exists('branches')],
        ], ['card_reference.required_if' => 'أدخل رقم العملية من جهاز الشبكة.']);
        $user = $request->user();

        if ((float) ($data['discount'] ?? 0) > 0 && ! $user->can('sales.discount')) {
            throw new BusinessRuleException('pos.discount_permission', 'الخصم يحتاج صلاحية «تطبيق الخصومات عند البيع».', 'discount');
        }
        abort_if(! empty($data['memberships']) && ! $user->can('memberships.sell'), 403, 'بيع العضويات يحتاج صلاحية «بيع وتجديد العضويات».');
        abort_if(! empty($data['packages']) && ! $user->can('pt.sell'), 403, 'بيع التدريب الشخصي يحتاج صلاحية «بيع باقات التدريب الشخصي».');
        if ($data['payment_method'] === 'paymob' && ! app(OnlinePaymentService::class)->available()) {
            throw new BusinessRuleException('pos.paymob_unavailable', 'الدفع عبر Paymob غير مفعّل في التطبيقات التكاملية.', 'payment_method');
        }

        $device = $this->devices->current($request);
        if ($device) {
            $this->devices->ensureBranch($device);
        } elseif (ShopSettings::of($this->tenants->require())['pos_require_device']) {
            throw new BusinessRuleException('pos.device_required', 'البيع من أجهزة نقاط البيع المفعّلة فقط. فعّل هذا الجهاز أولًا.');
        }
        $branch = $this->sellingBranch($device, isset($data['branch_id']) ? (int) $data['branch_id'] : null);

        // One basket, one sale: a double click or a retried request returns the first result, never a second sale.
        $key = isset($data['client_key']) ? 'pos-basket:'.$this->tenants->id().':'.$data['client_key'] : null;
        $documents = $key ? Cache::get($key) : null;
        if ($documents === null) {
            $run = fn () => Cache::get($key ?? '') ?? $checkout->checkout([
                'items' => $data['items'] ?? [], 'memberships' => $data['memberships'] ?? [], 'packages' => $data['packages'] ?? [],
                'method' => $data['payment_method'], 'member' => isset($data['member_id']) ? Member::find($data['member_id']) : null,
                'discount' => $data['discount'] ?? 0, 'branch_id' => $branch, 'card_reference' => $data['card_reference'] ?? null,
                'customer_name' => $data['customer_name'] ?? null, 'device' => $device, 'client_key' => $data['client_key'] ?? null,
            ]);
            $documents = $key ? Cache::lock($key.':lock', 30)->block(15, $run) : $run();
            if ($key) {
                Cache::put($key, $documents, now()->addDay());
            }
        }

        $total = array_reduce($documents, fn ($sum, $d) => Money::add($sum, $d['total']), '0');
        $sale = collect($documents)->firstWhere('kind', 'sale');
        $open = collect($documents)->contains(fn ($d) => ! $d['paid']);

        return back()
            ->with('success', $open ? 'صدرت الفواتير؛ تُفعّل العضوية أو الباقة بعد الدفع عبر الرابط.' : 'تمت عملية البيع بمبلغ '.$total.'.')
            ->with('sale_id', $sale['id'] ?? null)
            ->with('pos_result', ['documents' => $documents, 'total' => $total, 'method' => $data['payment_method'], 'at' => now()->format('H:i')]);
    }

    /**
     * The branch a till sale belongs to: the device's, else the one asked for, else the seller's own or the one the panel
     * shows, else the seller's only branch. A branch the seller may not sell in (or a closed one) is refused, and with
     * no branch at all nothing is sold: a club-wide seller with several branches chooses one first.
     */
    private function sellingBranch(?PosDevice $device, ?int $asked): int
    {
        if ($asked !== null && (! $this->branches->allows(request()->user(), $asked) || ! Branch::whereKey($asked)->where('is_active', true)->exists())) {
            throw new BusinessRuleException('pos.branch', 'لا يمكنك البيع في هذا الفرع.', 'branch_id');
        }
        if ($device && $asked !== null && $asked !== $device->branch_id) {
            throw new BusinessRuleException('pos.branch', 'هذا الجهاز يبيع من فرعه وحده.', 'branch_id');
        }

        return $device?->branch_id ?? $this->branches->assign($asked) ?? $this->onlyBranch()
            ?? throw new BusinessRuleException('pos.branch', 'اختر الفرع الذي تبيع منه قبل إتمام البيع.', 'branch_id');
    }

    /** The branch, when the user has only one open to them. */
    private function onlyBranch(): ?int
    {
        $choices = $this->branches->choices();

        return $choices->count() === 1 ? $choices->first()->id : null;
    }

    /** What the cashier should know about the member chosen: their membership, an unpaid balance, a block or freeze. */
    public function member(Member $member): JsonResponse
    {
        $member->load(['currentMembership', 'upcomingMembership']);
        $balance = Invoice::where('billable_type', $member->getMorphClass())->where('billable_id', $member->id)->open()->get()->reduce(fn ($sum, Invoice $i) => Money::add($sum, $i->balance()), '0');

        return response()->json(GymPresenter::memberRow($member) + ['balance' => Money::normalize($balance)]);
    }

    /**
     * Today's receipts at the point of sale: every payment taken here (products, memberships, packages), newest first,
     * in the branch the till serves — to reprint one, or to void a product sale (sales.refund).
     *
     * The amounts and the day's total go only to whoever may see the club's payments (invoices.view, the payments
     * screen's own permission): left out of the answer itself, not hidden on the page, or anyone at the till would add
     * up the day's takings and each colleague's. Finding a receipt by its number or customer, reprinting it and voiding a
     * sale stay as they were; a single receipt still prints whole, as the customer holds it anyway.
     */
    public function receipts(Request $request): Response
    {
        $amounts = $request->user()->can('invoices.view');
        $device = $this->devices->current($request);
        $branch = $device?->branch_id ?? $this->branches->id();
        $payments = Payment::with(['invoice:id,number,type,customer_name,billable_type,billable_id', 'receiver:id,name'])
            ->where('kind', Payment::KIND_PAYMENT)->where('status', Payment::COMPLETED)->where('notes', 'like', 'نقطة البيع%')
            ->where('paid_at', '>=', today())->when($branch, fn ($q) => $q->where('branch_id', $branch))
            ->latest('paid_at')->latest('id')->limit(200)->get();
        $sales = Sale::whereIn('invoice_id', $payments->pluck('invoice_id'))->get(['id', 'invoice_id', 'number', 'status'])->keyBy('invoice_id');

        return Inertia::render('Inventory/PosReceipts', [
            'receipts' => $payments->map(fn (Payment $p) => [
                'id' => $p->id,
                'receipt' => $p->receipt_number,
                'invoice' => $p->invoice?->number,
                'invoice_id' => $p->invoice_id,
                'kind' => ['pos' => 'منتجات', 'membership' => 'عضوية', 'pt' => 'تدريب شخصي'][$p->invoice?->type] ?? 'أخرى',
                'customer' => $p->invoice?->customer_name,
                'method' => $p->method,
                'amount' => $amounts ? $p->amount : null,
                'time' => $p->paid_at?->format('H:i'),
                'cashier' => $p->receiver?->name,
                'sale' => ($sale = $sales[$p->invoice_id] ?? null) ? ['id' => $sale->id, 'number' => $sale->number, 'voided' => $sale->status === Sale::VOIDED] : null,
            ]),
            'total' => $amounts ? Money::normalize($payments->reduce(fn ($sum, Payment $p) => Money::add($sum, $p->amount), '0')) : null,
            'can' => ['refund' => $request->user()->can('sales.refund'), 'print' => $request->user()->can('papers.print'), 'invoices' => $request->user()->can('invoices.view'), 'amounts' => $amounts],
            // A receipt printed again goes to this till's printer, through the printing layer.
            'printer' => $device?->loadMissing('peripherals')->printer()?->forPrinting(),
        ]);
    }

    public function void(Request $request, Sale $sale): RedirectResponse
    {
        SalesController::ensureBranch($request->user(), $sale);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
            'refund_method' => ['nullable', 'in:cash,card,bank_transfer,other,paymob'],
        ]);
        $this->pos->void($sale, $data['reason'], $data['refund_method'] ?? null);

        return back()->with('success', 'أُلغيت عملية البيع '.$sale->number.' وعاد المخزون وسُجّل رد المبلغ.');
    }
}
