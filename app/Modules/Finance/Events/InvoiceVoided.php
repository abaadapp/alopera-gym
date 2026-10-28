<?php

namespace App\Modules\Finance\Events;

use App\Modules\Finance\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

class InvoiceVoided
{
    use Dispatchable;

    public function __construct(public readonly Invoice $invoice)
    {
    }
}
