<?php

namespace App\Modules\Gym\Events;

use App\Modules\Gym\Models\Membership;
use Illuminate\Foundation\Events\Dispatchable;

/** A membership was paid and is now active or scheduled. */
class MembershipActivated
{
    use Dispatchable;

    public function __construct(public readonly Membership $membership)
    {
    }
}
