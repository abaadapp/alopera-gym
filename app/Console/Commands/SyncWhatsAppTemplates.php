<?php

namespace App\Console\Commands;

use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\HomeGym;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Modules\Messaging\Services\MetaTemplates;
use Illuminate\Console\Command;

/**
 * Reads every WhatsApp message template's state from Meta (approved, under review, refused…), so a message sent for
 * review from «رسائل الواتساب» starts being sent soon after Meta approves it, even without Meta's webhook.
 */
class SyncWhatsAppTemplates extends Command
{
    protected $signature = 'whatsapp:sync-templates';

    protected $description = 'Read the WhatsApp message templates\' review state from Meta';

    public function handle(TenantContext $tenants, MetaTemplates $meta): int
    {
        foreach (Tenant::query()->when(HomeGym::id(), fn ($q, $home) => $q->whereKey($home))->orderBy('id')->get() as $club) {
            $tenants->run($club, function () use ($meta, $club) {
                if ($meta->unavailable() !== null) {
                    return;
                }
                try {
                    $changed = $meta->sync();
                    if ($changed > 0) {
                        $this->line("{$club->slug}: {$changed} updated");
                    }
                } catch (BusinessRuleException $e) {
                    $this->warn("{$club->slug}: {$e->getMessage()}");
                }
            });
        }

        return self::SUCCESS;
    }
}
