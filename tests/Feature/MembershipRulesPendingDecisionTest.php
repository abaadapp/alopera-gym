<?php

namespace Tests\Feature;

use App\Modules\Gym\Access\AccessControlService;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\BookingService;
use App\Modules\Gym\Services\MembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * What the system does TODAY in the two membership rules that wait for the owner's decision (release audit, «لم
 * يُصلح»). These tests pin the current behaviour so that a change is a deliberate one: when the owner decides, the
 * test of the rule that changes is rewritten to the new rule in the same pull request as the change.
 *
 * 1. Decided (#70, option A): a sale may not cover a day another paid, scheduled or frozen period covers; its tests are
 *    in MembershipOverlapTest. Still pinned here: a renewal never overlaps, it always starts the day after the latest
 *    period ends.
 * 2. Decided (#70): the plan's type decides the door and the classes for periods sold from #70 on (PlanTypeRulesTest).
 *    Still pinned here: a period sold before keeps the door and the classes, whatever its type, until it ends.
 */
class MembershipRulesPendingDecisionTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_a_renewal_never_overlaps_it_starts_the_day_after_the_current_period(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $current = $this->activeMembership($club, $member);

        $this->asTenant($club, function () use ($member, $current) {
            $renewal = app(MembershipService::class)->renew($member, null, ['starts_on' => CarbonImmutable::today()->toDateString()]);

            $this->assertSame($current->ends_on->addDay()->toDateString(), $renewal->starts_on->toDateString());
        });
    }

    /** A period as it was sold before #70: its terms carry no type rule. */
    private function soldBefore70(Membership $membership): void
    {
        $membership->forceFill(['plan_snapshot' => array_diff_key($membership->plan_snapshot, ['type_enforced' => true])])->save();
    }

    public function test_a_classes_plan_sold_before_70_still_opens_the_door(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $membership = $this->activeMembership($club, $member, $this->plan($club, ['name' => 'Classes only', 'type' => 'classes']));
        $this->asTenant($club, fn () => $this->soldBefore70($membership));

        $decision = $this->asTenant($club, fn () => app(AccessControlService::class)->evaluate($member->fresh()));

        $this->assertTrue($decision->allowed, 'a period sold before #70 keeps the door, whatever its type');
    }

    public function test_a_gym_plan_sold_before_70_still_books_a_class_unless_its_programs_are_limited(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $membership = $this->activeMembership($club, $member, $this->plan($club, ['name' => 'Gym only', 'type' => 'gym']));
        $this->asTenant($club, fn () => $this->soldBefore70($membership));
        $session = $this->classSession($club);

        $booking = $this->asTenant($club, fn () => app(BookingService::class)->book($member->fresh(), $session));

        $this->assertSame(Booking::BOOKED, $booking->status, 'a period sold before #70 keeps the classes, whatever its type');
    }
}
