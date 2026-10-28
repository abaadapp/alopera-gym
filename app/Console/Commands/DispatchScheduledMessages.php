<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Messaging\Jobs\SendWhatsAppMessage;
use App\Modules\Messaging\Models\Campaign;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Services\CampaignService;

class DispatchScheduledMessages extends ForEachTenant
{
    protected $signature = 'messaging:dispatch-scheduled {--tenant=}';

    protected $description = 'Release scheduled WhatsApp messages and campaigns whose time has come';

    protected function handleTenant(Tenant $tenant): string
    {
        $messages = 0;
        Message::where('status', Message::SCHEDULED)->where('scheduled_at', '<=', now())->each(function (Message $m) use (&$messages) {
            $m->forceFill(['status' => Message::QUEUED])->save();
            SendWhatsAppMessage::dispatch($m->id, $m->tenant_id);
            $messages++;
        });

        $campaigns = 0;
        Campaign::with('template')->where('status', Campaign::SCHEDULED)->where('scheduled_at', '<=', now())->each(function (Campaign $c) use (&$campaigns) {
            $c->forceFill(['scheduled_at' => null])->save();
            app(CampaignService::class)->launch($c);
            $campaigns++;
        });

        return "messages={$messages} campaigns={$campaigns}";
    }
}
