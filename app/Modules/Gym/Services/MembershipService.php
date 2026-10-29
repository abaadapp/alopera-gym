<?php

namespace App\Modules\Gym\Services;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Gym\Events\MembershipActivated;
use App\Modules\Gym\Events\MembershipCancelled;
use App\Modules\Gym\Events\MembershipCreated;
use App\Modules\Gym\Events\MembershipExpired;
use App\Modules\Gym\Events\MembershipFrozen;
use App\Modules\Gym\Events\MembershipResumed;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Selling, activating, freezing, renewing, upgrading and ending memberships.
 *
 * Nothing here activates a membership except {@see activate()}, which is only
 * called when its invoice is fully paid (see ActivatePaidItems listener).
 */
final class MembershipService
{
    public function __construct(
        private readonly InvoiceService $invoices,
        private readonly CouponService $coupons,
        private readonly BookingService $bookings,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Sell a membership: creates it as `pending` and issues its invoice.
     *
     * @param  array{starts_on?: string|null, coupon_code?: string|null, discount?: string|float|null, branch_id?: int|null, notes?: string|null}  $options
     */
    public function sell(Member $member, MembershipPlan $plan, array $options = []): Membership
    {
        return $this->create($member, $plan, Membership::KIND_NEW, $options);
    }

    /**
     * Renew: the new period starts the day after the current one ends (or today
     * if nothing is running), so paying early never loses days.
     *
     * @param  array{coupon_code?: string|null, discount?: string|float|null, branch_id?: int|null, notes?: string|null}  $options
     */
    public function renew(Member $member, ?MembershipPlan $plan = null, array $options = []): Membership
    {
        $latest = $member->memberships()
            ->whereIn('status', [Membership::ACTIVE, Membership::FROZEN, Membership::SCHEDULED])
            ->orderByDesc('ends_on')
            ->first();

        $latestAny = $latest ?? $member->memberships()->whereIn('status', [Membership::EXPIRED])->orderByDesc('ends_on')->first();
        $plan ??= $latestAny?->plan;

        if (! $plan) {
            throw new BusinessRuleException('membership.no_plan', 'اختر الباقة للتجديد.', 'membership_plan_id');
        }

        $today = CarbonImmutable::today();
        $start = $latest && $latest->ends_on->gte($today) ? $latest->ends_on->addDay() : $today;

        return $this->create($member, $plan, $latestAny ? Membership::KIND_RENEWAL : Membership::KIND_NEW, $options + [
            'starts_on' => $start->toDateString(),
            'previous_membership_id' => $latestAny?->id,
        ]);
    }

    /**
     * Upgrade an active membership to another plan starting today. The unused
     * value of the current plan is credited against the new one. The current
     * membership keeps working until the upgrade is paid.
     *
     * @param  array{coupon_code?: string|null, discount?: string|float|null, notes?: string|null}  $options
     */
    public function upgrade(Membership $current, MembershipPlan $plan, array $options = []): Membership
    {
        if ($current->status !== Membership::ACTIVE) {
            throw new BusinessRuleException('membership.upgrade_inactive', 'يمكن الترقية فقط من عضوية نشطة.');
        }

        if ($plan->id === $current->membership_plan_id) {
            throw new BusinessRuleException('membership.upgrade_same', 'اختر باقة مختلفة للترقية.', 'membership_plan_id');
        }

        $credit = $this->unusedValue($current);

        return $this->create($current->member, $plan, Membership::KIND_UPGRADE, $options + [
            'starts_on' => CarbonImmutable::today()->toDateString(),
            'previous_membership_id' => $current->id,
            'credit' => $credit,
            'branch_id' => $current->branch_id,
        ]);
    }

    /** Value of the days not yet used, pro rata on what was paid for the plan (fees excluded). */
    public function unusedValue(Membership $membership, ?CarbonImmutable $today = null): string
    {
        $today ??= CarbonImmutable::today();
        $paidForPlan = Money::sub(Money::sub($membership->price, $membership->discount), $membership->credit_applied);
        $paidForPlan = Money::max($paidForPlan, 0);
        $remaining = $membership->daysRemaining($today);
        $total = max(1, $membership->totalDays());

        return Money::mul($paidForPlan, min(1, $remaining / $total));
    }

    /** @param array<string, mixed> $options */
    private function create(Member $member, MembershipPlan $plan, string $kind, array $options): Membership
    {
        if (! $plan->is_active) {
            throw new BusinessRuleException('membership.plan_inactive', 'هذه الباقة غير متاحة للبيع.', 'membership_plan_id');
        }

        if ($member->status === Member::BLOCKED) {
            throw new BusinessRuleException('member.blocked', 'حساب العضو موقوف.');
        }

        return DB::transaction(function () use ($member, $plan, $kind, $options) {
            $start = CarbonImmutable::parse($options['starts_on'] ?? today())->startOfDay();
            $end = $plan->endDateFrom($start);

            $isFirst = ! $member->memberships()->whereNot('status', Membership::CANCELLED)->exists();
            $signupFee = $kind === Membership::KIND_NEW && $isFirst ? Money::normalize($plan->signup_fee) : '0.000';
            $price = Money::normalize($plan->price);

            $credit = Money::normalize($options['credit'] ?? 0);
            if (Money::cmp($credit, $price) > 0) {
                $credit = $price;
            }

            $coupon = null;
            $discount = '0.000';
            if (! empty($options['coupon_code'])) {
                $coupon = $this->coupons->validate($options['coupon_code'], $member, $plan, Money::sub($price, $credit));
                $discount = $coupon->discountFor(Money::sub($price, $credit));
            }

            if (! empty($options['discount'])) {
                $discount = Money::add($discount, $options['discount']);
            }

            if (Money::cmp(Money::add($discount, $credit), $price) > 0) {
                throw new BusinessRuleException('membership.discount', 'الخصم أكبر من سعر الباقة.', 'discount');
            }

            $membership = Membership::create([
                'branch_id' => $options['branch_id'] ?? $member->branch_id,
                'member_id' => $member->id,
                'membership_plan_id' => $plan->id,
                'previous_membership_id' => $options['previous_membership_id'] ?? null,
                'coupon_id' => $coupon?->id,
                'kind' => $kind,
                'status' => Membership::PENDING,
                'starts_on' => $start,
                'ends_on' => $end,
                'price' => $price,
                'signup_fee' => $signupFee,
                'discount' => $discount,
                'credit_applied' => $credit,
                'total' => Money::sub(Money::add($price, $signupFee), Money::add($discount, $credit)),
                'sessions_total' => $plan->sessions_limit,
                'plan_snapshot' => $plan->snapshot(),
                'notes' => $options['notes'] ?? null,
                'created_by' => Auth::guard('web')->id(),
            ]);

            $this->event($membership, 'created', ['kind' => $kind, 'total' => $membership->total]);

            if ($coupon) {
                $this->coupons->redeem($coupon, $member, $membership, $discount);
            }

            $kindLabel = ['new' => 'اشتراك', 'renewal' => 'تجديد', 'upgrade' => 'ترقية'][$kind];
            $lines = [[
                'description' => $kindLabel.' — '.$plan->name.' (من '.$start->format('Y-m-d').' إلى '.$end->format('Y-m-d').')',
                'unit_price' => $price,
                'discount' => Money::add($discount, $credit),
                'itemable' => $membership,
            ]];
            if (! Money::isZero($signupFee)) {
                $lines[] = ['description' => 'رسوم التسجيل', 'unit_price' => $signupFee, 'itemable' => $membership];
            }

            $invoice = $this->invoices->issue($member, $lines, [
                'type' => 'membership',
                'branch_id' => $membership->branch_id,
                'notes' => Money::isZero($credit) ? null : 'رصيد مستخدم من العضوية السابقة: '.$credit,
            ]);

            $membership->forceFill(['invoice_id' => $invoice->id])->save();
            $membership->refresh();

            MembershipCreated::dispatch($membership);

            return $membership;
        });
    }

    /**
     * Called when the membership's invoice is fully paid.
     *
     * If the payment arrives after the planned start date, the period is moved
     * to start today: unpaid days were never usable.
     */
    public function activate(Membership $membership, ?CarbonImmutable $today = null): Membership
    {
        $today ??= CarbonImmutable::today();
        $membership = Membership::whereKey($membership->id)->lockForUpdate()->firstOrFail();

        if ($membership->status !== Membership::PENDING) {
            return $membership;
        }

        if ($membership->starts_on->lt($today)) {
            $plan = $membership->plan;
            $membership->starts_on = $today;
            $membership->ends_on = $plan->endDateFrom($today);
        }

        $membership->status = $membership->starts_on->gt($today) ? Membership::SCHEDULED : Membership::ACTIVE;
        $membership->activated_at = now();
        $membership->save();

        $this->event($membership, 'activated', ['status' => $membership->status]);

        if ($membership->kind === Membership::KIND_UPGRADE && $membership->previous_membership_id) {
            $previous = Membership::whereKey($membership->previous_membership_id)->lockForUpdate()->first();
            if ($previous && in_array($previous->status, [Membership::ACTIVE, Membership::FROZEN, Membership::SCHEDULED], true)) {
                $previous->forceFill([
                    'status' => Membership::CANCELLED,
                    'cancelled_at' => now(),
                    'cancel_reason' => 'upgraded',
                ])->save();
                $previous->openFreeze?->update(['ended_on' => $today, 'days' => 0]);
                $this->event($previous, 'upgraded', ['to' => $membership->id]);
            }
        }

        MembershipActivated::dispatch($membership);

        return $membership;
    }

    public function freeze(Membership $membership, ?int $days, ?string $reason = null, ?CarbonImmutable $today = null): Membership
    {
        $today ??= CarbonImmutable::today();

        return DB::transaction(function () use ($membership, $days, $reason, $today) {
            $membership = Membership::whereKey($membership->id)->lockForUpdate()->firstOrFail();
            $rules = $membership->plan_snapshot;

            if ($membership->status !== Membership::ACTIVE) {
                throw new BusinessRuleException('freeze.inactive', 'يمكن تجميد عضوية نشطة فقط.');
            }
            if (! ($rules['freeze_allowed'] ?? false)) {
                throw new BusinessRuleException('freeze.not_allowed', 'هذه الباقة لا تسمح بالتجميد.');
            }
            $maxFreezes = (int) ($rules['max_freezes'] ?? 0);
            if ($maxFreezes > 0 && $membership->freezes_count >= $maxFreezes) {
                throw new BusinessRuleException('freeze.count', 'تم استهلاك عدد مرات التجميد المسموحة.');
            }
            $maxDays = (int) ($rules['max_freeze_days'] ?? 0);
            $left = $maxDays > 0 ? $maxDays - $membership->freeze_days_used : null;
            if ($left !== null && $left <= 0) {
                throw new BusinessRuleException('freeze.days', 'تم استهلاك أيام التجميد المسموحة.');
            }
            if ($days !== null && ($days < 1 || ($left !== null && $days > $left))) {
                throw new BusinessRuleException('freeze.days', 'عدد أيام التجميد غير مسموح (المتاح: '.($left ?? '∞').').', 'days');
            }
            $days ??= $left;

            $membership->freezes()->create([
                'starts_on' => $today,
                'planned_end_on' => $days ? $today->addDays($days) : null,
                'reason' => $reason,
                'created_by' => Auth::guard('web')->id(),
            ]);

            $membership->forceFill([
                'status' => Membership::FROZEN,
                'freezes_count' => $membership->freezes_count + 1,
            ])->save();

            // Bookings during the freeze would consume a membership she cannot use.
            $this->bookings->cancelFutureForMembership($membership, 'تجميد العضوية');

            $this->event($membership, 'frozen', ['days' => $days, 'reason' => $reason]);
            MembershipFrozen::dispatch($membership);

            return $membership;
        });
    }

    /** Resume a frozen membership; the end date moves forward by the days spent frozen. */
    public function resume(Membership $membership, ?CarbonImmutable $today = null): Membership
    {
        $today ??= CarbonImmutable::today();

        return DB::transaction(function () use ($membership, $today) {
            $membership = Membership::whereKey($membership->id)->lockForUpdate()->firstOrFail();

            if ($membership->status !== Membership::FROZEN) {
                throw new BusinessRuleException('resume.not_frozen', 'العضوية ليست مجمدة.');
            }

            $freeze = $membership->openFreeze()->first();
            $resumeOn = $freeze?->planned_end_on && $freeze->planned_end_on->lt($today) ? $freeze->planned_end_on : $today;
            $days = $freeze ? (int) $freeze->starts_on->diffInDays($resumeOn) : 0;

            $maxDays = (int) ($membership->plan_snapshot['max_freeze_days'] ?? 0);
            if ($maxDays > 0) {
                $days = min($days, max(0, $maxDays - $membership->freeze_days_used));
            }

            $freeze?->update(['ended_on' => $resumeOn, 'days' => $days, 'resumed_by' => Auth::guard('web')->id()]);

            $membership->forceFill([
                'status' => Membership::ACTIVE,
                'ends_on' => $membership->ends_on->addDays($days),
                'freeze_days_used' => $membership->freeze_days_used + $days,
            ])->save();

            $this->event($membership, 'resumed', ['days' => $days, 'new_end' => $membership->ends_on->toDateString()]);
            MembershipResumed::dispatch($membership);

            return $membership;
        });
    }

    public function cancel(Membership $membership, string $reason): Membership
    {
        return DB::transaction(function () use ($membership, $reason) {
            $membership = Membership::whereKey($membership->id)->lockForUpdate()->firstOrFail();

            if (in_array($membership->status, [Membership::CANCELLED, Membership::EXPIRED], true)) {
                throw new BusinessRuleException('membership.closed', 'العضوية منتهية أو ملغاة بالفعل.');
            }

            $wasPending = $membership->status === Membership::PENDING;

            $membership->forceFill([
                'status' => Membership::CANCELLED,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
                'cancelled_by' => Auth::guard('web')->id(),
            ])->save();

            $membership->openFreeze?->update(['ended_on' => today(), 'days' => 0]);
            $this->bookings->cancelFutureForMembership($membership, 'إلغاء العضوية');

            // An unpaid sale is simply withdrawn; money already taken is refunded by finance.
            if ($wasPending && $membership->invoice && Money::isZero($membership->invoice->paid_total) && $membership->invoice->isOpen()) {
                $this->invoices->void($membership->invoice, 'إلغاء العضوية: '.$reason);
            }

            $this->event($membership, 'cancelled', ['reason' => $reason]);
            MembershipCancelled::dispatch($membership);

            return $membership;
        });
    }

    /** Withdraw pending memberships whose invoice was voided. */
    public function cancelForVoidedInvoice(Invoice $invoice): void
    {
        Membership::where('invoice_id', $invoice->id)->where('status', Membership::PENDING)->get()
            ->each(function (Membership $m) {
                $m->forceFill(['status' => Membership::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => 'invoice_voided'])->save();
                $this->event($m, 'cancelled', ['reason' => 'invoice_voided']);
            });
    }

    /**
     * Daily housekeeping for the current tenant: start scheduled periods,
     * resume freezes that reached their planned end, expire finished periods.
     *
     * @return array{started: int, resumed: int, expired: int}
     */
    public function runDailyTransitions(?CarbonImmutable $today = null): array
    {
        $today ??= CarbonImmutable::today();
        $counts = ['started' => 0, 'resumed' => 0, 'expired' => 0];

        Membership::where('status', Membership::FROZEN)
            ->whereHas('openFreeze', fn ($q) => $q->whereNotNull('planned_end_on')->where('planned_end_on', '<=', $today))
            ->each(function (Membership $m) use ($today, &$counts) {
                $this->resume($m, $today);
                $counts['resumed']++;
            });

        Membership::where('status', Membership::SCHEDULED)->where('starts_on', '<=', $today)
            ->each(function (Membership $m) use (&$counts) {
                $m->forceFill(['status' => Membership::ACTIVE])->save();
                $this->event($m, 'started');
                $counts['started']++;
            });

        Membership::where('status', Membership::ACTIVE)->where('ends_on', '<', $today)
            ->each(function (Membership $m) use (&$counts) {
                $m->forceFill(['status' => Membership::EXPIRED])->save();
                $this->bookings->cancelFutureForMembership($m, 'انتهاء العضوية');
                $this->event($m, 'expired');
                MembershipExpired::dispatch($m);
                $counts['expired']++;
            });

        return $counts;
    }

    /** @param array<string, mixed> $data */
    private function event(Membership $membership, string $type, array $data = []): void
    {
        $membership->events()->create([
            'type' => $type,
            'data' => $data ?: null,
            'user_id' => Auth::guard('web')->id(),
        ]);

        $this->audit->log('membership.'.$type, $membership, $data);
    }
}
