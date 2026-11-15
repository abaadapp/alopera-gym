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
        $ids = Booking::where('status', Booking::BOOKED)
            ->whereHas('session', fn ($q) => $q->where('starts_at', '>', now()))
            ->whereHas('membership', fn ($q) => $q->where('status', Membership::CANCELLED))
            ->distinct()->orderBy('membership_id')->pluck('membership_id');

        if ($this->option('dry-run')) {
            return "memberships_left={$ids->count()}";
        }

        $settled = 0;
        foreach (Membership::whereIn('id', $ids)->orderBy('id')->get() as $membership) {
            $settled += app(BookingService::class)->settleCancelled($membership);
        }

        return "memberships={$ids->count()} bookings={$settled}";
    }
}
