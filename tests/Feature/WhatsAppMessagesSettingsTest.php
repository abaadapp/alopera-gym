<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\TemplateCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * «الإعدادات ← رسائل الواتساب» (owner's decision, 2026-11-25): every message with its purpose and timing; its text
 * edited and sent to Meta for review, «قيد المراجعة» (and not sent) until Meta approves it; never deleted.
 */
class WhatsAppMessagesSettingsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.whatsapp.app_secret' => 'app-secret']);
    }

    private function connect(Tenant $club): void
    {
        $this->asTenant($club, function () {
            TemplateCatalog::seedDefaults();
            MessageTemplate::query()->update(['status' => 'approved', 'meta_synced_at' => now()]);
            TenantIntegration::create([
                'provider' => 'whatsapp', 'is_enabled' => true, 'public_identifier' => 'PNID-1',
                'credentials' => ['phone_number_id' => 'PNID-1', 'access_token' => 'EAAG-test'],
                'settings' => ['vendor' => 'meta', 'waba_id' => 'WABA-1'],
            ]);
        });
    }

    private function template(Tenant $club, string $key): MessageTemplate
    {
        return $this->asTenant($club, fn () => MessageTemplate::where('key', $key)->firstOrFail());
    }

    private function edit(Tenant $club, MessageTemplate $template, string $body): TestResponse
    {
        return $this->actingAs($this->owner($club))->put(route('club.settings.whatsapp-messages.update', $template), ['body' => $body]);
    }

    public function test_every_message_shows_its_purpose_timing_and_text(): void
    {
        $club = $this->club();
        $this->connect($club);

        $this->actingAs($this->owner($club))->get(route('club.settings.whatsapp-messages'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Club/Settings/WhatsAppMessages')
                ->where('unavailable', null)
                ->where('messages', fn ($messages) => collect($messages)->firstWhere('key', 'payment_received')['when'] !== ''
                    && collect($messages)->firstWhere('key', 'payment_received')['values'][0]['label'] === 'اسم العضوة'
                    && collect($messages)->every(fn ($m) => $m['purpose'] !== '' && $m['group'] !== '')));
        $this->actingAs($this->staff($club, 'reception'))->get(route('club.settings.whatsapp-messages'))->assertForbidden();
    }

    public function test_an_edited_text_goes_to_meta_keeping_its_other_parts_and_waits_for_approval(): void
    {
        $club = $this->club();
        $this->connect($club);
        Http::fake([
            'graph.facebook.com/*/WABA-1/message_templates*' => Http::response(['data' => [[
                'id' => 'TPL-77', 'name' => 'alopera_payment_received', 'language' => 'ar', 'status' => 'APPROVED',
                'components' => [['type' => 'HEADER', 'format' => 'TEXT', 'text' => 'Alopera'], ['type' => 'BODY', 'text' => 'قديم {{1}} {{2}} {{3}} {{4}} .']],
            ]]]),
            'graph.facebook.com/*/TPL-77' => Http::response(['success' => true]),
        ]);
        $template = $this->template($club, 'payment_received');
        $body = 'شكرًا {{1}}، وصلت دفعتك {{2}}. الإيصال {{3}} والفاتورة {{4}} مع خالص التحية.';

        $this->edit($club, $template, $body)->assertSessionHasNoErrors()->assertSessionHas('success');

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/TPL-77')
            && $r['components'][0]['type'] === 'HEADER' && $r['components'][1]['text'] === $body
            && count($r['components'][1]['example']['body_text'][0]) === 4);
        $template->refresh();
        $this->assertSame('pending', $template->status);
        $this->assertSame($body, $template->body);
        $this->assertSame('TPL-77', $template->meta_id);
        $this->assertFalse($template->isUsable(), 'Not sent while Meta reviews it.');
    }

    public function test_a_message_meta_does_not_have_yet_is_created_there(): void
    {
        $club = $this->club();
        $this->connect($club);
        Http::fake(fn (Request $r) => $r->method() === 'GET' ? Http::response(['data' => []]) : Http::response(['id' => 'NEW-1', 'status' => 'PENDING']));
        $template = $this->template($club, 'booking_confirmed');

        $this->edit($club, $template, 'تم حجزك يا {{1}} في {{2}} يوم {{3}} الساعة {{4}} بنجاح.')->assertSessionHasNoErrors();

        Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/WABA-1/message_templates')
            && $r['name'] === 'alopera_booking_confirmed' && $r['category'] === 'UTILITY' && $r['language'] === 'ar');
        $this->assertSame('NEW-1', $template->fresh()->meta_id);
    }

    public function test_a_text_missing_a_value_or_ending_with_one_is_refused_before_meta(): void
    {
        $club = $this->club();
        $this->connect($club);
        Http::fake();
        $template = $this->template($club, 'payment_received');

        $this->edit($club, $template, 'شكرًا {{1}} على دفعتك {{2}}.')->assertSessionHasErrors('body');
        $this->edit($club, $template, 'شكرًا {{1}} {{2}} {{3}} نص {{4}}')->assertSessionHasErrors('body');
        Http::assertNothingSent();
        $this->assertSame('approved', $template->fresh()->status);
    }

    public function test_metas_refusal_changes_nothing_here(): void
    {
        $club = $this->club();
        $this->connect($club);
        Http::fake(fn (Request $r) => $r->method() === 'GET'
            ? Http::response(['data' => [['id' => 'TPL-1', 'name' => 'alopera_member_welcome', 'language' => 'ar', 'components' => []]]])
            : Http::response(['error' => ['code' => 100, 'message' => 'Invalid parameter', 'error_user_msg' => 'Body too promotional']], 400));
        $template = $this->template($club, 'member_welcome');
        $before = $template->body;

        $this->edit($club, $template, 'أهلًا {{1}} في {{2}}، رقمك {{3}} ونتمنى لك وقتًا ممتعًا.')->assertSessionHasErrors(['body' => 'رفضت Meta الطلب: Body too promotional']);
        $this->assertSame([$before, 'approved'], [$template->fresh()->body, $template->fresh()->status]);
    }

    public function test_meta_states_reach_the_system_by_sync_and_by_webhook(): void
    {
        $club = $this->club();
        $this->connect($club);
        $this->asTenant($club, fn () => MessageTemplate::query()->update(['status' => 'pending', 'meta_synced_at' => null]));
        Http::fake(['graph.facebook.com/*' => Http::response(['data' => [
            ['id' => 'A', 'name' => 'alopera_payment_received', 'language' => 'ar', 'status' => 'APPROVED', 'components' => [['type' => 'BODY', 'text' => 'شكرًا {{1}} على {{2}} إيصال {{3}} الرابط {{4}} .']]],
            ['id' => 'B', 'name' => 'alopera_booking_confirmed', 'language' => 'ar', 'status' => 'REJECTED', 'rejected_reason' => 'INVALID_FORMAT', 'components' => []],
        ]])]);

        Artisan::call('whatsapp:sync-templates');

        $this->assertSame('approved', $this->template($club, 'payment_received')->status);
        $this->assertSame('شكرًا {{1}} على {{2}} إيصال {{3}} الرابط {{4}} .', $this->template($club, 'payment_received')->body);
        $rejected = $this->template($club, 'booking_confirmed');
        $this->assertSame('rejected', $rejected->status);
        $this->assertStringContainsString('صيغة النص', (string) $rejected->rejection_reason);

        // Meta's webhook: approved now.
        $payload = ['object' => 'whatsapp_business_account', 'entry' => [['id' => 'WABA-1', 'changes' => [['field' => 'message_template_status_update', 'value' => [
            'event' => 'APPROVED', 'message_template_id' => 'B', 'message_template_name' => 'alopera_booking_confirmed', 'message_template_language' => 'ar', 'reason' => 'NONE',
        ]]]]]];
        $body = json_encode($payload);
        $this->call('POST', route('webhooks.whatsapp'), [], [], [], ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'app-secret'), 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $this->assertSame('approved', $this->template($club, 'booking_confirmed')->status);
        $this->assertNull($this->template($club, 'booking_confirmed')->rejection_reason);
    }

    public function test_without_whatsapp_linked_the_messages_are_read_only(): void
    {
        $club = $this->club();
        $this->asTenant($club, fn () => TemplateCatalog::seedDefaults());

        $this->actingAs($this->owner($club))->get(route('club.settings.whatsapp-messages'))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('unavailable', fn ($why) => str_contains((string) $why, 'غير مربوط')));
        $this->edit($club, $this->template($club, 'member_welcome'), 'أهلًا {{1}} في {{2}}، رقمك {{3}} مرحبًا.')->assertSessionHasErrors('body');
    }
}
