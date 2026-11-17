<?php

namespace App\Modules\Finance\Events;

use App\Modules\Finance\Models\Invoice;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A credit note is about to be issued on this invoice for this amount (CreditNoteService::issue, the invoice locked,
 * inside the transaction). A listener that refuses it throws a BusinessRuleException and nothing is written: what an
 * invoice line was sold for may limit how it is given back (a gift membership is refunded in full or not at all).
 */
class CreditNoteIssuing
{
    use Dispatchable;

    public function __construct(public readonly Invoice $invoice, public readonly string $amount) {}
}
