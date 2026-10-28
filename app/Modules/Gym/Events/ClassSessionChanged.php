<?php

namespace App\Modules\Gym\Events;

use App\Modules\Gym\Models\ClassSession;
use Illuminate\Foundation\Events\Dispatchable;

/** Time, trainer or room of a booked session changed; booked members are told. */
class ClassSessionChanged
{
    use Dispatchable;

    public function __construct(public readonly ClassSession $session, public readonly string $change) {}
}
