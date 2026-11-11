<?php

namespace Tests\Feature;

use App\Core\Tenancy\TenantContext;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Access\AccessControlService;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\BookingService;
use App\Modules\Gym\Services\MembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Freezing a membership releases the future bookings of the paid renewal waiting behind it, as it releases its own: that
 * renewal is held by the freeze (it books nothing and the door refuses it, #89), so its booked seats and sessions are
 * given back.
 */
class FreezeReleasesRenewalBookingsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_freezing_releases_the_bookings_made_on_the_renewal_it_holds(): void
    {
        $club = $this->club();
        // Three-day periods with 8 classes each: the current one ends tomorrow, the paid renewal starts the day after.
        $plan = $this->plan($club, ['duration_value' => 3, 'duration_unit' => 'day', 'sessions_limit' => 8, 'max_freeze_days' => 14]);
        $member = $this->member($club);
        $current = $this->activeMembership($club, $member, $plan);
        $today = CarbonImmutable::today();

        $this->asTenant($club, function () use ($member, $plan, $current, $today) {
            $renewal = app(MembershipService::class)->renew($member, $plan);
            app(PaymentService::class)->record($renewal->invoice()->first(), $renewal->total, 'cash');
            $renewal = $renewal->fresh();
            $this->assertSame(Membership::SCHEDULED, $renewal->status);
            $this->assertTrue($renewal->starts_on->equalTo($current->ends_on->addDay()));

            // A class four days from now: inside the renewal, booked at the desk on it.
            $session = $this->classSession(app(TenantContext::class)->require(), null, ['starts_at' => $today->addDays(4)->setTime(18, 0)]);
            $booking = app(BookingService::class)->book($member, $session);
            $this->assertSame($renewal->id, $booking->membership_id);
            $this->assertSame(1, $renewal->fresh()->sessions_used);

            // The member freezes today for ten days: the class falls inside the freeze.
            app(MembershipService::class)->freeze($current, 10, 'travel');

            // The door refuses her on the class day (the renewal is held by the freeze)…
            $decision = app(AccessControlService::class)->evaluate($member->fresh(), $today->addDays(4));
            $this->assertFalse($decision->allowed);

            // …so, as for the frozen period's own bookings, the seat and the renewal's session should be given back.
            $this->assertSame(Booking::CANCELLED, $booking->fresh()->status, 'booking on the held renewal is still '.$booking->fresh()->status);
            $this->assertSame(0, ClassSession::find($session->id)->booked_count);
            $this->assertSame(0, $renewal->fresh()->sessions_used);
        });
    }
}
