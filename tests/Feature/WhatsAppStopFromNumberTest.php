<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Messaging\Models\Campaign;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\CampaignService;
use App\Modules\Messaging\Services\TemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * «STOP» on WhatsApp withdraws marketing consent for the member whose number sent it, whether or not the chat is linked
 * to her: a chat opened before she joined stays unlinked until staff link it by hand.
 */
class WhatsAppStopFromNumberTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.app_secret' => 'app-secret']);
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

    private function postInbound(string $from, string $text, string $id): void
    {
        $payload = ['entry' => [['changes' => [['field' => 'messages', 'value' => [
            'metadata' => ['phone_number_id' => 'PNID-1'],
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Sara']]],
            'messages' => [['from' => $from, 'id' => $id, 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]];
        $body = json_encode($payload);
        $this->call('POST', route('webhooks.whatsapp'), [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'),
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();
    }

    private function launchOffer(Tenant $club): Campaign
    {
        return $this->asTenant($club, function () {
            $template = MessageTemplate::create(['key' => 'custom_offer', 'name' => 'offer', 'category' => 'marketing', 'body' => 'عرض {{1}}', 'variables' => ['member_name'], 'status' => 'approved']);
            $campaign = Campaign::create(['name' => 'Offer', 'message_template_id' => $template->id, 'audience' => ['segment' => 'all']]);
            app(CampaignService::class)->launch($campaign->load('template'));

            return $campaign;
        });
    }

    public function test_stop_from_a_prospect_who_joined_after_first_writing_withdraws_marketing_consent(): void
    {
        $club = $this->club();
        $this->enableWhatsApp($club);

        // She asks about prices before joining: the chat is created with no member.
        $this->postInbound('96892222222', 'كم الأسعار؟', 'wamid.Q1');
        // She joins at the desk with that number and agrees to marketing.
        $member = $this->member($club, ['phone' => '96892222222', 'marketing_opt_in' => true]);
        $this->assertNull($this->asTenant($club, fn () => Conversation::firstOrFail()->member_id), 'precondition: chat not linked');

        // She asks to stop.
        $this->postInbound('96892222222', 'STOP', 'wamid.S1');

        $this->asTenant($club, fn () => $this->assertFalse((bool) $member->fresh()->marketing_opt_in, 'STOP from her own number must withdraw marketing consent'));

        $campaign = $this->launchOffer($club);
        $this->assertSame(0, $this->asTenant($club, fn () => Message::where('campaign_id', $campaign->id)->where('member_id', $member->id)->where('status', '!=', Message::SKIPPED)->count()),
            'a campaign must not be sent to a number that said STOP');
    }

    public function test_an_ordinary_message_from_an_unlinked_number_changes_no_consent(): void
    {
        $club = $this->club();
        $this->enableWhatsApp($club);
        $this->postInbound('96894444444', 'مرحبا', 'wamid.Q2');
        $member = $this->member($club, ['phone' => '96894444444', 'marketing_opt_in' => true]);

        $this->postInbound('96894444444', 'كم السعر؟', 'wamid.Q3');

        $this->asTenant($club, fn () => $this->assertTrue((bool) $member->fresh()->marketing_opt_in));
    }
}
