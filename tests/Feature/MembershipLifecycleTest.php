<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\TenantContext;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Access\AccessControlService;
use App\Modules\Gym\Models\Coupon;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** Spec 1.3 / 1.4: statuses, activation only after payment, freeze, renewal, change of plan, expiry. */
class MembershipLifecycleTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function svc(): MembershipService
    {
        return app(MembershipService::class);
    }

    private function pay(Membership $m, string|float|null $amount = null, string $method = 'cash')
    {
        return app(PaymentService::class)->record($m->invoice()->first(), $amount ?? $m->invoice()->first()->balance(), $method);
    }

    public function test_sold_membership_waits_for_payment_and_blocks_access_until_paid(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 25, 'signup_fee' => 5]);

        $this->asTenant($club, function () use ($member, $plan) {
            $m = $this->svc()->sell($member, $plan);
            $this->assertSame(Membership::PENDING, $m->status);
            $this->assertSame('30.000', $m->invoice->total);
            $this->assertSame('pending_payment', app(AccessControlService::class)->evaluate($member)->reason);

            $this->pay($m, 10);
            $this->assertSame(Membership::PENDING, $m->fresh()->status, 'Partial payment does not activate.');
            $this->assertSame(Invoice::PARTIALLY_PAID, $m->invoice->fresh()->status);

            $this->pay($m->fresh());
            $this->assertSame(Membership::ACTIVE, $m->fresh()->status);
            $this->assertTrue(app(AccessControlService::class)->evaluate($member->fresh())->allowed);
        });
    }

    public function test_bank_transfer_activates_only_after_finance_confirms(): void
    {
        $club = $this->club();
        $member = $this->member($club);

        $this->asTenant($club, function () use ($club, $member) {
            $m = $this->svc()->sell($member, $this->plan($club));
            $payment = $this->pay($m, null, 'bank_transfer');

            $this->assertSame('pending', $payment->status);
            $this->assertNull($payment->receipt_number);
            $this->assertSame(Membership::PENDING, $m->fresh()->status);

            app(PaymentService::class)->confirm($payment);
            $this->assertSame(Membership::ACTIVE, $m->fresh()->status);
            $this->assertNotNull($payment->fresh()->receipt_number);
        });
    }

    public function test_late_payment_moves_the_period_to_start_on_the_payment_day(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club, ['duration_value' => 30, 'duration_unit' => 'day']);

        $m = $this->asTenant($club, fn () => $this->svc()->sell($member, $plan));
        $this->travel(5)->days();

        $this->asTenant($club, function () use ($m) {
            $this->pay($m);
            $m = $m->fresh();
            $this->assertSame(today()->toDateString(), $m->starts_on->toDateString());
            $this->assertSame(today()->addDays(29)->toDateString(), $m->ends_on->toDateString());
        });
    }

    public function test_renewal_starts_after_the_current_period_and_expired_members_regain_access_after_renewal(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club, ['duration_value' => 10, 'duration_unit' => 'day']);
        $current = $this->activeMembership($club, $member, $plan);

        $this->asTenant($club, function () use ($member, $plan, $current) {
            $renewal = $this->svc()->renew($member, $plan);
            $this->assertSame(Membership::KIND_RENEWAL, $renewal->kind);
            $this->assertSame($current->ends_on->addDay()->toDateString(), $renewal->starts_on->toDateString());
            $this->pay($renewal);
            $this->assertSame(Membership::SCHEDULED, $renewal->fresh()->status);
        });

        // Day 11: the first period has ended, the renewal starts — no gap in access.
        $this->travel(10)->days();
        $this->asTenant($club, function () use ($member, $current) {
            $result = $this->svc()->runDailyTransitions();
            $this->assertSame(1, $result['expired']);
            $this->assertSame(1, $result['started']);
            $this->assertSame(Membership::EXPIRED, $current->fresh()->status);
            $this->assertTrue(app(AccessControlService::class)->evaluate($member->fresh())->allowed);
        });
    }

    public function test_expired_membership_blocks_entry_and_renewal_after_payment_restores_it(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club, ['duration_value' => 7, 'duration_unit' => 'day']);
        $this->activeMembership($club, $member, $plan);

        $this->travel(8)->days();
        $this->asTenant($club, function () use ($member, $plan) {
            $this->svc()->runDailyTransitions();
            $this->assertSame('membership_expired', app(AccessControlService::class)->evaluate($member->fresh())->reason);

            $renewal = $this->svc()->renew($member, $plan);
            $this->assertSame(today()->toDateString(), $renewal->starts_on->toDateString());
            $this->assertFalse(app(AccessControlService::class)->evaluate($member->fresh())->allowed);

            $this->pay($renewal);
            $this->assertTrue(app(AccessControlService::class)->evaluate($member->fresh())->allowed);
        });
    }

    public function test_freeze_blocks_access_and_resume_extends_the_end_date(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $m = $this->activeMembership($club, $member, $this->plan($club, ['max_freeze_days' => 10, 'max_freezes' => 1]));
        $originalEnd = $m->ends_on;

        $this->asTenant($club, function () use ($m, $member) {
            $this->svc()->freeze($m, 5, 'travel');
            $this->assertSame(Membership::FROZEN, $m->fresh()->status);
            $this->assertSame('membership_frozen', app(AccessControlService::class)->evaluate($member->fresh())->reason);
        });

        $this->travel(3)->days();
        $this->asTenant($club, function () use ($m, $originalEnd) {
            $resumed = $this->svc()->resume($m->fresh());
            $this->assertSame(Membership::ACTIVE, $resumed->status);
            $this->assertSame($originalEnd->addDays(3)->toDateString(), $resumed->ends_on->toDateString());
            $this->assertSame(3, $resumed->freeze_days_used);

            $this->expectException(BusinessRuleException::class);
            $this->svc()->freeze($resumed, 2); // only one freeze allowed
        });
    }

    public function test_freeze_auto_resumes_when_the_planned_days_are_over(): void
    {
        $club = $this->club();
        $m = $this->activeMembership($club, $this->member($club));
        $end = $m->ends_on;
        $this->asTenant($club, fn () => $this->svc()->freeze($m, 4));

        $this->travel(6)->days();
        $this->asTenant($club, function () use ($m, $end) {
            $this->assertSame(1, $this->svc()->runDailyTransitions()['resumed']);
            $this->assertSame(Membership::ACTIVE, $m->fresh()->status);
            $this->assertSame($end->addDays(4)->toDateString(), $m->fresh()->ends_on->toDateString());
        });
    }

    public function test_plans_without_freeze_refuse_it(): void
    {
        $club = $this->club();
        $m = $this->activeMembership($club, $this->member($club), $this->plan($club, ['freeze_allowed' => false]));

        $this->expectException(BusinessRuleException::class);
        $this->asTenant($club, fn () => $this->svc()->freeze($m, 3));
    }

    public function test_cancelling_an_unpaid_sale_voids_its_invoice(): void
    {
        $club = $this->club();
        $member = $this->member($club);

        $this->asTenant($club, function () use ($club, $member) {
            $m = $this->svc()->sell($member, $this->plan($club));
            $this->svc()->cancel($m, 'changed her mind');
            $this->assertSame(Membership::CANCELLED, $m->fresh()->status);
            $this->assertSame(Invoice::VOID, $m->invoice->fresh()->status);
        });
    }

    public function test_change_of_plan_credits_unused_days_and_closes_the_old_membership_after_payment(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $basic = $this->plan($club, ['name' => 'Basic', 'price' => 30, 'duration_value' => 30, 'duration_unit' => 'day']);
        $premium = $this->plan($club, ['name' => 'Premium', 'price' => 60, 'duration_value' => 30, 'duration_unit' => 'day']);
        $old = $this->activeMembership($club, $member, $basic);

        $this->travel(10)->days();
        $this->asTenant($club, function () use ($old, $premium) {
            // 20 of 30 days left → 20.000 credit on a 60.000 plan
            $upgrade = $this->svc()->upgrade($old->fresh(), $premium);
            $this->assertSame('20.000', $upgrade->credit_applied);
            $this->assertSame('40.000', $upgrade->invoice->total);
            $this->assertSame(Membership::ACTIVE, $old->fresh()->status, 'Old membership keeps working until the upgrade is paid.');

            $this->pay($upgrade);
            $this->assertSame(Membership::ACTIVE, $upgrade->fresh()->status);
            $this->assertSame(Membership::CANCELLED, $old->fresh()->status);
            $this->assertSame('upgraded', $old->fresh()->cancel_reason);
        });
    }

    public function test_coupons_apply_with_their_limits(): void
    {
        $club = $this->club();
        $plan = $this->plan($club, ['price' => 50]);
        $other = $this->plan($club, ['price' => 50]);
        $member = $this->member($club);

        $this->asTenant($club, function () use ($plan, $other, $member) {
            Coupon::create(['code' => 'WELCOME10', 'type' => 'percent', 'value' => 10, 'per_member_limit' => 1, 'plan_ids' => [$plan->id]]);

            $m = $this->svc()->sell($member, $plan, ['coupon_code' => 'welcome10']);
            $this->assertSame('5.000', $m->discount);
            $this->assertSame('45.000', $m->invoice->total);

            try {
                $this->svc()->sell($member, $plan, ['coupon_code' => 'WELCOME10']);
                $this->fail('Per-member limit must apply.');
            } catch (BusinessRuleException $e) {
                $this->assertSame('coupon.invalid', $e->rule);
            }

            $this->expectException(BusinessRuleException::class);
            $this->svc()->sell($this->member(app(TenantContext::class)->require()), $other, ['coupon_code' => 'WELCOME10']);
        });
    }

    public function test_signup_fee_is_charged_only_on_the_first_membership(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 20, 'signup_fee' => 5]);

        $first = $this->activeMembership($club, $member, $plan);
        $this->assertSame('5.000', $first->signup_fee);

        $renewal = $this->asTenant($club, fn () => $this->svc()->renew($member, $plan));
        $this->assertSame('0.000', $renewal->signup_fee);
    }

    public function test_zero_total_membership_activates_immediately(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $free = $this->plan($club, ['price' => 0]);

        $m = $this->asTenant($club, fn () => $this->svc()->sell($member, $free));
        $this->assertSame(Membership::ACTIVE, $m->status);
        $this->assertSame(Invoice::PAID, $m->invoice->status);
    }

    public function test_desk_flow_through_http_sells_and_takes_payment(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club);
        $reception = $this->staff($club, 'reception');

        $this->actingAs($reception)->post(route('club.memberships.store', $member->id), ['membership_plan_id' => $plan->id, 'mode' => 'new'])
            ->assertRedirect();
        $m = $this->asTenant($club, fn () => Membership::firstOrFail());

        $this->actingAs($reception)->post(route('club.payments.store', $m->invoice_id), ['amount' => 25, 'method' => 'card'])
            ->assertSessionHasErrors('reference'); // card needs the terminal reference
        $this->actingAs($reception)->post(route('club.payments.store', $m->invoice_id), ['amount' => 25, 'method' => 'card', 'reference' => 'T-1234'])
            ->assertSessionHasNoErrors();

        $this->assertSame(Membership::ACTIVE, $this->asTenant($club, fn () => $m->fresh()->status));
    }
}
