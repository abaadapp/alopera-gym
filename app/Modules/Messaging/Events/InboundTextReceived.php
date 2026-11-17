<?php

namespace App\Modules\Messaging\Events;

use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\Message;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A customer's text message has come in on the club's number (a signed webhook, stored once, inside the club's
 * context). Asked with `until`: a listener that recognises the message as its own (a gift's confirmation code, #192)
 * answers true, and the inbox's automatic answers (STOP, auto-replies) leave it alone.
 */
final class InboundTextReceived
{
    use Dispatchable;

    public function __construct(public readonly Conversation $conversation, public readonly Message $message) {}
}
