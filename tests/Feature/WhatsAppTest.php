<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Messaging\Models\AutoReply;
use App\Modules\Messaging\Models\Campaign;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\CampaignService;
use App\Modules\Messaging\Services\TemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** Spec 3: operational messages, campaigns with consent, inbox, auto replies, delivery tracking. */
class WhatsAppTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function enableWhatsApp(Tenant $club, string $phoneNumberId = 'PNID-1'): void
    {
        $this->asTenant($club, function () use ($phoneNumberId) {
            TenantIntegration::create([
                'provider' => 'whatsapp', 'is_enabled' => true,
                'credentials' => ['phone_number_id' => $phoneNumberId, 'access_token' => 'EAAG-test'],
                'public_identifier' => $phoneNumberId,
            ]);
            TemplateCatalog::seedDefaults();
            MessageTemplate::query()->update(['status' => 'approved']);
        });
    }

    private function signed(array $payload): array
    {
        $body = json_encode($payload);

        return ['X-Hub-Signature-256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'), 'Content-Type' => 'application/json'];
    }

    private function inbound(string $from, string $text, string $id = 'wamid.IN1', string $pnid = 'PNID-1'): array
    {
        return ['entry' => [['changes' => [['value' => [
            'metadata' => ['phone_number_id' => $pnid],
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Sara']]],
            'messages' => [['from' => $from, 'id' => $id, 'type' => 'text', 'text' => ['body' => $text]]],
        ]]]]]];
    }

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.app_secret' => 'app-secret', 'services.whatsapp.verify_token' => 'verify-me']);
    }

    public function test_webhook_verification_handshake(): void
    {
        $this->get(route('webhooks.whatsapp.verify', ['hub_mode' => 'subscribe', 'hub_verify_token' => 'verify-me', 'hub_challenge' => '12345']))
            ->assertOk()->assertSeeText('12345');
        $this->get(route('webhooks.whatsapp.verify', ['hub_mode' => 'subscribe', 'hub_verify_token' => 'wrong', 'hub_challenge' => '1']))->assertForbidden();
    }

    public function test_unsigned_webhooks_are_rejected(): void
    {
        $this->postJson(route('webhooks.whatsapp'), $this->inbound('96891111111', 'hi'))->assertStatus(401);
        $this->call('POST', route('webhooks.whatsapp'), [], [], [], ['HTTP_X_HUB_SIGNATURE_256' => 'sha256=bad', 'CONTENT_TYPE' => 'application/json'], json_encode($this->inbound('1', 'x')))->assertStatus(401);
    }

    public function test_inbound_message_reaches_the_right_clubs_inbox_and_links_the_member(): void
    {
        $a = $this->club('A');
        $b = $this->club('B');
        $this->enableWhatsApp($a, 'PNID-A');
        $this->enableWhatsApp($b, 'PNID-B');
        $member = $this->member($a, ['phone' => '96891111111']);

        $payload = $this->inbound('96891111111', 'مرحبا', 'wamid.X', 'PNID-A');
        $this->call('POST', route('webhooks.whatsapp'), [], [], [], $this->server($payload), json_encode($payload))->assertOk();
        // Meta retries: the same message id is stored once.
        $this->call('POST', route('webhooks.whatsapp'), [], [], [], $this->server($payload), json_encode($payload))->assertOk();

        $this->asTenant($a, function () use ($member) {
            $conversation = Conversation::firstOrFail();
            $this->assertSame($member->id, $conversation->member_id);
            $this->assertSame(1, $conversation->unread_count);
            $this->assertSame(1, Message::where('direction', 'inbound')->count());
            $this->assertTrue($conversation->serviceWindowOpen());
        });
        $this->assertSame(0, $this->asTenant($b, fn () => Conversation::count()));
    }

    public function test_stop_keyword_withdraws_marketing_consent_and_auto_replies_answer(): void
    {
        $club = $this->club();
        $this->enableWhatsApp($club);
        $member = $this->member($club, ['phone' => '96892222222', 'marketing_opt_in' => true]);
        $this->asTenant($club, fn () => AutoReply::create(['keyword' => 'الأسعار', 'reply' => 'أسعارنا على الموقع']));

        $stop = $this->inbound('96892222222', 'إيقاف', 'wamid.S');
        $this->call('POST', route('webhooks.whatsapp'), [], [], [], $this->server($stop), json_encode($stop))->assertOk();
        $prices = $this->inbound('96892222222', 'كم الأسعار؟', 'wamid.P');
        $this->call('POST', route('webhooks.whatsapp'), [], [], [], $this->server($prices), json_encode($prices))->assertOk();

        $this->asTenant($club, function () use ($member) {
            $fresh = $member->fresh();
            $this->assertFalse($fresh->marketing_opt_in);
            $this->assertNotNull($fresh->marketing_opt_out_at);
            $this->assertSame(2, Message::where('direction', 'outbound')->where('type', 'text')->count(), 'opt-out confirmation + auto reply');
            $this->assertSame(1, AutoReply::first()->hits);
            $this->assertSame(2, Conversation::first()->unread_count, 'automatic replies leave the chat unread for staff');
        });
    }

    public function test_delivery_statuses_update_messages_and_never_go_backwards(): void
    {
        $club = $this->club();
        $this->enableWhatsApp($club);
        $member = $this->member($club);
        $message = $this->asTenant($club, fn () => Message::where('member_id', $member->id)->where('template_key', 'member_welcome')->firstOrFail());
        $this->asTenant($club, fn () => $message->forceFill(['provider_message_id' => 'wamid.OUT1'])->save());

        foreach (['read', 'delivered'] as $state) {
            $payload = ['entry' => [['changes' => [['value' => ['metadata' => ['phone_number_id' => 'PNID-1'], 'statuses' => [['id' => 'wamid.OUT1', 'status' => $state]]]]]]]];
            $this->call('POST', route('webhooks.whatsapp'), [], [], [], $this->server($payload), json_encode($payload))->assertOk();
        }

        $this->assertSame(Message::READ, $this->asTenant($club, fn () => $message->fresh()->status));
    }

    public function test_meta_cloud_api_receives_the_template_with_ordered_parameters(): void
    {
        config(['services.whatsapp.driver' => 'meta']);
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.SENT']]])]);
        $club = $this->club();
        $this->enableWhatsApp($club);

        $member = $this->member($club, ['full_name' => 'سارة البلوشية', 'phone' => '96893333333']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/PNID-1/messages')
            && $request->hasHeader('Authorization', 'Bearer EAAG-test')
            && $request['to'] === '96893333333'
            && $request['template']['name'] === 'alopera_member_welcome'
            && $request['template']['components'][0]['parameters'][0]['text'] === 'سارة');

        $this->asTenant($club, function () use ($member) {
            $message = Message::where('member_id', $member->id)->firstOrFail();
            $this->assertSame(Message::SENT, $message->status);
            $this->assertSame('wamid.SENT', $message->provider_message_id);
        });
    }

    public function test_membership_activation_and_payments_send_service_messages_but_respect_opt_out(): void
    {
        $club = $this->club();
        $this->enableWhatsApp($club);
        $member = $this->member($club);
        $optedOut = $this->member($club, ['whatsapp_opt_in' => false]);

        $this->activeMembership($club, $member);
        $this->activeMembership($club, $optedOut);

        $this->asTenant($club, function () use ($member, $optedOut) {
            $keys = Message::where('member_id', $member->id)->pluck('template_key')->all();
            foreach (['member_welcome', 'invoice_issued', 'payment_received', 'membership_activated'] as $key) {
                $this->assertContains($key, $keys);
            }
            $this->assertSame(0, Message::where('member_id', $optedOut->id)->whereIn('status', ['sent', 'queued'])->count());
            $this->assertSame('opted_out', Message::where('member_id', $optedOut->id)->value('error_code'));
        });
    }

    public function test_unapproved_templates_are_never_sent(): void
    {
        $club = $this->club();
        $this->enableWhatsApp($club);
        $this->asTenant($club, fn () => MessageTemplate::where('key', 'member_welcome')->update(['status' => 'pending']));
        $member = $this->member($club);

        $this->assertSame('template_not_approved', $this->asTenant($club, fn () => Message::where('member_id', $member->id)->value('error_code')));
    }

    public function test_campaigns_only_reach_members_with_marketing_consent(): void
    {
        $club = $this->club();
        $this->enableWhatsApp($club);
        $consenting = $this->member($club, ['marketing_opt_in' => true]);
        $notConsenting = $this->member($club, ['marketing_opt_in' => false]);
        $this->activeMembership($club, $consenting);
        $this->activeMembership($club, $notConsenting);

        $this->asTenant($club, function () use ($consenting, $notConsenting) {
            $template = MessageTemplate::create(['key' => 'custom_offer', 'name' => 'offer', 'category' => 'marketing', 'body' => 'عرض {{1}}', 'variables' => ['member_name'], 'status' => 'approved']);
            $campaign = Campaign::create(['name' => 'Offer', 'message_template_id' => $template->id, 'audience' => ['segment' => 'active']]);

            app(CampaignService::class)->launch($campaign->load('template'));

            $this->assertSame(1, Message::where('campaign_id', $campaign->id)->where('member_id', $consenting->id)->count());
            $this->assertSame(0, Message::where('campaign_id', $campaign->id)->where('member_id', $notConsenting->id)->count());
            $this->assertSame(Campaign::COMPLETED, $campaign->fresh()->status);
            $this->assertSame(1, $campaign->fresh()->sent_count);
        });
    }

    public function test_expiry_reminders_are_sent_once_per_member_and_day_offset(): void
    {
        $club = $this->club();
        $club->update(['settings' => ['expiry_reminder_days' => [3]]]);
        $this->enableWhatsApp($club);
        $member = $this->member($club);
        $this->activeMembership($club, $member, $this->plan($club, ['duration_value' => 4, 'duration_unit' => 'day']));

        Artisan::call('gym:send-reminders');
        Artisan::call('gym:send-reminders');

        $this->assertSame(1, $this->asTenant($club, fn () => Message::where('member_id', $member->id)->where('template_key', 'membership_expiring')->count()));
    }

    public function test_free_text_reply_is_refused_outside_the_24h_window(): void
    {
        $club = $this->club();
        $this->enableWhatsApp($club);
        $conversation = $this->asTenant($club, fn () => Conversation::create(['phone' => '96894444444', 'last_inbound_at' => now()->subDays(2)]));

        $this->actingAs($this->owner($club))->post(route('club.inbox.reply', $conversation->id), ['body' => 'hello'])->assertSessionHas('error');
        $this->assertSame('window_closed', $this->asTenant($club, fn () => Message::latest('id')->value('error_code')));
    }

    /** @return array<string, string> */
    private function server(array $payload): array
    {
        $headers = $this->signed($payload);

        return ['HTTP_X_HUB_SIGNATURE_256' => $headers['X-Hub-Signature-256'], 'CONTENT_TYPE' => 'application/json'];
    }
}
