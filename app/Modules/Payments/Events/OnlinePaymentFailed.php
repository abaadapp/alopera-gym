<?php

namespace App\Modules\Payments\Events;

use App\Modules\Payments\Models\PaymentIntent;
use Illuminate\Foundation\Events\Dispatchable;

class OnlinePaymentFailed
{
    use Dispatchable;

    public function __construct(public readonly PaymentIntent $intent) {}
}
