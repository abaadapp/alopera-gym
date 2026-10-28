<?php

namespace App\Modules\Finance\Events;

use App\Modules\Finance\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired inside the payment transaction the moment an invoice becomes fully
 * paid. Listeners that activate memberships run synchronously in the same
 * transaction; notification listeners queue after commit.
 */
class InvoicePaid
{
    use Dispatchable;

    public function __construct(public readonly Invoice $invoice)
    {
    }
}
