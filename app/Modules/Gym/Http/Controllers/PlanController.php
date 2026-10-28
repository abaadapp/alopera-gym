<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Gym\Http\GymPresenter;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Models\Program;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Membership plans: types, prices, durations, freeze rules, linked programs. */
class PlanController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Gym/Plans/Index', [
            'plans' => MembershipPlan::with('programs:id')->withCount(['programs'])->orderBy('sort')->orderBy('id')->get()
                ->map(fn ($p) => GymPresenter::plan($p) + ['active_count' => Membership::where('membership_plan_id', $p->id)->where('status', Membership::ACTIVE)->count()]),
            'programs' => Program::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'types' => MembershipPlan::TYPES,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        $plan = MembershipPlan::create(collect($data)->except('program_ids')->all());
        $plan->programs()->sync($data['program_ids'] ?? []);

        return back()->with('success', 'تمت إضافة الباقة.');
    }

    public function update(Request $request, MembershipPlan $plan): RedirectResponse
    {
        $data = $this->validated($request);
        // Existing memberships keep the terms they were sold with (plan_snapshot).
        $plan->update(collect($data)->except('program_ids')->all());
        $plan->programs()->sync($data['program_ids'] ?? []);

        return back()->with('success', 'تم حفظ الباقة. العضويات الحالية تحتفظ بشروطها الأصلية.');
    }

    public function destroy(MembershipPlan $plan): RedirectResponse
    {
        $plan->update(['is_active' => false]);
        $plan->delete();

        return back()->with('success', 'تم حذف الباقة من البيع. العضويات السابقة لم تتأثر.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', 'in:'.implode(',', array_keys(MembershipPlan::TYPES))],
            'description' => ['nullable', 'string', 'max:2000'],
            'duration_value' => ['required', 'integer', 'min:1', 'max:1000'],
            'duration_unit' => ['required', 'in:'.implode(',', MembershipPlan::UNITS)],
            'price' => ['required', 'numeric', 'min:0', 'max:99999'],
            'signup_fee' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'sessions_limit' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'freeze_allowed' => ['boolean'],
            'max_freeze_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'max_freezes' => ['nullable', 'integer', 'min:0', 'max:50'],
            'features' => ['nullable', 'array', 'max:12'],
            'features.*' => ['string', 'max:120'],
            'color' => ['nullable', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'is_public' => ['boolean'],
            'is_featured' => ['boolean'],
            'is_active' => ['boolean'],
            'sort' => ['nullable', 'integer'],
            'program_ids' => ['array'],
            'program_ids.*' => [TenantRule::exists('gym_programs')],
        ]);
        $data['signup_fee'] ??= 0;
        $data['max_freeze_days'] ??= 0;
        $data['max_freezes'] ??= 0;
        $data['sort'] ??= 0;

        return $data;
    }
}
