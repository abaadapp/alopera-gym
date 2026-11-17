<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Models\Tenant;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\GiftMembership;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Services\GiftService;
use App\Modules\Gym\Services\MembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * #192: a gift is an existing plan sold on its own invoice, redeemable only once that invoice is fully paid
 * (InvoicePaid → ActivatePaidItems), redeemed once into a membership whose zero invoice activates it through the
 * same path, without a second payment or the price counted twice.
 */
class GiftMembershipTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function gifts(): GiftService
    {
        return app(GiftService::class);
    }

    private function giftFor(Tenant $club, MembershipPlan $plan, string $source = 'staff'): GiftMembership
    {
        return $this->asTenant($club, fn () => $this->gifts()->issue($plan, [
            'giver_name' => 'Sara', 'giver_phone' => '96891234567', 'recipient_name' => 'Noor', 'message' => 'Happy birthday',
        ], $source));
    }

    private function paidGift(Tenant $club, MembershipPlan $plan): GiftMembership
    {
        $gift = $this->giftFor($club, $plan);
        $this->asTenant($club, fn () => app(PaymentService::class)->record($gift->invoice, $gift->invoice->balance(), 'cash'));

        return $gift->fresh();
    }

    public function test_a_gift_is_sold_on_its_own_invoice_and_is_not_redeemable_before_it_is_paid(): void
    {
        $club = $this->club();
        $plan = $this->plan($club, ['price' => 30, 'signup_fee' => 5]);
        $gift = $this->giftFor($club, $plan, 'site');

        $this->asTenant($club, function () use ($gift, $plan) {
            $this->assertSame(GiftMembership::PENDING, $gift->status);
            $this->assertSame('site', $gift->source);
            $this->assertSame('30.000', $gift->price);
            $this->assertSame('30.000', $gift->invoice->total, 'The plan price only: no signup fee on a gift.');
            $this->assertNull($gift->invoice->billable_id, 'The giver needs no member account.');
            $this->assertSame('Sara', $gift->invoice->customer_name);
            $this->assertSame('gift_membership', $gift->invoice->items()->first()->itemable_type);
            $this->assertSame($plan->snapshot()['duration_unit'], $gift->plan_snapshot['duration_unit']);
            $this->assertNull($gift->expires_on);

            $member = $this->member(Tenant::find($gift->tenant_id));
            try {
                $this->gifts()->redeem($gift, $member, today()->toDateString(), 'link');
                $this->fail('An unpaid gift was redeemed.');
            } catch (BusinessRuleException $e) {
                $this->assertSame('gift.unpaid', $e->rule);
            }
            $this->assertSame(0, Membership::count());
        });
    }

    public function test_full_payment_makes_it_redeemable_for_the_club_window_but_a_partial_or_pending_transfer_does_not(): void
    {
        $club = $this->club();
        $plan = $this->plan($club, ['price' => 30]);
        $gift = $this->giftFor($club, $plan);

        $this->asTenant($club, function () use ($gift) {
            $pay = app(PaymentService::class);
            $pay->record($gift->invoice, 10, 'cash');
            $this->assertSame(GiftMembership::PENDING, $gift->fresh()->status, 'A partial payment issues nothing.');

            $transfer = $pay->record($gift->invoice->fresh(), 20, 'bank_transfer');
            $this->assertSame(Payment::PENDING, $transfer->status);
            $this->assertSame(GiftMembership::PENDING, $gift->fresh()->status, 'A transfer awaiting confirmation issues nothing.');

            $pay->confirm($transfer);
            $gift = $gift->fresh();
            $this->assertSame(GiftMembership::PAID, $gift->status);
            $this->assertSame(today()->addDays(90)->toDateString(), $gift->expires_on->toDateString());
        });
    }

    public function test_the_redemption_window_follows_the_club_setting(): void
    {
        $club = $this->club();
        $club->forceFill(['settings' => array_merge($club->settings ?? [], ['gift_redeem_days' => 30])])->save();
        $gift = $this->paidGift($club, $this->plan($club));

        $this->assertSame(today()->addDays(30)->toDateString(), $gift->expires_on->toDateString());
    }

    public function test_redeeming_from_the_link_gives_an_existing_member_the_period_from_the_chosen_date_at_no_charge(): void
    {
        $club = $this->club();
        $plan = $this->plan($club, ['price' => 30, 'signup_fee' => 5, 'duration_value' => 1, 'duration_unit' => 'month']);
        $gift = $this->paidGift($club, $plan);
        $member = $this->member($club);
        $start = today()->addDays(10);

        $this->asTenant($club, function () use ($gift, $member, $start) {
            $gift = $this->gifts()->redeem($gift, $member, $start->toDateString(), 'link');
            $this->assertSame(GiftMembership::REDEEMED, $gift->status);
            $this->assertSame('link', $gift->redeemed_via);
            $this->assertSame($member->id, $gift->member_id);

            $membership = $gift->membership;
            $this->assertSame(Membership::SCHEDULED, $membership->status, 'Activated by InvoicePaid on its zero invoice, starting later.');
            $this->assertSame($start->toDateString(), $membership->starts_on->toDateString());
            $this->assertSame(CarbonImmutable::parse($start)->addMonthNoOverflow()->subDay()->toDateString(), $membership->ends_on->toDateString());
            $this->assertSame('0.000', $membership->total);
            $this->assertSame('0.000', $membership->signup_fee, 'No signup fee on a redeemed gift.');

            $zero = $membership->invoice;
            $this->assertSame(Invoice::PAID, $zero->status);
            $this->assertSame('0.000', $zero->total);
            $this->assertSame($member->id, $zero->billable_id);

            // The money once: the gift's invoice and its one payment, nothing on the recipient.
            $this->assertSame(2, Invoice::count());
            $this->assertSame(1, Payment::count());
            $this->assertEqualsWithDelta(30.0, (float) Invoice::sum('total'), 0.0001);
            $this->assertSame(0, Payment::where('invoice_id', $zero->id)->count());
        });
    }

    public function test_redeeming_from_the_panel_for_a_new_member_starting_today_activates_at_once(): void
    {
        $club = $this->club();
        $staff = $this->staff($club, 'reception');
        $gift = $this->paidGift($club, $this->plan($club));
        $member = $this->member($club, ['full_name' => 'Noor New']);

        $this->actingAs($staff);
        $this->asTenant($club, function () use ($gift, $member, $staff) {
            $gift = $this->gifts()->redeem($gift, $member, today()->toDateString(), 'staff');
            $this->assertSame('staff', $gift->redeemed_via);
            $this->assertSame($staff->id, $gift->redeemed_by);
            $this->assertSame(Membership::ACTIVE, $gift->membership->status);
            $this->assertDatabaseHas('audit_logs', ['event' => 'gift.redeemed', 'subject_id' => $gift->id]);
        });
    }

    public function test_a_gift_is_used_once(): void
    {
        $club = $this->club();
        $gift = $this->paidGift($club, $this->plan($club));
        $first = $this->member($club);
        $second = $this->member($club);

        $this->asTenant($club, function () use ($gift, $first, $second) {
            $this->gifts()->redeem($gift, $first, today()->toDateString(), 'link');

            $this->expectException(BusinessRuleException::class);
            $this->expectExceptionMessage('استُخدمت هذه الهدية من قبل.');
            try {
                $this->gifts()->redeem($gift->fresh(), $second, today()->toDateString(), 'staff');
            } finally {
                $this->assertSame(1, Membership::count());
            }
        });
    }

    public function test_a_redemption_that_would_overlap_a_period_is_refused_and_leaves_the_gift_unused(): void
    {
        $club = $this->club();
        $plan = $this->plan($club);
        $member = $this->member($club);
        $this->activeMembership($club, $member, $plan);
        $gift = $this->paidGift($club, $plan);

        $this->asTenant($club, function () use ($gift, $member) {
            try {
                $this->gifts()->redeem($gift, $member, today()->addDays(3)->toDateString(), 'link');
                $this->fail('An overlapping period was sold.');
            } catch (BusinessRuleException $e) {
                $this->assertSame('membership.overlap', $e->rule);
                $this->assertSame('starts_on', $e->field);
            }
            $this->assertSame(GiftMembership::PAID, $gift->fresh()->status);
            $this->assertSame(1, Membership::count());
            $this->assertSame(2, Invoice::count(), 'The membership invoice and the gift invoice; no zero invoice left behind.');
        });
    }

    public function test_the_start_date_cannot_be_in_the_past_or_too_far_ahead(): void
    {
        $club = $this->club();
        $gift = $this->paidGift($club, $this->plan($club));
        $member = $this->member($club);

        $this->asTenant($club, function () use ($gift, $member) {
            foreach ([today()->subDay(), today()->addDays(GiftService::MAX_START_DAYS + 1)] as $date) {
                try {
                    $this->gifts()->redeem($gift, $member, $date->toDateString(), 'link');
                    $this->fail('Start '.$date->toDateString().' accepted.');
                } catch (BusinessRuleException) {
                }
            }
            $this->assertSame(GiftMembership::PAID, $gift->fresh()->status);
        });
    }

    public function test_an_expired_cancelled_or_refunded_gift_is_not_redeemed(): void
    {
        $club = $this->club();
        $plan = $this->plan($club);
        $member = $this->member($club);

        $expired = $this->paidGift($club, $plan);
        $cancelled = $this->paidGift($club, $plan);
        $refunded = $this->paidGift($club, $plan);
        $unpaid = $this->giftFor($club, $plan);

        $this->asTenant($club, function () use ($expired, $cancelled, $refunded, $unpaid, $member) {
            $expired->forceFill(['expires_on' => today()->subDay()])->save();
            $this->assertSame(GiftMembership::EXPIRED, $expired->fresh()->displayStatus());

            $this->gifts()->cancel($cancelled, 'طلب المشتري');
            $this->assertSame(Invoice::PAID, $cancelled->invoice->fresh()->status, 'The money stays on its invoice for finance to refund.');

            app(PaymentService::class)->cancel(Payment::where('invoice_id', $refunded->invoice_id)->first(), 'سُجّلت بالخطأ');

            $this->gifts()->cancel($unpaid, 'لم يدفع');
            $this->assertSame(Invoice::VOID, $unpaid->invoice->fresh()->status, 'An unpaid gift voids its invoice.');

            foreach ([['gift.expired', $expired], ['gift.cancelled', $cancelled], ['gift.refunded', $refunded], ['gift.cancelled', $unpaid]] as [$why, $gift]) {
                try {
                    $this->gifts()->redeem($gift->fresh(), $member, today()->toDateString(), 'link');
                    $this->fail($why.' gift redeemed.');
                } catch (BusinessRuleException $e) {
                    $this->assertSame($why, $e->rule);
                }
            }
            $this->assertSame(0, Membership::count());
        });
    }

    public function test_a_voided_gift_invoice_cancels_the_waiting_gift(): void
    {
        $club = $this->club();
        $gift = $this->giftFor($club, $this->plan($club));

        $this->asTenant($club, function () use ($gift) {
            app(InvoiceService::class)->void($gift->invoice, 'خطأ');
            $this->assertSame(GiftMembership::CANCELLED, $gift->fresh()->status);
        });
    }

    public function test_a_redeemed_gift_cannot_be_cancelled(): void
    {
        $club = $this->club();
        $gift = $this->paidGift($club, $this->plan($club));
        $member = $this->member($club);

        $this->asTenant($club, function () use ($gift, $member) {
            $this->gifts()->redeem($gift, $member, today()->toDateString(), 'link');
            $this->expectException(BusinessRuleException::class);
            $this->gifts()->cancel($gift->fresh(), 'x');
        });
    }

    public function test_a_plan_turned_off_for_gifts_or_sales_cannot_be_gifted_but_a_paid_gift_still_redeems(): void
    {
        $club = $this->club();
        $plan = $this->plan($club, ['price' => 30]);
        $gift = $this->paidGift($club, $plan);
        $member = $this->member($club);

        $this->asTenant($club, function () use ($plan, $gift, $member) {
            $plan->update(['giftable' => false]);
            try {
                $this->gifts()->issue($plan->fresh(), ['giver_name' => 'A', 'recipient_name' => 'B'], 'site');
                $this->fail('A plan closed to gifts was gifted.');
            } catch (BusinessRuleException) {
            }

            // Bought before the change: kept with the terms it was bought with.
            $plan->update(['giftable' => true, 'is_active' => false, 'price' => 99, 'duration_value' => 6]);
            $membership = $this->gifts()->redeem($gift, $member, today()->toDateString(), 'link')->membership;
            $this->assertSame(today()->addMonthNoOverflow()->subDay()->toDateString(), $membership->ends_on->toDateString());
            $this->assertSame('30.000', (string) $membership->plan_snapshot['price']);
        });
    }

    public function test_a_link_opens_only_its_own_clubs_gift_and_the_token_is_never_serialized(): void
    {
        $club = $this->club('A');
        $other = $this->club('B');
        $gift = $this->paidGift($club, $this->plan($club));
        $token = $this->asTenant($club, fn () => $gift->fresh()->linkToken());

        $this->assertGreaterThanOrEqual(48, strlen($token));
        $this->asTenant($club, fn () => $this->assertTrue($gift->is(GiftMembership::findByToken($token))));
        $this->asTenant($other, fn () => $this->assertNull(GiftMembership::findByToken($token)));
        $this->asTenant($club, fn () => $this->assertNull(GiftMembership::findByToken('short')));

        $array = $this->asTenant($club, fn () => $gift->fresh()->toArray());
        $this->assertArrayNotHasKey('token', $array);
        $this->assertArrayNotHasKey('token_hash', $array);
        $this->assertNotSame($token, \DB::table('gym_gift_memberships')->value('token'), 'Stored encrypted.');
    }

    public function test_ordinary_sales_are_unchanged(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 25, 'signup_fee' => 5]);

        $this->asTenant($club, function () use ($member, $plan) {
            $m = app(MembershipService::class)->sell($member, $plan);
            $this->assertSame('30.000', $m->invoice->total);
            $this->assertNull($m->notes);
            app(PaymentService::class)->record($m->invoice, '30.000', 'cash');
            $this->assertSame(Membership::ACTIVE, $m->fresh()->status);
        });
    }

    public function test_upgrading_a_redeemed_gift_credits_the_unused_part_of_what_the_gift_cost_once(): void
    {
        $club = $this->club();
        $plan = $this->plan($club, ['price' => 30, 'duration_value' => 30, 'duration_unit' => 'day']);
        $better = $this->plan($club, ['name' => 'Gold', 'price' => 90, 'duration_value' => 30, 'duration_unit' => 'day']);
        $gift = $this->paidGift($club, $plan);
        $member = $this->member($club);

        $this->asTenant($club, function () use ($gift, $member, $better) {
            $membership = $this->gifts()->redeem($gift, $member, today()->toDateString(), 'link')->membership;
            $this->travelTo(today()->addDays(10));

            $svc = app(MembershipService::class);
            $this->assertSame('20.000', $svc->unusedValue($membership->fresh()), '20 of 30 days left of a 30.000 gift.');

            $upgrade = $svc->upgrade($membership->fresh(), $better);
            $this->assertSame('20.000', $upgrade->credit_applied);
            $this->assertSame('70.000', $upgrade->invoice->total);
            app(PaymentService::class)->record($upgrade->invoice, '70.000', 'cash');
            $this->assertSame(Membership::CANCELLED, $membership->fresh()->status, 'The gift period is replaced: its credit cannot be spent again.');
            $this->expectException(BusinessRuleException::class);
            $svc->upgrade($membership->fresh(), $better); // a replaced period gives no credit a second time
        });
    }
}
