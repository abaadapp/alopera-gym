<?php

namespace App\Modules\Gym\Events;

use App\Modules\Gym\Models\Membership;
use Illuminate\Foundation\Events\Dispatchable;

class MembershipResumed
{
    use Dispatchable;

    public function __construct(public readonly Membership $membership)
    {
    }
}
