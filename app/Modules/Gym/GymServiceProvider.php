<?php

namespace App\Modules\Gym;

use App\Core\Support\ModuleServiceProvider;
use App\Modules\Finance\Events\InvoicePaid;
use App\Modules\Finance\Events\InvoiceVoided;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Gym\Listeners\ActivatePaidItems;
use App\Modules\Gym\Services\GiftService;
use Illuminate\Support\Facades\Event;

class GymServiceProvider extends ModuleServiceProvider
{
    protected function modulePath(): string
    {
        return __DIR__;
    }

    protected function bootModule(): void
    {
        Event::listen(InvoicePaid::class, [ActivatePaidItems::class, 'handlePaid']);
        Event::listen(InvoiceVoided::class, [ActivatePaidItems::class, 'handleVoided']);
        // A credit note on a paid gift's invoice (inside CreditNoteService::issue, under the invoice's lock) ends the gift.
        CreditNote::created(fn (CreditNote $note) => app(GiftService::class)->refundedBy($note));
    }
}
