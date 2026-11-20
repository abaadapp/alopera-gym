<?php

namespace Tests\Feature;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\TenantContext;
use App\Models\StaffNotification;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentReview;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * Two real PostgreSQL processes at once: the same mismatched Paymob confirmation twice (one review, one alert), and a
 * mismatched confirmation while the customer asks for a new checkout (the checkout comes before it, or is refused).
 */
class PaymobReconciliationConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    private const HMAC = 'recon-race-hmac';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    /** @return array{0: Tenant, 1: Membership, 2: PaymentIntent} */
    private function pending(): array
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_rr', 'intention_order_id' => 7900], 201)]);
        $club = $this->club();
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC, 'integration_ids' => '111'],
        ]));
        $this->owner($club);
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 25]);

        return $this->asTenant($club, function () use ($club, $member, $plan) {
            $membership = app(MembershipService::class)->sell($member, $plan);

            return [$club, $membership, app(OnlinePaymentService::class)->start($membership->invoice, 'portal')];
        });
    }

    private static function short(PaymentIntent $intent): array
    {
        return [
            'id' => 990701, 'pending' => false, 'amount_cents' => 24990, 'success' => true, 'is_auth' => false, 'is_capture' => false,
            'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false, 'is_3d_secure' => true, 'integration_id' => 111,
            'has_parent_transaction' => false, 'order' => ['id' => 7900, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-11-20T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '2346', 'sub_type' => 'Visa', 'type' => 'card'],
        ];
    }

    private static function deliver(array $obj): string
    {
        return app(OnlinePaymentService::class)->handleWebhook(['type' => 'TRANSACTION', 'obj' => $obj], PaymobSignature::compute($obj, self::HMAC));
    }

    public function test_the_same_mismatched_confirmation_twice_at_once_is_one_review_and_one_alert(): void
    {
        [$club, $membership, $intent] = $this->pending();
        $obj = self::short($intent);

        $results = $this->concurrently([
            function (string $dir) use ($obj) {
                self::pauseAfter('insert into "payment_reviews"', $dir, 0.8);

                return self::deliver($obj);
            },
            function (string $dir) use ($obj) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? self::deliver($obj) : 'not at the same time';
            },
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertSame(['amount_mismatch', 'amount_mismatch'], array_column($results, 'value'), 'both answered: the money is kept');
        $this->asTenant($club, function () use ($membership) {
            $this->assertSame(1, PaymentReview::count());
            $this->assertSame(0, Payment::where('invoice_id', $membership->invoice_id)->count());
            $this->assertSame(Membership::PENDING, $membership->fresh()->status);
        });
        $this->assertSame(1, StaffNotification::withoutGlobalScopes()->where('tenant_id', $club->id)->where('key', 'like', 'payment-review:%')->count());
    }

    public function test_a_new_checkout_asked_while_a_mismatched_confirmation_is_kept_comes_before_it_or_is_refused(): void
    {
        [$club, $membership, $intent] = $this->pending();
        $obj = self::short($intent);

        $results = $this->concurrently([
            function (string $dir) use ($obj) {
                self::pauseAfter('insert into "payment_reviews"', $dir, 0.8); // the review written, under the invoice's lock

                return self::deliver($obj);
            },
            function (string $dir) use ($club, $membership) {
                self::waitFor("$dir/inside");
                if (! is_file("$dir/inside")) {
                    return 'not at the same time';
                }
                try {
                    return app(TenantContext::class)->run($club, fn () => app(OnlinePaymentService::class)->start(Invoice::findOrFail($membership->invoice_id), 'portal')->reference);
                } catch (BusinessRuleException $e) {
                    return 'refused:'.$e->rule;
                }
            },
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertSame('amount_mismatch', $results[0]['value']);
        $this->assertSame('refused:paymob.review', $results[1]['value'], 'it waited for the review and saw it');
        $this->asTenant($club, fn () => $this->assertSame(1, PaymentIntent::where('invoice_id', $membership->invoice_id)->count(), 'no second checkout'));
    }

    public function test_two_staff_closing_the_same_review_at_once_close_it_once(): void
    {
        [$club, , $intent] = $this->pending();
        $owner = User::where('tenant_id', $club->id)->firstOrFail();
        self::deliver(self::short($intent));
        $refund = ['id' => 990951, 'pending' => false, 'amount_cents' => 24990, 'success' => true, 'is_auth' => false, 'is_capture' => false,
            'is_standalone_payment' => false, 'is_voided' => false, 'is_refunded' => false, 'is_refund' => true, 'is_3d_secure' => false,
            'integration_id' => 111, 'has_parent_transaction' => true, 'order' => ['id' => 7900], 'created_at' => '2026-11-21T09:00:00',
            'currency' => 'OMR', 'error_occured' => false, 'owner' => 1, 'source_data' => ['pan' => '2346', 'sub_type' => 'Visa', 'type' => 'card']];
        self::deliver($refund);
        $review = $this->asTenant($club, fn () => PaymentReview::sole());
        $close = fn () => app(TenantContext::class)->run($club, fn () => PaymentReview::closeOn($review->id, PaymentReview::REFUNDED, 'رُدّ من لوحة Paymob', $owner->id, app(AuditLogger::class)) ? 'closed' : 'already');

        $results = $this->concurrently([
            function (string $dir) use ($close) {
                self::pauseAfter('update "payment_reviews"', $dir, 0.8); // closed, not yet committed

                return $close();
            },
            function (string $dir) use ($close) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? $close() : 'not at the same time';
            },
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertSame(['closed', 'already'], array_column($results, 'value'));
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'payment.review_closed')->count(), 'closed once');
    }
}
