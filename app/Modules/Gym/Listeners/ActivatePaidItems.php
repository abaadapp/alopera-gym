<?php

namespace App\Modules\Gym\Listeners;

use App\Modules\Finance\Events\InvoicePaid;
use App\Modules\Finance\Events\InvoiceVoided;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\PtSubscription;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Gym\Services\PtService;

/**
 * The bridge from money to access: when an invoice is fully paid, the
 * memberships and PT packages on it are activated — in the same transaction
 * as the payment. This is the only path to activation.
 */
final class ActivatePaidItems
{
    public function __construct(
        private readonly MembershipService $memberships,
        private readonly PtService $pt,
    ) {
    }

    public function handlePaid(InvoicePaid $event): void
    {
        $items = $event->invoice->items()->whereIn('itemable_type', ['membership', 'pt_subscription'])->get();

        foreach ($items->where('itemable_type', 'membership')->pluck('itemable_id')->unique() as $id) {
            $membership = Membership::find($id);
            if ($membership) {
                $this->memberships->activate($membership);
            }
        }

        foreach ($items->where('itemable_type', 'pt_subscription')->pluck('itemable_id')->unique() as $id) {
            $subscription = PtSubscription::find($id);
            if ($subscription) {
                $this->pt->activate($subscription);
            }
        }
    }

    public function handleVoided(InvoiceVoided $event): void
    {
        $this->memberships->cancelForVoidedInvoice($event->invoice);

        PtSubscription::where('invoice_id', $event->invoice->id)->where('status', PtSubscription::PENDING)
            ->update(['status' => PtSubscription::CANCELLED, 'updated_at' => now()]);
    }
}
