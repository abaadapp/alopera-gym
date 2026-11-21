<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentLink;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use App\Modules\Payments\Services\PaymentLinks;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * A payment link cancelled at the very moment its customer opens the payment page, or its money arrives (two real
 * PostgreSQL processes). Both ways take the invoice's lock first, so one waits for the other: either the page opens
 * and the cancel is refused, or the cancel goes through and no page opens — never a cancelled link with a page or money.
 */
class PaymentLinksConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    private const HMAC = 'links-race-hmac';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    /** @return array{0: Tenant, 1: PaymentLink} */
    private function link(): array
    {
        $club = $this->club();
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC, 'integration_ids' => '111'],
        ]));
        $this->actingAs($this->owner($club));
        $link = $this->asTenant($club, fn () => app(PaymentLinks::class)->create(['amount' => '9.990', 'description' => 'سباق', 'valid_days' => 7]));

        return [$club, $link];
    }

    private static function open(Tenant $club, PaymentLink $link): string
    {
        try {
            return app(TenantContext::class)->run($club, fn () => app(OnlinePaymentService::class)->start(Invoice::findOrFail($link->invoice_id), 'whatsapp')->reference);
        } catch (BusinessRuleException $e) {
            return 'refused:'.$e->rule;
        }
    }

    private static function cancel(Tenant $club, PaymentLink $link): string
    {
        try {
            app(TenantContext::class)->run($club, fn () => app(PaymentLinks::class)->cancel(PaymentLink::findOrFail($link->id)));

            return 'cancelled';
        } catch (BusinessRuleException $e) {
            return 'refused:'.$e->rule;
        }
    }

    public function test_a_page_opening_while_the_link_is_cancelled(): void
    {
        [$club, $link] = $this->link();
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_r1', 'intention_order_id' => 9101], 201)]);

        $results = $this->concurrently([
            function (string $dir) use ($club, $link) {
                self::pauseAfter('insert into "payment_intents"', $dir, 0.8); // the page claimed, the invoice still locked

                return self::open($club, $link);
            },
            function (string $dir) use ($club, $link) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? self::cancel($club, $link) : 'not at the same time';
            },
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertStringStartsWith('PT-', $results[0]['value'], 'the page opened');
        $this->assertSame('refused:payment_link.page_open', $results[1]['value'], 'so the cancel is refused');
        $this->asTenant($club, fn () => $this->assertSame([Invoice::ISSUED, null], [Invoice::find($link->invoice_id)->status, $link->fresh()->cancelled_at]));
    }

    public function test_a_cancel_going_through_while_the_page_is_asked_for(): void
    {
        [$club, $link] = $this->link();
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_r2', 'intention_order_id' => 9102], 201)]);

        $results = $this->concurrently([
            function (string $dir) use ($club, $link) {
                self::pauseAfter('update "payment_links"', $dir, 0.8); // the link cancelled (the link only), not yet committed

                return self::cancel($club, $link);
            },
            function (string $dir) use ($club, $link) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? self::open($club, $link) : 'not at the same time';
            },
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame('cancelled', $results[0]['value']);
        $this->assertStringStartsWith('refused:', $results[1]['value'], 'no page for a cancelled link');
        $this->asTenant($club, fn () => $this->assertSame(0, PaymentIntent::where('invoice_id', $link->invoice_id)->count()));
        Http::assertNothingSent();
    }

    public function test_money_arriving_while_the_link_is_cancelled(): void
    {
        [$club, $link] = $this->link();
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_r3', 'intention_order_id' => 9103], 201)]);
        $reference = self::open($club, $link);
        $intent = $this->asTenant($club, fn () => PaymentIntent::where('reference', $reference)->sole());
        // The page's time and its late window are over, so only the money itself can stop the cancel now.
        $this->travelTo($intent->expires_at->copy()->addMinutes(OnlinePaymentService::LATE_CALLBACK_MINUTES + 5));

        $results = $this->concurrently([
            function (string $dir) use ($intent) {
                self::pauseAfter('insert into "payments"', $dir, 0.8); // the money written, not yet committed
                $obj = [
                    'id' => 990301, 'pending' => false, 'amount_cents' => Money::toMinor($intent->amount), 'success' => true, 'is_auth' => false,
                    'is_capture' => false, 'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false, 'is_3d_secure' => true,
                    'integration_id' => 111, 'has_parent_transaction' => false, 'order' => ['id' => 9103, 'merchant_order_id' => $intent->reference],
                    'created_at' => '2026-11-19T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
                ];

                return app(OnlinePaymentService::class)->handleWebhook(['type' => 'TRANSACTION', 'obj' => $obj], PaymobSignature::compute($obj, self::HMAC));
            },
            function (string $dir) use ($club, $link) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? self::cancel($club, $link) : 'not at the same time';
            },
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertSame('held_for_review', $results[0]['value'], 'late money is recorded and held');
        $this->assertSame('refused:payment_link.money', $results[1]['value'], 'so the link is not cancelled under it');
        $this->asTenant($club, function () use ($link) {
            $this->assertSame([Invoice::ISSUED, null], [Invoice::find($link->invoice_id)->status, $link->fresh()->cancelled_at]);
            $this->assertSame(1, Payment::where('invoice_id', $link->invoice_id)->count());
        });
    }
}
