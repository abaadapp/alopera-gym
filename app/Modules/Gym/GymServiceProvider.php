<?php

namespace App\Modules\Gym;

use App\Core\Support\ModuleServiceProvider;
use App\Modules\Finance\Events\CreditNoteIssuing;
use App\Modules\Finance\Events\InvoicePaid;
use App\Modules\Finance\Events\InvoiceVoided;
use App\Modules\Finance\Events\PaymentCancelled;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Gym\Listeners\ActivatePaidItems;
use App\Modules\Gym\Models\GiftMembership;
use App\Modules\Gym\Services\GiftService;
use App\Modules\Gym\Services\GiftWhatsApp;
use App\Modules\Messaging\Events\InboundTextReceived;
use App\Modules\Messaging\Models\Message;
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
        // A gift not used is refunded in full or not at all; a mistaken payment's cancellation sends it back to waiting.
        Event::listen(CreditNoteIssuing::class, [GiftService::class, 'guardCredit']);
        Event::listen(PaymentCancelled::class, [GiftService::class, 'paymentCancelled']);
        // A gift sent to its recipient's WhatsApp (#192): her message with the code confirms the number; a message
        // of a gift that fails (refused by WhatsApp, or a failed status) is told to staff, and the gift stands.
        Event::listen(InboundTextReceived::class, [GiftWhatsApp::class, 'confirmFromWhatsApp']);
        Message::saved(function (Message $message) {
            if ($message->related_type === (new GiftMembership)->getMorphClass()
                && in_array($message->status, [Message::FAILED, Message::SKIPPED], true)
                && ($message->wasRecentlyCreated || $message->wasChanged('status'))) {
                app(GiftWhatsApp::class)->messageFailed($message);
            }
        });
    }
}
