<?php

namespace Tests\Feature;

use App\Core\Support\Money;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * Signed Paymob callbacks that really run at the same time on PostgreSQL (one process each): a capture delivered twice,
 * two orders naming one intent, an intent with no Paymob order, and an order changed while a callback waits for the
 * intent's lock. Paymob is faked.
 */
class PaymobWebhookBindingConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    private const HMAC = 'binding-hmac-secret';

    private const LOCK_INTENT = 'select * from "payment_intents" where "payment_intents"."id" = ? and "payment_intents"."tenant_id" = ? limit 1 for update';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    private function paymobClub(): Tenant
    {
        $club = $this->club();
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC, 'integration_ids' => '111'],
        ]));

        return $club;
    }

    /** @return array{0: Membership, 1: PaymentIntent} */
    private function pending(Tenant $club): array
    {
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 25]);

        return $this->asTenant($club, function () use ($member, $plan) {
            $membership = app(MembershipService::class)->sell($member, $plan);

            return [$membership, app(OnlinePaymentService::class)->start($membership->invoice, 'portal')];
        });
    }

    /** @return array<string, mixed> */
    private function obj(PaymentIntent $intent, int $order, array $overrides = []): array
    {
        return array_replace_recursive([
            'id' => 556001, 'pending' => false, 'amount_cents' => Money::toMinor($intent->amount), 'success' => true,
            'is_auth' => false, 'is_capture' => false, 'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false,
            'is_3d_secure' => true, 'integration_id' => 111, 'has_parent_transaction' => false,
            'order' => ['id' => $order, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-11-14T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
        ], $overrides);
    }

    private static function deliver(array $obj): string
    {
        return app(OnlinePaymentService::class)->handleWebhook(['type' => 'TRANSACTION', 'obj' => $obj], PaymobSignature::compute($obj, self::HMAC));
    }

    public function test_the_same_capture_twice_at_once_records_the_money_once(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_capture', 'intention_order_id' => 4810], 201)]);
        $club = $this->paymobClub();
        [$membership, $intent] = $this->pending($club);
        $capture = $this->obj($intent, 4810, ['id' => 556101, 'is_capture' => true, 'has_parent_transaction' => true, 'is_standalone_payment' => false]);

        $results = $this->concurrently([
            function (string $dir) use ($capture) {
                self::pauseAfter(self::LOCK_INTENT, $dir, 0.8);

                return self::deliver($capture);
            },
            function (string $dir) use ($capture) {
                self::waitFor("$dir/inside");
                if (! is_file("$dir/inside")) {
                    return 'not at the same time';
                }

                return self::deliver($capture);
            },
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertEqualsCanonicalizing(['processed', 'duplicate'], array_column($results, 'value'), json_encode($results));
        $this->asTenant($club, function () use ($membership) {
            $payment = Payment::where('gateway', 'paymob')->sole();
            $this->assertSame('0.000', $payment->credit_amount);
            $this->assertSame(Membership::ACTIVE, $membership->fresh()->status);
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'));
        });
    }

    public function test_two_orders_naming_one_intent_at_once_record_only_its_own_order(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_two_orders', 'intention_order_id' => 4950], 201)]);
        $club = $this->paymobClub();
        [$membership, $intent] = $this->pending($club);
        $own = $this->obj($intent, 4950, ['id' => 556201]);
        // Another order's signed payment of the same amount, its unsigned reference naming this intent.
        $other = $this->obj($intent, 4951, ['id' => 556202]);

        $results = $this->concurrently([
            function (string $dir) use ($own) {
                self::pauseAfter(self::LOCK_INTENT, $dir, 0.8);

                return self::deliver($own);
            },
            function (string $dir) use ($other) {
                self::waitFor("$dir/inside");
                if (! is_file("$dir/inside")) {
                    return 'not at the same time';
                }

                return self::deliver($other);
            },
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertSame(['processed', 'order_mismatch'], array_column($results, 'value'), json_encode($results));
        $this->asTenant($club, function () use ($membership, $intent) {
            $payment = Payment::where('gateway', 'paymob')->sole();
            $this->assertSame('556201', $payment->gateway_transaction_id, 'only the intent\'s own order is its money');
            $this->assertSame('0.000', $payment->credit_amount, 'no credit from the other order');
            $this->assertSame(Membership::ACTIVE, $membership->fresh()->status);
            $this->assertSame('4950', $intent->fresh()->provider_order_id);
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'));
        });
    }

    public function test_an_intent_without_an_order_takes_neither_of_two_orders_arriving_at_once(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_no_order', 'intention_order_id' => 4955], 201)]);
        $club = $this->paymobClub();
        [$membership, $intent] = $this->pending($club);
        // An intent with no Paymob order (left from before, or written by hand): nothing signed names it.
        $this->asTenant($club, fn () => $intent->forceFill(['provider_order_id' => null])->save());
        $first = $this->obj($intent, 4956, ['id' => 556301]);
        $second = $this->obj($intent, 4957, ['id' => 556302]);

        $results = $this->concurrently([
            fn () => self::deliver($first),
            fn () => self::deliver($second),
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertSame(['order_unverified', 'order_unverified'], array_column($results, 'value'), json_encode($results));
        $this->asTenant($club, function () use ($membership, $intent) {
            $this->assertSame(0, Payment::where('gateway', 'paymob')->count(), 'no money on any invoice');
            $this->assertSame(Membership::PENDING, $membership->fresh()->status);
            $this->assertSame(PaymentIntent::PENDING, $intent->fresh()->status);
            $this->assertNull($intent->fresh()->provider_order_id, 'nothing bound');
        });
    }

    public function test_the_order_is_asked_again_under_the_intents_lock_before_any_money(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_relock', 'intention_order_id' => 4960], 201)]);
        $club = $this->paymobClub();
        [$membership, $intent] = $this->pending($club);
        $callback = $this->obj($intent, 4960, ['id' => 556401]);

        $results = $this->concurrently([
            // Another writer holds the intent and changes its order while the callback is on its way in.
            function (string $dir) use ($club, $intent) {
                return $this->asTenant($club, fn () => DB::transaction(function () use ($dir, $intent) {
                    $locked = PaymentIntent::whereKey($intent->id)->lockForUpdate()->firstOrFail();
                    file_put_contents("$dir/inside", (string) getmypid());
                    usleep(800_000);
                    $locked->forceFill(['provider_order_id' => '4999'])->save();

                    return 'changed';
                }));
            },
            function (string $dir) use ($callback) {
                self::waitFor("$dir/inside");
                if (! is_file("$dir/inside")) {
                    return 'not at the same time';
                }

                return self::deliver($callback);
            },
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertSame(['changed', 'order_mismatch'], array_column($results, 'value'), json_encode($results));
        $this->asTenant($club, function () use ($membership) {
            $this->assertSame(0, Payment::where('gateway', 'paymob')->count(), 'read again under the lock: not this intent\'s order now');
            $this->assertSame(Membership::PENDING, $membership->fresh()->status);
        });
    }
}
