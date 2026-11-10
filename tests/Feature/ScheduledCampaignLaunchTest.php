<?php

namespace Tests\Feature;

use App\Models\StaffNotification;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Messaging\Models\Campaign;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\CampaignService;
use App\Modules\Messaging\Services\TemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A scheduled campaign that cannot start at its time (its template back under review at Meta, WhatsApp switched off)
 * stays scheduled with its time and starts on the next run once the reason is gone; whoever runs campaigns is told once.
 * Before, its time was cleared first, so it sat «scheduled» with no time and no run ever picked it up again.
 */
class ScheduledCampaignLaunchTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function enableWhatsApp(Tenant $club): void
    {
        $this->asTenant($club, function () {
            TenantIntegration::create([
                'provider' => 'whatsapp', 'is_enabled' => true,
                'credentials' => ['phone_number_id' => 'PNID-1', 'access_token' => 'EAAG-test'],
                'public_identifier' => 'PNID-1',
            ]);
            TemplateCatalog::seedDefaults();
            MessageTemplate::query()->update(['status' => 'approved']);
        });
    }

    public function test_a_scheduled_campaign_that_cannot_start_on_time_is_not_left_scheduled_forever(): void
    {
        $club = $this->club();
        $this->enableWhatsApp($club);
        $member = $this->member($club, ['marketing_opt_in' => true]);

        [$campaign, $template] = $this->asTenant($club, function () {
            $template = MessageTemplate::create(['key' => 'custom_offer', 'name' => 'offer', 'category' => 'marketing', 'body' => 'عرض {{1}}', 'variables' => ['member_name'], 'status' => 'approved']);
            $campaign = Campaign::create(['name' => 'Offer', 'message_template_id' => $template->id, 'audience' => ['segment' => 'all'], 'scheduled_at' => now()->addHour()]);
            app(CampaignService::class)->launch($campaign->load('template'));

            return [$campaign, $template];
        });
        $this->assertSame(Campaign::SCHEDULED, $this->asTenant($club, fn () => $campaign->fresh()->status));

        // At the scheduled time the template happens to be back under review at Meta (or WhatsApp is switched off).
        $this->asTenant($club, fn () => $template->forceFill(['status' => 'pending'])->save());
        $this->travel(61)->minutes();
        Artisan::call('messaging:dispatch-scheduled');
        $this->assertSame(1, $this->asTenant($club, fn () => StaffNotification::where('key', 'campaign-waiting:'.$campaign->id)->whereNull('read_at')->count()), 'the owner is told why it waits');

        // It is approved again: the campaign should still go out (or at least not sit «scheduled» with no time).
        $this->asTenant($club, fn () => $template->forceFill(['status' => 'approved'])->save());
        $this->travel(1)->minutes();
        Artisan::call('messaging:dispatch-scheduled');

        $fresh = $this->asTenant($club, fn () => $campaign->fresh());
        $this->assertFalse($fresh->status === Campaign::SCHEDULED && $fresh->scheduled_at === null,
            'the campaign is left «scheduled» with no time: no run will ever pick it up again');
        $this->assertSame(1, $this->asTenant($club, fn () => Message::where('campaign_id', $campaign->id)->where('member_id', $member->id)->count()),
            'the scheduled campaign was never sent');
        $this->assertSame(0, $this->asTenant($club, fn () => StaffNotification::where('key', 'campaign-waiting:'.$campaign->id)->whereNull('read_at')->count()), 'the alert is settled once it started');
    }
}
