<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Role;
use App\Models\StaffNotification;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Finance\Events\InvoicePaid;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\GiftMembership;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\GiftService;
use App\Modules\Gym\Services\GiftWhatsApp;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Models\WebhookEvent;
use App\Modules\Messaging\Services\WhatsAppAutomation;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use GuzzleHttp\Exception\NetworkTimeoutException;
use GuzzleHttp\Promise\Create;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\SendsGiftWhatsApp;
use Tests\TestCase;

/**
 * #192: the gift sent to the recipient's WhatsApp. Her number is required, confirmed only by her own message from it
 * (a send WhatsApp accepts proves nothing), the website's gift is paid only after that, and the gift's message goes
 * once, after the payment is confirmed, through the club's own number. Its delivery follows WhatsApp's status
 * webhooks; a failure leaves the gift paid, tells staff and can be corrected and sent again, never twice. The
 * development `log` driver sends nothing: no HTTP leaves the tests.
 */
class GiftWhatsAppTest extends TestCase
{
    use BuildsClubs, RefreshDatabase, SendsGiftWhatsApp;

    private const HMAC = 'test-hmac-secret';

    private const RECIPIENT = '96895551234';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
    }

    private function deskGift(Tenant $club, bool $pay = true, string $phone = '95551234'): GiftMembership
    {
        $plan = $this->plan($club, ['price' => 30, 'name' => 'Gold']);

        return $this->asTenant($club, function () use ($plan, $pay, $phone) {
            $gift = app(GiftService::class)->issue($plan, ['giver_name' => 'Sara', 'recipient_name' => 'Noor', 'recipient_phone' => $phone,
                'message' => "Happy\nbirthday"], 'staff');
            $pay && app(PaymentService::class)->record($gift->invoice, $gift->invoice->balance(), 'cash');

            return $gift->fresh();
        });
    }

    /** @return Collection<int, Message> */
    private function giftMessages(Tenant $club)
    {
        return $this->asTenant($club, fn () => Message::where('template_key', 'gift_received')->orderBy('id')->get());
    }

    private function roleWith(Tenant $club, array $permissions): User
    {
        $role = $this->asTenant($club, fn () => Role::create(['key' => 'custom_'.uniqid(), 'name' => 'Custom', 'permissions' => $permissions]));
        $user = $this->staff($club, 'reception');
        $user->forceFill(['role_id' => $role->id])->save();

        return $user->fresh();
    }

    private function configurePaymob(Tenant $club): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_abc', 'intention_order_id' => 9911], 201)]);
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC, 'integration_ids' => '111, 222'],
        ]));
    }

    private function paidBy(PaymentIntent $intent, int $cents, bool $success = true)
    {
        $obj = [
            'id' => $success ? 555001 : 555002, 'pending' => false, 'amount_cents' => $cents, 'success' => $success, 'is_auth' => false, 'is_capture' => false,
            'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false, 'is_3d_secure' => true, 'integration_id' => 111,
            'has_parent_transaction' => false, 'order' => ['id' => 9911, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-10-28T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
        ];

        return $this->postJson(route('webhooks.paymob').'?hmac='.PaymobSignature::compute($obj, self::HMAC), ['type' => 'TRANSACTION', 'obj' => $obj]);
    }

    private function buyer(array $overrides = []): array
    {
        return $overrides + ['giver_name' => 'Sara Giver', 'giver_phone' => '91234567', 'recipient_name' => 'Noor', 'recipient_phone' => '95551234',
            'message' => 'Happy birthday!', 'accept_terms' => '1'];
    }

    public function test_an_invalid_number_is_refused_on_the_site_at_the_desk_and_in_the_service(): void
    {
        $club = $this->club();
        $this->configurePaymob($club);
        $this->connectWhatsApp($club);
        $plan = $this->plan($club);

        foreach (['', '12345', '81234567', '+968 2412 3456', 'not a number'] as $bad) {
            $this->post(route('site.gift.store', ['club' => $club->slug, 'plan' => $plan->id]), $this->buyer(['recipient_phone' => $bad]))
                ->assertSessionHasErrors('recipient_phone');
            $this->actingAs($this->owner($club))->post(route('club.gifts.store'), [
                'membership_plan_id' => $plan->id, 'giver_name' => 'Sara', 'recipient_name' => 'Noor', 'recipient_phone' => $bad, 'payment_method' => 'later',
            ])->assertSessionHasErrors('recipient_phone');
        }
        try {
            $this->asTenant($club, fn () => app(GiftService::class)->issue($plan, ['giver_name' => 'A', 'recipient_name' => 'B', 'recipient_phone' => '123'], 'staff'));
            $this->fail('a gift was issued without a WhatsApp number');
        } catch (BusinessRuleException $e) {
            $this->assertSame('gift.recipient_phone', $e->rule);
        }
        $this->asTenant($club, fn () => $this->assertSame(0, GiftMembership::count() + Invoice::count()));

        // The Omani forms all come to one number: 9XXXXXXX, +968, 00968, Arabic digits.
        foreach (['9555 1234', '+968 9555 1234', '0096895551234', '٩٥٥٥١٢٣٤'] as $good) {
            $gift = $this->asTenant($club, fn () => app(GiftService::class)->issue($plan, ['giver_name' => 'A', 'recipient_name' => 'B', 'recipient_phone' => $good], 'staff'));
            $this->assertSame(self::RECIPIENT, $gift->recipient_phone);
        }
    }

    public function test_without_the_clubs_whatsapp_the_site_says_so_sells_nothing_and_never_claims_a_confirmation(): void
    {
        $club = $this->club();
        $this->configurePaymob($club);
        $plan = $this->plan($club);

        $this->get(route('site.gift.create', ['club' => $club->slug, 'plan' => $plan->id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('whatsapp.available', false)->where('whatsapp.refusal', fn ($r) => str_contains((string) $r, 'غير متاح')));
        $this->post(route('site.gift.store', ['club' => $club->slug, 'plan' => $plan->id]), $this->buyer())->assertSessionHasErrors('error');
        $this->asTenant($club, fn () => $this->assertSame(0, GiftMembership::count()));

        // Connected but switched off, or without the club's number: still not possible, said as such.
        $integration = $this->connectWhatsApp($club);
        $this->asTenant($club, fn () => $integration->forceFill(['is_enabled' => false])->save());
        $gift = $this->deskGift($club);
        $this->asTenant($club, function () use ($gift) {
            $v = app(GiftWhatsApp::class)->verification($gift);
            $this->assertSame(['unavailable', 'not_connected', null, null], [$v['state'], $v['refusal'], $v['code'], $v['link']]);
        });
        $this->asTenant($club, fn () => $integration->forceFill(['is_enabled' => true, 'settings' => ['vendor' => 'meta']])->save());
        $this->asTenant($club, fn () => $this->assertSame('no_club_number', app(GiftWhatsApp::class)->verification($gift->fresh())['refusal']));
        $this->asTenant($club, fn () => $this->assertFalse($gift->fresh()->phoneVerified()));
    }

    public function test_the_number_is_confirmed_only_by_a_message_from_it_carrying_the_gifts_live_code(): void
    {
        $club = $this->club();
        $this->connectWhatsApp($club);
        $gift = $this->deskGift($club, pay: false);

        $v = $this->asTenant($club, fn () => app(GiftWhatsApp::class)->verification($gift));
        $this->assertSame('waiting', $v['state']);
        $this->assertStringStartsWith('https://wa.me/'.self::CLUB_WHATSAPP.'?text=', $v['link']);
        $this->assertStringContainsString($v['code'], $v['text']);
        $this->assertStringContainsString('أوافق على استلام رسائل', $v['text']);
        // The message she sends names no gift and no price.
        $this->assertStringNotContainsString('هدية', $v['text']);
        // (The price is 30.000; the club's random test name may hold "30", so the price is looked for as written.)
        $this->assertStringNotContainsString('30.000', $v['text']);

        // Her plain hello is just a chat; the code from someone else's number confirms nothing.
        $this->whatsappText(self::RECIPIENT, 'مرحبا', 'wamid.hello')->assertOk();
        $this->recipientConfirms($club, $gift, from: '96899990000')->assertOk();
        $this->asTenant($club, function () use ($gift) {
            $this->assertFalse($gift->fresh()->phoneVerified());
            $this->assertSame('other_number', AuditLog::where('event', 'gift.phone_verify_refused')->sole()->properties['reason']);
        });

        // Past its 24 hours the code confirms nothing; a new one does, from her number, and the old one is dead.
        $old = $v['text'];
        $this->travel(25)->hours();
        $this->whatsappText(self::RECIPIENT, $old, 'wamid.expired')->assertOk();
        $this->asTenant($club, function () use ($gift) {
            $this->assertFalse($gift->fresh()->phoneVerified());
            $this->assertSame('expired', app(GiftWhatsApp::class)->verification($gift->fresh())['state']);
        });
        $this->travelBack();
        $this->asTenant($club, fn () => app(GiftWhatsApp::class)->newCode($gift->fresh()));
        $this->whatsappText(self::RECIPIENT, $old, 'wamid.old-code')->assertOk();
        $this->asTenant($club, fn () => $this->assertFalse($gift->fresh()->phoneVerified()));

        $this->recipientConfirms($club, $gift, id: 'wamid.proof')->assertOk();
        $this->asTenant($club, function () use ($gift) {
            $gift = $gift->fresh();
            $this->assertTrue($gift->phoneVerified());
            $this->assertSame(Message::where('provider_message_id', 'wamid.proof')->value('id'), $gift->phone_verified_message_id, 'her message is the evidence');
            $this->assertNull($gift->phone_verify_hash);
            $this->assertSame(1, AuditLog::where('event', 'gift.phone_verified')->count());
            // A recipient who is not a member: her chat exists, no member was made for her.
            $this->assertSame(0, Member::count());
            $this->assertNull(Conversation::where('phone', self::RECIPIENT)->sole()->member_id);
        });
    }

    public function test_nothing_goes_before_payment_and_once_after_it_however_often_the_payment_is_reported(): void
    {
        $club = $this->club();
        $this->connectWhatsApp($club);
        $gift = $this->deskGift($club, pay: false);
        $this->recipientConfirms($club, $gift)->assertOk();
        $this->assertCount(0, $this->giftMessages($club), 'confirmed but not paid: nothing sent');
        $this->asTenant($club, fn () => $this->assertSame('not_due', app(GiftWhatsApp::class)->delivery($gift->fresh())['state']));

        // Paid in part: still nothing.
        $this->asTenant($club, fn () => app(PaymentService::class)->record($gift->invoice, '10.000', 'cash'));
        $this->assertCount(0, $this->giftMessages($club));

        $this->asTenant($club, fn () => app(PaymentService::class)->record($gift->invoice->fresh(), $gift->invoice->fresh()->balance(), 'cash'));
        $messages = $this->giftMessages($club);
        $this->assertCount(1, $messages);
        $this->assertSame([self::RECIPIENT, null, Message::SENT], [$messages[0]->to_phone, $messages[0]->member_id, $messages[0]->status]);

        // The event again, the nightly repair and the nightly sender: still one message.
        $this->asTenant($club, function () use ($gift) {
            InvoicePaid::dispatch($gift->invoice->fresh());
            app(GiftService::class)->reconcilePaid();
            app(GiftWhatsApp::class)->sendPending();
        });
        Artisan::call('gym:daily-memberships');
        $this->assertCount(1, $this->giftMessages($club));
        $this->asTenant($club, fn () => $this->assertSame(1, $gift->fresh()->whatsapp_attempts));
    }

    public function test_the_message_carries_the_gift_and_link_but_no_price_or_payment_and_the_link_stays_on_the_server(): void
    {
        $club = $this->club();
        $this->connectWhatsApp($club);
        $gift = $this->deskGift($club, pay: false);
        $this->recipientConfirms($club, $gift)->assertOk();
        $this->asTenant($club, fn () => app(PaymentService::class)->record($gift->invoice, $gift->invoice->balance(), 'cash'));
        $message = $this->giftMessages($club)->sole();
        $token = $this->asTenant($club, fn () => $gift->fresh()->linkToken());
        $invoice = $this->asTenant($club, fn () => $gift->invoice->number);

        // Sent with the real link: the giver, the plan, the link — three values, and no free text (the desk's message
        // stays on the card only).
        $this->assertCount(3, $message->template_params);
        $this->assertSame(['Sara', 'Gold'], array_slice($message->template_params, 0, 2));
        $this->assertStringContainsString($token, $message->template_params[2]);
        $this->assertStringNotContainsString('birthday', json_encode($message->template_params));
        // Kept for staff without it, and never in an array or JSON.
        $this->assertStringContainsString('[رابط الهدية]', $message->body);
        $this->assertStringContainsString('أهداك Sara اشتراكًا في باقة Gold', $message->body);
        foreach ([$token, '30', $invoice, 'OMR', 'ر.ع'] as $secret) {
            $this->assertStringNotContainsString($secret, $message->body);
        }
        // The price in no value: "30" is looked for outside the link (its random token may hold those two digits).
        $this->assertStringNotContainsString('30', implode(' ', array_slice($message->template_params, 0, 3)));
        foreach (['30.000', $invoice, 'OMR', 'ر.ع'] as $secret) {
            $this->assertStringNotContainsString($secret, implode(' ', $message->template_params));
        }
        $this->assertArrayNotHasKey('template_params', $message->toArray());

        // Whoever only views gifts sees the state, not the code to confirm it.
        $viewer = $this->roleWith($club, ['gifts.view']);
        $other = $this->deskGift($club, pay: false, phone: '95559999');
        $this->actingAs($viewer)->get(route('club.gifts.show', $other))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('delivery_panel.verification.state', 'waiting')->where('delivery_panel.verification.code', null)->where('delivery_panel.verification.link', null));
    }

    public function test_delivery_follows_whatsapps_status_webhooks_and_a_failure_leaves_the_gift_paid_and_tells_staff(): void
    {
        $club = $this->club();
        $this->connectWhatsApp($club);
        $manager = $this->roleWith($club, ['gifts.view', 'gifts.manage']);
        $gift = $this->deskGift($club, pay: false);
        $this->recipientConfirms($club, $gift)->assertOk();
        $this->asTenant($club, fn () => app(PaymentService::class)->record($gift->invoice, $gift->invoice->balance(), 'cash'));
        $state = fn () => $this->asTenant($club, fn () => app(GiftWhatsApp::class)->delivery($gift->fresh()));
        $id = $this->giftMessages($club)->sole()->provider_message_id;

        // Taken by WhatsApp is not delivered.
        $this->assertSame(['sent', 'أُرسلت إلى المزود'], [$state()['state'], $state()['label']]);
        $this->whatsappStatus($id, 'delivered')->assertOk();
        $this->assertSame('delivered', $state()['state']);
        $this->whatsappStatus($id, 'read')->assertOk();
        $this->whatsappStatus($id, 'sent')->assertOk(); // late: never back
        $this->assertSame(['read', false], [$state()['state'], $state()['can_send']]);
        $this->actingAs($manager)->get(route('club.gifts.index'))->assertInertia(fn (AssertableInertia $page) => $page->where('gifts.data.0.delivery.state', 'read'));

        // Another gift whose message fails at WhatsApp.
        $second = $this->deskGift($club, pay: false, phone: '95557777');
        $this->recipientConfirms($club, $second)->assertOk();
        $this->asTenant($club, fn () => app(PaymentService::class)->record($second->invoice, $second->invoice->balance(), 'cash'));
        $failedId = $this->asTenant($club, fn () => Message::whereKey($second->fresh()->whatsapp_message_id)->value('provider_message_id'));
        [$invoices, $payments] = $this->asTenant($club, fn () => [Invoice::count(), Payment::count()]);
        $this->whatsappStatus($failedId, 'failed', ['code' => 131026, 'title' => 'Message undeliverable', 'error_data' => ['details' => 'Receiver is incapable of receiving this message']])->assertOk();

        $this->asTenant($club, function () use ($second, $invoices, $payments, $manager) {
            $d = app(GiftWhatsApp::class)->delivery($second->fresh());
            $this->assertSame(['failed', true], [$d['state'], $d['can_send']]);
            $this->assertStringContainsString('incapable', (string) $d['reason']);
            $this->assertSame(GiftMembership::PAID, $second->fresh()->status, 'the paid gift stands');
            $this->assertSame([$invoices, $payments], [Invoice::count(), Payment::count()], 'no invoice or payment made for it');
            $this->assertSame(1, AuditLog::where('event', 'gift.whatsapp_failed')->count());
            $this->assertSame(1, StaffNotification::where('user_id', $manager->id)->where('key', 'like', 'gift-whatsapp:'.$second->id.':%')->count());
        });
    }

    public function test_a_failed_gift_is_sent_again_once_and_a_corrected_number_is_confirmed_again_with_a_new_link(): void
    {
        $club = $this->club();
        $this->connectWhatsApp($club);
        $manager = $this->roleWith($club, ['gifts.view', 'gifts.manage']);
        $gift = $this->deskGift($club, pay: false);
        $this->recipientConfirms($club, $gift)->assertOk();
        $this->asTenant($club, fn () => app(PaymentService::class)->record($gift->invoice, $gift->invoice->balance(), 'cash'));
        $first = $this->giftMessages($club)->sole();

        // Nothing failed: a resend is refused, there is one on its way.
        $this->actingAs($manager)->post(route('club.gifts.resend', $gift), ['client_key' => (string) Str::uuid()])->assertSessionHasErrors();
        $this->assertCount(1, $this->giftMessages($club));

        $this->whatsappStatus($first->provider_message_id, 'failed', ['code' => 131026, 'title' => 'Message undeliverable'])->assertOk();
        // The same press twice (one key) and a second press: one new attempt.
        $key = (string) Str::uuid();
        $this->actingAs($manager)->post(route('club.gifts.resend', $gift), ['client_key' => $key])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('club.gifts.resend', $gift), ['client_key' => $key]);
        $this->actingAs($manager)->post(route('club.gifts.resend', $gift), ['client_key' => (string) Str::uuid()])->assertSessionHasErrors();
        $this->assertCount(2, $this->giftMessages($club));
        $this->asTenant($club, fn () => $this->assertSame(['gift:'.$gift->id.':1', 'gift:'.$gift->id.':2'], Message::where('template_key', 'gift_received')->orderBy('id')->pluck('dedupe_key')->all()));

        // The number was wrong: corrected, confirmed again by the new number, and the old link no longer opens.
        $oldToken = $this->asTenant($club, fn () => $gift->fresh()->linkToken());
        $this->actingAs($manager)->post(route('club.gifts.recipient', $gift), ['recipient_phone' => '+968 9666 1111'])->assertSessionHasNoErrors();
        $gift = $this->asTenant($club, fn () => $gift->fresh());
        $this->assertSame(['96896661111', false], [$gift->recipient_phone, $gift->phoneVerified()]);
        $this->get(route('site.gift.show', ['club' => $club->slug, 'token' => $oldToken]))->assertNotFound();
        $this->asTenant($club, fn () => $this->assertSame(['waiting', false], [app(GiftWhatsApp::class)->delivery($gift)['state'], app(GiftWhatsApp::class)->delivery($gift)['can_send']]));
        $this->asTenant($club, fn () => $this->assertSame(1, AuditLog::where('event', 'gift.recipient_changed')->count()));

        // The old number's code-holder cannot confirm the new one; the new number can, and the gift goes there at once.
        $this->recipientConfirms($club, $gift, from: self::RECIPIENT)->assertOk();
        $this->assertCount(2, $this->giftMessages($club));
        $this->recipientConfirms($club, $gift)->assertOk();
        $messages = $this->giftMessages($club);
        $this->assertCount(3, $messages);
        $this->assertSame('96896661111', $messages->last()->to_phone);
        $this->assertStringContainsString($this->asTenant($club, fn () => $gift->fresh()->linkToken()), $messages->last()->template_params[2]);
    }

    public function test_a_website_gift_is_paid_only_after_the_confirmation_and_sent_once_after_the_signed_webhook(): void
    {
        $club = $this->club();
        $this->configurePaymob($club);
        $this->connectWhatsApp($club);
        $plan = $this->plan($club, ['price' => 30]);

        $this->post(route('site.gift.store', ['club' => $club->slug, 'plan' => $plan->id]), $this->buyer(['recipient_phone' => '95550000']))->assertRedirectContains('/verify');
        $gift = $this->asTenant($club, fn () => GiftMembership::sole());
        $token = $this->asTenant($club, fn () => $gift->linkToken());

        // The buyer checks the number and corrects it before it is confirmed; the page shows how she confirms it.
        $this->get(route('site.gift.verify', ['club' => $club->slug, 'token' => $token]))->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Site/GiftVerify', false)->where('recipient_phone', '96895550000')->where('verification.state', 'waiting')
            ->where('verification.link', fn ($l) => str_starts_with((string) $l, 'https://wa.me/'.self::CLUB_WHATSAPP))->where('can_pay', false));
        $this->post(route('site.gift.phone', ['club' => $club->slug, 'token' => $token]), ['recipient_phone' => '81234567'])->assertSessionHasErrors('recipient_phone');
        $this->post(route('site.gift.phone', ['club' => $club->slug, 'token' => $token]), ['recipient_phone' => '95551234'])->assertRedirectContains('/verify');
        $gift = $this->asTenant($club, fn () => $gift->fresh());
        $this->assertSame(self::RECIPIENT, $gift->recipient_phone);

        // No checkout before the confirmation, from the gift's page or the invoice's own pay link.
        $this->post(route('site.gift.pay', ['club' => $club->slug, 'token' => $token]))->assertRedirectContains('/verify');
        try {
            $this->asTenant($club, fn () => app(OnlinePaymentService::class)->start($gift->invoice, 'website'));
            $this->fail('a checkout opened for a gift whose number is not confirmed');
        } catch (BusinessRuleException $e) {
            $this->assertSame('gift.phone_unverified', $e->rule);
        }
        $this->asTenant($club, fn () => $this->assertSame(0, PaymentIntent::count()));
        // Someone else with the link gets the card, not the buyer's page.
        $this->flushSession();
        $this->get(route('site.gift.verify', ['club' => $club->slug, 'token' => $token]))->assertRedirect(route('site.gift.show', ['club' => $club->slug, 'token' => $token]));
        $this->post(route('site.gift.pay', ['club' => $club->slug, 'token' => $token]))->assertNotFound();
        $this->withSession(['gifts.bought' => [$gift->id]]);

        $this->recipientConfirms($club, $gift)->assertOk();
        $this->get(route('site.gift.verify', ['club' => $club->slug, 'token' => $token]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('verification.state', 'verified')->where('verification.code', null)->where('can_pay', true));
        $this->post(route('site.gift.pay', ['club' => $club->slug, 'token' => $token]))->assertRedirectContains('unifiedcheckout');
        $intent = $this->asTenant($club, fn () => PaymentIntent::sole());

        // Back from the gateway proves nothing and sends nothing.
        $this->get(route('site.pay.return', ['club' => $club->slug, 'reference' => $intent->reference]))->assertOk();
        $this->assertCount(0, $this->giftMessages($club));

        // The signed webhook pays it: one message; the webhook again: still one.
        $this->paidBy($intent, 30000)->assertOk();
        $this->paidBy($intent, 30000)->assertOk();
        $this->asTenant($club, fn () => $this->assertSame(GiftMembership::PAID, $gift->fresh()->status));
        $this->assertCount(1, $this->giftMessages($club));
        $this->assertSame(self::RECIPIENT, $this->giftMessages($club)->sole()->to_phone);
    }

    public function test_nothing_goes_while_the_notice_is_off_and_switching_it_on_sends_no_backlog_staff_send_each(): void
    {
        $club = $this->club();
        $this->connectWhatsApp($club, sendGifts: false);
        $this->assertFalse(WhatsAppAutomation::on($club->fresh(), 'gift_received'), 'off until the owner switches it on');
        $this->assertTrue(WhatsAppAutomation::on($club->fresh(), 'payment_received'), 'the other notices keep their default');

        // Sold at the desk while it is off (the launch's first step): confirmed and paid, nothing goes.
        $gifts = collect([1, 2, 3])->map(function ($i) use ($club) {
            $gift = $this->deskGift($club, pay: false, phone: '9555123'.$i);
            $this->recipientConfirms($club, $gift)->assertOk();
            $this->asTenant($club, fn () => app(PaymentService::class)->record($gift->invoice, $gift->invoice->balance(), 'cash'));

            return $gift->fresh();
        });
        $this->assertCount(0, $this->giftMessages($club));
        $this->asTenant($club, function () use ($gifts) {
            $d = app(GiftWhatsApp::class)->delivery($gifts[0]->fresh());
            $this->assertSame(['waiting', false], [$d['state'], $d['can_send']]);
            $this->assertStringContainsString('غير مفعّل', (string) $d['reason']);
            // With the switch off, her confirmation got no answer either: nothing at all went out.
            $this->assertSame(0, Message::where('direction', 'outbound')->count());
        });

        // Switched on from «الرسائل التلقائية»: the three that waited are not sent, at its hour or ever, by themselves.
        $this->travelTo(now()->addHour());
        $this->actingAs($this->owner($club))->put(route('club.automation.update'), ['on' => ['gift_received' => true], 'hour' => 10])->assertSessionHasNoErrors();
        $club = $club->fresh();
        $this->assertNotNull(WhatsAppAutomation::enabledSince($club, 'gift_received'));
        $this->travelTo(now()->setTime(10, 5));
        Artisan::call('gym:send-reminders', ['--due' => true]);
        Artisan::call('gym:daily-memberships');
        $this->assertCount(0, $this->giftMessages($club), 'no backlog goes out when the notice is switched on');
        $this->asTenant($club, function () use ($gifts) {
            $d = app(GiftWhatsApp::class)->delivery($gifts[0]->fresh());
            $this->assertSame(['waiting', true], [$d['state'], $d['can_send']]);
            $this->assertStringContainsString('دُفعت قبل تشغيل', (string) $d['reason']);
        });

        // Staff send one after checking: that one, once.
        $this->actingAs($this->owner($club))->post(route('club.gifts.resend', $gifts[0]))->assertSessionHasNoErrors();
        $this->assertCount(1, $this->giftMessages($club));

        // A gift sold after the switch goes at once.
        $this->deskGift($club, phone: '95551299');
        $fresh = $this->asTenant($club, fn () => GiftMembership::latest('id')->first());
        $this->recipientConfirms($club, $fresh)->assertOk();
        $this->assertCount(2, $this->giftMessages($club));
    }

    public function test_a_gift_whose_template_waited_for_meta_goes_once_at_the_clubs_hour_not_at_midnight(): void
    {
        $club = $this->club();
        $this->connectWhatsApp($club, approved: false);
        $gift = $this->deskGift($club, pay: false);
        $this->recipientConfirms($club, $gift)->assertOk();
        $this->asTenant($club, fn () => app(PaymentService::class)->record($gift->invoice, $gift->invoice->balance(), 'cash'));
        $this->asTenant($club, fn () => $this->assertStringContainsString('لم يُعتمد', (string) app(GiftWhatsApp::class)->delivery($gift->fresh())['reason']));

        $this->asTenant($club, fn () => MessageTemplate::where('key', 'gift_received')->update(['status' => 'approved']));
        $this->asTenant($club, fn () => $this->assertStringContainsString('10:00', (string) app(GiftWhatsApp::class)->delivery($gift->fresh())['reason']));
        // The 00:10 run and an hour that is not the club's send nothing.
        $this->travelTo(now()->addDay()->setTime(0, 10));
        Artisan::call('gym:daily-memberships');
        $this->travelTo(now()->setTime(3, 0));
        Artisan::call('gym:send-reminders', ['--due' => true]);
        $this->assertCount(0, $this->giftMessages($club));

        $this->travelTo(now()->setTime(10, 0));
        Artisan::call('gym:send-reminders', ['--due' => true]);
        Artisan::call('gym:send-reminders', ['--due' => true]);
        $this->assertCount(1, $this->giftMessages($club));
    }

    public function test_a_gift_message_in_flight_or_with_an_uncertain_outcome_is_shown_as_such_and_never_said_to_have_failed(): void
    {
        $club = $this->club();
        $this->connectWhatsApp($club);
        $gift = $this->deskGift($club);
        $this->recipientConfirms($club, $gift)->assertOk();
        $message = $this->asTenant($club, fn () => Message::findOrFail($gift->fresh()->whatsapp_message_id));
        $owner = $this->owner($club);

        // A worker is sending it now (#203: `sending`): on its way; not sent again.
        $this->asTenant($club, fn () => $message->forceFill(['status' => 'sending'])->save());
        $this->asTenant($club, fn () => $this->assertSame(['sending', 'قيد الإرسال', false], array_values(array_intersect_key(app(GiftWhatsApp::class)->delivery($gift->fresh()), array_flip(['state', 'label', 'can_send'])))));
        $this->actingAs($owner)->post(route('club.gifts.resend', $gift))->assertSessionHasErrors();

        // Its outcome is unknown (#203: `uncertain`): may have arrived. Staff hear of it as that, and may send again.
        $this->asTenant($club, fn () => $message->forceFill(['status' => 'uncertain', 'error_code' => 'outcome_unknown', 'error_message' => null])->save());
        $this->asTenant($club, function () use ($gift) {
            $d = app(GiftWhatsApp::class)->delivery($gift->fresh());
            $this->assertSame(['uncertain', 'نتيجة الإرسال غير مؤكدة', true], [$d['state'], $d['label'], $d['can_send']]);
            $this->assertStringContainsString('قد تكون وصلت', (string) $d['reason']);
            $alert = StaffNotification::where('key', 'like', 'gift-whatsapp:'.$gift->id.':%')->sole();
            $this->assertStringContainsString('غير مؤكدة', $alert->text);
            $this->assertStringNotContainsString('لم تصل', $alert->text);
            $this->assertSame(1, AuditLog::where('event', 'gift.whatsapp_uncertain')->count());
        });
        $this->get(route('site.gift.show', ['club' => $club->slug, 'token' => $this->asTenant($club, fn () => $gift->fresh()->linkToken())]))->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('delivery', null));

        $this->actingAs($owner)->post(route('club.gifts.resend', $gift))->assertSessionHasNoErrors();
        $this->assertCount(2, $this->giftMessages($club), 'sent again because staff chose to, once');
        $this->asTenant($club, fn () => $this->assertSame(2, $gift->fresh()->whatsapp_attempts));
    }

    public function test_staff_permissions_and_club_isolation(): void
    {
        $club = $this->club();
        $this->connectWhatsApp($club);
        $gift = $this->deskGift($club, pay: false);
        $viewer = $this->roleWith($club, ['gifts.view']);
        $issuer = $this->roleWith($club, ['gifts.view', 'gifts.create']);

        foreach (['club.gifts.code', 'club.gifts.recipient', 'club.gifts.resend'] as $route) {
            $this->actingAs($viewer)->post(route($route, $gift), ['recipient_phone' => '96661111', 'client_key' => (string) Str::uuid()])->assertForbidden();
        }
        $this->actingAs($issuer)->post(route('club.gifts.code', $gift))->assertSessionHasNoErrors();
        $this->actingAs($issuer)->post(route('club.gifts.recipient', $gift), ['recipient_phone' => '96661111'])->assertForbidden();
        $this->actingAs($issuer)->post(route('club.gifts.resend', $gift), ['client_key' => (string) Str::uuid()])->assertForbidden();

        // Another club, connected on its own number: club A's code sent there confirms nothing in either club.
        $other = $this->club();
        $this->connectWhatsApp($other, pnid: 'PNID-OTHER');
        $text = $this->asTenant($club, fn () => app(GiftWhatsApp::class)->verification($gift->fresh())['text']);
        $this->whatsappText(self::RECIPIENT, $text, 'wamid.wrong-club', 'PNID-OTHER')->assertOk();
        $this->asTenant($club, fn () => $this->assertFalse($gift->fresh()->phoneVerified()));
        $this->asTenant($other, fn () => $this->assertSame(0, GiftMembership::count()));
        $this->actingAs($this->owner($other))->post(route('club.gifts.resend', $gift), ['client_key' => (string) Str::uuid()])->assertNotFound();
    }

    public function test_a_forged_unsigned_or_wrongly_tokened_webhook_and_a_wrong_code_confirm_nothing(): void
    {
        $club = $this->club();
        $this->connectWhatsApp($club);
        $gift = $this->deskGift($club, pay: false);
        $text = $this->asTenant($club, fn () => app(GiftWhatsApp::class)->verification($gift)['text']);
        $body = json_encode($this->whatsappChange([
            'contacts' => [['wa_id' => self::RECIPIENT, 'profile' => ['name' => 'Noor']]],
            'messages' => [['from' => self::RECIPIENT, 'id' => 'wamid.forged', 'timestamp' => (string) now()->timestamp, 'type' => 'text', 'text' => ['body' => $text]]],
        ]));

        // Signed with another secret, or not signed at all: refused before anything is written.
        $this->call('POST', route('webhooks.whatsapp'), [], [], [], ['HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $body, 'not-the-secret'), 'CONTENT_TYPE' => 'application/json'], $body)->assertStatus(401);
        $this->call('POST', route('webhooks.whatsapp'), [], [], [], ['CONTENT_TYPE' => 'application/json'], $body)->assertStatus(401);
        // The 360dialog door with a wrong club token: refused too.
        $this->call('POST', route('webhooks.whatsapp.d360'), [], [], [], ['HTTP_X_ALOPERA_WEBHOOK_TOKEN' => 'guess', 'CONTENT_TYPE' => 'application/json'], $body)->assertStatus(401);
        $this->assertSame(0, WebhookEvent::count());
        $this->asTenant($club, fn () => $this->assertFalse($gift->fresh()->phoneVerified()));

        // A code of the right shape that is not this gift's, from her own number: a chat message, nothing confirmed.
        $this->whatsappText(self::RECIPIENT, 'رمز التأكيد: ABCD-EFGH', 'wamid.wrong-code')->assertOk();
        $this->asTenant($club, function () use ($gift) {
            $this->assertFalse($gift->fresh()->phoneVerified());
            $this->assertSame(1, Message::where('provider_message_id', 'wamid.wrong-code')->count(), 'kept as an ordinary chat message');
            $this->assertSame(0, AuditLog::where('event', 'gift.phone_verified')->count());
        });
    }

    public function test_the_real_meta_request_carries_the_approved_template_and_the_three_values_and_nothing_else(): void
    {
        config(['services.whatsapp.driver' => 'meta']);
        // Meta answers each send with its own id: the gift's template gets wamid.REAL-1.
        Http::fake(['https://graph.facebook.com/*' => fn (HttpRequest $r) => Http::response(['messaging_product' => 'whatsapp',
            'messages' => [['id' => ($r->data()['type'] ?? '') === 'template' ? 'wamid.REAL-1' : 'wamid.TEXT-'.Str::random(6)]]], 200)]);
        $club = $this->club();
        $this->connectWhatsApp($club);
        $gift = $this->deskGift($club, pay: false);
        $this->recipientConfirms($club, $gift)->assertOk();
        // Her confirmation got a short answer in the chat she opened (the notice is on): that one text, nothing else yet.
        $this->assertCount(1, Http::recorded(fn (HttpRequest $r) => ($r->data()['type'] ?? null) === 'text'));
        $this->assertCount(0, Http::recorded(fn (HttpRequest $r) => ($r->data()['type'] ?? null) === 'template'));
        $invoice = $this->asTenant($club, fn () => $gift->invoice->number);

        $this->asTenant($club, fn () => app(PaymentService::class)->record($gift->invoice, $gift->invoice->balance(), 'cash'));

        $sent = Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/PNID-GIFT/messages') && ($r->data()['type'] ?? null) === 'template');
        $this->assertCount(1, $sent, 'one template request to Meta');
        [$request] = $sent->first();
        $this->assertSame('https://graph.facebook.com/'.config('services.whatsapp.graph_version').'/PNID-GIFT/messages', $request->url());
        $this->assertTrue($request->hasHeader('Authorization'));
        $data = $request->data();
        $this->assertSame([self::RECIPIENT, 'alopera_gift_received', 'ar'], [$data['to'], $data['template']['name'], $data['template']['language']['code']]);
        $values = array_column($data['template']['components'][0]['parameters'], 'text');
        $token = $this->asTenant($club, fn () => $gift->fresh()->linkToken());
        $this->assertCount(3, $values, 'the giver, the plan and the link: no free text');
        $this->assertSame(['Sara', 'Gold'], array_slice($values, 0, 2));
        $this->assertStringContainsString('/gift/'.$token, $values[2]);
        $json = json_encode($data, JSON_UNESCAPED_UNICODE);
        foreach (['30.000', $invoice, 'OMR', 'ر.ع'] as $secret) {
            $this->assertStringNotContainsString($secret, $json);
        }

        // The message keeps WhatsApp's id; its statuses come back by it. Nobody became a member, no period started.
        $this->asTenant($club, function () use ($gift) {
            $message = Message::whereKey($gift->fresh()->whatsapp_message_id)->sole();
            $this->assertSame(['wamid.REAL-1', Message::SENT], [$message->provider_message_id, $message->status]);
            $this->assertSame(0, Member::count());
            $this->assertSame(0, Membership::count());
            $this->assertNull($gift->fresh()->membership_id);
        });
        $this->whatsappStatus('wamid.REAL-1', 'delivered')->assertOk();
        $this->asTenant($club, fn () => $this->assertSame('delivered', app(GiftWhatsApp::class)->delivery($gift->fresh())['state']));
    }

    /**
     * A free plan given as a gift: no payment and no journal line at any point. The zero invoice is paid on issue,
     * her confirmation sends the one template, and cancelling the gift afterwards moves nothing. The website never
     * offers a hidden plan. (Not a production test procedure: the launch uses no free plans or test invoices.)
     */
    public function test_a_gift_of_a_hidden_free_plan_sends_once_and_posts_nothing(): void
    {
        config(['services.whatsapp.driver' => 'meta']);
        Http::fake(['https://graph.facebook.com/*' => fn (HttpRequest $r) => Http::response(['messaging_product' => 'whatsapp',
            'messages' => [['id' => ($r->data()['type'] ?? '') === 'template' ? 'wamid.LAUNCH-1' : 'wamid.TEXT-'.Str::random(6)]]], 200)]);
        $club = $this->club();
        $this->connectWhatsApp($club);
        $plan = $this->plan($club, ['price' => 0, 'name' => 'اختبار داخلي', 'is_public' => false]);
        $this->get(route('site.gift.create', ['club' => $club->slug, 'plan' => $plan->id]))->assertNotFound();

        $gift = $this->asTenant($club, fn () => app(GiftService::class)->issue($plan, ['giver_name' => 'Owner', 'recipient_name' => 'Owner',
            'recipient_phone' => '95551234'], 'staff'));
        $this->asTenant($club, function () use ($gift) {
            $this->assertSame([GiftMembership::PAID, Invoice::PAID, '0.000'], [$gift->fresh()->status, $gift->invoice->fresh()->status, $gift->invoice->fresh()->total]);
            $this->assertSame(0, Payment::count());
            $this->assertSame(0, JournalLine::count());
        });
        $this->assertCount(0, Http::recorded(), 'nothing is sent before her own number confirms');

        $confirmation = $this->asTenant($club, fn () => app(GiftWhatsApp::class)->verification($gift->fresh())['text']);
        $this->whatsappText(self::RECIPIENT, $confirmation, 'wamid.launch-confirm')->assertOk();
        $templates = Http::recorded(fn (HttpRequest $r) => ($r->data()['type'] ?? null) === 'template');
        $this->assertCount(1, $templates);
        $this->assertSame([self::RECIPIENT, 'alopera_gift_received'], [$templates->first()[0]->data()['to'], $templates->first()[0]->data()['template']['name']]);
        $this->whatsappStatus('wamid.LAUNCH-1', 'delivered')->assertOk();
        $this->whatsappStatus('wamid.LAUNCH-1', 'read')->assertOk();
        // The nightly run and a repeated webhook add nothing.
        Artisan::call('gym:daily-memberships');
        $this->whatsappText(self::RECIPIENT, $confirmation, 'wamid.launch-confirm')->assertOk();
        $this->whatsappText(self::RECIPIENT, $confirmation, 'wamid.launch-confirm-again')->assertOk();
        $this->assertCount(1, Http::recorded(fn (HttpRequest $r) => ($r->data()['type'] ?? null) === 'template'));

        $this->asTenant($club, function () use ($gift) {
            $this->assertSame('read', app(GiftWhatsApp::class)->delivery($gift->fresh())['state']);
            app(GiftService::class)->cancel($gift->fresh(), 'اختبار الإطلاق');
            $this->assertSame(GiftMembership::CANCELLED, $gift->fresh()->status);
            $this->assertSame(0, Payment::count());
            $this->assertSame(0, JournalLine::count(), 'the test gift left nothing in the accounts');
            $this->assertSame([0, 0], [Member::count(), Membership::count()]);
        });
    }

    /** With #204 on main: the real send path, a request that timed out after it left — once, then for staff to decide. */
    public function test_a_gift_message_that_timed_out_after_it_left_is_uncertain_once_and_staff_are_told_so(): void
    {
        config(['services.whatsapp.driver' => 'meta']);
        $templates = 0;
        Http::fake(['https://graph.facebook.com/*' => function (HttpRequest $r) use (&$templates) {
            if (($r->data()['type'] ?? '') === 'template' && ++$templates === 1) {
                return Create::rejectionFor(new NetworkTimeoutException('cURL error 28: Operation timed out after 15001 milliseconds with 0 bytes received', $r->toPsrRequest()));
            }

            return Http::response(['messaging_product' => 'whatsapp', 'messages' => [['id' => 'wamid.'.Str::random(10)]]], 200);
        }]);
        $club = $this->club();
        $this->connectWhatsApp($club);
        $gift = $this->deskGift($club, pay: false);
        $this->recipientConfirms($club, $gift)->assertOk();
        $this->asTenant($club, fn () => app(PaymentService::class)->record($gift->invoice, $gift->invoice->balance(), 'cash'));
        $this->artisan('messaging:dispatch-scheduled')->assertSuccessful();

        $this->assertSame(1, $templates, 'one request, never retried on its own');
        $this->asTenant($club, function () use ($gift) {
            $this->assertSame(Message::UNCERTAIN, Message::findOrFail($gift->fresh()->whatsapp_message_id)->status);
            $d = app(GiftWhatsApp::class)->delivery($gift->fresh());
            $this->assertSame(['uncertain', 'نتيجة الإرسال غير مؤكدة', true], [$d['state'], $d['label'], $d['can_send']]);
            $alert = StaffNotification::where('key', 'like', 'gift-whatsapp:'.$gift->id.':%')->sole();
            $this->assertStringContainsString('غير مؤكدة', $alert->text);
            $this->assertSame(1, AuditLog::where('event', 'gift.whatsapp_uncertain')->count());
        });

        // Staff check with her, then send again: one more request, and the gift stands.
        $this->actingAs($this->owner($club))->post(route('club.gifts.resend', $gift))->assertSessionHasNoErrors();
        $this->assertSame(2, $templates);
        $this->asTenant($club, fn () => $this->assertSame(GiftMembership::PAID, $gift->fresh()->status));
    }

    public function test_a_number_meta_refuses_fails_once_without_retries_and_the_gift_stands(): void
    {
        config(['services.whatsapp.driver' => 'meta']);
        Http::fake(['https://graph.facebook.com/*' => Http::response(['error' => ['code' => 131026, 'message' => 'Message undeliverable',
            'error_data' => ['details' => 'Receiver is incapable of receiving this message']]], 400)]);
        $club = $this->club();
        $this->connectWhatsApp($club, sendGifts: false); // no answer to her confirmation: only the gift's request is counted
        $club->forceFill(['settings' => array_replace_recursive($club->fresh()->settings ?? [], ['whatsapp_automation' => ['enabled' => []]])])->save();
        $gift = $this->deskGift($club, pay: false);
        $this->recipientConfirms($club, $gift)->assertOk();
        $club->forceFill(['settings' => array_replace_recursive($club->fresh()->settings ?? [], ['whatsapp_automation' => ['enabled' => ['gift_received']]])])->save();
        $club = $club->fresh();

        $this->asTenant($club, fn () => app(PaymentService::class)->record($gift->invoice, $gift->invoice->balance(), 'cash'));
        Artisan::call('gym:daily-memberships');

        $this->assertCount(1, Http::recorded(fn (HttpRequest $r) => str_ends_with($r->url(), '/messages')), 'refused once, not retried');
        $this->asTenant($club, function () use ($gift) {
            $d = app(GiftWhatsApp::class)->delivery($gift->fresh());
            $this->assertSame(['failed', true], [$d['state'], $d['can_send']]);
            $this->assertStringContainsString('incapable', (string) $d['reason']);
            $this->assertSame(GiftMembership::PAID, $gift->fresh()->status);
            $this->assertSame(1, AuditLog::where('event', 'gift.whatsapp_failed')->count());
        });
    }

    public function test_nothing_goes_while_the_payment_waits_for_confirmation_or_the_gateway_reports_a_failure(): void
    {
        $club = $this->club();
        $this->configurePaymob($club);
        $this->connectWhatsApp($club);

        // At the desk: a bank transfer waiting for finance.
        $desk = $this->deskGift($club, pay: false);
        $this->recipientConfirms($club, $desk)->assertOk();
        $this->asTenant($club, fn () => app(PaymentService::class)->record($desk->invoice, $desk->invoice->balance(), 'bank_transfer'));
        $this->asTenant($club, fn () => $this->assertSame(GiftMembership::PENDING, $desk->fresh()->status));
        $this->assertCount(0, $this->giftMessages($club));

        // Online: the gateway says the payment failed.
        $this->post(route('site.gift.store', ['club' => $club->slug, 'plan' => $this->plan($club, ['price' => 30])->id]), $this->buyer(['recipient_phone' => '95559999']));
        $site = $this->asTenant($club, fn () => GiftMembership::where('source', 'site')->sole());
        $this->recipientConfirms($club, $site)->assertOk();
        $this->post(route('site.gift.pay', ['club' => $club->slug, 'token' => $this->asTenant($club, fn () => $site->linkToken())]))->assertRedirectContains('unifiedcheckout');
        $this->paidBy($this->asTenant($club, fn () => PaymentIntent::sole()), 30000, success: false)->assertOk();
        $this->asTenant($club, fn () => $this->assertSame(GiftMembership::PENDING, $site->fresh()->status));
        Artisan::call('gym:daily-memberships');
        $this->assertCount(0, $this->giftMessages($club));
    }

    public function test_the_buyer_sees_where_the_gift_message_is_in_their_language_and_nobody_else_does(): void
    {
        $club = $this->club();
        $this->configurePaymob($club);
        $this->connectWhatsApp($club);
        $this->post(route('site.gift.store', ['club' => $club->slug, 'plan' => $this->plan($club, ['price' => 30])->id]), $this->buyer());
        $gift = $this->asTenant($club, fn () => GiftMembership::sole());
        $token = $this->asTenant($club, fn () => $gift->linkToken());

        // English confirmation page: the message she sends is in English too.
        $this->get('/c/'.$club->slug.'/en/gift/'.$token.'/verify')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->where('locale', 'en')->where('verification.text', fn ($t) => str_starts_with((string) $t, 'I agree to receive messages from')));

        $this->recipientConfirms($club, $gift)->assertOk();
        $this->post(route('site.gift.pay', ['club' => $club->slug, 'token' => $token]));
        $this->paidBy($this->asTenant($club, fn () => PaymentIntent::sole()), 30000)->assertOk();

        $this->get(route('site.gift.show', ['club' => $club->slug, 'token' => $token]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('delivery.state', 'sent')->where('delivery.label', 'أُرسلت إلى المزود'));
        $this->get('/c/'.$club->slug.'/en/gift/'.$token)->assertInertia(fn (AssertableInertia $page) => $page->where('delivery.label', 'Handed to WhatsApp'));

        // Failed: the buyer is told to share the link or contact the club, without the provider's error.
        $id = $this->giftMessages($club)->sole()->provider_message_id;
        $this->whatsappStatus($id, 'failed', ['code' => 131026, 'title' => 'Message undeliverable'])->assertOk();
        $this->get(route('site.gift.show', ['club' => $club->slug, 'token' => $token]))->assertInertia(fn (AssertableInertia $page) => $page
            ->where('delivery.state', 'failed')->where('delivery.note', fn ($n) => str_contains((string) $n, 'تواصل مع النادي') && ! str_contains((string) $n, '131026')));

        // Anyone else with the link: no delivery details.
        $this->flushSession();
        $this->get(route('site.gift.show', ['club' => $club->slug, 'token' => $token]))->assertInertia(fn (AssertableInertia $page) => $page->where('delivery', null));
    }
}
