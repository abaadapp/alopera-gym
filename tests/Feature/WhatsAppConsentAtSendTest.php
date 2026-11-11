<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Messaging\Jobs\SendWhatsAppMessage;
use App\Modules\Messaging\Models\Campaign;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\CampaignService;
use App\Modules\Messaging\Services\TemplateCatalog;
use App\Modules\Messaging\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Consent is checked again when the queue worker sends a WhatsApp message, not only when it was queued: a member who
 * says STOP (or whose consent staff withdraw) while her campaign message waits in the queue — a backlog, Meta's rate,
 * retries — does not receive it. A service message she still agreed to goes out as before.
 */
class WhatsAppConsentAtSendTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.driver' => 'meta', 'services.whatsapp.app_secret' => 'app-secret']);
    }

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

    public function test_a_campaign_message_waiting_in_the_queue_is_not_sent_after_the_member_says_stop(): void
    {
        $club = $this->club();
        $this->enableWhatsApp($club);
        $member = $this->member($club, ['phone' => '96892222222', 'marketing_opt_in' => true]);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT-1']]], 200)]);

        // The campaign is fanned out; the per-member send jobs wait in the queue.
        Queue::fake([SendWhatsAppMessage::class]);
        $campaign = $this->asTenant($club, function () {
            $template = MessageTemplate::create(['key' => 'custom_offer', 'name' => 'offer', 'category' => 'marketing', 'body' => 'عرض {{1}}', 'variables' => ['member_name'], 'status' => 'approved']);
            $campaign = Campaign::create(['name' => 'Offer', 'message_template_id' => $template->id, 'audience' => ['segment' => 'all']]);
            app(CampaignService::class)->fanOut($campaign->load('template')->forceFill(['status' => Campaign::SENDING]));

            return $campaign;
        });
        $message = $this->asTenant($club, fn () => Message::where('campaign_id', $campaign->id)->where('member_id', $member->id)->firstOrFail());
        $this->assertSame(Message::QUEUED, $message->status);

        // Meanwhile she replies STOP (the same effect InboundProcessor gives it).
        $this->asTenant($club, fn () => $member->forceFill(['marketing_opt_in' => false, 'marketing_opt_out_at' => now()])->save());

        // The worker now picks the job up.
        app()->call([new SendWhatsAppMessage($message->id, $club->id), 'handle']);

        Http::assertNothingSent();
        $this->assertNotSame(Message::SENT, $this->asTenant($club, fn () => $message->fresh()->status),
            'the marketing message went to Meta after the member withdrew marketing consent');
    }

    public function test_a_service_message_still_goes_when_only_marketing_consent_was_withdrawn(): void
    {
        $club = $this->club();
        $this->enableWhatsApp($club);
        $member = $this->member($club, ['phone' => '96892222223', 'marketing_opt_in' => true]);
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OUT-2']]], 200)]);

        Queue::fake([SendWhatsAppMessage::class]);
        $message = $this->asTenant($club, function () use ($member) {
            $template = MessageTemplate::create(['key' => 'custom_service', 'name' => 'service', 'category' => 'utility', 'body' => 'تذكير {{1}}', 'variables' => ['member_name'], 'status' => 'approved']);

            return app(WhatsAppService::class)->queueTemplate($member, $template->key, ['member_name' => 'Sara']);
        });
        $this->assertSame(Message::QUEUED, $message->status);
        $this->asTenant($club, fn () => $member->forceFill(['marketing_opt_in' => false, 'marketing_opt_out_at' => now()])->save());

        app()->call([new SendWhatsAppMessage($message->id, $club->id), 'handle']);

        Http::assertSentCount(1);
        $this->assertSame(Message::SENT, $this->asTenant($club, fn () => $message->fresh()->status));
    }
}
