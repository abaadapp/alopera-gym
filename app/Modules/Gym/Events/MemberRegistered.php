<?php

namespace App\Modules\Gym\Events;

use App\Modules\Gym\Models\Member;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new member profile was created (desk or website). $welcome false: registered by a new customer's invoice whose
 * staff did not ask for the welcome message (the member's choice, 2026-11-21) — nothing is sent for it.
 */
class MemberRegistered
{
    use Dispatchable;

    public function __construct(public readonly Member $member, public readonly bool $welcome = true) {}
}
