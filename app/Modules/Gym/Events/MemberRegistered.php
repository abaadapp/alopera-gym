<?php

namespace App\Modules\Gym\Events;

use App\Modules\Gym\Models\Member;
use Illuminate\Foundation\Events\Dispatchable;

/** A new member profile was created (desk or website). */
class MemberRegistered
{
    use Dispatchable;

    public function __construct(public readonly Member $member) {}
}
