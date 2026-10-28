<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PlatformInvoice;
use App\Modules\Platform\Services\SubscriptionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class PlatformInvoiceController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Platform/Invoices', [
            'invoices' => PlatformInvoice::with('tenant:id,name,slug')
                ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
                ->latest()->paginate(25)->withQueryString()
                ->through(fn (PlatformInvoice $i) => $i->only('id', 'number', 'status', 'amount', 'currency', 'payment_method', 'payment_reference') + [
                    'tenant' => $i->tenant?->name, 'tenant_id' => $i->tenant_id,
                    'period' => $i->period_start->toDateString().' → '.$i->period_end->toDateString(),
                    'due_on' => $i->due_on->toDateString(), 'paid_at' => $i->paid_at?->toDateString(),
                ]),
            'filters' => $request->only('status'),
        ]);
    }

    public function pay(Request $request, PlatformInvoice $invoice, SubscriptionService $subscriptions): RedirectResponse
    {
        $data = $request->validate([
            'method' => ['required', 'in:bank_transfer,cash,card,other'],
            'reference' => ['nullable', 'string', 'max:120'],
        ]);
        $subscriptions->markPaid($invoice, $data['method'], $data['reference'] ?? null);

        return back()->with('success', 'تم تسجيل دفع فاتورة المنصة وتفعيل اشتراك النادي.');
    }
}
