<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\BookingService;

/**
 * Bookings still booked on a membership that can no longer use them — cancelled (carried to the upgrade that replaced
 * it, or released), frozen (released, with those on the paid periods it holds) or expired (released): BookingService::
 * settle(). Done right after the cancellation, the upgrade's payment, the freeze or the expiry commits; this finishes what
 * a crash or a failure left, every minute. Each booking is moved or released once, however often this runs.
 */
class SettleMembershipBookings extends ForEachTenant
{
    protected $signature = 'gym:settle-bookings {--tenant=} {--dry-run : Count what is left to do, change nothing}';

    protected $description = 'Carry or release bookings still booked on cancelled, frozen or expired memberships';

    protected function handleTenant(Tenant $tenant): string
    {
        // Only ids are read: cancelled or expired memberships with a booking still booked, and frozen ones whose member
        // has one (on it, or on a paid period it holds). settle() takes the classes after each one's moment.
        $ids = Booking::where('status', Booking::BOOKED)
            ->whereHas('membership', fn ($q) => $q->whereIn('status', [Membership::CANCELLED, Membership::EXPIRED]))
            ->distinct()->pluck('membership_id')
            ->merge(Membership::where('status', Membership::FROZEN)
                ->whereIn('member_id', Booking::select('member_id')->where('status', Booking::BOOKED))->pluck('id'))
            ->unique();
        $bookings = app(BookingService::class);
        $memberships = Membership::whereIn('id', $ids)->orderBy('id')->get();

        if ($this->option('dry-run')) {
            $left = $memberships->map(fn (Membership $m) => $bookings->leftToSettle($m))->filter();

            return "dry_run memberships_left={$left->count()} bookings_left={$left->sum()}";
        }

        $settled = 0;
        foreach ($memberships as $membership) {
            $settled += $bookings->settle($membership);
        }

        return "memberships={$memberships->count()} bookings={$settled}";
    }
}
