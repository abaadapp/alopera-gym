<?php

namespace App\Console\Commands;

use App\Core\Notifications\StaffNotifier;
use App\Core\Support\BusinessRuleException;
use App\Models\Tenant;
use App\Modules\Messaging\Models\Campaign;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Services\CampaignService;
use App\Modules\Messaging\Services\WhatsAppService;

class DispatchScheduledMessages extends ForEachTenant
{
    protected $signature = 'messaging:dispatch-scheduled {--tenant=}';

    protected $description = 'Release scheduled WhatsApp messages and campaigns whose time has come';

    protected function handleTenant(Tenant $tenant): string
    {
        $messages = 0;
        Message::where('status', Message::SCHEDULED)->where('scheduled_at', '<=', now())->each(function (Message $m) use (&$messages) {
            $m->forceFill(['status' => Message::QUEUED])->save();
            // Refused by the queue, it is scheduled again for now and handed over on the next run.
            if (WhatsAppService::dispatchOrRetry($m->id, $m->tenant_id)) {
                $messages++;
            }
        });

        $campaigns = 0;
        Campaign::with('template')->where('status', Campaign::SCHEDULED)->where('scheduled_at', '<=', now())->eachById(function (Campaign $c) use (&$campaigns) {
            // Its time has come, so launch() starts it now. Refused at that moment (its template back under review at Meta,
            // WhatsApp switched off), it stays scheduled with its time and is tried again on the next run; whoever runs
            // campaigns is told why, once. Clearing its time first left it «scheduled» with none, never picked up again.
            try {
                app(CampaignService::class)->launch($c);
            } catch (BusinessRuleException $e) {
                app(StaffNotifier::class)->notify('campaigns.manage', 'campaign-waiting:'.$c->id, 'integration',
                    'الحملة «'.$c->name.'» لم تبدأ في موعدها: '.$e->getMessage().' تبدأ تلقائيًا حين يزول السبب.', route('club.campaigns.show', $c));

                return;
            }
            app(StaffNotifier::class)->resolve('campaign-waiting:'.$c->id);
            $campaigns++;
        });

        return "messages={$messages} campaigns={$campaigns}";
    }
}
