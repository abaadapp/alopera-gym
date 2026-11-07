<?php

namespace App\Console\Commands;

use App\Modules\Messaging\Models\WebhookEvent;
use Illuminate\Console\Command;

/**
 * Webhook bodies hold customers' messages: once processed (or ignored) they are kept only as long as the settings
 * say (WHATSAPP_WEBHOOK_RETENTION_DAYS, 14 by default). The messages themselves stay in the conversations.
 */
class PruneWhatsAppWebhooks extends Command
{
    protected $signature = 'whatsapp:prune-webhooks';

    protected $description = 'Delete processed WhatsApp webhook bodies past their retention';

    public function handle(): int
    {
        $days = max(1, (int) config('services.whatsapp.webhook_retention_days', 14));
        $deleted = WebhookEvent::whereIn('status', [WebhookEvent::PROCESSED, WebhookEvent::IGNORED])->where('created_at', '<', now()->subDays($days))->delete()
            + WebhookEvent::where('status', WebhookEvent::FAILED)->where('created_at', '<', now()->subDays($days * 4))->delete();
        $this->info("deleted={$deleted}");

        return self::SUCCESS;
    }
}
