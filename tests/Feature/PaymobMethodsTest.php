<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\ManualInvoices;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Paymob\PaymobClient;
use App\Modules\Payments\Paymob\PaymobMethods;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Each method of the club's Paymob account (cards, OmanNet, Apple Pay) has an integration id of its own, sent to every
 * checkout and accepted on callbacks; kept with the keys, never shown back; and never «available» for a saved number.
 */
class PaymobMethodsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const HMAC = 'methods-hmac';

    private function paymob(Tenant $club, array $methods = [], array $attributes = []): TenantIntegration
    {
        $credentials = ($attributes['credentials'] ?? ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC])
            + array_combine(array_map(fn ($m) => PaymobClient::METHOD_KEYS[$m], array_keys($methods)), array_map('strval', $methods));
        unset($attributes['credentials']);

        return $this->asTenant($club, fn () => TenantIntegration::create($attributes + [
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test', 'credentials' => $credentials,
        ]));
    }

    private function row(Tenant $club): TenantIntegration
    {
        return $this->asTenant($club, fn () => TenantIntegration::where('provider', 'paymob')->firstOrFail());
    }

    /** @return array<string, array<string, mixed>> */
    private function states(Tenant $club): array
    {
        return $this->asTenant($club, fn () => collect(PaymobMethods::states(TenantIntegration::where('provider', 'paymob')->first()))->keyBy('key')->all());
    }

    private function event(TenantIntegration $row, string $outcome, int $integrationId, string $scheme = 'Visa', bool $valid = true): void
    {
        PaymentWebhookEvent::create([
            'tenant_id' => $row->tenant_id, 'provider' => 'paymob', 'transaction_id' => (string) random_int(1, 1_000_000_000), 'reference' => 'X',
            'signature_valid' => $valid, 'outcome' => $outcome, 'payload' => ['obj' => ['integration_id' => $integrationId, 'source_data' => ['type' => 'card', 'sub_type' => $scheme]]],
        ]);
    }

    private function invoice(Tenant $club, string $price = '10')
    {
        $this->actingAs($this->owner($club));

        return $this->asTenant($club, fn () => app(ManualInvoices::class)->issue([
            'customer_mode' => 'new', 'customer_name' => 'عميل', 'customer_phone' => '96891234567',
            'issue_date' => today()->toDateString(), 'due_date' => today()->addDays(7)->toDateString(), 'payment_terms_days' => 7,
            'lines' => [['description' => 'خدمة', 'quantity' => 1, 'unit_price' => $price]],
        ], ['method' => 'credit']));
    }

    private function signedObj(PaymentIntent $intent, int $integrationId, int $tx): array
    {
        return [
            'id' => $tx, 'pending' => false, 'amount_cents' => 10000, 'success' => true, 'is_auth' => false, 'is_capture' => false,
            'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false, 'is_3d_secure' => true, 'integration_id' => $integrationId,
            'has_parent_transaction' => false, 'order' => ['id' => (int) $intent->provider_order_id, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-11-15T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
        ];
    }

    private function deliver(Tenant $club, array $obj): string
    {
        return $this->asTenant($club, fn () => app(OnlinePaymentService::class)->handleWebhook(['type' => 'TRANSACTION', 'obj' => $obj], PaymobSignature::compute($obj, self::HMAC)));
    }

    public function test_each_number_is_saved_with_the_keys_kept_when_left_blank_and_never_shown_again(): void
    {
        $club = $this->club();
        $this->paymob($club);
        $owner = $this->owner($club);
        $put = fn (array $data) => $this->actingAs($owner)->put(route('club.integrations.paymob.methods'), $data);

        $put(['card' => '7011101', 'omannet' => '7033303', 'apple_pay' => '7055505'])->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->assertSame(['card' => 7011101, 'omannet' => 7033303, 'apple_pay' => 7055505], PaymobClient::methodIds($this->row($club)));

        // Blank keeps every number; the keys are untouched; «clear» removes one.
        $put(['card' => '', 'omannet' => '', 'apple_pay' => ''])->assertSessionHasNoErrors();
        $put(['clear' => ['apple_pay']])->assertSessionHasNoErrors();
        $row = $this->row($club);
        $this->assertSame(['card' => 7011101, 'omannet' => 7033303, 'apple_pay' => null], PaymobClient::methodIds($row));
        $this->assertSame(['sk_test', 'pk_test', self::HMAC], [$row->credential('secret_key'), $row->credential('public_key'), $row->credential('hmac_secret')]);

        // Refused: letters, zero, one number for two methods — nothing saved.
        $put(['card' => '12a'])->assertSessionHasErrors('card');
        $put(['omannet' => '0'])->assertSessionHasErrors('omannet');
        $put(['apple_pay' => '7011101'])->assertSessionHas('error');
        $this->assertSame(['card' => 7011101, 'omannet' => 7033303, 'apple_pay' => null], PaymobClient::methodIds($this->row($club)));

        // At rest: encrypted with the keys; on the screen: states and «saved», never a number.
        $raw = DB::table('tenant_integrations')->where('id', $row->id)->first();
        $this->assertStringNotContainsString('method_card', (string) $raw->credentials);
        $this->assertStringNotContainsString('7033303', (string) $raw->settings);
        $html = $this->actingAs($owner)->get(route('club.integrations.paymob'))->assertOk()
            ->assertInertia(fn ($page) => $page->has('methods', 3)->where('methods.0.saved', true)->where('methods.1.saved', true)->where('methods.2.saved', false)->missing('methods.0.id'))
            ->getContent();
        $this->assertStringNotContainsString('7011101', $html);
        $this->assertStringNotContainsString('7033303', $html);
        // (Seven digits: a three-digit number can be any record's id on the page, and was, once earlier tests had
        // created enough rows.)
    }

    public function test_the_page_after_a_reload_shows_what_was_saved_and_a_blank_save_keeps_it(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $keys = fn (array $data) => $this->actingAs($owner)->put(route('club.integrations.update', 'paymob'), $data)->assertSessionHasNoErrors();
        $reload = fn () => $this->actingAs($owner)->get(route('club.integrations.paymob'))->assertOk();

        $keys(['is_enabled' => true, 'mode' => 'test', 'credentials' => ['secret_key' => 'omn_sk_test_reload', 'public_key' => 'omn_pk_test_reload', 'hmac_secret' => 'hmac-reload']]);
        $this->actingAs($owner)->put(route('club.integrations.paymob.methods'), ['card' => '6101', 'apple_pay' => '6103'])->assertSessionHasNoErrors();
        $saved = fn ($page) => $page->where('integration.mode', 'test')
            ->where('integration.present', ['secret_key' => true, 'public_key' => true, 'hmac_secret' => true, 'integration_ids' => false])
            ->where('methods.0.saved', true)->where('methods.0.state', PaymobMethods::SAVED)
            ->where('methods.1.saved', false)->where('methods.1.within_cards', true)
            ->where('methods.2.saved', true)->where('methods.2.state', PaymobMethods::WRONG_MODE);
        $html = $reload()->assertInertia(fn ($page) => $saved($page->where('integration.is_enabled', true)))->getContent();
        foreach (['omn_sk_test_reload', 'hmac-reload', '6101', '6103'] as $value) {
            $this->assertStringNotContainsString($value, $html);
        }

        // Saved again with the key fields blank and the switch off: the keys and the numbers stay; only the switch moved.
        $keys(['is_enabled' => false, 'mode' => 'test', 'credentials' => ['secret_key' => '', 'public_key' => '', 'hmac_secret' => '']]);
        $reload()->assertInertia(fn ($page) => $saved($page->where('integration.is_enabled', false)));
        $this->assertSame('omn_sk_test_reload', $this->row($club)->credential('secret_key'));
    }

    public function test_every_method_number_goes_to_the_checkout_and_apple_pay_only_under_live_keys(): void
    {
        $club = $this->club();
        $this->paymob($club, ['card' => 111, 'omannet' => 333, 'apple_pay' => 555], ['credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC, 'integration_ids' => '777']]);
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs', 'intention_order_id' => 1], 201)]);

        $this->asTenant($club, fn () => app(OnlinePaymentService::class)->start($this->invoice($club), 'whatsapp'));
        Http::assertSent(fn ($r) => $r['payment_methods'] === [777, 111, 333]); // the older list kept, Apple Pay left out under test keys
        $this->assertSame(PaymobMethods::WRONG_MODE, $this->states($club)['apple_pay']['state']);

        // Live keys: Apple Pay goes too (this is not the production server, where live is the only way anyway).
        $live = $this->club('Live');
        $this->paymob($live, ['card' => 211, 'apple_pay' => 255], ['mode' => 'live', 'credentials' => ['secret_key' => 'x_sk_live_1', 'public_key' => 'x_pk_live_1', 'hmac_secret' => self::HMAC]]);
        $this->asTenant($live, fn () => app(OnlinePaymentService::class)->start($this->invoice($live), 'whatsapp'));
        Http::assertSent(fn ($r) => $r['payment_methods'] === [211, 255]);
    }

    public function test_a_callback_through_any_method_number_is_the_clubs_money_and_another_number_is_not(): void
    {
        $club = $this->club();
        $this->paymob($club, ['card' => 111, 'omannet' => 333]);
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs', 'intention_order_id' => 4501], 201)]);
        $intent = $this->asTenant($club, fn () => app(OnlinePaymentService::class)->start($this->invoice($club), 'whatsapp'));

        $this->assertSame('integration_mismatch', $this->deliver($club, $this->signedObj($intent, 999, 1)));
        $this->assertSame('processed', $this->deliver($club, $this->signedObj($intent, 333, 2)));
        $this->assertSame('duplicate', $this->deliver($club, $this->signedObj($intent, 333, 2)));
        $this->assertSame(1, $this->asTenant($club, fn () => Payment::where('gateway', 'paymob')->count()));
        $this->assertSame(PaymobMethods::AVAILABLE, $this->states($club)['omannet']['state']);
    }

    public function test_keys_hmac_and_numbers_stay_out_of_the_logs_and_the_audit_on_every_path(): void
    {
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$logged) {
            $logged[] = $m->message.' '.json_encode($m->context, JSON_PARTIAL_OUTPUT_ON_ERROR);
        });
        $club = $this->club();
        $owner = $this->owner($club);
        $secrets = ['omn_sk_test_LEAKCHECK_91', 'omn_pk_test_LEAKCHECK_92', 'hmac-LEAKCHECK-93'];
        $this->actingAs($owner)->put(route('club.integrations.update', 'paymob'), [
            'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => $secrets[0], 'public_key' => $secrets[1], 'hmac_secret' => $secrets[2]],
        ])->assertSessionHasNoErrors();
        $this->actingAs($owner)->put(route('club.integrations.paymob.methods'), ['card' => '918273', 'omannet' => '918274'])->assertSessionHasNoErrors();

        // Paymob refusing, then unreachable, on the check and on a checkout; then a forged callback and a signed one.
        Http::fake(['*/v1/intention/' => Http::sequence()
            ->push(['detail' => 'Integration ID/Name does not exist in our system'], 404)
            ->pushFailedConnection()
            ->pushFailedConnection()
            ->push(['client_secret' => 'cs', 'intention_order_id' => 4601], 201)]);
        $this->actingAs($owner)->post(route('club.integrations.verify', 'paymob'))->assertSessionHas('error');
        $this->actingAs($owner)->post(route('club.integrations.verify', 'paymob'))->assertSessionHas('error');
        $invoice = $this->invoice($club);
        try {
            $this->asTenant($club, fn () => app(OnlinePaymentService::class)->start($invoice, 'whatsapp'));
            $this->fail('an unreachable Paymob opens no checkout');
        } catch (BusinessRuleException) {
        }
        $intent = $this->asTenant($club, fn () => app(OnlinePaymentService::class)->start($invoice->fresh(), 'whatsapp'));
        $obj = $this->signedObj($intent, 918274, 46011);
        $this->postJson(route('webhooks.paymob').'?hmac=forged', ['type' => 'TRANSACTION', 'obj' => $obj]);
        $this->postJson(route('webhooks.paymob').'?hmac='.PaymobSignature::compute($obj, $secrets[2]), ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
        $this->assertSame(1, $this->asTenant($club, fn () => Payment::where('gateway', 'paymob')->count()));

        // The secret key and the HMAC secret are nowhere, the callback log and the checkout records included. The public
        // key is in the checkout page's address by Paymob's design (unifiedcheckout/?publicKey=…), and the numbers in
        // Paymob's own callbacks; but the club's copies of both stay encrypted: not in the logs, the audit or the settings.
        $plain = implode("\n", [...$logged, DB::table('audit_logs')->get()->toJson(), DB::table('tenant_integrations')->select('settings', 'last_error')->get()->toJson()]);
        $everything = $plain."\n".DB::table('payment_webhook_events')->get()->toJson()."\n".DB::table('payment_intents')->get()->toJson();
        foreach ([$secrets[0], $secrets[2]] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }
        foreach ([$secrets[1], '918273', '918274'] as $value) {
            $this->assertStringNotContainsString($value, $plain);
        }
        $this->assertNotEmpty(DB::table('audit_logs')->get(), 'the saves were audited, without their values');
    }

    public function test_the_ladder_saved_checked_enabled_available_and_a_failed_check(): void
    {
        $club = $this->club();
        $this->paymob($club, ['card' => 111]);
        $this->assertSame(PaymobMethods::SAVED, $this->states($club)['card']['state']);

        Http::fake(['*/v1/intention/' => Http::sequence()->push(['detail' => 'Integration ID/Name does not exist in our system'], 404)->push(['client_secret' => 'cs', 'intention_order_id' => 1], 201)]);
        $this->actingAs($this->owner($club))->post(route('club.integrations.verify', 'paymob'))->assertSessionHas('error');
        $this->assertSame(PaymobMethods::SAVED, $this->states($club)['card']['state'], 'a refused check proves nothing');
        $this->assertNotNull($this->row($club)->last_error);

        $this->actingAs($this->owner($club))->post(route('club.integrations.verify', 'paymob'))->assertSessionHas('success');
        Http::assertSent(fn ($r) => $r['payment_methods'] === [111]);
        $this->assertSame(PaymobMethods::CHECKED, $this->states($club)['card']['state']);

        $row = $this->row($club);
        $this->event($row, 'failed_payment', 111);
        $this->assertSame(PaymobMethods::ENABLED, $this->states($club)['card']['state'], 'Paymob ran a payment on it: enabled, not yet available');
        $this->event($row, 'processed', 111, 'Visa', false);
        $this->assertSame(PaymobMethods::ENABLED, $this->states($club)['card']['state'], 'an unsigned callback proves nothing');
        $this->event($row, 'processed', 111);
        $this->assertSame(PaymobMethods::AVAILABLE, $this->states($club)['card']['state']);

        // Online payment switched off: proven, but not available now.
        $this->asTenant($club, fn () => TenantIntegration::where('provider', 'paymob')->update(['is_enabled' => false]));
        $this->assertSame(PaymobMethods::ENABLED, $this->states($club)['card']['state']);
    }

    public function test_a_new_number_drops_the_earlier_check(): void
    {
        $club = $this->club();
        $this->paymob($club, ['card' => 111], ['verified_at' => now()]);
        $this->assertSame(PaymobMethods::CHECKED, $this->states($club)['card']['state']);

        $this->actingAs($this->owner($club))->put(route('club.integrations.paymob.methods'), ['card' => '112'])->assertSessionHasNoErrors();
        $this->assertSame(PaymobMethods::SAVED, $this->states($club)['card']['state']);
        $this->assertNull($this->row($club)->verified_at);
    }

    public function test_omannet_without_a_number_of_its_own_goes_with_the_cards_and_only_a_signed_omannet_payment_proves_it(): void
    {
        $club = $this->club();
        $row = $this->paymob($club, ['card' => 111], ['verified_at' => now()]);
        $s = $this->states($club);
        $this->assertSame(PaymobMethods::SAVED, $s['omannet']['state']);
        $this->assertTrue($s['omannet']['within_cards']);
        $this->assertFalse($s['omannet']['saved']);

        $this->event($row, 'processed', 111, 'MasterCard');
        $this->event($row, 'processed', 111, 'OmanNet', false);
        $this->event($row, 'processed', 222, 'OmanNet');
        $this->assertSame(PaymobMethods::SAVED, $this->states($club)['omannet']['state']);
        $this->event($row, 'processed', 111, 'OMAN-NET');
        $this->assertSame(PaymobMethods::AVAILABLE, $this->states($club)['omannet']['state']);
    }

    public function test_numbers_are_not_changed_while_a_checkouts_money_may_still_arrive_and_its_late_payment_is_kept(): void
    {
        $club = $this->club();
        $this->paymob($club, ['card' => 111]);
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs', 'intention_order_id' => 4601], 201)]);
        $intent = $this->asTenant($club, fn () => app(OnlinePaymentService::class)->start($this->invoice($club), 'whatsapp'));

        $this->actingAs($this->owner($club))->put(route('club.integrations.paymob.methods'), ['card' => '112'])->assertSessionHas('error');
        $this->assertSame(111, PaymobClient::methodIds($this->row($club))['card']);

        // Its money still arrives through the number it was opened with.
        $this->assertSame('processed', $this->deliver($club, $this->signedObj($intent, 111, 9)));
    }

    public function test_clubs_keep_their_own_numbers_and_proofs(): void
    {
        $a = $this->club();
        $b = $this->club('B');
        $rowA = $this->paymob($a, ['card' => 111]);
        $this->paymob($b, ['card' => 333]);
        $this->event($rowA, 'processed', 333);

        $this->assertSame(111, PaymobClient::methodIds($this->row($a))['card']);
        $this->assertSame(333, PaymobClient::methodIds($this->row($b))['card']);
        $this->assertSame(PaymobMethods::SAVED, $this->states($b)['card']['state'], 'another club\'s callback proves nothing here');

        $this->actingAs($this->owner($b))->put(route('club.integrations.paymob.methods'), ['card' => '334'])->assertSessionHasNoErrors();
        $this->assertSame(111, PaymobClient::methodIds($this->row($a))['card']);
    }

    public function test_the_older_list_alone_still_takes_payments(): void
    {
        $club = $this->club();
        $this->paymob($club, [], ['credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC, 'integration_ids' => '111, 222']]);

        $this->assertTrue(PaymobClient::ready($this->row($club)));
        $this->assertSame([111, 222], PaymobClient::checkoutIds($this->row($club)));
        $this->assertSame(PaymobMethods::NOT_SET, $this->states($club)['card']['state']);
    }

    public function test_no_number_at_all_takes_no_payment(): void
    {
        $club = $this->club();
        $this->paymob($club);

        $this->assertFalse(PaymobClient::ready($this->row($club)));
        $this->assertFalse($this->asTenant($club, fn () => app(OnlinePaymentService::class)->available()));
    }

    public function test_staff_without_integrations_manage_cannot_change_numbers(): void
    {
        $club = $this->club();
        $this->paymob($club);

        $this->actingAs($this->staff($club, 'manager'))->put(route('club.integrations.paymob.methods'), ['card' => '111'])->assertForbidden();
    }
}
