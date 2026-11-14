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
 * On the production server, signed Paymob callbacks that really run at the same time on PostgreSQL (one process each):
 * a live payment delivered twice is money once; a test payment delivered twice is no money at all. Paymob is faked.
 */
class PaymobTestModeConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    private const HMAC = 'isolation-hmac-secret';

    private const LOCK_INTENT = 'select * from "payment_intents" where "payment_intents"."id" = ? and "payment_intents"."tenant_id" = ? limit 1 for update';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    private function paymobClub(string $mode, string $keys): Tenant
    {
        $club = $this->club();
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => $mode,
            'credentials' => ['secret_key' => "omn_sk_{$keys}_isolation", 'public_key' => "omn_pk_{$keys}_isolation", 'hmac_secret' => self::HMAC, 'integration_ids' => '111'],
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
    private function obj(PaymentIntent $intent): array
    {
        return [
            'id' => 559001, 'pending' => false, 'amount_cents' => Money::toMinor($intent->amount), 'success' => true,
            'is_auth' => false, 'is_capture' => false, 'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false,
            'is_3d_secure' => true, 'integration_id' => 111, 'has_parent_transaction' => false,
            'order' => ['id' => (int) $intent->provider_order_id, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-11-14T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
        ];
    }

    private static function deliver(array $obj): string
    {
        return app(OnlinePaymentService::class)->handleWebhook(['type' => 'TRANSACTION', 'obj' => $obj], PaymobSignature::compute($obj, self::HMAC));
    }

    /** @return list<array{ok: bool, value: mixed, error: ?string}> the same callback twice, the second while the first holds the intent */
    private function twiceAtOnce(array $obj): array
    {
        return $this->concurrently([
            function (string $dir) use ($obj) {
                self::pauseAfter(self::LOCK_INTENT, $dir, 0.8);

                return self::deliver($obj);
            },
            function (string $dir) use ($obj) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? self::deliver($obj) : 'not at the same time';
            },
        ]);
    }

    public function test_a_live_payment_delivered_twice_at_once_on_production_is_money_once(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_live', 'intention_order_id' => 4970], 201)]);
        $club = $this->paymobClub('live', 'live');
        $this->app->detectEnvironment(fn () => 'production');
        [$membership, $intent] = $this->pending($club);

        $results = $this->twiceAtOnce($this->obj($intent));

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertEqualsCanonicalizing(['processed', 'duplicate'], array_column($results, 'value'), json_encode($results));
        $this->asTenant($club, function () use ($membership) {
            $payment = Payment::where('gateway', 'paymob')->sole();
            $this->assertSame('0.000', $payment->credit_amount);
            $this->assertSame(Membership::ACTIVE, $membership->fresh()->status);
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'));
        });
    }

    public function test_a_test_payment_delivered_twice_at_once_on_production_is_no_money_at_all(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_test', 'intention_order_id' => 4980], 201)]);
        $club = $this->paymobClub('test', 'test');
        [$membership, $intent] = $this->pending($club); // a test checkout, opened off production
        $this->app->detectEnvironment(fn () => 'production');

        $results = $this->concurrently([fn () => self::deliver($this->obj($intent)), fn () => self::deliver($this->obj($intent))]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertSame(['test_payment', 'test_payment'], array_column($results, 'value'), json_encode($results));
        $this->asTenant($club, function () use ($membership, $intent) {
            $this->assertSame(0, Payment::count());
            $this->assertSame(Membership::PENDING, $membership->fresh()->status);
            $this->assertSame(PaymentIntent::PENDING, $intent->fresh()->status);
        });
    }
}
