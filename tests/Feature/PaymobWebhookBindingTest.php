<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A signed Paymob callback is money for an intent only when it is bound to it by what Paymob signs: one of the club's
 * own integrations, the intent's Paymob order, an amount actually captured (never an authorization alone), and a
 * transaction not already in the books for another payment. Paymob is faked; nothing here proves Paymob's real behaviour.
 */
class PaymobWebhookBindingTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const HMAC = 'binding-hmac-secret';

    /** @var list<int|null> the Paymob order the faked intention API gives each checkout, in turn (null: none given) */
    private array $orderIds = [];

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Http::fake(['*/v1/intention/' => function () {
            $order = array_shift($this->orderIds);

            return Http::response(['client_secret' => 'cs_binding'] + ($order !== null ? ['intention_order_id' => $order] : []), 201);
        }]);
    }

    private function paymobClub(): Tenant
    {
        $club = $this->club();
        // Two integrations of the club's own account, e.g. cards and Apple Pay.
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC, 'integration_ids' => '111, 222'],
        ]));

        return $club;
    }

    /** @return array{0: Membership, 1: PaymentIntent} */
    private function pending(Tenant $club, ?int $paymobOrder): array
    {
        $this->orderIds[] = $paymobOrder;
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
        $order = array_filter(['id' => $intent->provider_order_id !== null ? (int) $intent->provider_order_id : null, 'merchant_order_id' => $intent->reference]);

        return array_replace_recursive([
            'id' => 555001, 'pending' => false, 'amount_cents' => Money::toMinor($intent->amount), 'success' => true,
            'is_auth' => false, 'is_capture' => false, 'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false,
            'is_3d_secure' => true, 'integration_id' => 111, 'has_parent_transaction' => false, 'order' => $order,
            'created_at' => '2026-11-14T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
        ], $overrides);
    }

    private function deliver(array $obj): string
    {
        return $this->postJson(route('webhooks.paymob').'?hmac='.PaymobSignature::compute($obj, self::HMAC), ['type' => 'TRANSACTION', 'obj' => $obj])
            ->assertOk()->json('outcome');
    }

    private function assertNothingRecorded(Tenant $club, Membership $membership, PaymentIntent $intent): void
    {
        $this->asTenant($club, function () use ($membership, $intent) {
            $this->assertSame(0, Payment::where('gateway', 'paymob')->where('invoice_id', $membership->invoice_id)->count(), 'no money on this invoice');
            $this->assertSame(Membership::PENDING, $membership->fresh()->status, 'the membership is not activated');
            $this->assertSame(PaymentIntent::PENDING, $intent->fresh()->status, 'the attempt is neither paid nor failed');
        });
    }

    // ── integration_id ────────────────────────────────────────────────────────────────────────────────────────────

    public function test_a_payment_through_an_integration_the_club_did_not_set_is_not_recorded(): void
    {
        $club = $this->paymobClub();
        [$membership, $intent] = $this->pending($club, 4801);

        $this->assertSame('integration_mismatch', $this->deliver($this->obj($intent, ['integration_id' => 999])));
        $this->assertNothingRecorded($club, $membership, $intent);
    }

    public function test_a_callback_naming_no_integration_is_not_recorded(): void
    {
        $club = $this->paymobClub();
        [$membership, $intent] = $this->pending($club, 4801);
        $obj = $this->obj($intent);
        unset($obj['integration_id']);

        $this->assertSame('integration_mismatch', $this->deliver($obj));
        $this->assertNothingRecorded($club, $membership, $intent);
    }

    public function test_a_payment_through_any_of_the_clubs_integrations_is_recorded(): void
    {
        $club = $this->paymobClub();
        [$membership, $intent] = $this->pending($club, 4801);

        $this->assertSame('processed', $this->deliver($this->obj($intent, ['integration_id' => 222])));
        $this->asTenant($club, fn () => $this->assertSame(Membership::ACTIVE, $membership->fresh()->status));
    }

    public function test_a_decline_from_another_integration_does_not_fail_the_attempt(): void
    {
        $club = $this->paymobClub();
        [$membership, $intent] = $this->pending($club, 4801);

        $this->assertSame('integration_mismatch', $this->deliver($this->obj($intent, ['integration_id' => 999, 'success' => false])));
        $this->assertNothingRecorded($club, $membership, $intent);

        $this->assertSame('processed', $this->deliver($this->obj($intent, ['id' => 555002])));
        $this->asTenant($club, fn () => $this->assertSame(Membership::ACTIVE, $membership->fresh()->status));
    }

    // ── authorization and capture ─────────────────────────────────────────────────────────────────────────────────

    public function test_an_authorization_alone_is_not_money(): void
    {
        $club = $this->paymobClub();
        [$membership, $intent] = $this->pending($club, 4801);

        $this->assertSame('authorized_only', $this->deliver($this->obj($intent, ['is_auth' => true, 'is_standalone_payment' => false])));
        $this->assertNothingRecorded($club, $membership, $intent);
    }

    public function test_an_authorization_sent_with_text_flags_is_still_an_authorization(): void
    {
        $club = $this->paymobClub();
        [$membership, $intent] = $this->pending($club, 4801);

        $this->assertSame('authorized_only', $this->deliver($this->obj($intent, ['is_auth' => 'true', 'is_capture' => 'false'])));
        $this->assertNothingRecorded($club, $membership, $intent);
    }

    public function test_authorization_then_capture_is_the_money_once_and_no_credit(): void
    {
        $club = $this->paymobClub();
        [$membership, $intent] = $this->pending($club, 4801);
        $auth = $this->obj($intent, ['id' => 555101, 'is_auth' => true, 'is_standalone_payment' => false]);
        $capture = $this->obj($intent, ['id' => 555102, 'is_capture' => true, 'has_parent_transaction' => true, 'is_standalone_payment' => false]);

        $this->assertSame('authorized_only', $this->deliver($auth));
        $this->assertSame('processed', $this->deliver($capture));
        $this->assertSame('duplicate', $this->deliver($capture), 'the capture delivered again');
        $this->assertSame('authorized_only', $this->deliver($auth), 'the authorization delivered again');

        $this->asTenant($club, function () use ($membership, $intent) {
            $payment = Payment::where('gateway', 'paymob')->sole();
            $this->assertSame('555102', $payment->gateway_transaction_id, 'the captured transaction is the payment');
            $this->assertSame('25.000', $payment->amount);
            $this->assertSame('0.000', $payment->credit_amount, 'no second count as credit');
            $this->assertSame(Invoice::PAID, Invoice::find($membership->invoice_id)->status);
            $this->assertSame(Membership::ACTIVE, $membership->fresh()->status);
            $this->assertSame(PaymentIntent::PAID, $intent->fresh()->status);
        });
    }

    public function test_a_capture_that_also_carries_the_authorization_flag_is_money_once(): void
    {
        $club = $this->paymobClub();
        [$membership, $intent] = $this->pending($club, 4801);
        $capture = $this->obj($intent, ['id' => 555103, 'is_auth' => true, 'is_capture' => true, 'has_parent_transaction' => true]);

        $this->assertSame('processed', $this->deliver($capture));
        $this->assertSame('duplicate', $this->deliver($capture));
        $this->asTenant($club, fn () => $this->assertSame(1, Payment::where('gateway', 'paymob')->count()));
    }

    // ── the intent's Paymob order ─────────────────────────────────────────────────────────────────────────────────

    public function test_a_checkout_paymob_opens_without_an_order_is_never_offered(): void
    {
        $club = $this->paymobClub();
        $this->orderIds[] = null;
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 25]);
        $membership = $this->asTenant($club, fn () => app(MembershipService::class)->sell($member, $plan));

        try {
            $this->asTenant($club, fn () => app(OnlinePaymentService::class)->start($membership->invoice, 'portal'));
            $this->fail('a checkout without a Paymob order was offered');
        } catch (BusinessRuleException $e) {
            $this->assertSame('paymob.no_order', $e->rule);
        }

        $intent = $this->asTenant($club, fn () => PaymentIntent::where('invoice_id', $membership->invoice_id)->sole());
        $this->assertSame(PaymentIntent::FAILED, $intent->status);
        $this->assertNull($intent->checkout_url, 'no payment page handed out');
        $this->assertNull($intent->provider_order_id);

        // Were it paid anyway, nothing signed names it: no callback is its money.
        $this->assertSame('order_unverified', $this->deliver($this->obj($intent, ['order' => ['id' => 7001]])));
        $this->asTenant($club, function () use ($membership) {
            $this->assertSame(0, Payment::where('gateway', 'paymob')->count());
            $this->assertSame(Membership::PENDING, $membership->fresh()->status);
        });
    }

    public function test_an_intent_without_a_paymob_order_takes_no_callback_not_even_the_first(): void
    {
        $club = $this->paymobClub();
        [$membership, $intent] = $this->pending($club, 4801);
        // An intent left from before, or written by hand, with no Paymob order.
        $this->asTenant($club, fn () => $intent->forceFill(['provider_order_id' => null])->save());

        $this->assertSame('order_unverified', $this->deliver($this->obj($intent, ['order' => ['id' => 7001]])), 'the first signed order is not taken');
        $this->assertSame('order_unverified', $this->deliver($this->obj($intent, ['id' => 555002, 'order' => ['id' => 7002]])));
        $this->assertSame('order_unverified', $this->deliver($this->obj($intent)), 'nor a callback naming none');

        $this->assertNothingRecorded($club, $membership, $intent);
        $this->asTenant($club, fn () => $this->assertNull($intent->fresh()->provider_order_id, 'nothing bound'));
    }

    public function test_another_intents_signed_order_is_not_let_onto_an_intent(): void
    {
        $club = $this->paymobClub();
        [$paid, $a] = $this->pending($club, 4801);
        [$other, $b] = $this->pending($club, 4802);
        $aObj = $this->obj($a, ['id' => 555201]);

        // A's signed payment, its unsigned reference rewritten to name B.
        $this->assertSame('order_mismatch', $this->deliver(array_replace_recursive($aObj, ['order' => ['merchant_order_id' => $b->reference]])));
        $this->assertNothingRecorded($club, $other, $b);

        $this->assertSame('processed', $this->deliver($aObj));
        $this->asTenant($club, fn () => $this->assertSame(Membership::ACTIVE, $paid->fresh()->status));
    }

    public function test_a_transaction_in_the_books_for_another_payment_never_marks_an_intent_paid(): void
    {
        $club = $this->paymobClub();
        [, $a] = $this->pending($club, 4801);
        [$other, $b] = $this->pending($club, 4802);

        $this->assertSame('processed', $this->deliver($this->obj($a, ['id' => 555301])));
        // The same transaction, signed with B's order, naming B.
        $this->assertSame('transaction_elsewhere', $this->deliver($this->obj($b, ['id' => 555301])));

        $this->assertNothingRecorded($club, $other, $b);
        $this->asTenant($club, function () use ($b) {
            $this->assertNull($b->fresh()->payment_id);
            $this->assertSame(1, Payment::where('gateway', 'paymob')->count());
        });
    }
}
