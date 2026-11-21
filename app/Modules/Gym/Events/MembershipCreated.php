<?php

namespace App\Modules\Gym\Events;

use App\Modules\Gym\Models\Membership;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A membership was sold and its invoice issued (still pending payment). $notify false: the invoice message is not
 * sent for it (a new customer's invoice whose staff did not ask for it, 2026-11-21).
 */
class MembershipCreated
{
    use Dispatchable;

    public function __construct(public readonly Membership $membership, public readonly bool $notify = true) {}
}
