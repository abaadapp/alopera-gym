<?php

namespace App\Modules\Finance\Events;

use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A payment recorded by mistake was cancelled (PaymentService::cancel), inside its transaction, the invoice locked and
 * saved with what is left paid. A listener may undo what the payment made possible, or refuse (BusinessRuleException)
 * when that can no longer be undone, and the cancellation rolls back.
 */
class PaymentCancelled
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment, public readonly Invoice $invoice) {}
}
