<?php

namespace App\Modules\Finance\Events;

use App\Modules\Finance\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/** A completed payment (cash, confirmed transfer, gateway) was applied to an invoice. */
class PaymentRecorded
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment)
    {
    }
}
