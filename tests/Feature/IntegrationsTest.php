<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Documents\Models\DocumentAlert;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\AccessCredential;
use App\Modules\Gym\Models\AccessDevice;
use App\Modules\Gym\Models\CheckIn;
use App\Modules\Gym\Services\BookingService;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\TemplateCatalog;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * «التطبيقات التكاملية»: three cards by permission, write-only keys, a state that is earned by a real check
 * (Paymob accepting the keys, Meta answering for the number, a device calling in), devices tried by simulation
 * without recording, and the WhatsApp features linked to it.
 */
class IntegrationsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const HMAC = 'hmac-integration-secret';

    private function paymobKeys(Tenant $club): void
    {
        $this->actingAs($this->owner($club))->put(route('club.integrations.update', 'paymob'), [
            'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'omn_sk_test_secret123', 'public_key' => 'omn_pk_test_public', 'hmac_secret' => self::HMAC, 'integration_ids' => '111, 222'],
        ])->assertSessionHasNoErrors();
    }

    private function whatsapp(Tenant $club): void
    {
        $this->asTenant($club, function () {
            TenantIntegration::create([
                'provider' => 'whatsapp', 'is_enabled' => true,
                'credentials' => ['phone_number_id' => 'PNID-9', 'access_token' => 'EAAG-secret-token'], 'public_identifier' => 'PNID-9',
            ]);
            TemplateCatalog::seedDefaults();
            MessageTemplate::query()->update(['status' => 'approved']);
        });
    }

    /** @return array<string, mixed> */
    private function cards(Tenant $club, $user): array
    {
        $cards = [];
        $this->actingAs($user)->get(route('club.integrations.index'))->assertOk()
            ->assertInertia(function ($page) use (&$cards) {
                $page->component('Integrations/Index')->has('cards', 3);
                $cards = collect($page->toArray()['props']['cards'])->keyBy('key')->all();
            });

        return $cards;
    }

    public function test_three_cards_open_by_permission(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);

        $cards = $this->cards($club, $owner);
        $this->assertSame(['paymob', 'devices', 'whatsapp'], array_keys($cards));
        $this->assertTrue($cards['paymob']['can'] && $cards['devices']['can'] && $cards['whatsapp']['can']);
        $this->assertSame(['off', 'off', 'off'], array_column(array_column($cards, 'status'), 'state'));
        foreach (['club.integrations.paymob', 'club.integrations.whatsapp', 'club.devices.index'] as $route) {
            $this->actingAs($owner)->get(route($route))->assertOk();
        }

        // The manager runs the devices but not the keys: integrations.manage is the owner's unless given explicitly.
        $manager = $this->staff($club, 'manager');
        $cards = $this->cards($club, $manager);
        $this->assertFalse($cards['paymob']['can']);
        $this->assertTrue($cards['devices']['can']);
        $this->actingAs($manager)->get(route('club.integrations.paymob'))->assertForbidden();
        $this->actingAs($manager)->get(route('club.integrations.whatsapp'))->assertForbidden();
        $this->actingAs($manager)->put(route('club.integrations.update', 'paymob'), ['mode' => 'test'])->assertForbidden();
        $this->actingAs($manager)->post(route('club.integrations.verify', 'paymob'))->assertForbidden();
        $this->actingAs($manager)->get(route('club.devices.index'))->assertOk();

        $this->actingAs($this->staff($club, 'reception'))->get(route('club.integrations.index'))->assertForbidden();
        $this->actingAs($this->staff($club, 'reception'))->get(route('club.devices.index'))->assertForbidden();
    }

    public function test_old_addresses_lead_to_the_new_section(): void
    {
        $owner = $this->owner($this->club());
        $this->actingAs($owner)->get('/app/settings/integrations')->assertRedirect('/app/integrations');
        $this->actingAs($owner)->get('/app/devices')->assertRedirect('/app/integrations/devices');
        $this->assertStringEndsWith('/app/integrations/devices', route('club.devices.index'));
    }

    public function test_saved_keys_are_write_only_and_not_yet_connected(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->paymobKeys($club);

        $hub = $this->actingAs($owner)->get(route('club.integrations.index'))->getContent();
        $page = $this->actingAs($owner)->get(route('club.integrations.paymob'))->getContent();
        foreach ([$hub, $page] as $html) {
            $this->assertStringNotContainsString('omn_sk_test_secret123', $html);
            $this->assertStringNotContainsString(self::HMAC, $html);
        }
        $this->assertSame('setup', $this->cards($club, $owner)['paymob']['status']['state'], 'saved keys are not a connection');

        $this->whatsapp($club);
        $this->assertStringNotContainsString('EAAG-secret-token', $this->actingAs($owner)->get(route('club.integrations.whatsapp'))->getContent());
    }

    public function test_paymob_is_connected_only_after_paymob_accepts_the_keys(): void
    {
        Http::fake(['*/v1/intention/' => Http::sequence()
            ->push(['client_secret' => 'cs_check', 'intention_order_id' => 1], 201)
            ->push(['detail' => 'Invalid token.'], 401)]);
        $club = $this->club();
        $owner = $this->owner($club);
        $this->paymobKeys($club);

        $this->actingAs($owner)->post(route('club.integrations.verify', 'paymob'))->assertSessionHas('success');
        Http::assertSent(fn ($r) => $r->hasHeader('Authorization', 'Token omn_sk_test_secret123') && $r['amount'] === 1000 && ! isset($r['redirection_url']));
        $this->assertSame('connected', $this->cards($club, $owner)['paymob']['status']['state']);

        // Paymob refuses: the card says so, with Paymob's words and never the key.
        $this->actingAs($owner)->post(route('club.integrations.verify', 'paymob'))->assertSessionHas('error');
        $status = $this->cards($club, $owner)['paymob']['status'];
        $this->assertSame('error', $status['state']);
        $this->assertStringContainsString('Invalid token', $status['detail']);
        $this->assertStringNotContainsString('omn_sk_test_secret123', $status['detail']);

        // New keys are unproven until tested again.
        $this->actingAs($owner)->put(route('club.integrations.update', 'paymob'), ['is_enabled' => true, 'mode' => 'test', 'credentials' => ['secret_key' => 'omn_sk_test_other']]);
        $this->assertSame('setup', $this->cards($club, $owner)['paymob']['status']['state']);
        // A test key under live mode is pointed out.
        $this->actingAs($owner)->put(route('club.integrations.update', 'paymob'), ['is_enabled' => true, 'mode' => 'live']);
        $this->actingAs($owner)->get(route('club.integrations.paymob'))->assertInertia(fn ($page) => $page->where('warning', 'المفتاح السري مفتاح اختبار بينما الوضع «حقيقي».'));
    }

    public function test_paymob_callbacks_show_on_the_screen_and_a_bad_signature_is_an_error(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_abc', 'intention_order_id' => 77], 201)]);
        $club = $this->club();
        $owner = $this->owner($club);
        $this->paymobKeys($club);
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 25]);
        $intent = $this->asTenant($club, fn () => app(OnlinePaymentService::class)->start(app(MembershipService::class)->sell($member, $plan)->invoice, 'portal'));
        $this->assertSame('connected', $this->cards($club, $owner)['paymob']['status']['state'], 'a payment page Paymob opened proves the keys');

        $obj = ['id' => 9001, 'pending' => false, 'amount_cents' => 25000, 'success' => true, 'is_voided' => false, 'is_refunded' => false,
            'error_occured' => false, 'currency' => 'OMR', 'order' => ['id' => 77, 'merchant_order_id' => $intent->reference]];
        $this->postJson(route('webhooks.paymob').'?hmac=bad', ['obj' => $obj])->assertStatus(401);
        $this->assertSame('error', $this->cards($club, $owner)['paymob']['status']['state']);

        $hmac = PaymobSignature::compute($obj, self::HMAC);
        $this->postJson(route('webhooks.paymob').'?hmac='.$hmac, ['obj' => $obj])->assertOk();
        $this->postJson(route('webhooks.paymob').'?hmac='.$hmac, ['obj' => $obj])->assertOk();
        $this->asTenant($club, fn () => $this->assertSame(1, Payment::where('gateway_transaction_id', '9001')->count()));

        $this->actingAs($owner)->get(route('club.integrations.paymob'))->assertInertia(fn ($page) => $page
            ->has('events', 3)->where('events.0.outcome', 'مكرر — لم يُسجَّل مرة أخرى')->where('events.2.valid', false)
            ->where('checks.2.ok', true)->has('intents', 1)->where('intents.0.channel', 'بوابة الأعضاء'));
    }

    public function test_whatsapp_is_connected_when_meta_answers_for_the_number(): void
    {
        Http::fake(['graph.facebook.com/*/PNID-9*' => Http::sequence()
            ->push(['display_phone_number' => '+968 9000 0000', 'verified_name' => 'Club Fitness', 'quality_rating' => 'GREEN'])
            ->push(['error' => ['code' => 190, 'message' => 'Invalid OAuth access token.']], 401)]);
        $club = $this->club();
        $owner = $this->owner($club);
        $this->whatsapp($club);
        $this->assertSame('setup', $this->cards($club, $owner)['whatsapp']['status']['state']);

        $this->actingAs($owner)->post(route('club.integrations.verify', 'whatsapp'))->assertSessionHas('success');
        Http::assertSent(fn ($r) => $r->method() === 'GET' && $r->hasHeader('Authorization', 'Bearer EAAG-secret-token'));
        $status = $this->cards($club, $owner)['whatsapp']['status'];
        $this->assertSame('connected', $status['state']);
        $this->assertStringContainsString('Club Fitness', $status['detail']);
        $this->assertSame(0, $this->asTenant($club, fn () => Message::where('direction', 'outbound')->whereNotIn('template_key', ['member_welcome'])->count()), 'checking sends nothing');

        $this->actingAs($owner)->post(route('club.integrations.verify', 'whatsapp'))->assertSessionHas('error');
        $this->assertSame('error', $this->cards($club, $owner)['whatsapp']['status']['state']);
    }

    public function test_a_refused_token_while_sending_turns_the_card_to_error(): void
    {
        config(['services.whatsapp.driver' => 'meta']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['code' => 190, 'message' => 'Error validating access token']], 401)]);
        $club = $this->club();
        $owner = $this->owner($club);
        $this->whatsapp($club);
        $this->member($club, ['phone' => '96891112222']); // queues the welcome message, which is sent and refused

        $status = $this->cards($club, $owner)['whatsapp']['status'];
        $this->assertSame('error', $status['state']);
        $this->assertStringContainsString('190', $status['detail']);
        $this->actingAs($owner)->get(route('club.integrations.whatsapp'))->assertInertia(fn ($page) => $page
            ->where('log.0.status', 'failed')->has('features', 4)->where('features.2.templates.0.key', 'class_reminder'));
    }

    public function test_device_state_follows_real_calls_and_simulation_records_nothing(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->assertSame('off', $this->cards($club, $owner)['devices']['status']['state']);

        [$device, $token] = $this->asTenant($club, function () {
            $device = new AccessDevice(['name' => 'Main gate', 'type' => 'turnstile', 'purpose' => 'both', 'direction' => 'in']);
            $token = $device->issueToken();
            $device->save();

            return [$device, $token];
        });
        $this->assertSame('setup', $this->cards($club, $owner)['devices']['status']['state'], 'registered but never called in');

        $this->withToken($token)->postJson(route('devices.heartbeat'))->assertOk();
        $this->assertSame('connected', $this->cards($club, $owner)['devices']['status']['state']);

        // Simulation: the decision the device would get, with nothing written.
        $member = $this->member($club);
        $this->activeMembership($club, $member);
        $seen = $this->asTenant($club, fn () => $device->fresh()->last_seen_at);
        $this->travel(1)->minutes();
        $this->actingAs($owner)->post(route('club.devices.simulate', $device), ['credential_type' => 'qr', 'credential' => $member->qr_token])
            ->assertSessionHas('device_simulation', fn ($r) => $r['allowed'] === true && $r['holder'] === $member->full_name);
        $this->actingAs($owner)->post(route('club.devices.simulate', $device), ['credential_type' => 'card', 'credential' => 'NOPE'])
            ->assertSessionHas('device_simulation', fn ($r) => $r['allowed'] === false && $r['credential'] === '••••');
        $this->asTenant($club, function () use ($device, $seen) {
            $this->assertSame(0, CheckIn::count());
            $this->assertEquals($seen, $device->fresh()->last_seen_at);
        });

        // A real reading through the API is logged and listed.
        $this->withToken($token)->postJson(route('devices.access'), ['credential_type' => 'qr', 'credential' => $member->qr_token])->assertJson(['allowed' => true]);
        $this->actingAs($owner)->get(route('club.devices.index'))->assertInertia(fn ($page) => $page
            ->component('Integrations/Devices')->has('events', 1)->where('events.0.who', $member->full_name)->where('devices.0.online', true));

        $this->travel(AccessDevice::ONLINE_MINUTES + 1)->minutes();
        $this->assertSame('error', $this->cards($club, $owner)['devices']['status']['state'], 'no device online now');
    }

    public function test_a_fingerprint_is_kept_as_the_device_user_number_never_as_biometric_data(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $template = base64_encode(random_bytes(400));

        $this->actingAs($owner)->post(route('club.devices.staff-credentials'), ['holder_id' => $owner->id, 'type' => 'fingerprint', 'identifier' => $template])
            ->assertSessionHasErrors('identifier');
        $member = $this->member($club);
        $this->actingAs($owner)->post(route('club.members.credentials.store', $member), ['type' => 'fingerprint', 'identifier' => $template])
            ->assertSessionHasErrors('identifier');
        $this->actingAs($owner)->post(route('club.devices.staff-credentials'), ['holder_id' => $owner->id, 'type' => 'fingerprint', 'identifier' => '1042'])
            ->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame(['1042'], AccessCredential::pluck('identifier')->all()));

        $token = $this->asTenant($club, function () {
            $device = new AccessDevice(['name' => 'Staff reader', 'type' => 'fingerprint', 'purpose' => 'staff']);
            $token = $device->issueToken();
            $device->save();

            return $token;
        });
        $this->withToken($token)->postJson(route('devices.staff-punch'), ['credential_type' => 'fingerprint', 'credential' => $template])->assertStatus(422);
        $this->withToken($token)->postJson(route('devices.staff-punch'), ['credential_type' => 'fingerprint', 'credential' => '1042'])->assertOk()->assertJson(['type' => 'in']);
    }

    public function test_each_club_keeps_its_own_integrations(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_check'], 201)]);
        $a = $this->club('A');
        $b = $this->club('B');
        $this->paymobKeys($a);
        $this->actingAs($this->owner($a))->post(route('club.integrations.verify', 'paymob'));
        $device = $this->asTenant($a, function () {
            $device = new AccessDevice(['name' => 'A gate', 'type' => 'qr_reader']);
            $device->issueToken();
            $device->save();

            return $device;
        });

        $ownerB = $this->owner($b);
        $cards = $this->cards($b, $ownerB);
        $this->assertSame('off', $cards['paymob']['status']['state']);
        $this->assertSame('off', $cards['devices']['status']['state']);
        $this->actingAs($ownerB)->post(route('club.devices.simulate', $device), ['credential_type' => 'qr', 'credential' => 'x'])->assertNotFound();
        $this->actingAs($ownerB)->put(route('club.devices.update', $device), ['name' => 'X', 'type' => 'qr_reader', 'direction' => 'in', 'purpose' => 'members'])->assertNotFound();
        $this->assertSame('connected', $this->cards($a, $this->owner($a))['paymob']['status']['state']);
    }

    public function test_class_reminders_go_once_for_bookings_made_before_the_window(): void
    {
        $club = $this->club();
        $this->whatsapp($club);
        $member = $this->member($club, ['phone' => '96894445555']);
        $this->activeMembership($club, $member);
        $late = $this->member($club, ['phone' => '96894446666']);
        $this->activeMembership($club, $late);
        $session = $this->classSession($club, null, ['starts_at' => now()->addHours(2)]);
        $booking = $this->asTenant($club, fn () => app(BookingService::class)->book($member, $session));
        $this->asTenant($club, fn () => $booking->forceFill(['created_at' => now()->subDay()])->save());
        $this->asTenant($club, fn () => app(BookingService::class)->book($late, $session)); // booked inside the window

        Artisan::call('gym:class-reminders');
        $this->asTenant($club, fn () => $this->assertSame(0, Message::where('template_key', 'class_reminder')->count(), 'off by default'));

        $club->forceFill(['settings' => array_merge($club->settings ?? [], ['class_reminder_hours' => 3])])->save();
        Artisan::call('gym:class-reminders');
        Artisan::call('gym:class-reminders');
        $this->asTenant($club, function () use ($member, $booking) {
            $reminders = Message::where('template_key', 'class_reminder')->get();
            $this->assertCount(1, $reminders);
            $this->assertSame($member->id, $reminders->first()->member_id);
            $this->assertSame($booking->id, $reminders->first()->related_id);
        });
    }

    public function test_document_alerts_reach_the_responsible_only_when_authorised(): void
    {
        Storage::fake('local');
        $club = $this->club();
        $owner = $this->owner($club);
        $this->whatsapp($club);
        $club->forceFill(['settings' => array_merge($club->settings ?? [], ['documents' => ['alert_days' => [30], 'whatsapp' => true]])])->save();
        $reception = $this->staff($club, 'reception', ['phone' => '96897770000']);
        $manager = $this->staff($club, 'manager', ['phone' => '96897771111']);

        foreach ([['السجل التجاري', $reception], ['الترخيص البلدي', $manager]] as [$name, $user]) {
            $this->actingAs($owner)->post(route('club.business-documents.store'), [
                'name' => $name, 'expiry_date' => today()->addDays(20)->toDateString(), 'responsible_user_id' => $user->id,
                'file' => UploadedFile::fake()->create('doc.pdf', 50, 'application/pdf'),
            ])->assertSessionHasNoErrors();
        }

        Artisan::call('documents:alerts');
        $this->asTenant($club, function () {
            $this->assertSame(['skipped:not_authorized', 'queued'], DocumentAlert::orderBy('id')->pluck('whatsapp_status')->all());
            $this->assertSame(['96897771111'], Message::where('template_key', 'document_expiry')->pluck('to_phone')->all());
        });
    }

    public function test_the_class_reminder_setting_is_saved_with_the_notification_rules(): void
    {
        $club = $this->club();
        $this->actingAs($this->owner($club))->put(route('club.settings.notifications.update'), [
            'expiry_reminder_days' => [7], 'expired_followup_days' => [7], 'notify_class_changes' => true, 'class_reminder_hours' => 4,
        ])->assertSessionHasNoErrors();
        $this->assertSame(4, $club->fresh()->setting('class_reminder_hours'));
        $this->actingAs($this->owner($club))->get(route('club.settings.notifications'))->assertInertia(fn ($page) => $page->where('rules.class_reminder_hours', 4));
    }
}
