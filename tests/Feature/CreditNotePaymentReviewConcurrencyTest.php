<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\TenantContext;
use App\Models\StaffNotification;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\CreditNoteService;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentReview;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * Two real PostgreSQL processes at once: Paymob's mismatched confirmation (kept as a review under the invoice's lock)
 * and staff issuing a credit note on the same invoice (CreditNoteService::issue, under that lock). Whichever takes the
 * invoice first, the rule holds and no money is lost: the review first → the note waits and is refused, nothing
 * written; the note first → the review is still written after it, open, and staff are told.
 */
class CreditNotePaymentReviewConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    private const HMAC = 'credit-race-hmac';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    /** @return array{0: Tenant, 1: Membership, 2: PaymentIntent} */
    private function pending(): array
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_cr', 'intention_order_id' => 7960], 201)]);
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

    private static function deliverShort(PaymentIntent $intent): string
    {
        $obj = [
            'id' => 990901, 'pending' => false, 'amount_cents' => 24990, 'success' => true, 'is_auth' => false, 'is_capture' => false,
            'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false, 'is_3d_secure' => true, 'integration_id' => 111,
            'has_parent_transaction' => false, 'order' => ['id' => 7960, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-11-20T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '2346', 'sub_type' => 'Visa', 'type' => 'card'],
        ];

        return app(OnlinePaymentService::class)->handleWebhook(['type' => 'TRANSACTION', 'obj' => $obj], PaymobSignature::compute($obj, self::HMAC));
    }

    private static function credit(Tenant $club, int $invoiceId): string
    {
        try {
            app(TenantContext::class)->run($club, fn () => app(CreditNoteService::class)->issue(Invoice::findOrFail($invoiceId), '5.000', 'خصم متفق عليه'));

            return 'credited';
        } catch (BusinessRuleException $e) {
            return 'refused:'.$e->rule;
        }
    }

    public function test_the_review_written_first_makes_the_note_wait_and_refuse(): void
    {
        [$club, $membership, $intent] = $this->pending();

        $results = $this->concurrently([
            function (string $dir) use ($intent) {
                self::pauseAfter('insert into "payment_reviews"', $dir, 0.8); // written, under the invoice's lock

                return self::deliverShort($intent);
            },
            function (string $dir) use ($club, $membership) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? self::credit($club, $membership->invoice_id) : 'not at the same time';
            },
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertSame(['amount_mismatch', 'refused:credit.payment_review'], array_column($results, 'value'));
        $this->asTenant($club, function () use ($membership) {
            $invoice = Invoice::find($membership->invoice_id);
            $this->assertSame([Invoice::ISSUED, '0.000'], [$invoice->status, $invoice->credited_total]);
            $this->assertSame(0, CreditNote::count());
            $this->assertSame(1, PaymentReview::open()->count());
            $this->assertSame(0, Payment::where('invoice_id', $membership->invoice_id)->count());
        });
    }

    public function test_the_note_taken_first_still_leaves_the_money_in_an_open_review(): void
    {
        [$club, $membership, $intent] = $this->pending();

        $results = $this->concurrently([
            function (string $dir) use ($club, $membership) {
                self::pauseAfter('insert into "credit_notes"', $dir, 0.8); // issued, under the invoice's lock, not committed

                return self::credit($club, $membership->invoice_id);
            },
            function (string $dir) use ($intent) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? self::deliverShort($intent) : 'not at the same time';
            },
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertSame(['credited', 'amount_mismatch'], array_column($results, 'value'));
        $this->asTenant($club, function () use ($membership) {
            $this->assertSame('5.000', Invoice::find($membership->invoice_id)->credited_total);
            $this->assertSame(1, CreditNote::count());
            $review = PaymentReview::sole();
            $this->assertSame([PaymentReview::OPEN, 25000, 24990], [$review->status, $review->expected_minor, $review->received_minor], 'the money is kept for staff');
            $this->assertSame(0, Payment::where('invoice_id', $membership->invoice_id)->count());
        });
        $this->assertSame(1, StaffNotification::withoutGlobalScopes()->where('tenant_id', $club->id)->where('key', 'like', 'payment-review:%')->count());

        // From now on the next note waits for the review to be closed.
        $this->assertSame('refused:credit.payment_review', self::credit($club, $membership->invoice_id));
    }
}
