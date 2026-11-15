<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Models\Branch;
use App\Models\Tenant;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\BookingService;
use App\Modules\Gym\Services\MembershipService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * #174 — attendance is marked only on a booking still alive, whose membership covered the class when it began: not
 * cancelled, frozen or expired before it, and paid. The booking is read again under its class's lock (class, then
 * booking, then membership — the bookings' order), so a booking cancelled, released or carried since the page was
 * read is never turned into attended or absent. Real PostgreSQL; the races run in real processes.
 */
class AttendanceMembershipTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    /** A member on 8 classes, booked (counted) on a class starting in ten minutes: attendance can be marked. */
    private function bookedNow(array $session = []): array
    {
        $club = $this->club();
        $member = $this->member($club);
        $membership = $this->activeMembership($club, $member, $this->plan($club, ['sessions_limit' => 8]));
        $class = $this->classSession($club, null, $session + ['starts_at' => now()->addMinutes(10)]);
        $booking = $this->asTenant($club, fn () => app(BookingService::class)->book($member, $class, 'reception'));

        return compact('club', 'member', 'membership', 'class', 'booking');
    }

    private function mark(Tenant $club, Booking $booking, string $status = Booking::ATTENDED): Booking
    {
        return $this->asTenant($club, fn () => app(BookingService::class)->markAttendance($booking, $status));
    }

    private function refused(callable $mark): string
    {
        try {
            $mark();
        } catch (BusinessRuleException $e) {
            return $e->rule;
        }
        $this->fail('attendance was marked');
    }

    /** Seats (every booking not cancelled: booked, attended or absent) and counted classes agree with the bookings. */
    private function assertCounts(Tenant $club): void
    {
        $this->asTenant($club, function () {
            foreach (ClassSession::all() as $s) {
                $this->assertSame(Booking::where('class_session_id', $s->id)->where('status', '!=', Booking::CANCELLED)->count(), (int) $s->booked_count, "seats of session {$s->id}");
            }
            foreach (Membership::all() as $m) {
                $this->assertSame(Booking::where('membership_id', $m->id)->where('consumed_session', true)->where('status', '!=', Booking::CANCELLED)->count(), (int) $m->sessions_used, "classes counted on membership {$m->id}");
            }
        });
    }

    private function state(Tenant $club, Booking $booking, Membership $membership): array
    {
        return $this->asTenant($club, fn () => [$booking->fresh()->status, (bool) $booking->fresh()->consumed_session, $membership->fresh()->sessions_used]);
    }

    public function test_attended_and_absent_on_a_valid_membership_and_again_without_counting_twice(): void
    {
        ['club' => $club, 'membership' => $membership, 'booking' => $booking] = $this->bookedNow();

        $this->assertSame(Booking::ATTENDED, $this->mark($club, $booking)->status);
        $at = $this->asTenant($club, fn () => $booking->fresh()->attended_at);
        $this->mark($club, $booking); // sent twice
        $this->asTenant($club, fn () => $this->assertTrue($at->equalTo($booking->fresh()->attended_at), 'the first time stays'));
        $this->assertSame([Booking::ATTENDED, true, 1], $this->state($club, $booking, $membership));

        $this->assertSame(Booking::NO_SHOW, $this->mark($club, $booking, Booking::NO_SHOW)->status); // a correction, as today
        $this->assertSame([Booking::NO_SHOW, true, 1], $this->state($club, $booking, $membership));
        $this->assertCounts($club);
    }

    public function test_a_membership_cancelled_before_the_class_refuses_attendance_and_the_sweep_releases_the_booking(): void
    {
        ['club' => $club, 'membership' => $membership, 'booking' => $booking] = $this->bookedNow();
        // As a crash after the cancellation leaves it: cancelled, its booking not released yet.
        $this->asTenant($club, fn () => $membership->forceFill(['status' => Membership::CANCELLED, 'cancelled_at' => now()->subMinute(), 'cancel_reason' => 'x'])->save());

        $this->assertSame('attendance.membership', $this->refused(fn () => $this->mark($club, $booking)));
        $this->assertSame('attendance.membership', $this->refused(fn () => $this->mark($club, $booking, Booking::NO_SHOW)));
        $this->assertSame([Booking::BOOKED, true, 1], $this->state($club, $booking, $membership));

        Artisan::call('gym:settle-bookings');
        $this->assertSame([Booking::CANCELLED, false, 0], $this->state($club, $booking, $membership));
        $this->assertSame('attendance.cancelled', $this->refused(fn () => $this->mark($club, $booking)));
        $this->assertCounts($club);
    }

    public function test_a_membership_cancelled_after_the_class_began_still_takes_its_attendance(): void
    {
        ['club' => $club, 'membership' => $membership, 'class' => $class, 'booking' => $booking] = $this->bookedNow();
        $this->travelTo($class->starts_at->addMinutes(20));
        $this->asTenant($club, fn () => app(MembershipService::class)->cancel($membership->fresh(), 'بعد الحصة'));

        // The class was covered when it began: the cancellation does not release it, and it can be marked.
        $this->assertSame([Booking::BOOKED, true, 1], $this->state($club, $booking, $membership));
        $this->assertSame(Booking::ATTENDED, $this->mark($club, $booking)->status);
        $this->assertCounts($club);
    }

    public function test_a_frozen_membership_refuses_attendance(): void
    {
        ['club' => $club, 'membership' => $membership, 'booking' => $booking] = $this->bookedNow();
        // Frozen before the class (as a crash after the freeze leaves it, its booking not released yet).
        $this->asTenant($club, function () use ($membership) {
            $membership->freezes()->create(['starts_on' => today(), 'planned_end_on' => today()->addDays(7)]);
            $membership->forceFill(['status' => Membership::FROZEN])->save();
        });

        $this->assertSame('attendance.membership', $this->refused(fn () => $this->mark($club, $booking)));
        $this->assertSame([Booking::BOOKED, true, 1], $this->state($club, $booking, $membership));
        $this->assertCounts($club);
    }

    public function test_an_expired_membership_refuses_attendance_for_a_class_after_its_period(): void
    {
        ['club' => $club, 'membership' => $membership, 'booking' => $booking] = $this->bookedNow();
        $this->asTenant($club, fn () => $membership->forceFill(['status' => Membership::EXPIRED, 'ends_on' => today()->subDay()])->save());

        $this->assertSame('attendance.membership', $this->refused(fn () => $this->mark($club, $booking)));
        $this->assertCounts($club);
    }

    public function test_an_expired_membership_keeps_attendance_for_a_class_inside_its_period(): void
    {
        ['club' => $club, 'membership' => $membership, 'class' => $class, 'booking' => $booking] = $this->bookedNow();
        // Marked the next morning: the class was on the period's last day, the daily job has expired it since.
        $this->asTenant($club, fn () => $membership->forceFill(['ends_on' => $class->starts_at->startOfDay()])->save());
        $this->travelTo($class->starts_at->addDay()->setTime(9, 0));
        $this->asTenant($club, fn () => app(MembershipService::class)->runDailyTransitions());
        $this->assertSame(Membership::EXPIRED, $this->asTenant($club, fn () => $membership->fresh()->status));

        $this->assertSame(Booking::ATTENDED, $this->mark($club, $booking)->status);
        $this->assertCounts($club);
    }

    public function test_an_unpaid_membership_refuses_attendance(): void
    {
        ['club' => $club, 'membership' => $membership, 'booking' => $booking] = $this->bookedNow();
        $this->asTenant($club, fn () => $membership->forceFill(['status' => Membership::PENDING])->save());

        $this->assertSame('attendance.membership', $this->refused(fn () => $this->mark($club, $booking)));
        $this->assertCounts($club);
    }

    public function test_a_scheduled_membership_whose_period_has_begun_takes_attendance(): void
    {
        ['club' => $club, 'membership' => $membership, 'booking' => $booking] = $this->bookedNow();
        // Paid, its first day today, the daily job not run yet: still «scheduled».
        $this->asTenant($club, fn () => $membership->forceFill(['status' => Membership::SCHEDULED, 'starts_on' => today()])->save());

        $this->assertSame(Booking::ATTENDED, $this->mark($club, $booking)->status);
        $this->assertCounts($club);
    }

    public function test_a_booking_cancelled_after_the_page_was_read_is_never_marked(): void
    {
        ['club' => $club, 'membership' => $membership, 'booking' => $booking] = $this->bookedNow();
        $page = $this->asTenant($club, fn () => Booking::find($booking->id)); // what the attendance request carries
        $this->asTenant($club, fn () => app(BookingService::class)->cancel($booking->fresh(), true, 'من الاستقبال'));

        $this->assertSame('attendance.cancelled', $this->refused(fn () => $this->mark($club, $page)));
        $this->assertSame([Booking::CANCELLED, false, 0], $this->state($club, $booking, $membership));
        $this->assertCounts($club);
    }

    public function test_a_class_cancelled_after_the_page_was_read_leaves_its_bookings_cancelled(): void
    {
        ['club' => $club, 'membership' => $membership, 'class' => $class, 'booking' => $booking] = $this->bookedNow();
        $page = $this->asTenant($club, fn () => Booking::find($booking->id));
        $this->asTenant($club, fn () => app(BookingService::class)->cancelSession($class->fresh(), 'المدربة مريضة'));

        $this->assertSame('attendance.cancelled', $this->refused(fn () => $this->mark($club, $page)));
        $this->assertSame([Booking::CANCELLED, false, 0], $this->state($club, $booking, $membership));
        $this->assertCounts($club);
    }

    public function test_a_closed_class_can_still_be_corrected(): void
    {
        ['club' => $club, 'membership' => $membership, 'class' => $class, 'booking' => $booking] = $this->bookedNow();
        $this->travelTo($class->ends_at->addHours(2));
        $this->asTenant($club, fn () => app(BookingService::class)->closeFinishedSessions());
        $this->assertSame(Booking::NO_SHOW, $this->asTenant($club, fn () => $booking->fresh()->status));

        // She came after all: the reception corrects it the next day, as before.
        $this->travelTo($class->starts_at->addDay());
        $this->assertSame(Booking::ATTENDED, $this->mark($club, $booking)->status);
        $this->assertSame([Booking::ATTENDED, true, 1], $this->state($club, $booking, $membership));
        $this->assertCounts($club);
    }

    public function test_two_attendance_requests_at_once_mark_it_once(): void
    {
        ['club' => $club, 'membership' => $membership, 'class' => $class, 'booking' => $booking] = $this->bookedNow();
        $classLock = fn (string $sql, array $b) => str_contains($sql, 'from "gym_class_sessions"') && ($b[0] ?? null) === $class->id;

        $results = $this->concurrently([
            function (string $dir) use ($club, $booking, $classLock) {
                self::holdUntilAnotherWaits($dir, $classLock);

                return [$this->mark($club, $booking)->status, is_file("$dir/blocked")];
            },
            function (string $dir) use ($club, $booking) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? $this->mark($club, $booking)->status : 'not at the same time';
            },
        ]);

        $this->assertSame([[true, [Booking::ATTENDED, true]], [true, Booking::ATTENDED]], array_map(fn ($r) => [$r['ok'], $r['value']], $results), json_encode($results));
        $this->assertSame([Booking::ATTENDED, true, 1], $this->state($club, $booking, $membership));
        $this->assertCounts($club);
    }

    public function test_the_sweep_and_an_attendance_request_at_once_release_the_booking_and_mark_nothing(): void
    {
        ['club' => $club, 'membership' => $membership, 'class' => $class, 'booking' => $booking] = $this->bookedNow();
        $this->asTenant($club, fn () => $membership->forceFill(['status' => Membership::CANCELLED, 'cancelled_at' => now()->subMinute(), 'cancel_reason' => 'x'])->save());
        $classLock = fn (string $sql, array $b) => str_contains($sql, 'from "gym_class_sessions"') && ($b[0] ?? null) === $class->id;

        $results = $this->concurrently([
            function (string $dir) use ($classLock) {
                self::holdUntilAnotherWaits($dir, $classLock); // the sweep holds the class

                return [Artisan::call('gym:settle-bookings'), is_file("$dir/blocked")];
            },
            function (string $dir) use ($club, $booking) {
                self::waitFor("$dir/inside");
                if (! is_file("$dir/inside")) {
                    return 'not at the same time';
                }
                try {
                    return $this->mark($club, $booking)->status;
                } catch (BusinessRuleException $e) {
                    return $e->rule;
                }
            },
        ]);

        $this->assertSame([[true, [0, true]], [true, 'attendance.cancelled']], array_map(fn ($r) => [$r['ok'], $r['value']], $results), json_encode($results));
        $this->assertSame([Booking::CANCELLED, false, 0], $this->state($club, $booking, $membership));
        $this->assertCounts($club);
    }

    public function test_a_class_cancelled_while_attendance_is_being_marked_marks_nothing(): void
    {
        ['club' => $club, 'membership' => $membership, 'class' => $class, 'booking' => $booking] = $this->bookedNow();
        $classLock = fn (string $sql, array $b) => str_contains($sql, 'from "gym_class_sessions"') && ($b[0] ?? null) === $class->id;

        $results = $this->concurrently([
            function (string $dir) use ($club, $class, $classLock) {
                self::holdUntilAnotherWaits($dir, $classLock); // the cancellation holds the class

                return [$this->asTenant($club, fn () => app(BookingService::class)->cancelSession($class, 'القاعة مغلقة')->status), is_file("$dir/blocked")];
            },
            function (string $dir) use ($club, $booking) {
                self::waitFor("$dir/inside");
                if (! is_file("$dir/inside")) {
                    return 'not at the same time';
                }
                try {
                    return $this->mark($club, $booking)->status;
                } catch (BusinessRuleException $e) {
                    return $e->rule;
                }
            },
        ]);

        $this->assertSame([[true, [ClassSession::CANCELLED, true]], [true, 'attendance.cancelled']], array_map(fn ($r) => [$r['ok'], $r['value']], $results), json_encode($results));
        $this->assertSame([Booking::CANCELLED, false, 0], $this->state($club, $booking, $membership));
        $this->assertCounts($club);
    }

    public function test_another_club_or_branch_cannot_mark_it_and_the_reason_is_shown_in_arabic(): void
    {
        ['club' => $club, 'membership' => $membership, 'class' => $class, 'booking' => $booking] = $this->bookedNow();
        $here = $this->asTenant($club, fn () => Branch::create(['name' => 'فرع الخوض', 'is_active' => true]));
        $there = $this->asTenant($club, fn () => Branch::create(['name' => 'فرع المعبيلة', 'is_active' => true]));
        $this->asTenant($club, fn () => $class->forceFill(['branch_id' => $here->id])->save());
        $other = $this->club();

        $this->actingAs($this->owner($other))->post(route('club.bookings.attendance', $booking), ['status' => 'attended'])->assertNotFound();
        $this->actingAs($this->staff($club, 'reception', ['branch_id' => $there->id]))->post(route('club.bookings.attendance', $booking), ['status' => 'attended'])->assertNotFound(); // another branch's record, as the panel hides it
        $this->assertSame([Booking::BOOKED, true, 1], $this->state($club, $booking, $membership));

        $this->asTenant($club, fn () => $membership->forceFill(['status' => Membership::CANCELLED, 'cancelled_at' => now()->subMinute(), 'cancel_reason' => 'x'])->save());
        $this->actingAs($this->staff($club, 'reception', ['branch_id' => $here->id]))->post(route('club.bookings.attendance', $booking), ['status' => 'attended'])
            ->assertSessionHasErrors(['booking' => 'لا يُسجَّل الحضور: العضوية أُلغيت قبل موعد الحصة.']);
        $this->assertSame([Booking::BOOKED, true, 1], $this->state($club, $booking, $membership));
    }
}
