<?php

namespace App\Modules\Finance\Events;

use App\Modules\Finance\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/** A bank transfer was recorded and is waiting for finance to confirm it. */
class TransferSubmitted
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment)
    {
    }
}
