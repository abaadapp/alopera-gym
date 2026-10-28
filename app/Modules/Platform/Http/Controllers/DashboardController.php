<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\PlatformInvoice;
use App\Models\Tenant;
use App\Models\TenantSubscription;
use App\Modules\Gym\Models\Member;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(TenantContext $tenants): Response
    {
        $byStatus = Tenant::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        // Monthly recurring revenue: yearly prices spread over 12 months.
        $mrr = TenantSubscription::whereIn('status', [TenantSubscription::ACTIVE])
            ->get(['price', 'billing_cycle'])
            ->sum(fn ($s) => $s->billing_cycle === TenantSubscription::YEARLY ? (float) $s->price / 12 : (float) $s->price);

        return Inertia::render('Platform/Dashboard', [
            'stats' => [
                'tenants' => (int) $byStatus->sum(),
                'active' => (int) ($byStatus[Tenant::STATUS_ACTIVE] ?? 0),
                'trial' => (int) ($byStatus[Tenant::STATUS_TRIAL] ?? 0),
                'past_due' => (int) ($byStatus[Tenant::STATUS_PAST_DUE] ?? 0),
                'suspended' => (int) ($byStatus[Tenant::STATUS_SUSPENDED] ?? 0),
                'mrr' => round($mrr, 3),
                'unpaid_invoices' => PlatformInvoice::where('status', PlatformInvoice::UNPAID)->count(),
                'unpaid_amount' => (float) PlatformInvoice::where('status', PlatformInvoice::UNPAID)->sum('amount'),
                'members' => $tenants->bypass(fn () => Member::count()),
            ],
            'recent' => Tenant::with('currentSubscription.plan:id,name')->latest()->limit(8)->get()->map(fn (Tenant $t) => [
                'id' => $t->id, 'name' => $t->name, 'slug' => $t->slug, 'status' => $t->status,
                'plan' => $t->currentSubscription?->plan?->name, 'created_at' => $t->created_at->toDateString(),
            ]),
        ]);
    }
}
