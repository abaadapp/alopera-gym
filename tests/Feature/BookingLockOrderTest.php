<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Services\BookingService;
use App\Modules\Gym\Services\MembershipService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * A booking cancelled by the member while staff freeze her membership or cancel the whole class, in separate processes
 * on PostgreSQL. Every path takes the class before the booking and reads the booking again under that lock: a booking is
 * released once (the class never takes more members than its seats), and the two never deadlock.
 */
class BookingLockOrderTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    public function test_a_booking_cancelled_by_the_member_while_her_membership_is_frozen_is_released_once(): void
    {
        $club = $this->club();
        $plan = $this->plan($club); // unlimited classes, freeze allowed
        $session = $this->classSession($club, null, ['capacity' => 2]);
        [$her, $other, $third, $fourth] = [$this->member($club), $this->member($club), $this->member($club), $this->member($club)];
        $membership = $this->activeMembership($club, $her, $plan);
        foreach ([$other, $third, $fourth] as $m) {
            $this->activeMembership($club, $m, $plan);
        }
        $booking = $this->asTenant($club, fn () => app(BookingService::class)->book($her, $session, 'portal'));
        $this->asTenant($club, fn () => app(BookingService::class)->book($other, $session));
        $this->assertSame(2, $this->asTenant($club, fn () => $session->fresh()->booked_count));

        $results = $this->concurrently([
            // The member cancels her booking in the portal, and is slowed down while holding the class's row lock.
            function (string $dir) use ($club, $booking) {
                $held = false;
                DB::listen(function ($query) use ($dir, &$held) {
                    if (! $held && str_contains($query->sql, 'from "gym_class_sessions"') && str_contains($query->sql, 'for update')) {
                        $held = true;
                        file_put_contents("$dir/inside", '1');
                        usleep(1_500_000);
                    }
                });

                return $this->asTenant($club, fn () => app(BookingService::class)->cancel($booking, false, 'member')->status);
            },
            // Meanwhile reception freezes her membership.
            function (string $dir) use ($club, $membership) {
                self::waitFor("$dir/inside");

                return $this->asTenant($club, fn () => app(MembershipService::class)->freeze($membership, 7, 'travel')->status);
            },
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertTrue($results[1]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));

        $this->asTenant($club, function () use ($session, $third, $fourth) {
            // One booking was cancelled, the other member's is still live: one seat taken.
            $live = Booking::where('class_session_id', $session->id)->where('status', '!=', Booking::CANCELLED)->count();
            $this->assertSame(1, $live);
            $counted = ClassSession::find($session->id)->booked_count;

            // And so the class never takes more members than its seats: two more members try for the one seat left.
            foreach ([$third, $fourth] as $m) {
                try {
                    app(BookingService::class)->book($m, ClassSession::find($session->id));
                } catch (BusinessRuleException) {
                }
            }
            $this->assertLessThanOrEqual(2, Booking::where('class_session_id', $session->id)->where('status', '!=', Booking::CANCELLED)->count(),
                'more live bookings than the 2 seats (booked_count after the race was '.$counted.' for 1 live booking)');
        });
    }

    public function test_a_member_cancelling_while_staff_cancel_the_class_does_not_deadlock(): void
    {
        $club = $this->club();
        $session = $this->classSession($club, null, ['capacity' => 5]);
        $member = $this->member($club);
        $this->activeMembership($club, $member);
        $booking = $this->asTenant($club, fn () => app(BookingService::class)->book($member, $session, 'portal'));

        $results = $this->concurrently([
            // The member cancels in the portal; slowed down right after her booking row is locked.
            function (string $dir) use ($club, $booking) {
                $held = false;
                DB::listen(function ($query) use ($dir, &$held) {
                    if (! $held && str_contains($query->sql, 'from "gym_bookings"') && str_contains($query->sql, 'for update')) {
                        $held = true;
                        file_put_contents("$dir/inside", '1');
                        usleep(1_000_000);
                    }
                });

                return $this->asTenant($club, fn () => app(BookingService::class)->cancel($booking, false, 'member')->status);
            },
            // Meanwhile staff cancel the class (trainer sick).
            function (string $dir) use ($club, $session) {
                self::waitFor("$dir/inside");

                return $this->asTenant($club, fn () => app(BookingService::class)->cancelSession($session, 'trainer sick')->status);
            },
        ]);

        foreach ($results as $r) {
            $this->assertStringNotContainsString('40P01', (string) $r['error'], json_encode($results, JSON_UNESCAPED_UNICODE));
        }
        $this->asTenant($club, function () use ($session, $booking) {
            $this->assertSame(Booking::CANCELLED, Booking::find($booking->id)->status);
            $this->assertSame(ClassSession::CANCELLED, ClassSession::find($session->id)->status);
        });
    }
}
