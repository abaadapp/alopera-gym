<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Payments\Events\OnlinePaymentFailed;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Test and live Paymob money never mix on the production server: a test account opens no checkout there, and a signed
 * callback moves money there only for a live checkout (PL-) of an account that is live now. Off production a test
 * account still runs the whole flow. Paymob is faked; nothing here proves Paymob's real behaviour or its key format.
 */
class PaymobTestModeIsolationTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const HMAC = 'isolation-hmac-secret';

    private const LIVE_KEYS = ['secret_key' => 'omn_sk_live_isolation', 'public_key' => 'omn_pk_live_isolation'];

    private const TEST_KEYS = ['secret_key' => 'omn_sk_test_isolation', 'public_key' => 'omn_pk_test_isolation'];

    private int $nextOrder = 7100;

    /** Runs while the faked Paymob answers a checkout, when a test needs something to happen at that moment. */
    private ?\Closure $whileOpening = null;

    /** Statements starting with this fail, as a database failing at that point would. */
    private ?string $failOn = null;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['*/v1/intention/' => function () {
            if ($this->whileOpening) {
                ($this->whileOpening)();
            }

            return Http::response(['client_secret' => 'cs_isolation', 'intention_order_id' => $this->nextOrder++], 201);
        }]);
        DB::beforeExecuting(function (string $sql) {
            if ($this->failOn !== null && str_starts_with($sql, $this->failOn)) {
                throw new \PDOException('SQLSTATE[XX000]: simulated database failure on: '.$this->failOn);
            }
        });
    }

    private function onProduction(): void
    {
        $this->app->detectEnvironment(fn () => 'production');
        $this->assertTrue(app()->isProduction());
    }

    /** @param  array<string, string>  $keys */
    private function paymobClub(string $mode, array $keys): Tenant
    {
        $club = $this->club();
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => $mode,
            'credentials' => $keys + ['hmac_secret' => self::HMAC, 'integration_ids' => '111'],
        ]));

        return $club;
    }

    private function account(Tenant $club, array $attributes): void
    {
        $this->asTenant($club, fn () => TenantIntegration::where('provider', 'paymob')->firstOrFail()->forceFill($attributes)->save());
    }

    /** @return array{0: Membership, 1: PaymentIntent} a membership waiting for its online payment */
    private function pending(Tenant $club): array
    {
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 25]);

        return $this->asTenant($club, function () use ($member, $plan) {
            $membership = app(MembershipService::class)->sell($member, $plan);

            return [$membership, app(OnlinePaymentService::class)->start($membership->invoice, 'portal')];
        });
    }

    /** @return array<string, mixed> a successful sale of the intent's amount, signed fields as Paymob sends them */
    private function obj(PaymentIntent $intent, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 558001, 'pending' => false, 'amount_cents' => Money::toMinor($intent->amount), 'success' => true,
            'is_auth' => false, 'is_capture' => false, 'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false,
            'is_3d_secure' => true, 'integration_id' => 111, 'has_parent_transaction' => false,
            'order' => ['id' => (int) $intent->provider_order_id, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-11-14T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
        ], $overrides);
    }

    private function send(array $obj)
    {
        $this->app->forgetScopedInstances();

        return $this->postJson(route('webhooks.paymob').'?hmac='.PaymobSignature::compute($obj, self::HMAC), ['type' => 'TRANSACTION', 'obj' => $obj]);
    }

    private function deliver(array $obj): string
    {
        return $this->send($obj)->assertOk()->json('outcome');
    }

    private function assertNoMoneyMoved(Tenant $club, Membership $membership, PaymentIntent $intent, string $intentStatus = PaymentIntent::PENDING): void
    {
        $this->asTenant($club, function () use ($membership, $intent, $intentStatus) {
            $this->assertSame(0, Payment::count(), 'no payment of any kind');
            $this->assertSame(Invoice::ISSUED, Invoice::findOrFail($membership->invoice_id)->status, 'the invoice is not paid');
            $this->assertSame(Membership::PENDING, $membership->fresh()->status, 'the membership is not activated');
            $this->assertSame($intentStatus, $intent->fresh()->status);
            $this->assertNull($intent->fresh()->payment_id);
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'));
        });
    }

    // ── a test account on the production server ───────────────────────────────────────────────────────────────────

    public function test_a_test_account_opens_no_checkout_on_production_through_any_entry(): void
    {
        $club = $this->paymobClub('test', self::TEST_KEYS);
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 25]);
        $this->onProduction();

        $this->asTenant($club, function () use ($member, $plan) {
            $online = app(OnlinePaymentService::class);
            $this->assertFalse($online->available(), 'every screen and link asks this: no online payment offered');
            $membership = app(MembershipService::class)->sell($member, $plan);
            try {
                $online->start($membership->invoice, 'portal');
                $this->fail('a test account opened a checkout for a real invoice');
            } catch (BusinessRuleException $e) {
                $this->assertSame('paymob.test_mode', $e->rule);
            }
            $this->assertSame(0, PaymentIntent::count());
        });
        Http::assertNothingSent();

        // The Paymob screen says so: no channel takes payments, and why.
        $this->actingAs($this->owner($club))->get(route('club.integrations.paymob'))->assertInertia(fn ($page) => $page
            ->where('usage', fn ($usage) => collect($usage)->every(fn ($u) => $u['on'] === false))
            ->where('warning', fn ($warning) => str_contains((string) $warning, 'نظام الإنتاج')));
    }

    public function test_live_mode_is_not_taken_on_the_word_of_the_mode_alone(): void
    {
        foreach ([self::TEST_KEYS, ['secret_key' => 'omn_sk_live_x', 'public_key' => 'omn_pk_test_x'], ['secret_key' => 'sk_unknown', 'public_key' => 'pk_unknown']] as $keys) {
            $club = $this->paymobClub('live', $keys);
            $this->onProduction();
            $this->asTenant($club, fn () => $this->assertFalse(app(OnlinePaymentService::class)->available(), json_encode($keys)));
        }
        Http::assertNothingSent();
    }

    public function test_a_signed_test_payment_on_production_moves_no_money_pays_no_invoice_and_activates_nothing(): void
    {
        $club = $this->paymobClub('test', self::TEST_KEYS);
        [$membership, $intent] = $this->pending($club); // opened off production: a test checkout
        $this->assertStringStartsWith(OnlinePaymentService::TEST_PREFIX, $intent->reference);
        $this->onProduction();

        $this->assertSame('test_payment', $this->deliver($this->obj($intent)));
        $this->assertSame('test_payment', $this->deliver($this->obj($intent)), 'delivered again');
        $this->assertNoMoneyMoved($club, $membership, $intent);

        // Kept for review: the signed callback in full, never as money.
        $event = $this->asTenant($club, fn () => PaymentWebhookEvent::latest('id')->firstOrFail());
        $this->assertTrue($event->signature_valid);
        $this->assertSame(558001, $event->payload['obj']['id']);
    }

    public function test_a_test_decline_on_production_neither_fails_the_attempt_nor_tells_the_customer(): void
    {
        Event::fake([OnlinePaymentFailed::class]);
        $club = $this->paymobClub('test', self::TEST_KEYS);
        [$membership, $intent] = $this->pending($club);
        $this->onProduction();

        $this->assertSame('test_payment', $this->deliver($this->obj($intent, ['success' => false])));
        $this->assertNoMoneyMoved($club, $membership, $intent);
        Event::assertNotDispatched(OnlinePaymentFailed::class);
    }

    public function test_a_test_checkout_is_not_paid_after_the_account_turns_live(): void
    {
        $club = $this->paymobClub('test', self::TEST_KEYS);
        [$membership, $intent] = $this->pending($club);
        $this->account($club, ['mode' => 'live', 'credentials' => self::LIVE_KEYS + ['hmac_secret' => self::HMAC, 'integration_ids' => '111']]);
        $this->onProduction();

        $this->assertSame('test_payment', $this->deliver($this->obj($intent)), 'the checkout was opened in test: the account now being live does not make it real');
        $this->assertNoMoneyMoved($club, $membership, $intent);
    }

    // ── a live account on the production server ───────────────────────────────────────────────────────────────────

    public function test_a_live_payment_on_production_is_recorded_once_as_before(): void
    {
        $club = $this->paymobClub('live', self::LIVE_KEYS);
        $this->onProduction();
        [$membership, $intent] = $this->pending($club);
        $this->assertStringStartsWith(OnlinePaymentService::LIVE_PREFIX, $intent->reference);

        $this->assertSame('processed', $this->deliver($this->obj($intent, ['is_live' => true])));
        $this->assertSame('duplicate', $this->deliver($this->obj($intent, ['is_live' => true])), 'delivered again: not twice');
        $this->asTenant($club, function () use ($membership, $intent) {
            $payment = Payment::where('gateway', 'paymob')->sole();
            $this->assertSame('25.000', $payment->amount);
            $this->assertSame('0.000', $payment->credit_amount);
            $this->assertSame(Invoice::PAID, Invoice::findOrFail($membership->invoice_id)->status);
            $this->assertSame(Membership::ACTIVE, $membership->fresh()->status);
            $this->assertSame(PaymentIntent::PAID, $intent->fresh()->status);
        });
    }

    public function test_a_callback_saying_it_is_not_live_moves_no_money_even_for_a_live_checkout(): void
    {
        $club = $this->paymobClub('live', self::LIVE_KEYS);
        $this->onProduction();
        [$membership, $intent] = $this->pending($club);

        $this->assertSame('test_payment', $this->deliver($this->obj($intent, ['is_live' => false])));
        $this->assertSame('test_payment', $this->deliver($this->obj($intent, ['id' => 558002, 'is_live' => 'false'])));
        $this->assertNoMoneyMoved($club, $membership, $intent);

        $this->assertSame('processed', $this->deliver($this->obj($intent, ['id' => 558003])), 'a live one still is');
    }

    public function test_a_live_checkout_is_not_paid_while_the_account_is_no_longer_live(): void
    {
        $club = $this->paymobClub('live', self::LIVE_KEYS);
        $this->onProduction();
        [$membership, $intent] = $this->pending($club);
        $this->account($club, ['mode' => 'test']);

        $this->assertSame('test_payment', $this->deliver($this->obj($intent)));
        $this->assertNoMoneyMoved($club, $membership, $intent);
    }

    public function test_a_checkout_from_before_the_environment_mark_is_not_taken_for_live(): void
    {
        $club = $this->paymobClub('live', self::LIVE_KEYS);
        $this->onProduction();
        [$membership, $intent] = $this->pending($club);
        $this->asTenant($club, fn () => $intent->forceFill(['reference' => 'PI-'.substr($intent->reference, 3)])->save());
        $intent->refresh();

        $this->assertSame('environment_unverified', $this->deliver($this->obj($intent)));
        $this->assertNoMoneyMoved($club, $membership, $intent);
    }

    public function test_the_database_failing_on_production_is_answered_500_and_the_resend_records_once(): void
    {
        $club = $this->paymobClub('live', self::LIVE_KEYS);
        $this->onProduction();
        [$membership, $intent] = $this->pending($club);

        $this->failOn = 'insert into "payments"';
        $this->send($this->obj($intent))->assertStatus(500);
        $this->failOn = null;
        $this->asTenant($club, fn () => $this->assertSame(0, Payment::count(), 'nothing recorded, never a success'));

        $this->assertSame('processed', $this->deliver($this->obj($intent)));
        $this->assertSame('duplicate', $this->deliver($this->obj($intent)));
        $this->asTenant($club, function () use ($membership) {
            $this->assertSame(1, Payment::count());
            $this->assertSame(Membership::ACTIVE, $membership->fresh()->status);
        });
    }

    // ── the account's settings under open checkouts ───────────────────────────────────────────────────────────────

    public function test_mode_keys_and_integrations_are_not_changed_under_an_open_checkout(): void
    {
        $club = $this->paymobClub('live', self::LIVE_KEYS);
        $owner = $this->owner($club);
        [, $intent] = $this->pending($club);
        $save = fn (array $change) => $this->actingAs($owner)->put(route('club.integrations.update', 'paymob'),
            array_replace_recursive(['is_enabled' => true, 'mode' => 'live', 'credentials' => []], $change));

        foreach ([['mode' => 'test'], ['credentials' => ['secret_key' => 'omn_sk_test_other']], ['credentials' => ['public_key' => 'omn_pk_test_other']],
            ['credentials' => ['hmac_secret' => 'other']], ['credentials' => ['integration_ids' => '111, 333']]] as $change) {
            $save($change)->assertSessionHas('error');
            $this->asTenant($club, function () use ($change) {
                $row = TenantIntegration::where('provider', 'paymob')->firstOrFail();
                $this->assertSame('live', $row->mode, json_encode($change));
                $this->assertSame(self::LIVE_KEYS['secret_key'], $row->credential('secret_key'));
                $this->assertSame(self::LIVE_KEYS['public_key'], $row->credential('public_key'));
                $this->assertSame(self::HMAC, $row->credential('hmac_secret'));
                $this->assertSame('111', $row->credential('integration_ids'));
            });
        }

        // What does not change how a callback is read stays possible: switching online payment off, the same values.
        $save(['is_enabled' => false])->assertSessionHas('success');
        $this->asTenant($club, fn () => $this->assertFalse(TenantIntegration::where('provider', 'paymob')->firstOrFail()->is_enabled));

        // Once the checkout has ended and its late money had time to arrive, the account can change.
        $this->travelTo($intent->expires_at->copy()->addMinutes(OnlinePaymentService::LATE_CALLBACK_MINUTES + 1));
        $save(['mode' => 'test'])->assertSessionHas('success');
        $this->asTenant($club, fn () => $this->assertSame('test', TenantIntegration::where('provider', 'paymob')->firstOrFail()->mode));
    }

    public function test_the_account_changing_while_paymob_opens_the_checkout_hands_out_no_page(): void
    {
        $club = $this->paymobClub('live', self::LIVE_KEYS);
        $this->whileOpening = fn () => $this->account($club, ['mode' => 'test']); // switched while Paymob was answering
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 25]);

        $this->asTenant($club, function () use ($member, $plan) {
            $membership = app(MembershipService::class)->sell($member, $plan);
            try {
                app(OnlinePaymentService::class)->start($membership->invoice, 'portal');
                $this->fail('a checkout opened under other settings was handed out');
            } catch (BusinessRuleException $e) {
                $this->assertSame('paymob.mode_changed', $e->rule);
            }
            $intent = PaymentIntent::sole();
            $this->assertSame(PaymentIntent::FAILED, $intent->status);
            $this->assertNull($intent->checkout_url);
        });
    }

    // ── what does not change ──────────────────────────────────────────────────────────────────────────────────────

    public function test_keys_saved_with_online_payment_switched_off_open_no_checkout_anywhere(): void
    {
        $club = $this->paymobClub('live', self::LIVE_KEYS);
        $this->account($club, ['is_enabled' => false]);
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 25]);
        $membership = $this->asTenant($club, fn () => app(MembershipService::class)->sell($member, $plan));

        foreach (['testing', 'production'] as $env) {
            $this->app->detectEnvironment(fn () => $env);
            $this->asTenant($club, function () use ($membership, $env) {
                $online = app(OnlinePaymentService::class);
                $this->assertFalse($online->available(), $env);
                try {
                    $online->start($membership->invoice, 'portal');
                    $this->fail('a switched-off account opened a checkout ('.$env.')');
                } catch (BusinessRuleException $e) {
                    $this->assertSame('paymob.unavailable', $e->rule, $env);
                }
                $this->assertSame(0, PaymentIntent::count(), $env);
            });
        }
        Http::assertNothingSent();
    }

    public function test_switching_online_payment_off_does_not_lose_the_money_of_a_live_checkout_already_open(): void
    {
        $club = $this->paymobClub('live', self::LIVE_KEYS);
        $this->onProduction();
        [$membership, $intent] = $this->pending($club);
        $this->account($club, ['is_enabled' => false]); // allowed under an open checkout: it changes how nothing is read

        $this->assertSame('processed', $this->deliver($this->obj($intent)), 'the customer already paid: recorded');
        $this->asTenant($club, fn () => $this->assertSame(Membership::ACTIVE, $membership->fresh()->status));
    }

    public function test_off_production_a_test_account_still_runs_the_whole_flow(): void
    {
        $club = $this->paymobClub('test', self::TEST_KEYS);
        [$membership, $intent] = $this->pending($club);
        $this->assertFalse(app()->isProduction());

        $this->assertSame('processed', $this->deliver($this->obj($intent, ['is_live' => false])));
        $this->asTenant($club, fn () => $this->assertSame(Membership::ACTIVE, $membership->fresh()->status));
    }

    public function test_cash_and_bank_payments_are_untouched_by_a_test_account_on_production(): void
    {
        $club = $this->paymobClub('test', self::TEST_KEYS);
        $this->onProduction();
        $byCash = $this->member($club);
        $byBank = $this->member($club);
        $plan = $this->plan($club, ['price' => 25]);

        $this->asTenant($club, function () use ($byCash, $byBank, $plan) {
            foreach ([[$byCash, 'cash'], [$byBank, 'bank_transfer']] as [$member, $method]) {
                $membership = app(MembershipService::class)->sell($member, $plan);
                $payment = app(PaymentService::class)->record($membership->invoice, '25.000', $method, ['reference' => 'DESK-'.$method]);
                if ($method === 'bank_transfer') {
                    app(PaymentService::class)->confirm($payment); // finance sees it on the statement, as always
                }
                $this->assertSame(Invoice::PAID, $membership->invoice->fresh()->status, $method);
                $this->assertSame(Membership::ACTIVE, $membership->fresh()->status, $method);
            }
            $this->assertSame(['bank_transfer', 'cash'], Payment::orderBy('method')->pluck('method')->all());
        });
    }
}
