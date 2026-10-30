<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Models\SaleItem;
use App\Modules\Inventory\Services\OrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** طلبات الموقع الإلكتروني: review, prepare, hand over or cancel orders placed on the club's website. */
class OrderController extends Controller
{
    public const STEP_LABELS = ['new' => 'جديد', 'preparing' => 'قيد التجهيز', 'ready' => 'جاهز للاستلام', 'delivered' => 'تم التسليم'];

    public function __construct(private readonly OrderService $orders) {}

    public function index(Request $request): Response
    {
        $tab = in_array($request->query('tab'), ['open', 'completed', 'cancelled', 'all'], true) ? $request->query('tab') : 'open';
        $base = fn () => SalesController::scopedFor($request->user())->where('source', Sale::WEBSITE);

        $orders = $base()->with(['items.addons', 'customer', 'cashier:id,name', 'deliveredBy:id,name', 'invoice:id,number,status,paid_total,total'])
            ->when($tab === 'open', fn ($q) => $q->where('status', Sale::PENDING))
            ->when($tab === 'completed', fn ($q) => $q->whereIn('status', [Sale::COMPLETED, Sale::VOIDED]))
            ->when($tab === 'cancelled', fn ($q) => $q->where('status', Sale::CANCELLED))
            ->when($request->string('q')->trim()->toString(), fn ($q, $t) => $q->where(fn ($w) => $w->where('number', 'ilike', "%{$t}%")->orWhere('customer_name', 'ilike', "%{$t}%")->orWhere('customer_phone', 'ilike', "%{$t}%")))
            ->latest('id')->paginate(25)->withQueryString()
            ->through(fn (Sale $s) => SalesController::row($s) + [
                'phone' => $s->customer_phone,
                'email' => $s->customer_email,
                'notes' => $s->notes,
                'step' => $s->fulfillment,
                'step_label' => self::STEP_LABELS[$s->fulfillment] ?? null,
                'reserved_until' => $s->reserved_until?->format('Y-m-d H:i'),
                'delivered_at' => $s->delivered_at?->format('Y-m-d H:i'),
                'delivered_by' => $s->deliveredBy?->name,
                'cancel_reason' => $s->cancel_reason,
                'invoice_number' => $s->invoice?->number,
                'items' => $s->items->map(fn (SaleItem $i) => $i->summary()),
            ]);

        return Inertia::render('Sales/Orders', [
            'orders' => $orders,
            'tab' => $tab,
            'counts' => [
                'open' => $base()->where('status', Sale::PENDING)->count(),
                'new' => $base()->where('status', Sale::PENDING)->where('fulfillment', 'new')->count(),
            ],
            'filters' => $request->only('q'),
            'can' => ['refund' => $request->user()->can('sales.refund')],
        ]);
    }

    public function status(Request $request, Sale $sale): RedirectResponse
    {
        SalesController::ensureBranch($request->user(), $sale);
        $data = $request->validate(['status' => ['required', 'in:preparing,ready']]);
        $this->orders->advance($sale, $data['status']);

        return back()->with('success', 'الطلب '.$sale->number.': '.self::STEP_LABELS[$data['status']].'.');
    }

    public function deliver(Request $request, Sale $sale): RedirectResponse
    {
        SalesController::ensureBranch($request->user(), $sale);
        $data = $request->validate([
            'method' => ['nullable', 'in:cash,card'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);
        $this->orders->deliver($sale, $data['method'] ?? null, $data['reference'] ?? null);

        return back()->with('success', 'تم تسليم الطلب '.$sale->number.'.');
    }

    public function cancel(Request $request, Sale $sale): RedirectResponse
    {
        SalesController::ensureBranch($request->user(), $sale);
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:250'],
            'refund_method' => ['nullable', 'in:'.implode(',', OrderService::REFUND_METHODS)],
        ]);
        // Cancelling a paid order gives money back: that needs the refund permission, not only order handling.
        if ($sale->paid_at && ! $request->user()->can('sales.refund')) {
            abort(403);
        }
        $this->orders->cancel($sale, $data['reason'], $data['refund_method'] ?? null);

        return back()->with('success', 'أُلغي الطلب '.$sale->number.' وعادت الكميات إلى المخزون.');
    }
}
