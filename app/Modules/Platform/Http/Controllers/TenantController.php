<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Core\Auth\DemoAccounts;
use App\Core\Auth\SystemRoles;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Models\PlatformPlan;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Platform\Services\SubscriptionService;
use App\Modules\Platform\Services\TenantProvisioner;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class TenantController extends Controller
{
    public function index(Request $request): Response
    {
        $tenants = Tenant::with('currentSubscription.plan:id,name')
            ->when($request->string('q')->toString(), fn ($q, $term) => $q->where(fn ($w) => $w->where('name', 'ilike', "%{$term}%")->orWhere('slug', 'ilike', "%{$term}%")))
            ->when($request->string('status')->toString(), fn ($q, $s) => $q->where('status', $s))
            ->latest()->paginate(20)->withQueryString()
            ->through(fn (Tenant $t) => [
                'id' => $t->id, 'name' => $t->name, 'slug' => $t->slug, 'status' => $t->status, 'city' => $t->city,
                'domain' => $t->domain, 'plan' => $t->currentSubscription?->plan?->name,
                'ends_on' => $t->currentSubscription?->ends_on?->toDateString(), 'created_at' => $t->created_at->toDateString(),
            ]);

        return Inertia::render('Platform/Tenants/Index', ['tenants' => $tenants, 'filters' => $request->only('q', 'status')]);
    }

    public function create(): Response
    {
        return Inertia::render('Platform/Tenants/Create', ['plans' => PlatformPlan::where('is_active', true)->orderBy('sort')->get(['id', 'name', 'price_monthly', 'price_yearly'])]);
    }

    public function store(Request $request, TenantProvisioner $provisioner): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'slug' => ['nullable', 'regex:/^[a-z0-9]+(-[a-z0-9]+)*$/', 'max:60', Rule::unique('tenants', 'slug')],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'city' => ['nullable', 'string', 'max:80'],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'email', 'max:255', Rule::unique('users', 'email'), DemoAccounts::rule()],
            'owner_password' => ['required', Password::defaults()],
            'platform_plan_id' => ['required', 'exists:platform_plans,id'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
        ], ['slug.regex' => 'المعرّف في الرابط: حروف إنجليزية صغيرة وأرقام وشرطات فقط (مثل alopera-gym).']);

        $tenant = $provisioner->provision(
            ['name' => $data['name'], 'slug' => $data['slug'] ?? null, 'email' => $data['email'] ?? null, 'phone' => $data['phone'] ?? null, 'city' => $data['city'] ?? null],
            ['name' => $data['owner_name'], 'email' => $data['owner_email'], 'password' => $data['owner_password']],
            PlatformPlan::find($data['platform_plan_id']),
            $data['billing_cycle'],
        );

        return redirect()->route('platform.tenants.show', $tenant)->with('success', 'تم إنشاء النادي وحساب المالك.');
    }

    public function show(Tenant $tenant, TenantContext $tenants): Response
    {
        $tenant->load(['subscriptions.plan:id,name', 'platformInvoices' => fn ($q) => $q->latest()]);

        $usage = $tenants->run($tenant, fn () => [
            'members' => Member::count(),
            'active_memberships' => Membership::where('status', Membership::ACTIVE)->count(),
            'staff' => User::ofTenant($tenant->id)->count(),
        ]);

        $owner = User::ofTenant($tenant->id)->whereHas('role', fn ($q) => $q->where('key', SystemRoles::OWNER))->first(['id', 'name', 'email']);

        return Inertia::render('Platform/Tenants/Show', [
            'tenant' => $tenant->only('id', 'name', 'slug', 'legal_name', 'status', 'domain', 'email', 'phone', 'city', 'primary_color', 'secondary_color', 'suspension_reason') + [
                'logo_url' => $tenant->logoUrl(), 'created_at' => $tenant->created_at->toDateString(),
                'trial_ends_at' => $tenant->trial_ends_at?->toDateString(),
                'listed_on_landing' => (bool) $tenant->setting('listed_on_landing'),
            ],
            'owner' => $owner,
            'usage' => $usage,
            'subscriptions' => $tenant->subscriptions->sortByDesc('id')->values()->map(fn ($s) => [
                'id' => $s->id, 'plan' => $s->plan?->name, 'cycle' => $s->billing_cycle, 'status' => $s->status,
                'price' => $s->price, 'starts_on' => $s->starts_on->toDateString(), 'ends_on' => $s->ends_on->toDateString(),
            ]),
            'invoices' => $tenant->platformInvoices->map(fn ($i) => $i->only('id', 'number', 'status', 'amount', 'currency', 'payment_method') + [
                'period' => $i->period_start->toDateString().' ← '.$i->period_end->toDateString(), 'due_on' => $i->due_on->toDateString(),
                'paid_at' => $i->paid_at?->toDateString(),
            ]),
            'plans' => PlatformPlan::where('is_active', true)->orderBy('sort')->get(['id', 'name', 'price_monthly', 'price_yearly']),
        ]);
    }

    public function update(Request $request, Tenant $tenant): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'legal_name' => ['nullable', 'string', 'max:160'],
            'domain' => ['nullable', 'string', 'max:190', 'regex:/^(?!-)[a-z0-9-]+(\.[a-z0-9-]+)+$/i', Rule::unique('tenants', 'domain')->ignore($tenant->id)],
            'email' => ['nullable', 'email'],
            'phone' => ['nullable', 'string', 'max:32'],
            'city' => ['nullable', 'string', 'max:80'],
            'listed_on_landing' => ['boolean'],
        ]);
        $data['domain'] = isset($data['domain']) ? strtolower($data['domain']) : null;
        // Showing a club on the platform's public page is an explicit choice, never a default.
        $data['settings'] = array_replace($tenant->settings ?? [], ['listed_on_landing' => (bool) ($data['listed_on_landing'] ?? false)]);
        unset($data['listed_on_landing']);
        $oldDomain = $tenant->domain;
        $tenant->update($data);
        foreach (array_filter([$oldDomain, $data['domain']]) as $domain) {
            cache()->forget('custom-domain:'.$domain);
            cache()->forget('custom-domain:www.'.$domain);
        }

        return back()->with('success', 'تم حفظ بيانات النادي.');
    }

    public function subscribe(Request $request, Tenant $tenant, SubscriptionService $subscriptions): RedirectResponse
    {
        $data = $request->validate([
            'platform_plan_id' => ['required', 'exists:platform_plans,id'],
            'billing_cycle' => ['required', 'in:monthly,yearly'],
        ]);
        $subscriptions->subscribe($tenant, PlatformPlan::findOrFail($data['platform_plan_id']), $data['billing_cycle']);

        return back()->with('success', 'تم إنشاء الاشتراك وإصدار فاتورة المنصة.');
    }

    public function suspend(Request $request, Tenant $tenant, SubscriptionService $subscriptions): RedirectResponse
    {
        $data = $request->validate(['reason' => ['required', 'string', 'max:200']]);
        $subscriptions->suspend($tenant, $data['reason']);

        return back()->with('success', 'تم إيقاف النادي.');
    }

    public function reactivate(Tenant $tenant, SubscriptionService $subscriptions): RedirectResponse
    {
        $subscriptions->reactivate($tenant);

        return back()->with('success', 'تم إعادة تفعيل النادي.');
    }
}
