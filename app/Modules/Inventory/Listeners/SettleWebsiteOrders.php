<?php

namespace App\Modules\Inventory\Listeners;

use App\Modules\Finance\Events\InvoicePaid;
use App\Modules\Finance\Events\InvoiceVoided;
use App\Modules\Inventory\Services\OrderService;

/**
 * Website orders follow their invoice: fully paid (by the signed gateway webhook or at the desk) marks the order
 * paid; voided from the finance screens cancels a pending order and puts its stock back. Runs inside the
 * payment's own transaction, like membership activation.
 */
final class SettleWebsiteOrders
{
    public function __construct(private readonly OrderService $orders) {}

    public function handlePaid(InvoicePaid $event): void
    {
        $this->orders->markPaid($event->invoice);
    }

    public function handleVoided(InvoiceVoided $event): void
    {
        $this->orders->cancelForVoidedInvoice($event->invoice);
    }
}
