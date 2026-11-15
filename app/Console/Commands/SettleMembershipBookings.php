<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\BookingService;

/**
 * Future bookings still booked on a cancelled membership: carried to the upgrade that replaced it, or released
 * (BookingService::settleCancelled). Done right after a cancellation or an upgrade's payment commits; this finishes what
 * a crash or a failure left, every minute. Each booking is moved or released once, however often this runs.
 */
class SettleMembershipBookings extends ForEachTenant
{
    protected $signature = 'gym:settle-bookings {--tenant=} {--dry-run : Count what is left to do, change nothing}';

    protected $description = 'Carry or release future bookings still booked on cancelled memberships';

    protected function handleTenant(Tenant $tenant): string
    {
        // Cancelled memberships with a booking still booked; settleCancelled() takes the classes after each one's
        // cancellation (a class may have started since).
        $ids = Booking::where('status', Booking::BOOKED)
            ->whereHas('membership', fn ($q) => $q->where('status', Membership::CANCELLED))
            ->distinct()->orderBy('membership_id')->pluck('membership_id');
        $bookings = app(BookingService::class);
        $memberships = Membership::whereIn('id', $ids)->orderBy('id')->get();

        if ($this->option('dry-run')) {
            $left = $memberships->map(fn (Membership $m) => $bookings->leftAfterCancellation($m))->filter();

            return "dry_run memberships_left={$left->count()} bookings_left={$left->sum()}";
        }

        $settled = 0;
        foreach ($memberships as $membership) {
            $settled += $bookings->settleCancelled($membership);
        }

        return "memberships={$memberships->count()} bookings={$settled}";
    }
}
