<?php

namespace App\Http\Controllers\Club;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\PlatformInvoice;
use App\Modules\Platform\Services\PlanLimits;
use Inertia\Inertia;
use Inertia\Response;

/** The club's own subscription to the platform (read-only for the club). */
class BillingController extends Controller
{
    public function index(TenantContext $tenants, PlanLimits $limits): Response
    {
        $tenant = $tenants->require();
        $subscription = $tenant->currentSubscription()->with('plan')->first();

        return Inertia::render('Club/Settings/Billing', [
            'subscription' => $subscription ? [
                'plan' => $subscription->plan?->name, 'cycle' => $subscription->billing_cycle, 'status' => $subscription->status,
                'price' => $subscription->price, 'starts_on' => $subscription->starts_on->toDateString(), 'ends_on' => $subscription->ends_on->toDateString(),
            ] : null,
            'usage' => $limits->usage(),
            'invoices' => PlatformInvoice::where('tenant_id', $tenant->id)->latest()->get()->map(fn ($i) => $i->only('id', 'number', 'status', 'amount', 'currency') + [
                'period' => $i->period_start->toDateString().' ← '.$i->period_end->toDateString(), 'due_on' => $i->due_on->toDateString(),
            ]),
            'status' => $tenant->status,
        ]);
    }
}
