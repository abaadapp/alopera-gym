<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\OrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** طلبات الموقع الإلكتروني: prepare, hand over or cancel an order placed on the club's website (from the sale's page). */
class OrderController extends Controller
{
    public const STEP_LABELS = ['new' => 'جديد', 'preparing' => 'قيد التجهيز', 'ready' => 'جاهز للاستلام', 'delivered' => 'تم التسليم'];

    public function __construct(private readonly OrderService $orders) {}

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
