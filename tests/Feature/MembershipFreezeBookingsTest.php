<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\BookingService;
use App\Modules\Gym\Services\MembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * A freeze held its membership while it released the bookings on it, class by class; the member cancelling one of them
 * held that class and waited for the membership: the two deadlocked (40P01). The freeze now settles its bookings once it
 * has committed (as a cancellation does since #172), and gym:settle-bookings finishes whatever a crash left — a booked
 * class after the freeze on a frozen membership, or after the end of an expired one. Real PostgreSQL, real processes.
 */
class MembershipFreezeBookingsTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    /** That membership row's own lock (its id the first binding; the tenant's id comes after). */
    private static function membershipLock(int $id): callable
    {
        return fn (string $sql, array $bindings) => str_contains($sql, 'from "gym_memberships" where "gym_memberships"."id" = ?') && ($bindings[0] ?? null) === $id;
    }

    /** Inside a task: the process dies (SIGKILL, no shutdown) right after the first row lock on a class. */
    private static function dieAtTheFirstClassLock(): void
    {
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'from "gym_class_sessions"') && str_contains($query->sql, 'for update')) {
                posix_kill(getmypid(), SIGKILL);
            }
        });
    }

    /** Both finished; a refusal is a business rule, never a deadlock. */
    private function assertNoDeadlock(array $results): void
    {
        $json = json_encode($results, JSON_UNESCAPED_UNICODE);
        foreach ($results as $result) {
            $this->assertNotSame('not at the same time', $result['value'] ?? null, $json);
            $this->assertStringNotContainsString('40P01', (string) $result['error'], $json);
            $this->assertStringNotContainsStringIgnoringCase('deadlock', (string) $result['error'], $json);
            if (! $result['ok']) {
                $this->assertStringContainsString('BusinessRuleException', (string) $result['error'], $json);
            }
        }
    }

    /** Seats and counted classes agree with the bookings, no payment was touched, and the books balance. */
    private function assertCounts(Tenant $club): void
    {
        $this->asTenant($club, function () {
            foreach (ClassSession::all() as $s) {
                $this->assertSame(Booking::where('class_session_id', $s->id)->where('status', Booking::BOOKED)->count(), (int) $s->booked_count, "seats of session {$s->id}");
            }
            foreach (Membership::all() as $m) {
                $this->assertSame(Booking::where('membership_id', $m->id)->where('consumed_session', true)->where('status', '!=', Booking::CANCELLED)->count(), (int) $m->sessions_used, "classes counted on membership {$m->id}");
            }
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'), 'the books balance');
        });
    }

    /** @return array{club: Tenant, membership: Membership, session: ClassSession, booking: Booking} */
    private function memberWithACountedBooking(): array
    {
        $club = $this->club();
        $member = $this->member($club);
        $membership = $this->activeMembership($club, $member, $this->plan($club, ['sessions_limit' => 8]));
        $session = $this->classSession($club);
        $booking = $this->asTenant($club, fn () => app(BookingService::class)->book($member, $session, 'reception'));
        $this->asTenant($club, fn () => $this->assertSame([true, 1], [(bool) $booking->consumed_session, $membership->fresh()->sessions_used]));

        return compact('club', 'membership', 'session', 'booking');
    }

    public function test_a_freeze_while_the_member_cancels_a_counted_booking_does_not_deadlock(): void
    {
        ['club' => $club, 'membership' => $membership, 'session' => $session, 'booking' => $booking] = $this->memberWithACountedBooking();
        $payments = $this->asTenant($club, fn () => Payment::count());

        $results = $this->concurrently([
            function (string $dir) use ($club, $membership) {
                self::holdUntilAnotherWaits($dir, self::membershipLock($membership->id)); // the freeze holds the membership

                return [$this->asTenant($club, fn () => app(MembershipService::class)->freeze($membership, 7)->status), is_file("$dir/blocked")];
            },
            function (string $dir) use ($club, $booking) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? $this->asTenant($club, fn () => app(BookingService::class)->cancel($booking, false)->status) : 'not at the same time';
            },
        ]);

        $this->assertNoDeadlock($results);
        $this->assertSame([[true, [Membership::FROZEN, true]], [true, Booking::CANCELLED]], array_map(fn ($r) => [$r['ok'], $r['value']], $results), json_encode($results));
        $this->asTenant($club, fn () => $this->assertSame([Membership::FROZEN, Booking::CANCELLED, 0, 0, $payments],
            [$membership->fresh()->status, $booking->fresh()->status, $membership->fresh()->sessions_used, (int) $session->fresh()->booked_count, Payment::count()]));
        $this->assertCounts($club);
    }

    public function test_booking_while_the_membership_is_being_frozen_is_refused_and_leaves_nothing_booked(): void
    {
        ['club' => $club, 'membership' => $membership, 'booking' => $booking] = $this->memberWithACountedBooking();
        $other = $this->classSession($club, null, ['starts_at' => now()->addDays(2)->setTime(18, 0)]);

        $results = $this->concurrently([
            function (string $dir) use ($club, $membership) {
                self::holdUntilAnotherWaits($dir, self::membershipLock($membership->id));

                return [$this->asTenant($club, fn () => app(MembershipService::class)->freeze($membership, 7)->status), is_file("$dir/blocked")];
            },
            function (string $dir) use ($club, $membership, $other) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? $this->asTenant($club, fn () => app(BookingService::class)->book($membership->member, $other, 'reception')->id) : 'not at the same time';
            },
        ]);

        $this->assertNoDeadlock($results);
        $this->assertSame([true, [Membership::FROZEN, true]], [$results[0]['ok'], $results[0]['value']], json_encode($results));
        $this->assertFalse($results[1]['ok'], 'frozen: '.json_encode($results));
        $this->asTenant($club, fn () => $this->assertSame([Booking::CANCELLED, 0, 1], [$booking->fresh()->status, Booking::where('status', Booking::BOOKED)->count(), Booking::count()]));
        $this->assertCounts($club);
    }

    public function test_a_crash_after_the_freeze_leaves_the_booking_for_the_sweep_to_release_once(): void
    {
        ['club' => $club, 'membership' => $membership, 'session' => $session, 'booking' => $booking] = $this->memberWithACountedBooking();

        $results = $this->concurrently([function () use ($club, $membership) {
            self::dieAtTheFirstClassLock();

            return $this->asTenant($club, fn () => app(MembershipService::class)->freeze($membership, 7)->status);
        }]);
        $this->assertSame('killed before it finished', $results[0]['error'], json_encode($results));
        // The freeze committed; its booking was not touched yet.
        $this->asTenant($club, fn () => $this->assertSame([Membership::FROZEN, Booking::BOOKED, 1], [$membership->fresh()->status, $booking->fresh()->status, (int) $session->fresh()->booked_count]));

        $this->assertSame(0, Artisan::call('gym:settle-bookings', ['--dry-run' => true]));
        $this->assertStringContainsString('bookings_left=1', Artisan::output());
        $this->asTenant($club, fn () => $this->assertSame(Booking::BOOKED, $booking->fresh()->status), 'a dry run changes nothing');

        Artisan::call('gym:settle-bookings');
        Artisan::call('gym:settle-bookings');
        Artisan::call('gym:settle-bookings', ['--dry-run' => true]);
        $this->assertStringContainsString('bookings_left=0', Artisan::output());
        $this->asTenant($club, fn () => $this->assertSame([Membership::FROZEN, Booking::CANCELLED, 0, 0],
            [$membership->fresh()->status, $booking->fresh()->status, (int) $session->fresh()->booked_count, $membership->fresh()->sessions_used]));
        $this->assertCounts($club);
    }

    public function test_two_sweeps_at_once_release_a_frozen_membership_s_bookings_once(): void
    {
        ['club' => $club, 'membership' => $membership, 'session' => $session, 'booking' => $booking] = $this->memberWithACountedBooking();
        $results = $this->concurrently([function () use ($club, $membership) {
            self::dieAtTheFirstClassLock();

            return $this->asTenant($club, fn () => app(MembershipService::class)->freeze($membership, 7)->status);
        }]);
        $this->assertSame('killed before it finished', $results[0]['error'], json_encode($results));

        $results = $this->concurrently([
            fn () => Artisan::call('gym:settle-bookings'),
            fn () => Artisan::call('gym:settle-bookings'),
        ]);

        $this->assertSame([[true, 0], [true, 0]], array_map(fn ($r) => [$r['ok'], $r['value']], $results), json_encode($results));
        $this->asTenant($club, fn () => $this->assertSame([Booking::CANCELLED, 0, 0], [$booking->fresh()->status, (int) $session->fresh()->booked_count, $membership->fresh()->sessions_used]));
        $this->assertCounts($club);
    }

    public function test_nothing_is_released_when_the_freeze_is_rolled_back(): void
    {
        ['club' => $club, 'membership' => $membership, 'booking' => $booking] = $this->memberWithACountedBooking();

        try {
            $this->asTenant($club, fn () => DB::transaction(function () use ($membership) {
                app(MembershipService::class)->freeze($membership, 7);
                throw new RuntimeException('something after the freeze failed');
            }));
        } catch (RuntimeException) {
        }

        $this->asTenant($club, fn () => $this->assertSame([Membership::ACTIVE, Booking::BOOKED, 1, 0],
            [$membership->fresh()->status, $booking->fresh()->status, $membership->fresh()->sessions_used, $membership->fresh()->freezes()->count()]));
        Artisan::call('gym:settle-bookings');
        $this->asTenant($club, fn () => $this->assertSame(Booking::BOOKED, $booking->fresh()->status), 'an active membership keeps its booking');
        $this->assertCounts($club);
    }

    public function test_a_freeze_releases_many_future_bookings_each_once(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club, ['duration_value' => 2, 'duration_unit' => 'month']); // unlimited classes
        $membership = $this->activeMembership($club, $member, $plan);
        $bookings = [];
        for ($day = 1; $day <= 30; $day++) {
            $s = $this->classSession($club, null, ['starts_at' => now()->addDays($day)->setTime(18, 0)]);
            $bookings[] = $this->asTenant($club, fn () => app(BookingService::class)->book($member, $s, 'reception'));
        }
        // (The bookings on a paid renewal held by the freeze: FreezeReleasesRenewalBookingsTest.)
        $this->asTenant($club, fn () => app(MembershipService::class)->freeze($membership->fresh(), 7));

        $this->asTenant($club, fn () => $this->assertSame([30, 0, 30], [Booking::where('status', Booking::CANCELLED)->count(), Booking::where('status', Booking::BOOKED)->count(), Booking::count()]));
        Artisan::call('gym:settle-bookings', ['--dry-run' => true]);
        $this->assertStringContainsString('bookings_left=0', Artisan::output());
        $this->assertCounts($club);
    }

    public function test_a_membership_resumed_before_the_sweep_keeps_its_booking(): void
    {
        ['club' => $club, 'membership' => $membership, 'booking' => $booking] = $this->memberWithACountedBooking();
        $results = $this->concurrently([function () use ($club, $membership) {
            self::dieAtTheFirstClassLock();

            return $this->asTenant($club, fn () => app(MembershipService::class)->freeze($membership, 7)->status);
        }]);
        $this->assertSame('killed before it finished', $results[0]['error'], json_encode($results));
        $this->asTenant($club, fn () => app(MembershipService::class)->resume($membership->fresh()));

        Artisan::call('gym:settle-bookings');
        // Active again: the sweep settles frozen, cancelled and expired memberships only; her class stays booked.
        $this->asTenant($club, fn () => $this->assertSame([Membership::ACTIVE, Booking::BOOKED, 1], [$membership->fresh()->status, $booking->fresh()->status, $membership->fresh()->sessions_used]));
        $this->assertCounts($club);
    }

    public function test_a_freeze_and_the_daily_expiry_at_once_do_not_deadlock(): void
    {
        ['club' => $club, 'membership' => $membership, 'booking' => $booking] = $this->memberWithACountedBooking();
        // Its period ended yesterday; the daily job has not run yet.
        $this->asTenant($club, fn () => $membership->forceFill(['ends_on' => today()->subDay()])->save());

        $results = $this->concurrently([
            function (string $dir) use ($club, $membership) {
                self::holdUntilAnotherWaits($dir, self::membershipLock($membership->id));

                return [$this->asTenant($club, fn () => app(MembershipService::class)->freeze($membership, 7)->status), is_file("$dir/blocked")];
            },
            function (string $dir) use ($club) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? $this->asTenant($club, fn () => app(MembershipService::class)->runDailyTransitions(CarbonImmutable::today())) : 'not at the same time';
            },
        ]);

        $this->assertNoDeadlock($results);
        // The freeze took the membership first: the daily job read it again under its lock and left it frozen.
        $this->assertSame([true, [Membership::FROZEN, true]], [$results[0]['ok'], $results[0]['value']], json_encode($results));
        $this->asTenant($club, fn () => $this->assertSame([Membership::FROZEN, Booking::CANCELLED], [$membership->fresh()->status, $booking->fresh()->status]));
        $this->assertCounts($club);
    }

    public function test_a_booking_left_on_an_expired_membership_is_released_by_the_sweep_once(): void
    {
        ['club' => $club, 'membership' => $membership, 'session' => $session, 'booking' => $booking] = $this->memberWithACountedBooking();
        // As a crash between the daily expiry's commit and its release leaves it: expired, a later class still booked.
        $this->asTenant($club, fn () => $membership->forceFill(['status' => Membership::EXPIRED, 'ends_on' => today()->subDay()])->save());

        Artisan::call('gym:settle-bookings', ['--dry-run' => true]);
        $this->assertStringContainsString('bookings_left=1', Artisan::output());
        Artisan::call('gym:settle-bookings');
        Artisan::call('gym:settle-bookings');
        $this->asTenant($club, fn () => $this->assertSame([Booking::CANCELLED, 0, 0], [$booking->fresh()->status, (int) $session->fresh()->booked_count, $membership->fresh()->sessions_used]));
        $this->assertCounts($club);
    }
}
