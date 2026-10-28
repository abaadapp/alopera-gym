<?php

namespace App\Modules\Gym\Events;

use App\Modules\Gym\Models\Membership;
use Illuminate\Foundation\Events\Dispatchable;

/** A membership was sold and its invoice issued (still pending payment). */
class MembershipCreated
{
    use Dispatchable;

    public function __construct(public readonly Membership $membership) {}
}
