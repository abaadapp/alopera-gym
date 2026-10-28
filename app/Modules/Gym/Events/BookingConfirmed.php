<?php

namespace App\Modules\Gym\Events;

use App\Modules\Gym\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

class BookingConfirmed
{
    use Dispatchable;

    public function __construct(public readonly Booking $booking) {}
}
