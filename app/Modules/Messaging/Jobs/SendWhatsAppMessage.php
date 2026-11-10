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
            if (! $message || ! in_array($message->status, [Message::QUEUED], true)) {
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

            $result = $whatsapp->deliver($message);

            if (! $result->ok && $result->retryable) {
                if ($this->attempts() >= $this->tries) {
                    $message->forceFill(['status' => Message::FAILED])->save();

                    return;
                }
                throw new RuntimeException('WhatsApp temporary failure: '.$result->errorCode);
            }
        });
    }
}
