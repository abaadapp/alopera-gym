<?php

namespace App\Modules\Platform\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\PlatformPlan;
use App\Models\TenantSubscription;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/** SaaS plans the platform sells to clubs. */
class PlanController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Platform/Plans', [
            'plans' => PlatformPlan::orderBy('sort')->get()->map(fn (PlatformPlan $p) => $p->toArray() + [
                'subscribers' => TenantSubscription::where('platform_plan_id', $p->id)->whereIn('status', ['active', 'trialing', 'past_due'])->count(),
            ]),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        PlatformPlan::create($this->validated($request));

        return back()->with('success', 'تمت إضافة الباقة.');
    }

    public function update(Request $request, PlatformPlan $plan): RedirectResponse
    {
        $plan->update($this->validated($request, $plan));

        return back()->with('success', 'تم حفظ الباقة.');
    }

    public function destroy(PlatformPlan $plan): RedirectResponse
    {
        if (TenantSubscription::where('platform_plan_id', $plan->id)->exists()) {
            $plan->update(['is_active' => false]);

            return back()->with('success', 'الباقة مستخدمة؛ تم إيقافها بدل حذفها.');
        }
        $plan->delete();

        return back()->with('success', 'تم حذف الباقة.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?PlatformPlan $plan = null): array
    {
        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:40', Rule::unique('platform_plans', 'code')->ignore($plan?->id)],
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:255'],
            'price_monthly' => ['required', 'numeric', 'min:0', 'max:99999'],
            'price_yearly' => ['required', 'numeric', 'min:0', 'max:999999'],
            'max_members' => ['nullable', 'integer', 'min:1'],
            'max_staff' => ['nullable', 'integer', 'min:1'],
            'max_branches' => ['nullable', 'integer', 'min:1'],
            'trial_days' => ['required', 'integer', 'min:0', 'max:90'],
            'features' => ['nullable', 'array'],
            'features.*' => ['string', 'max:120'],
            'is_active' => ['boolean'],
            'sort' => ['nullable', 'integer'],
        ]);
        $data['sort'] ??= 0;

        return $data;
    }
}
