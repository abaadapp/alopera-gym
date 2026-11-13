<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Models\Tenant;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Access\AccessControlService;
use App\Modules\Gym\Access\AccessDecision;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Services\BookingService;
use App\Modules\Gym\Services\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * #70, rule 2: the plan's type decides the door and the classes, on the server, for periods sold from #70 on.
 * - «gym»: the door any day of the period, no classes.
 * - «classes»: the classes the plan allows, and the door only on a day she has a class of this club booked (or
 *   attended already). A cancelled booking, a cancelled class, another day's class and another club's booking do not
 *   count.
 * - «combo»: both.
 * A period sold before keeps the door and the classes until it ends (MembershipRulesPendingDecisionTest). Its renewal
 * is sold under the rule. Freezes, cancellations and expiry close the door as before.
 */
class PlanTypeRulesTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const GYM_ONLY = 'باقة العضو لدخول النادي فقط، ولا تشمل الحصص.';

    protected function setUp(): void
    {
        parent::setUp();
        // Morning, so this evening's class has not started whenever the tests run.
        $this->travelTo(now()->setTime(9, 0));
    }

    /** @return array{Member, Membership} */
    private function memberWith(Tenant $club, string $type, array $plan = []): array
    {
        $member = $this->member($club, ['password' => 'secret123']);
        $membership = $this->activeMembership($club, $member, $this->plan($club, $plan + ['name' => ucfirst($type), 'type' => $type]));

        return [$member, $membership];
    }

    private function door(Tenant $club, Member $member): AccessDecision
    {
        return $this->asTenant($club, fn () => app(AccessControlService::class)->evaluate($member->fresh()));
    }

    private function book(Tenant $club, Member $member, ClassSession $session, string $source = 'portal'): Booking
    {
        return $this->asTenant($club, fn () => app(BookingService::class)->book($member->fresh(), $session, $source));
    }

    private function bookingRefused(Tenant $club, Member $member, ClassSession $session, string $source = 'portal'): string
    {
        try {
            $this->book($club, $member, $session, $source);
        } catch (BusinessRuleException $e) {
            return $e->rule;
        }
        $this->fail("a $source booking went through");
    }

    private function tonight(Tenant $club, array $attributes = []): ClassSession
    {
        return $this->classSession($club, null, $attributes + ['starts_at' => today()->setTime(18, 0)]);
    }

    public function test_a_period_sold_now_carries_the_rule_and_one_sold_before_does_not(): void
    {
        $club = $this->club();
        [, $membership] = $this->memberWith($club, 'gym');

        $this->assertTrue($membership->enforcesPlanType());
        $this->assertSame('gym', $membership->plan_snapshot['type']);
    }

    public function test_a_gym_plan_opens_the_door_any_day_and_books_no_class_from_anywhere(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        [$member] = $this->memberWith($club, 'gym');
        $session = $this->tonight($club);

        $this->assertTrue($this->door($club, $member)->allowed);
        foreach (['portal', 'website', 'reception'] as $source) {
            $this->assertSame('membership.gym_only', $this->bookingRefused($club, $member, $session, $source));
        }
        // Sent straight to the server: the portal (Arabic and English) and the desk.
        $book = route('portal.classes.book', ['club' => $club->slug, 'session' => $session->id]);
        $this->actingAs($member, 'member')->post($book)->assertSessionHasErrors(['booking' => self::GYM_ONLY]);
        $this->actingAs($member, 'member')->post(str_replace('/c/'.$club->slug.'/', '/c/'.$club->slug.'/en/', parse_url($book, PHP_URL_PATH)))
            ->assertSessionHasErrors(['booking' => 'This membership is for the gym only: it does not include classes.']);
        $this->actingAs($owner)->post(route('club.bookings.store', $session), ['member_id' => $member->id])->assertSessionHasErrors(['booking' => self::GYM_ONLY]);
        $this->assertSame(0, $this->asTenant($club, fn () => Booking::count()));
    }

    public function test_a_classes_plan_books_classes_and_opens_the_door_only_on_the_day_of_a_class_booked(): void
    {
        $club = $this->club();
        [$member] = $this->memberWith($club, 'classes');

        $denied = $this->door($club, $member);
        $this->assertFalse($denied->allowed);
        $this->assertSame('classes_without_booking', $denied->reason);
        $this->assertSame('باقة حصص: الدخول يوم حصة محجوزة فقط', $denied->message());

        // Tomorrow's class does not open today's door; tonight's does.
        $this->book($club, $member, $this->classSession($club, null, ['starts_at' => today()->addDay()->setTime(18, 0)]));
        $this->assertFalse($this->door($club, $member)->allowed);
        $this->book($club, $member, $this->tonight($club));
        $this->assertTrue($this->door($club, $member)->allowed);

        // Tomorrow, the booking of that day opens the door.
        $this->travelTo(today()->addDay()->setTime(9, 0));
        $this->assertTrue($this->door($club, $member)->allowed);
    }

    public function test_a_cancelled_booking_or_class_does_not_open_the_door_but_an_attended_one_still_does(): void
    {
        $club = $this->club();
        [$cancels] = $this->memberWith($club, 'classes');
        [$classCancelled] = $this->memberWith($club, 'classes');
        [$attends] = $this->memberWith($club, 'classes');

        $booking = $this->book($club, $cancels, $this->tonight($club));
        $this->asTenant($club, fn () => app(BookingService::class)->cancel($booking, false));
        $this->assertFalse($this->door($club, $cancels)->allowed, 'a cancelled booking');

        $session = $this->tonight($club);
        $this->book($club, $classCancelled, $session);
        $this->asTenant($club, fn () => app(BookingService::class)->cancelSession($session, 'المدربة مريضة'));
        $this->assertFalse($this->door($club, $classCancelled)->allowed, 'a cancelled class');

        // Entering marks tonight's booking attended; stepping out and back in still works.
        $this->book($club, $attends, $this->tonight($club, ['starts_at' => today()->setTime(9, 30)]));
        $this->assertTrue($this->asTenant($club, fn () => app(AccessControlService::class)->checkIn($attends->fresh(), 'manual'))->allowed);
        $this->assertSame(Booking::ATTENDED, $this->asTenant($club, fn () => Booking::where('member_id', $attends->id)->value('status')));
        $this->travel(2)->hours();
        $this->assertTrue($this->door($club, $attends)->allowed, 'an attended booking of today');
    }

    public function test_another_clubs_booking_does_not_open_this_clubs_door(): void
    {
        [$club, $other] = [$this->club(), $this->club('Other')];
        [$here] = $this->memberWith($club, 'classes');
        [$there] = $this->memberWith($other, 'classes');
        $this->book($other, $there, $this->tonight($other));

        $this->assertTrue($this->door($other, $there)->allowed);
        $this->assertFalse($this->door($club, $here)->allowed);
    }

    public function test_a_combo_plan_opens_the_door_and_books_classes(): void
    {
        $club = $this->club();
        [$member] = $this->memberWith($club, 'combo');

        $this->assertTrue($this->door($club, $member)->allowed);
        $this->assertSame(Booking::BOOKED, $this->book($club, $member, $this->tonight($club))->status);
    }

    public function test_the_plans_program_list_still_limits_its_classes(): void
    {
        $club = $this->club();
        $yoga = $this->program($club, ['name' => 'Yoga']);
        $zumba = $this->program($club, ['name' => 'Zumba']);
        $plan = $this->plan($club, ['name' => 'Yoga classes', 'type' => 'classes']);
        $this->asTenant($club, fn () => $plan->programs()->sync([$yoga->id]));
        $member = $this->member($club);
        $this->activeMembership($club, $member, $plan->fresh());

        $this->assertSame(Booking::BOOKED, $this->book($club, $member, $this->classSession($club, $yoga, ['starts_at' => today()->setTime(18, 0)]))->status);
        $this->assertSame('membership.program', $this->bookingRefused($club, $member, $this->classSession($club, $zumba, ['starts_at' => today()->setTime(19, 0)])));
    }

    public function test_freezes_cancellations_and_expiry_close_the_door_as_before(): void
    {
        $club = $this->club();
        $plan = ['freeze_allowed' => true, 'max_freeze_days' => 30, 'max_freezes' => 1];
        [$frozen, $f] = $this->memberWith($club, 'combo', $plan);
        [$cancelled, $c] = $this->memberWith($club, 'gym');
        $this->asTenant($club, fn () => app(MembershipService::class)->freeze($f->fresh(), null, 'سفر'));
        $this->asTenant($club, fn () => app(MembershipService::class)->cancel($c->fresh(), 'طلب العضو'));
        [$expired, $e] = $this->memberWith($club, 'gym');

        $this->assertSame('membership_frozen', $this->door($club, $frozen)->reason);
        $this->assertFalse($this->door($club, $cancelled)->allowed);
        $this->travelTo($e->ends_on->addDay()->setTime(9, 0));
        $this->assertFalse($this->door($club, $expired)->allowed);
    }

    public function test_a_period_sold_before_keeps_its_rights_and_its_renewal_follows_the_rule(): void
    {
        $club = $this->club();
        [$member, $old] = $this->memberWith($club, 'classes');
        $this->asTenant($club, fn () => $old->forceFill(['plan_snapshot' => array_diff_key($old->plan_snapshot, ['type_enforced' => true])])->save());
        $sold = fn () => $this->asTenant($club, fn () => Membership::where('member_id', $member->id)->orderBy('id')->get()
            ->map(fn (Membership $m) => $m->only('status', 'starts_on', 'ends_on', 'plan_snapshot', 'sessions_used'))->all());
        $before = $sold();

        // Sold before: the door without a booking, as she always had.
        $this->assertTrue($this->door($club, $member)->allowed);
        $this->assertEquals($before, $sold(), 'nothing of the old period was rewritten');

        // Its renewal is sold under the rule: from its first day, the door needs a class booked.
        $renewal = $this->asTenant($club, function () use ($member) {
            $renewal = app(MembershipService::class)->renew($member->fresh());
            app(PaymentService::class)->record($renewal->invoice()->firstOrFail(), $renewal->total, 'cash');

            return $renewal->fresh();
        });
        $this->assertTrue($renewal->enforcesPlanType());
        $this->travelTo($renewal->starts_on->setTime(9, 0));
        $this->assertSame('classes_without_booking', $this->door($club, $member)->reason);
    }

    public function test_an_old_period_and_a_new_one_side_by_side_each_keep_their_own_terms(): void
    {
        $club = $this->club();
        [$member, $old] = $this->memberWith($club, 'gym');
        $this->asTenant($club, fn () => $old->forceFill(['plan_snapshot' => array_diff_key($old->plan_snapshot, ['type_enforced' => true])])->save());

        // The old «gym» period still books; a new «gym» period alone would not.
        $this->assertSame(Booking::BOOKED, $this->book($club, $member, $this->tonight($club))->status);
        [$newOnly] = $this->memberWith($club, 'gym');
        $this->assertSame('membership.gym_only', $this->bookingRefused($club, $newOnly, $this->tonight($club)));
    }

    public function test_the_plan_types_are_the_three_the_rules_know(): void
    {
        $this->assertSame(['gym', 'classes', 'combo'], array_keys(MembershipPlan::TYPES));
    }
}
