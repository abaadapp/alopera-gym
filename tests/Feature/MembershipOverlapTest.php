<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Models\Tenant;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Services\MembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * #70, rule 1 (the owner's option A): a sale never covers a day that another paid, scheduled or frozen period of the
 * member covers, whatever the plan. The same days would be paid twice, counted twice, and a freeze would stop only one
 * of them. A renewal (it starts the day after the last period) and an upgrade (it replaces the period it starts in)
 * are the ways on. A refused sale leaves no membership, invoice or payment behind; overlaps already in the data stay
 * as they are.
 */
class MembershipOverlapTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function sell(Tenant $club, Member $member, MembershipPlan $plan, ?string $startsOn = null): Membership
    {
        return $this->asTenant($club, fn () => app(MembershipService::class)->sell($member->fresh(), $plan, array_filter(['starts_on' => $startsOn])));
    }

    private function renew(Tenant $club, Member $member, MembershipPlan $plan): Membership
    {
        return $this->asTenant($club, fn () => app(MembershipService::class)->renew($member->fresh(), $plan));
    }

    private function pay(Tenant $club, Membership $membership): Membership
    {
        $this->asTenant($club, fn () => app(PaymentService::class)->record($membership->invoice()->firstOrFail(), $membership->total, 'cash'));

        return $this->asTenant($club, fn () => $membership->fresh());
    }

    private function refused(Tenant $club, \Closure $sale): string
    {
        try {
            $this->asTenant($club, $sale);
        } catch (BusinessRuleException $e) {
            return $e->rule;
        }
        $this->fail('an overlapping sale was accepted');
    }

    /** @return array{memberships: int, invoices: int, payments: int} */
    private function counts(Tenant $club): array
    {
        return $this->asTenant($club, fn () => ['memberships' => Membership::count(), 'invoices' => Invoice::count(), 'payments' => Payment::count()]);
    }

    private function period(Tenant $club, Membership $membership): array
    {
        return $this->asTenant($club, fn () => $membership->fresh()->only('status', 'starts_on', 'ends_on', 'total', 'sessions_used'));
    }

    public function test_a_sale_inside_a_paid_period_is_refused_whatever_the_plan_and_leaves_nothing_behind(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $gym = $this->plan($club, ['name' => 'Gym', 'type' => 'gym']);
        $classes = $this->plan($club, ['name' => 'Classes', 'type' => 'classes']);
        $current = $this->activeMembership($club, $member, $gym);
        [$before, $kept] = [$this->counts($club), $this->period($club, $current)];

        foreach ([today(), today()->addDays(10), $current->ends_on] as $start) {
            foreach ([$gym, $classes] as $plan) {
                $this->assertSame('membership.overlap', $this->refused($club, fn () => app(MembershipService::class)
                    ->sell($member->fresh(), $plan, ['starts_on' => $start->toDateString()])), "{$plan->name} from {$start->toDateString()}");
            }
        }
        // Ending inside it is the same overlap.
        $this->assertSame('membership.overlap', $this->refused($club, fn () => app(MembershipService::class)
            ->sell($member->fresh(), $gym, ['starts_on' => $current->starts_on->subDays(10)->toDateString()])));

        $this->assertSame($before, $this->counts($club), 'no membership, invoice or payment was made');
        $this->assertEquals($kept, $this->period($club, $current), 'the paid period is untouched');
    }

    public function test_the_day_after_a_period_ends_is_free_so_two_periods_follow_each_other(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club);
        $current = $this->activeMembership($club, $member, $plan);

        $next = $this->pay($club, $this->sell($club, $member, $plan, $current->ends_on->addDay()->toDateString()));

        $this->assertSame(Membership::SCHEDULED, $next->status);
        $this->assertSame($current->ends_on->addDay()->toDateString(), $next->starts_on->toDateString());
        $this->assertSame(2, $this->asTenant($club, fn () => Membership::where('member_id', $member->id)->live()->count()));
    }

    public function test_a_scheduled_period_blocks_a_sale_over_it(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club);
        $current = $this->activeMembership($club, $member, $plan);
        $scheduled = $this->pay($club, $this->renew($club, $member, $plan));
        $this->assertSame(Membership::SCHEDULED, $scheduled->status);

        $this->assertSame('membership.overlap', $this->refused($club, fn () => app(MembershipService::class)
            ->sell($member->fresh(), $plan, ['starts_on' => $scheduled->starts_on->addDays(5)->toDateString()])));
        $this->assertEquals(['memberships' => 2, 'invoices' => 2, 'payments' => 2], $this->counts($club));
    }

    public function test_a_frozen_period_blocks_a_sale_over_its_days_and_what_follows_it_moves_back_with_it(): void
    {
        $club = $this->club();
        [$member, $other] = [$this->member($club), $this->member($club)];
        $plan = $this->plan($club, ['freeze_allowed' => true, 'max_freeze_days' => 60, 'max_freezes' => 2]);
        $freeze = fn (Membership $m) => $this->asTenant($club, fn () => app(MembershipService::class)->freeze($m->fresh(), null, 'سفر'));
        $frozen = $freeze($this->activeMembership($club, $member, $plan));
        $this->assertSame(Membership::FROZEN, $frozen->status);

        $this->assertSame('membership.overlap', $this->refused($club, fn () => app(MembershipService::class)
            ->sell($member->fresh(), $plan, ['starts_on' => today()->addDays(3)->toDateString()])));

        // After its days: sold, held behind the freeze, and moved back by the days it was frozen when it resumes.
        $later = $this->pay($club, $this->sell($club, $member, $plan, $frozen->ends_on->addDay()->toDateString()));
        $this->assertSame(Membership::SCHEDULED, $later->status);
        $this->travel(6)->days();
        $resumed = $this->asTenant($club, fn () => app(MembershipService::class)->resume($frozen->fresh()));
        $later = $this->asTenant($club, fn () => $later->fresh());
        $this->assertSame($frozen->ends_on->addDays(6)->toDateString(), $resumed->ends_on->toDateString());
        $this->assertSame($resumed->ends_on->addDay()->toDateString(), $later->starts_on->toDateString(), 'it still follows, no day twice');

        // A renewal after a frozen period follows it, as before.
        $otherFrozen = $freeze($this->activeMembership($club, $other, $plan));
        $this->assertSame($otherFrozen->ends_on->addDay()->toDateString(), $this->renew($club, $other, $plan)->starts_on->toDateString());
    }

    public function test_a_renewal_starts_after_the_last_period_and_always_passes(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club);
        $plan = $this->plan($club);
        $current = $this->activeMembership($club, $member, $plan);

        $second = $this->pay($club, $this->renew($club, $member, $plan));
        $this->actingAs($owner)->post(route('club.memberships.store', $member), ['membership_plan_id' => $plan->id, 'mode' => 'renewal'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $third = $this->asTenant($club, fn () => Membership::where('member_id', $member->id)->latest('id')->firstOrFail());

        $this->assertSame($current->ends_on->addDay()->toDateString(), $second->starts_on->toDateString());
        $this->assertSame($second->ends_on->addDay()->toDateString(), $third->starts_on->toDateString());
    }

    public function test_expired_and_cancelled_periods_do_not_block_a_sale(): void
    {
        $club = $this->club();
        [$expiredMember, $cancelledMember] = [$this->member($club), $this->member($club)];
        $plan = $this->plan($club);
        $day = $this->plan($club, ['name' => 'Day pass', 'duration_value' => 1, 'duration_unit' => 'day']);

        $past = $this->pay($club, $this->sell($club, $expiredMember, $day, today()->subDays(3)->toDateString()));
        $this->assertSame(Membership::EXPIRED, $past->status);
        $cancelled = $this->activeMembership($club, $cancelledMember, $plan);
        $this->asTenant($club, fn () => app(MembershipService::class)->cancel($cancelled->fresh(), 'طلب العضو'));

        $this->assertSame(Membership::ACTIVE, $this->pay($club, $this->sell($club, $expiredMember, $plan))->status);
        $this->assertSame(Membership::ACTIVE, $this->pay($club, $this->sell($club, $cancelledMember, $plan))->status);
    }

    public function test_the_panel_shows_the_refusal_and_issues_no_invoice(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club);
        $plan = $this->plan($club);
        $this->activeMembership($club, $member, $plan);
        $before = $this->counts($club);

        // The member page sends a start date; the desk's form does not (the sale starts today).
        $this->actingAs($owner)->post(route('club.memberships.store', $member), ['membership_plan_id' => $plan->id, 'mode' => 'new', 'starts_on' => today()->addDays(3)->toDateString()])
            ->assertSessionHasErrors('starts_on');
        $this->actingAs($owner)->post(route('club.memberships.store', $member), ['membership_plan_id' => $plan->id, 'mode' => 'new'])
            ->assertSessionHasErrors('membership_plan_id');

        $this->assertSame($before, $this->counts($club));
    }

    public function test_other_members_and_other_clubs_are_not_affected(): void
    {
        [$club, $other] = [$this->club(), $this->club('Other')];
        [$member, $neighbour] = [$this->member($club), $this->member($club)];
        $this->activeMembership($club, $member, $plan = $this->plan($club));
        $theirs = $this->member($other);

        $this->assertSame(Membership::ACTIVE, $this->pay($club, $this->sell($club, $neighbour, $plan))->status);
        $this->assertSame(Membership::ACTIVE, $this->pay($other, $this->sell($other, $theirs, $this->plan($other)))->status);
    }

    public function test_an_upgrade_still_replaces_the_period_it_starts_in(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $current = $this->activeMembership($club, $member, $this->plan($club));
        $better = $this->plan($club, ['name' => 'Better', 'price' => 40]);

        $upgrade = $this->pay($club, $this->asTenant($club, fn () => app(MembershipService::class)->upgrade($current->fresh(), $better)));

        $this->assertSame(Membership::ACTIVE, $upgrade->status);
        $this->assertSame(Membership::CANCELLED, $this->asTenant($club, fn () => $current->fresh()->status));
    }

    public function test_overlaps_already_in_the_data_are_left_as_they_are(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club);
        $first = $this->activeMembership($club, $member, $plan);
        $second = $this->activeMembership($club, $this->member($club), $plan);
        // Two paid periods over the same days, as a sale before this rule could leave them.
        $this->asTenant($club, fn () => Membership::whereKey($second->id)->update(['member_id' => $member->id]));
        [$keptFirst, $keptSecond] = [$this->period($club, $first), $this->period($club, $second)];

        $this->assertSame('membership.overlap', $this->refused($club, fn () => app(MembershipService::class)->sell($member->fresh(), $plan)));
        $renewal = $this->renew($club, $member, $plan);

        $this->assertEquals($keptFirst, $this->period($club, $first));
        $this->assertEquals($keptSecond, $this->period($club, $second));
        $this->assertTrue(CarbonImmutable::parse($renewal->starts_on)->gt(max($first->ends_on, $second->ends_on)), 'the renewal starts after both');
    }
}
