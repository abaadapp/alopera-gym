<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Messaging\Jobs\ProcessWhatsAppWebhook;
use App\Modules\Messaging\Models\WebhookEvent;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * The first step for every verified WhatsApp webhook: write the body down (once — the same body delivered again is
 * the same row) and hand it to a queued job, so the provider gets its 200 at once and a failure is retried rather
 * than lost. A body still waiting after ten minutes (a lost job) is handed over again when it is delivered again.
 */
final class WebhookIntake
{
    /** @param array<string, mixed> $payload */
    public function accept(string $vendor, string $raw, array $payload): WebhookEvent
    {
        $digest = hash('sha256', $vendor."\n".$raw);
        $fields = collect(Arr::get($payload, 'entry', []))->flatMap(fn ($e) => collect(Arr::get($e, 'changes', []))->pluck('field'))
            ->filter()->unique()->implode(',');

        try {
            // Savepoint: a repeated body must not abort a surrounding transaction (PostgreSQL aborts the block on error).
            $event = DB::transaction(fn () => WebhookEvent::create([
                'vendor' => $vendor,
                'digest' => $digest,
                'payload' => $payload,
                'fields' => mb_substr($fields ?: 'messages', 0, 120),
                'status' => WebhookEvent::RECEIVED,
            ]));
        } catch (UniqueConstraintViolationException) {
            $event = WebhookEvent::where('digest', $digest)->firstOrFail();
            if ($event->status !== WebhookEvent::RECEIVED || $event->created_at->gt(now()->subMinutes(10))) {
                return $event;
            }
        }

        ProcessWhatsAppWebhook::dispatch($event->id);

        return $event;
    }
}
