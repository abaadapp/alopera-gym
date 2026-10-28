<?php

namespace App\Modules\Gym\Events;

use App\Modules\Gym\Models\PtSubscription;
use Illuminate\Foundation\Events\Dispatchable;

class PtSubscriptionActivated
{
    use Dispatchable;

    public function __construct(public readonly PtSubscription $subscription)
    {
    }
}
