<?php

namespace App\Modules\Messaging\Jobs;

use App\Modules\Messaging\Models\WebhookEvent;
use App\Modules\Messaging\Services\InboundProcessor;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Throwable;

/**
 * Processes one stored webhook body. Processing is safe to repeat (every message, echo and status is keyed by its
 * WhatsApp id), so a failure is retried with growing waits; the error kept on the row names the failure, never the
 * customer's message.
 */
class ProcessWhatsAppWebhook implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 5;

    /** @var list<int> */
    public array $backoff = [10, 60, 300, 900];

    public function __construct(public readonly int $eventId)
    {
        $this->onQueue('notifications');
    }

    public function handle(InboundProcessor $processor): void
    {
        $event = WebhookEvent::find($this->eventId);
        if (! $event || in_array($event->status, [WebhookEvent::PROCESSED, WebhookEvent::IGNORED], true)) {
            return;
        }

        $event->forceFill(['attempts' => $event->attempts + 1])->save();

        try {
            $result = $processor->handle((array) $event->payload, $event->vendor);
        } catch (Throwable $e) {
            $event->forceFill(['error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 250)])->save();

            throw $e;
        }

        $event->forceFill([
            'tenant_id' => $result['tenant_id'],
            'status' => $result['handled'] > 0 ? WebhookEvent::PROCESSED : WebhookEvent::IGNORED,
            'error' => $result['handled'] > 0 ? null : $result['note'],
            'processed_at' => now(),
        ])->save();
    }

    public function failed(Throwable $e): void
    {
        WebhookEvent::whereKey($this->eventId)->update(['status' => WebhookEvent::FAILED]);
    }
}
