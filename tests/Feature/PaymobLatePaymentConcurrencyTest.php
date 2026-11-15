<?php

namespace Tests\Feature;

use App\Core\Support\Money;
use App\Models\StaffNotification;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\PaymentService;
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
 * Held Paymob money under requests that really run at the same time on PostgreSQL (one process each): a late callback
 * delivered twice, the held credit applied and paid back at once, and a membership cancelled while its payment comes
 * in. Paymob is faked.
 */
class PaymobLatePaymentConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    private const HMAC = 'late-hmac-secret';

    private const LOCK_INTENT = 'select * from "payment_intents" where "payment_intents"."id" = ? and "payment_intents"."tenant_id" = ? limit 1 for update';

    private const LOCK_PAYMENT = 'select * from "payments" where "payments"."id" = ? and "payments"."tenant_id" = ? limit 1 for update';

    private const LOCK_MEMBERSHIP = 'select * from "gym_memberships" where "gym_memberships"."id" = ? and "gym_memberships"."tenant_id" = ? limit 1 for update';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_late', 'intention_order_id' => 4990], 201)]);
    }

    /** @return array{0: Tenant, 1: Membership, 2: PaymentIntent} */
    private function pending(): array
    {
        $club = $this->club();
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC, 'integration_ids' => '111'],
        ]));
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 25]);
        [$membership, $intent] = $this->asTenant($club, function () use ($member, $plan) {
            $membership = app(MembershipService::class)->sell($member, $plan);

            return [$membership, app(OnlinePaymentService::class)->start($membership->invoice, 'portal')];
        });

        return [$club, $membership, $intent];
    }

    /** @return array<string, mixed> */
    private function obj(PaymentIntent $intent): array
    {
        return [
            'id' => 561001, 'pending' => false, 'amount_cents' => Money::toMinor($intent->amount), 'success' => true,
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

    public function test_a_late_callback_delivered_twice_at_once_is_held_once(): void
    {
        [$club, $membership, $intent] = $this->pending();
        $this->travelTo($intent->expires_at->copy()->addSecond());
        $obj = $this->obj($intent);

        $results = $this->concurrently([
            function (string $dir) use ($obj) {
                self::pauseAfter(self::LOCK_INTENT, $dir, 0.8);

                return self::deliver($obj);
            },
            function (string $dir) use ($obj) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? self::deliver($obj) : 'not at the same time';
            },
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertEqualsCanonicalizing(['held_for_review', 'duplicate'], array_column($results, 'value'), json_encode($results));
        $this->asTenant($club, function () use ($membership) {
            $payment = Payment::where('gateway', 'paymob')->sole();
            $this->assertSame('25.000', $payment->credit_amount);
            $this->assertSame(Membership::PENDING, $membership->fresh()->status);
            $this->assertSame(StaffNotification::where('key', 'payment-credit:'.$payment->id)->count(),
                StaffNotification::where('key', 'payment-credit:'.$payment->id)->distinct('user_id')->count('user_id'), 'told once each');
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'));
        });
    }

    public function test_held_money_applied_and_paid_back_at_once_goes_one_way_only(): void
    {
        [$club, $membership, $intent] = $this->pending();
        $this->travelTo($intent->expires_at->copy()->addSecond());
        $this->assertSame('held_for_review', self::deliver($this->obj($intent)));
        $payment = $this->asTenant($club, fn () => Payment::where('gateway', 'paymob')->sole());
        $invoice = $this->asTenant($club, fn () => Invoice::findOrFail($membership->invoice_id));

        $results = $this->concurrently([
            function (string $dir) use ($club, $payment, $invoice) {
                self::pauseAfter(self::LOCK_PAYMENT, $dir, 0.8);

                return $this->asTenant($club, fn () => app(PaymentService::class)->allocate($payment, $invoice, '25', 'موافقة')->id);
            },
            function (string $dir) use ($club, $payment) {
                self::waitFor("$dir/inside");
                if (! is_file("$dir/inside")) {
                    return 'not at the same time';
                }

                return $this->asTenant($club, fn () => app(PaymentService::class)->refundCredit($payment, '25', 'paymob', null, null, 'استرداد')->id);
            },
        ]);

        $this->assertSame(1, count(array_filter($results, fn ($r) => $r['ok'])), json_encode($results));
        $failed = array_values(array_filter($results, fn ($r) => ! $r['ok']))[0];
        $this->assertStringContainsString('BusinessRuleException', (string) $failed['error'], json_encode($results));
        $this->asTenant($club, function () use ($payment, $membership, $results) {
            $this->assertSame('0.000', $payment->fresh()->creditLeft(), 'used once, not twice');
            $applied = $results[0]['ok'];
            $this->assertSame($applied ? Membership::ACTIVE : Membership::PENDING, $membership->fresh()->status);
            $this->assertSame($applied ? 0 : 1, Payment::where('refund_of_id', $payment->id)->count());
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'));
        });
    }

    /**
     * Cancelling a membership locks its invoice, then the membership — the order a payment takes (the invoice, then the
     * membership). Held right after the membership's lock, the moment that used to be a deadlock (PostgreSQL ended the
     * callback, 500), the callback now waits, then finds the sale withdrawn and holds the money.
     */
    public function test_a_membership_cancelled_while_its_payment_comes_in_leaves_nothing_wrong(): void
    {
        [$club, $membership, $intent] = $this->pending();
        $obj = $this->obj($intent);

        $results = $this->concurrently([
            function (string $dir) use ($club, $membership) {
                self::pauseAfter(self::LOCK_MEMBERSHIP, $dir, 0.8);

                return $this->asTenant($club, fn () => app(MembershipService::class)->cancel($membership, 'إلغاء')->status);
            },
            function (string $dir) use ($obj) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? self::deliver($obj) : 'not at the same time';
            },
        ]);

        // Both done, neither ended by PostgreSQL: the cancellation first, then the callback, which holds the money.
        $this->assertSame([[true, Membership::CANCELLED], [true, 'held_for_review']], array_map(fn ($r) => [$r['ok'], $r['value']], $results), json_encode($results));

        $this->asTenant($club, function () use ($membership, $results) {
            $payment = Payment::where('gateway', 'paymob')->sole();
            $invoice = Invoice::findOrFail($membership->invoice_id);
            $this->assertSame([Membership::CANCELLED, Invoice::VOID, '25.000', '0.000'],
                [$membership->fresh()->status, $invoice->status, $payment->credit_amount, $payment->credit_used], json_encode($results));
            $this->assertSame(1, PaymentIntent::where('status', PaymentIntent::PAID)->count());
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'));
        });
    }
}
