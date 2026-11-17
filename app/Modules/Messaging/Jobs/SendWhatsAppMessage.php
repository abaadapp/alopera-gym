<?php

namespace App\Modules\Messaging\Jobs;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Services\CampaignService;
use App\Modules\Messaging\Services\WhatsAppService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class SendWhatsAppMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;

    /** @var list<int> */
    public array $backoff = [30, 120, 600];

    public function __construct(public readonly int $messageId, public readonly int $tenantId)
    {
        $this->onQueue('notifications');
    }

    public function handle(TenantContext $tenants, WhatsAppService $whatsapp): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        $tenants->run($tenant, function () use ($whatsapp) {
            $message = Message::find($this->messageId);
            if ($message?->status === Message::SENDING) {
                // Claimed before and never settled (#203). A retry or a job handed back after its worker died: that
                // attempt's request may have reached WhatsApp, so the message is uncertain, not sent again. A first
                // attempt finding it claimed is a second hand-over while another worker sends it: that one settles it.
                if ($this->attempts() > 1) {
                    $whatsapp->markUncertain($message);
                }

                return;
            }
            if (! $message || $message->status !== Message::QUEUED) {
                return;
            }
            // Consent may have been withdrawn while it waited in the queue: it is skipped, as it would have been then.
            if ($reason = $whatsapp->consentRefusal($message)) {
                $message->forceFill(['status' => Message::SKIPPED, 'error_code' => $reason])->save();
                if ($message->campaign_id) {
                    CampaignService::refreshCounts($message->campaign_id);
                }

                return;
            }

            if (! $whatsapp->claim($message)) {
                return; // another worker took it a moment ago
            }
            $result = $whatsapp->deliver($message);

            // Known not to have reached WhatsApp (no connection, WhatsApp's own "not now"): sent again later.
            if (! $result->ok && $result->retryable) {
                if ($this->attempts() >= $this->tries) {
                    $message->forceFill(['status' => Message::FAILED])->save();

                    return;
                }
                throw new RuntimeException('WhatsApp temporary failure: '.$result->errorCode);
            }
        });
    }

    /** The queue gave up on the job (an error on its last attempt): a claim it left is settled as uncertain at once. */
    public function failed(?Throwable $e = null): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }
        app(TenantContext::class)->run($tenant, function () {
            $message = Message::find($this->messageId);
            if ($message?->status === Message::SENDING) {
                app(WhatsAppService::class)->markUncertain($message);
            }
        });
    }
}
