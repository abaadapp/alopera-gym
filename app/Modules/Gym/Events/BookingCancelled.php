<?php

namespace App\Modules\Gym\Events;

use App\Modules\Gym\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

class BookingCancelled
{
    use Dispatchable;

    public function __construct(public readonly Booking $booking, public readonly ?string $reason = null) {}
}
