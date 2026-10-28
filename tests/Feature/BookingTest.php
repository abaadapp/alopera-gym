<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Modules\Gym\Access\AccessControlService;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\BookingService;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Gym\Services\ScheduleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** Spec 2.2 / 2.3 / 2.4: capacity, validation against membership, confirmation, attendance. */
class BookingTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function book($member, $session, string $source = 'reception'): Booking
    {
        return app(BookingService::class)->book($member, $session, $source);
    }

    private function assertRule(string $rule, callable $callback): void
    {
        try {
            $callback();
            $this->fail("Expected business rule {$rule}");
        } catch (BusinessRuleException $e) {
            $this->assertSame($rule, $e->rule, $e->getMessage());
        }
    }

    public function test_a_full_session_refuses_further_bookings(): void
    {
        $club = $this->club();
        $session = $this->classSession($club, null, ['capacity' => 2]);
        $members = collect(range(1, 3))->map(function () use ($club) {
            $m = $this->member($club);
            $this->activeMembership($club, $m);

            return $m;
        });

        $this->asTenant($club, function () use ($members, $session) {
            $this->book($members[0], $session);
            $this->book($members[1], $session);
            $this->assertSame(2, $session->fresh()->booked_count);
            $this->assertTrue($session->fresh()->isFull());
            $this->assertRule('session.full', fn () => $this->book($members[2], $session));
        });
    }

    public function test_duplicate_and_overlapping_bookings_are_refused(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $this->activeMembership($club, $member);
        $a = $this->classSession($club, null, ['starts_at' => now()->addDay()->setTime(18, 0)]);
        $b = $this->classSession($club, null, ['starts_at' => now()->addDay()->setTime(18, 30)]);

        $this->asTenant($club, function () use ($member, $a, $b) {
            $this->book($member, $a);
            $this->assertRule('booking.duplicate', fn () => $this->book($member, $a));
            $this->assertRule('booking.overlap', fn () => $this->book($member, $b));
        });
    }

    public function test_bookings_require_a_valid_paid_membership_for_the_session_date(): void
    {
        $club = $this->club();
        $session = $this->classSession($club);

        $noMembership = $this->member($club);
        $unpaid = $this->member($club);
        $plan = $this->plan($club);
        $this->asTenant($club, fn () => app(MembershipService::class)->sell($unpaid, $plan));

        $this->asTenant($club, function () use ($noMembership, $unpaid, $session) {
            $this->assertRule('membership.none', fn () => $this->book($noMembership, $session));
            $this->assertRule('membership.unpaid', fn () => $this->book($unpaid, $session));
        });

        // A session after the membership ends is refused too.
        $short = $this->member($club);
        $this->activeMembership($club, $short, $this->plan($club, ['duration_value' => 3, 'duration_unit' => 'day']));
        $later = $this->classSession($club, null, ['starts_at' => now()->addDays(6)->setTime(10, 0)]);
        $this->asTenant($club, fn () => $this->assertRule('membership.none', fn () => $this->book($short, $later)));
    }

    public function test_plan_program_restrictions_and_session_limits_apply(): void
    {
        $club = $this->club();
        $yoga = $this->program($club, ['name' => 'Yoga']);
        $boxing = $this->program($club, ['name' => 'Boxing']);
        $plan = $this->plan($club, ['sessions_limit' => 1]);
        $this->asTenant($club, fn () => $plan->programs()->sync([$yoga->id]));

        $member = $this->member($club);
        $membership = $this->activeMembership($club, $member, $plan);
        $yogaSession = $this->classSession($club, $yoga, ['starts_at' => now()->addDay()->setTime(9, 0)]);
        $yoga2 = $this->classSession($club, $yoga, ['starts_at' => now()->addDays(2)->setTime(9, 0)]);
        $boxingSession = $this->classSession($club, $boxing, ['starts_at' => now()->addDays(3)->setTime(9, 0)]);

        $this->asTenant($club, function () use ($member, $membership, $yogaSession, $yoga2, $boxingSession) {
            $this->assertRule('membership.program', fn () => $this->book($member, $boxingSession));

            $booking = $this->book($member, $yogaSession);
            $this->assertSame(1, $membership->fresh()->sessions_used);
            $this->assertRule('membership.no_sessions', fn () => $this->book($member, $yoga2));

            // cancelling before the cutoff gives the session back
            app(BookingService::class)->cancel($booking, false);
            $this->assertSame(0, $membership->fresh()->sessions_used);
            $this->book($member, $yoga2);
        });
    }

    public function test_members_cannot_cancel_after_the_cutoff_but_staff_can(): void
    {
        $club = $this->club();
        $club->update(['settings' => ['booking_cancel_cutoff_hours' => 3]]);
        $member = $this->member($club);
        $this->activeMembership($club, $member);
        $soon = $this->classSession($club, null, ['starts_at' => now()->addHours(2)]);

        $this->asTenant($club->fresh(), function () use ($member, $soon) {
            $booking = $this->book($member, $soon);
            $this->assertRule('booking.cutoff', fn () => app(BookingService::class)->cancel($booking, false));
            app(BookingService::class)->cancel($booking, true);
            $this->assertSame(Booking::CANCELLED, $booking->fresh()->status);
            $this->assertSame(0, $soon->fresh()->booked_count);
        });
    }

    public function test_cancelling_a_session_releases_every_booking(): void
    {
        $club = $this->club();
        $session = $this->classSession($club);
        $members = collect(range(1, 3))->map(function () use ($club) {
            $m = $this->member($club);
            $this->activeMembership($club, $m);

            return $m;
        });

        $this->asTenant($club, function () use ($members, $session) {
            $members->each(fn ($m) => $this->book($m, $session));
            app(BookingService::class)->cancelSession($session, 'المدربة مريضة');
            $this->assertSame(ClassSession::CANCELLED, $session->fresh()->status);
            $this->assertSame(3, Booking::where('status', Booking::CANCELLED)->count());
            $this->assertSame(0, $session->fresh()->booked_count);
        });
    }

    public function test_freezing_cancels_future_bookings(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $membership = $this->activeMembership($club, $member);
        $session = $this->classSession($club);

        $this->asTenant($club, function () use ($member, $membership, $session) {
            $booking = $this->book($member, $session);
            app(MembershipService::class)->freeze($membership, 7);
            $this->assertSame(Booking::CANCELLED, $booking->fresh()->status);
            $this->assertRule('membership.frozen', fn () => $this->book($member, $session));
        });
    }

    public function test_door_check_in_marks_todays_booking_as_attended(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $this->activeMembership($club, $member);
        $session = $this->classSession($club, null, ['starts_at' => now()->addMinutes(20)]);

        $this->asTenant($club, function () use ($member, $session) {
            $booking = $this->book($member, $session);
            $decision = app(AccessControlService::class)->checkIn($member, 'qr');
            $this->assertTrue($decision->allowed);
            $this->assertSame(Booking::ATTENDED, $booking->fresh()->status);
        });
    }

    public function test_finished_sessions_close_and_unmarked_bookings_become_no_shows(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $this->activeMembership($club, $member);
        $session = $this->classSession($club, null, ['starts_at' => now()->addHour()]);
        $booking = $this->asTenant($club, fn () => $this->book($member, $session));

        $this->travel(4)->hours();
        $this->asTenant($club, function () use ($session, $booking) {
            $this->assertSame(1, app(BookingService::class)->closeFinishedSessions());
            $this->assertSame(ClassSession::COMPLETED, $session->fresh()->status);
            $this->assertSame(Booking::NO_SHOW, $booking->fresh()->status);
        });
    }

    public function test_weekly_timetable_generation_is_idempotent(): void
    {
        $club = $this->club();
        $program = $this->program($club);

        $this->asTenant($club, function () use ($program) {
            \App\Modules\Gym\Models\ClassSchedule::create([
                'program_id' => $program->id, 'weekday' => now()->addDays(2)->dayOfWeek, 'starts_at' => '18:00',
                'duration_minutes' => 60, 'capacity' => 12, 'valid_from' => today(),
            ]);
            $first = app(ScheduleService::class)->generate(2);
            $second = app(ScheduleService::class)->generate(2);
            $this->assertGreaterThanOrEqual(2, $first);
            $this->assertSame(0, $second);
        });
    }

    public function test_member_books_from_the_portal_and_full_sessions_are_refused_with_a_message(): void
    {
        $club = $this->club();
        $member = $this->member($club, ['password' => 'secret123']);
        $this->activeMembership($club, $member);
        $open = $this->classSession($club, null, ['capacity' => 5]);
        $full = $this->classSession($club, null, ['capacity' => 1, 'starts_at' => now()->addDays(2)->setTime(10, 0)]);
        $other = $this->member($club);
        $this->activeMembership($club, $other);
        $this->asTenant($club, fn () => $this->book($other, $full));

        $this->actingAs($member, 'member');
        $this->post(route('portal.classes.book', ['club' => $club->slug, 'session' => $open->id]))->assertSessionHasNoErrors();
        $this->post(route('portal.classes.book', ['club' => $club->slug, 'session' => $full->id]))->assertSessionHasErrors('booking');

        $this->assertSame(1, $this->asTenant($club, fn () => Booking::where('member_id', $member->id)->where('status', 'booked')->count()));
    }

    public function test_a_member_cannot_cancel_someone_elses_booking_from_the_portal(): void
    {
        $club = $this->club();
        $alice = $this->member($club);
        $bob = $this->member($club);
        $this->activeMembership($club, $alice);
        $this->activeMembership($club, $bob);
        $booking = $this->asTenant($club, fn () => $this->book($alice, $this->classSession($club)));

        $this->actingAs($bob, 'member')
            ->post(route('portal.bookings.cancel', ['club' => $club->slug, 'booking' => $booking->id]))
            ->assertNotFound();
        $this->assertSame(Booking::BOOKED, $this->asTenant($club, fn () => $booking->fresh()->status));
    }
}
