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
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * Signed Paymob callbacks that really run at the same time on PostgreSQL (one process each): a capture delivered twice,
 * and one transaction delivered for two intents that have no Paymob order yet. Paymob is faked.
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

    public function test_one_transaction_for_two_intents_without_an_order_at_once_is_money_once_on_one_invoice(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_no_order'], 201)]);
        $club = $this->paymobClub();
        [$first, $a] = $this->pending($club);
        [$second, $b] = $this->pending($club);
        $this->assertNull($a->provider_order_id);
        $this->assertNull($b->provider_order_id);
        $forA = $this->obj($a, 4900);
        $forB = array_replace_recursive($forA, ['order' => ['merchant_order_id' => $b->reference]]);

        $results = $this->concurrently([
            function (string $dir) use ($forA) {
                self::pauseAfter(self::LOCK_INTENT, $dir, 0.8);

                return self::deliver($forA);
            },
            function (string $dir) use ($forB) {
                self::waitFor("$dir/inside");
                if (! is_file("$dir/inside")) {
                    return 'not at the same time';
                }

                return self::deliver($forB);
            },
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $outcomes = array_column($results, 'value');
        $this->assertContains('processed', $outcomes, json_encode($results));
        $this->assertCount(1, array_intersect($outcomes, ['transaction_elsewhere', 'order_mismatch']), json_encode($results));
        $this->asTenant($club, function () use ($first, $second) {
            $payment = Payment::where('gateway', 'paymob')->sole();
            $this->assertSame('0.000', $payment->credit_amount);
            $this->assertSame(1, PaymentIntent::where('status', PaymentIntent::PAID)->count(), 'one intent paid');
            $this->assertSame(1, PaymentIntent::where('status', PaymentIntent::PENDING)->count(), 'the other untouched');
            $this->assertSame(1, PaymentIntent::where('provider_order_id', '4900')->count(), 'the order bound once');
            $statuses = [$first->fresh()->status, $second->fresh()->status];
            $this->assertEqualsCanonicalizing([Membership::ACTIVE, Membership::PENDING], $statuses);
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'));
        });
    }
}
