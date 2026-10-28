<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Gym\Models\Coupon;
use App\Modules\Gym\Models\MembershipPlan;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Discount codes and offers. */
class CouponController extends Controller
{
    public function index(): Response
    {
        return Inertia::render('Gym/Coupons/Index', [
            'coupons' => Coupon::latest()->get()->map(fn (Coupon $c) => $c->only('id', 'code', 'description', 'type', 'value', 'min_amount', 'max_uses', 'used_count', 'per_member_limit', 'plan_ids', 'is_active') + [
                'valid_from' => $c->valid_from?->toDateString(), 'valid_until' => $c->valid_until?->toDateString(),
            ]),
            'plans' => MembershipPlan::orderBy('sort')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Coupon::create($this->validated($request));

        return back()->with('success', 'تم إنشاء كود الخصم.');
    }

    public function update(Request $request, Coupon $coupon): RedirectResponse
    {
        $coupon->update($this->validated($request, $coupon));

        return back()->with('success', 'تم حفظ كود الخصم.');
    }

    public function destroy(Coupon $coupon): RedirectResponse
    {
        $coupon->used_count > 0 ? $coupon->update(['is_active' => false]) : $coupon->delete();

        return back()->with('success', 'تم.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Coupon $coupon = null): array
    {
        $request->merge(['code' => mb_strtoupper(trim((string) $request->input('code')))]);

        $data = $request->validate([
            'code' => ['required', 'alpha_dash', 'max:40', TenantRule::unique('gym_coupons', 'code')->ignore($coupon?->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'type' => ['required', 'in:percent,fixed'],
            'value' => ['required', 'numeric', 'min:0.001', $request->input('type') === 'percent' ? 'max:100' : 'max:99999'],
            'min_amount' => ['nullable', 'numeric', 'min:0'],
            'max_uses' => ['nullable', 'integer', 'min:1'],
            'per_member_limit' => ['nullable', 'integer', 'min:1'],
            'plan_ids' => ['nullable', 'array'],
            'plan_ids.*' => [TenantRule::exists('gym_membership_plans')],
            'valid_from' => ['nullable', 'date'],
            'valid_until' => ['nullable', 'date', 'after_or_equal:valid_from'],
            'is_active' => ['boolean'],
        ]);
        $data['min_amount'] ??= 0;
        $data['plan_ids'] = ! empty($data['plan_ids']) ? array_map('intval', $data['plan_ids']) : null;

        return $data;
    }
}
