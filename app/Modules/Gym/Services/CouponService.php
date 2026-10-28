<?php

namespace App\Modules\Gym\Services;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Modules\Gym\Models\Coupon;
use App\Modules\Gym\Models\CouponRedemption;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;

final class CouponService
{
    /** Lock and validate a coupon for this member and plan. Must run inside a transaction. */
    public function validate(string $code, Member $member, MembershipPlan $plan, string $amount): Coupon
    {
        $coupon = Coupon::where('code', mb_strtoupper(trim($code)))->lockForUpdate()->first();
        $fail = fn (string $msg) => throw new BusinessRuleException('coupon.invalid', $msg, 'coupon_code');

        if (! $coupon || ! $coupon->is_active) {
            $fail('كود الخصم غير صالح.');
        }
        if ($coupon->valid_from && $coupon->valid_from->isFuture()) {
            $fail('كود الخصم لم يبدأ بعد.');
        }
        if ($coupon->valid_until && $coupon->valid_until->endOfDay()->isPast()) {
            $fail('انتهت صلاحية كود الخصم.');
        }
        if ($coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses) {
            $fail('تم استهلاك كود الخصم بالكامل.');
        }
        if (! empty($coupon->plan_ids) && ! in_array($plan->id, array_map('intval', $coupon->plan_ids), true)) {
            $fail('كود الخصم لا ينطبق على هذه الباقة.');
        }
        if (Money::cmp($amount, $coupon->min_amount) < 0) {
            $fail('المبلغ أقل من الحد الأدنى لاستخدام الكود.');
        }
        if ($coupon->per_member_limit !== null) {
            $used = CouponRedemption::where('coupon_id', $coupon->id)->where('member_id', $member->id)->count();
            if ($used >= $coupon->per_member_limit) {
                $fail('سبق استخدام هذا الكود.');
            }
        }

        return $coupon;
    }

    public function redeem(Coupon $coupon, Member $member, Membership $membership, string $amount): void
    {
        CouponRedemption::create([
            'coupon_id' => $coupon->id,
            'member_id' => $member->id,
            'membership_id' => $membership->id,
            'amount' => $amount,
        ]);

        $coupon->increment('used_count');
    }
}
