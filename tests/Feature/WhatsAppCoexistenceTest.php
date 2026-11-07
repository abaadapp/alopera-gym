<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Messaging\Jobs\ProcessWhatsAppWebhook;
use App\Modules\Messaging\Models\Attachment;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\StatusBacklog;
use App\Modules\Messaging\Models\WebhookEvent;
use App\Modules\Messaging\Models\WhatsAppContact;
use App\Modules\Messaging\Services\TemplateCatalog;
use App\Modules\Messaging\Services\WhatsAppService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The webhook pipeline and WhatsApp Coexistence: every body is written once and processed by a job that can be
 * retried; messages are keyed by their WhatsApp id; late and out-of-order messages and statuses find their place;
 * what the owner sends from the WhatsApp Business app shows as sent from the app; the phone's history and contacts
 * come in only when the club allowed it; a disconnection is shown. Nothing reaches WhatsApp: HTTP is faked.
 */
class WhatsAppCoexistenceTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const BUSINESS = '96824000000';

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.app_secret' => 'app-secret', 'services.whatsapp.verify_token' => 'verify-me']);
        Http::preventStrayRequests();
    }

    private function connect(Tenant $club, string $pnid = 'PNID-1', array $settings = [], string $vendor = 'meta'): TenantIntegration
    {
        return $this->asTenant($club, function () use ($pnid, $settings, $vendor) {
            TemplateCatalog::seedDefaults();
            MessageTemplate::query()->update(['status' => 'approved']);

            return TenantIntegration::create([
                'provider' => 'whatsapp', 'is_enabled' => true, 'public_identifier' => $pnid,
                'credentials' => ['phone_number_id' => $pnid, 'access_token' => 'EAAG-test', 'api_key' => 'd360-key', 'webhook_token' => 'tok-'.$pnid],
                'settings' => $settings + ['vendor' => $vendor, 'waba_id' => 'WABA-'.$pnid],
            ]);
        });
    }

    /** @param array<string, mixed> $value */
    private function change(string $field, array $value, string $pnid = 'PNID-1'): array
    {
        return ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA-'.$pnid, 'changes' => [['field' => $field, 'value' => $value + [
            'messaging_product' => 'whatsapp', 'metadata' => ['display_phone_number' => self::BUSINESS, 'phone_number_id' => $pnid],
        ]]]]]];
    }

    private function deliver(array $payload): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', route('webhooks.whatsapp'), [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'), 'CONTENT_TYPE' => 'application/json',
        ], $body);
    }

    private function text(string $from, string $id, string $body, ?int $at = null): array
    {
        return $this->change('messages', [
            'contacts' => [['wa_id' => $from, 'profile' => ['name' => 'Huda']]],
            'messages' => [['from' => $from, 'id' => $id, 'timestamp' => (string) ($at ?? now()->timestamp), 'type' => 'text', 'text' => ['body' => $body]]],
        ]);
    }

    public function test_a_body_delivered_twice_is_written_once_and_processed_once(): void
    {
        $club = $this->club();
        $this->connect($club);
        $payload = $this->text('96891112222', 'wamid.A1', 'مرحبا');

        $this->deliver($payload)->assertOk();
        $this->deliver($payload)->assertOk();

        $this->assertSame(1, WebhookEvent::count());
        $this->assertSame(WebhookEvent::PROCESSED, WebhookEvent::first()->status);
        $this->assertSame($club->id, WebhookEvent::first()->tenant_id);
        // The body is encrypted at rest: the customer's words are not in the table as text.
        $this->assertStringNotContainsString('مرحبا', (string) \DB::table('whatsapp_webhook_events')->value('payload'));
        $this->asTenant($club, function () {
            $this->assertSame(1, Message::count());
            $this->assertSame(Conversation::NEW, Conversation::first()->status);
            $this->assertSame(1, Conversation::first()->unread_count);
        });

        // Another body carrying the same WhatsApp message id (a retry with other statuses beside it): still one message.
        $this->deliver(['entry' => [['changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => 'PNID-1'],
            'messages' => [['from' => '96891112222', 'id' => 'wamid.A1', 'type' => 'text', 'text' => ['body' => 'مرحبا']]], 'statuses' => []]]]]]])->assertOk();
        $this->asTenant($club, fn () => $this->assertSame(1, Message::count()));
    }

    public function test_the_provider_gets_its_answer_at_once_and_a_failed_processing_is_retried_by_the_queue(): void
    {
        $club = $this->club();
        $this->connect($club);
        Queue::fake();

        $this->deliver($this->text('96891112222', 'wamid.Q1', 'hi'))->assertOk();
        Queue::assertPushed(ProcessWhatsAppWebhook::class, 1);
        $this->assertSame(WebhookEvent::RECEIVED, WebhookEvent::first()->status);
        $this->asTenant($club, fn () => $this->assertSame(0, Message::count(), 'nothing is processed in the request itself'));

        $job = new ProcessWhatsAppWebhook(WebhookEvent::first()->id);
        $this->assertSame(5, $job->tries);
        $job->failed(new \RuntimeException('boom'));
        $this->assertSame(WebhookEvent::FAILED, WebhookEvent::first()->status);
    }

    public function test_messages_and_statuses_that_arrive_out_of_order_find_their_place(): void
    {
        $club = $this->club();
        $this->connect($club);
        $now = now()->timestamp;

        $this->deliver($this->text('96891112222', 'wamid.NEW', 'الثانية', $now))->assertOk();
        $this->deliver($this->text('96891112222', 'wamid.OLD', 'الأولى', $now - 600))->assertOk();

        $this->asTenant($club, function () use ($now) {
            $conversation = Conversation::first();
            $this->assertSame('الثانية', $conversation->last_preview, 'a late message does not take the place of a newer one');
            $this->assertSame($now, $conversation->last_inbound_at->timestamp);
            $this->assertSame($now - 600, $conversation->first_inbound_at->timestamp);
            $this->assertSame(['الأولى', 'الثانية'], Message::orderByRaw('coalesce(wa_timestamp, created_at)')->pluck('body')->all());
        });

        // Our reply's statuses: «read» arrives before WhatsApp has even answered our send with the id.
        $reply = $this->asTenant($club, function () {
            Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);
            config(['services.whatsapp.driver' => 'meta']);
            app()->forgetInstance(WhatsAppService::class);

            return Message::create(['conversation_id' => Conversation::first()->id, 'direction' => 'outbound', 'source' => Message::SOURCE_PLATFORM,
                'type' => 'text', 'to_phone' => '96891112222', 'body' => 'أهلًا', 'status' => Message::QUEUED]);
        });
        $this->deliver($this->change('messages', ['statuses' => [['id' => 'wamid.OUT1', 'status' => 'read', 'timestamp' => (string) $now]]]))->assertOk();
        $this->asTenant($club, fn () => $this->assertSame(1, StatusBacklog::count(), 'the early status waits'));

        $this->asTenant($club, function () use ($reply) {
            app(WhatsAppService::class)->deliver($reply->fresh());
            $this->assertSame(Message::READ, $reply->fresh()->status, 'applied as soon as the message has its id');
            $this->assertSame(0, StatusBacklog::count());
        });

        // A late «delivered» never takes it back from «read».
        $this->deliver($this->change('messages', ['statuses' => [['id' => 'wamid.OUT1', 'status' => 'delivered', 'timestamp' => (string) ($now - 5)]]]))->assertOk();
        $this->asTenant($club, fn () => $this->assertSame(Message::READ, $reply->fresh()->status));
    }

    public function test_what_the_owner_sends_from_the_business_app_shows_as_sent_from_the_app_and_opens_no_window(): void
    {
        $club = $this->club();
        $this->connect($club);
        $now = now()->timestamp;

        $this->deliver($this->change('smb_message_echoes', ['message_echoes' => [[
            'from' => self::BUSINESS, 'to' => '96893334444', 'id' => 'wamid.ECHO1', 'timestamp' => (string) $now, 'type' => 'text', 'text' => ['body' => 'تم حجزك غدًا'],
        ]]]))->assertOk();
        $this->deliver($this->change('smb_message_echoes', ['message_echoes' => [[
            'from' => self::BUSINESS, 'to' => '96893334444', 'id' => 'wamid.ECHO1', 'timestamp' => (string) $now, 'type' => 'text', 'text' => ['body' => 'تم حجزك غدًا'],
        ]]]))->assertOk();

        $this->asTenant($club, function () {
            $message = Message::sole();
            $this->assertSame(['outbound', Message::SOURCE_BUSINESS_APP, null, 'تم حجزك غدًا'], [$message->direction, $message->source, $message->sent_by, $message->body]);
            $conversation = Conversation::sole();
            $this->assertNull($conversation->last_inbound_at, 'a message from the app does not open the 24-hour window');
            $this->assertFalse($conversation->serviceWindowOpen());
            $this->assertSame(0, $conversation->unread_count);
        });
    }

    public function test_history_and_contacts_from_the_phone_come_in_only_when_the_club_allowed_it(): void
    {
        $club = $this->club();
        $integration = $this->connect($club);
        $past = now()->subDays(20)->timestamp;
        $history = $this->change('history', ['history' => [[
            'metadata' => ['phase' => 1, 'chunk_order' => 1, 'progress' => 100],
            'threads' => [['id' => '96895556666', 'context' => ['wa_id' => '96895556666'], 'messages' => [
                ['from' => '96895556666', 'id' => 'wamid.H1', 'timestamp' => (string) $past, 'type' => 'text', 'text' => ['body' => 'كم سعر الاشتراك؟'], 'history_context' => ['status' => 'read']],
                ['from' => self::BUSINESS, 'to' => '96895556666', 'id' => 'wamid.H2', 'timestamp' => (string) ($past + 60), 'type' => 'text', 'text' => ['body' => '25 ريال'], 'history_context' => ['status' => 'read']],
            ]]],
        ]]]);
        $contacts = $this->change('smb_app_state_sync', ['state_sync' => [
            ['type' => 'contact', 'contact' => ['full_name' => 'عائشة البلوشية', 'first_name' => 'عائشة', 'phone_number' => '96895556666'], 'action' => 'add', 'metadata' => ['timestamp' => (string) $past]],
        ]]);

        $this->deliver($history)->assertOk();
        $this->deliver($contacts)->assertOk();
        $this->asTenant($club, fn () => $this->assertSame([0, 0, 0], [Message::count(), Conversation::count(), WhatsAppContact::count()]));
        $this->assertSame(2, WebhookEvent::where('status', WebhookEvent::IGNORED)->count());

        $this->asTenant($club, fn () => $integration->forceFill(['settings' => $integration->settings + ['import_history' => true, 'import_contacts' => true]])->save());
        $this->deliver(['retry' => 1] + $history)->assertOk();
        $this->deliver(['retry' => 1] + $contacts)->assertOk();

        $this->asTenant($club, function () use ($integration) {
            $conversation = Conversation::sole();
            $this->assertSame(Conversation::CLOSED, $conversation->status, 'old chats come in closed');
            $this->assertSame(0, $conversation->unread_count);
            $this->assertNull($conversation->last_inbound_at, 'history opens no reply window');
            $this->assertSame('عائشة البلوشية', $conversation->contact_name);
            $this->assertSame([['inbound', Message::SOURCE_HISTORY, Message::RECEIVED], ['outbound', Message::SOURCE_HISTORY, Message::READ]],
                Message::orderBy('wa_timestamp')->get()->map(fn ($m) => [$m->direction, $m->source, $m->status])->all());
            $this->assertSame('done', $integration->fresh()->option('history_state'));
        });

        // Declined on the phone: said on the settings page.
        $this->deliver($this->change('history', ['history' => [['errors' => [['code' => 2593109, 'title' => 'History sync is turned off by the business from the WhatsApp Business App']]]]]))->assertOk();
        $this->asTenant($club, fn () => $this->assertSame('declined', $integration->fresh()->option('history_state')));
    }

    public function test_a_disconnected_number_is_shown_and_its_return_too(): void
    {
        $club = $this->club();
        $integration = $this->connect($club);
        $update = fn (string $event) => ['entry' => [['id' => 'WABA-PNID-1', 'changes' => [['field' => 'account_update', 'value' => [
            'event' => $event, 'waba_info' => ['waba_id' => 'WABA-PNID-1'], 'disconnection_info' => ['reason' => 'PRIMARY_INACTIVITY', 'initiated_by' => 'SYSTEM'],
        ]]]]]];

        $this->deliver($update('PARTNER_REMOVED'))->assertOk();
        $this->asTenant($club, function () use ($integration) {
            $this->assertTrue($integration->fresh()->failing());
            $this->assertStringContainsString('PRIMARY_INACTIVITY', (string) $integration->fresh()->last_error);
            $this->assertSame('disconnected', $integration->fresh()->option('connection_state'));
        });

        $this->deliver($update('ACCOUNT_RECONNECTED'))->assertOk();
        $this->asTenant($club, fn () => $this->assertFalse($integration->fresh()->failing()));
    }

    public function test_a_file_a_customer_sends_is_downloaded_to_the_private_disk(): void
    {
        Storage::fake('local');
        config(['services.whatsapp.driver' => 'meta']);
        $club = $this->club();
        $this->connect($club);
        Http::fake([
            'graph.facebook.com/*/MEDIA-1' => Http::response(['url' => 'https://lookaside.fbsbx.com/whatsapp_business/attachments/?mid=1', 'mime_type' => 'image/jpeg']),
            'lookaside.fbsbx.com/*' => Http::response('JPEGDATA', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $this->deliver($this->change('messages', ['messages' => [['from' => '96891112222', 'id' => 'wamid.IMG', 'timestamp' => (string) now()->timestamp,
            'type' => 'image', 'image' => ['id' => 'MEDIA-1', 'mime_type' => 'image/jpeg', 'sha256' => 'abc', 'caption' => 'إيصال التحويل']]]]))->assertOk();

        $this->asTenant($club, function () use ($club) {
            $attachment = Attachment::sole();
            $this->assertSame([Attachment::STORED, 'image', 8], [$attachment->status, $attachment->kind, $attachment->size]);
            $this->assertStringStartsWith('whatsapp/'.$club->id.'/', $attachment->path);
            Storage::disk('local')->assertExists($attachment->path);
            $this->assertSame('إيصال التحويل', $attachment->message->body);
            $this->assertSame('إيصال التحويل', Conversation::sole()->last_preview, 'the caption, or the kind of file when there is none');
        });
    }

    public function test_360dialog_webhooks_need_the_clubs_secret_header(): void
    {
        $club = $this->club();
        $this->connect($club, 'PNID-9', [], '360dialog');
        $payload = $this->change('messages', ['messages' => [['from' => '96891112222', 'id' => 'wamid.D1', 'type' => 'text', 'text' => ['body' => 'hi']]]], 'PNID-9');

        $this->postJson(route('webhooks.whatsapp.d360'), $payload)->assertStatus(401);
        $this->postJson(route('webhooks.whatsapp.d360'), $payload, ['X-Alopera-Webhook-Token' => 'wrong'])->assertStatus(401);
        $this->postJson(route('webhooks.whatsapp.d360'), $payload, ['X-Alopera-Webhook-Token' => 'tok-PNID-9'])->assertOk();
        $this->asTenant($club, fn () => $this->assertSame(1, Message::count()));

        // A Meta-connected club's number cannot be fed through the 360dialog address, token or not.
        $other = $this->club();
        $this->connect($other, 'PNID-M');
        $this->postJson(route('webhooks.whatsapp.d360'), $this->change('messages', ['messages' => [['from' => '1', 'id' => 'wamid.X', 'type' => 'text', 'text' => ['body' => 'x']]]], 'PNID-M'),
            ['X-Alopera-Webhook-Token' => 'tok-PNID-M'])->assertStatus(401);
    }

    public function test_a_message_goes_to_the_club_that_owns_the_number_only(): void
    {
        $a = $this->club();
        $b = $this->club();
        $this->connect($a, 'PNID-A');
        $this->connect($b, 'PNID-B');

        $this->deliver(['entry' => [['changes' => [['field' => 'messages', 'value' => ['metadata' => ['phone_number_id' => 'PNID-B'],
            'messages' => [['from' => '96891112222', 'id' => 'wamid.B1', 'type' => 'text', 'text' => ['body' => 'for B']]]]]]]]])->assertOk();

        $this->asTenant($a, fn () => $this->assertSame(0, Message::count()));
        $this->asTenant($b, fn () => $this->assertSame(1, Message::count()));
    }
}
