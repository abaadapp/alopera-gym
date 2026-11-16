<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The Omani rial amounts as the system sends and expects them today: baisa, 1 OMR = 1000 (Money::SCALE). Pinned with
 * literal numbers on both sides — the checkout request and the callback — for 1.000, 1.005, 10.000 and 25.500, so a
 * change of the unit can never happen by accident. Whether Paymob Oman reads `amount` / `amount_cents` in baisa is NOT
 * proven by any official source (docs/INTEGRATIONS.md, "Still unproven"): these tests pin our side only, they do not
 * prove Paymob's. A callback in any other unit (×100, or one baisa off) is refused as amount_mismatch, never paid.
 */
class PaymobOmrAmountTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const HMAC = 'omr-amount-hmac';

    /** @return array<string, array{string, int}> */
    public static function amounts(): array
    {
        return ['1.000 OMR' => ['1.000', 1000], '1.005 OMR' => ['1.005', 1005], '10.000 OMR' => ['10.000', 10000], '25.500 OMR' => ['25.500', 25500]];
    }

    /** @return array{0: Tenant, 1: Invoice, 2: PaymentIntent} */
    private function checkoutFor(string $price): array
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_omr', 'intention_order_id' => 7001], 201)]);
        $club = $this->club();
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC, 'integration_ids' => '111'],
        ]));
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => $price]);

        return [$club, ...$this->asTenant($club, function () use ($member, $plan) {
            $invoice = app(MembershipService::class)->sell($member, $plan)->invoice;

            return [$invoice, app(OnlinePaymentService::class)->start($invoice, 'portal')];
        })];
    }

    private static function paymobCallback(PaymentIntent $intent, int $amountCents): string
    {
        $obj = [
            'id' => 880000 + $amountCents, 'pending' => false, 'amount_cents' => $amountCents, 'success' => true, 'is_auth' => false,
            'is_capture' => false, 'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false, 'is_3d_secure' => true,
            'integration_id' => 111, 'has_parent_transaction' => false, 'order' => ['id' => 7001, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-11-16T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
        ];

        return app(OnlinePaymentService::class)->handleWebhook(['type' => 'TRANSACTION', 'obj' => $obj], PaymobSignature::compute($obj, self::HMAC));
    }

    #[DataProvider('amounts')]
    public function test_the_checkout_asks_paymob_for_the_amount_in_baisa(string $price, int $baisa): void
    {
        [, $invoice, $intent] = $this->checkoutFor($price);

        $this->assertSame([$price, $price], [(string) $invoice->total, (string) $intent->amount], 'the invoice and the attempt carry the exact price');
        Http::assertSent(fn ($request) => $request['amount'] === $baisa && $request['currency'] === 'OMR'
            && count($request['items']) === 1 && $request['items'][0]['amount'] === $baisa);
    }

    #[DataProvider('amounts')]
    public function test_a_callback_in_baisa_pays_exactly_the_invoice(string $price, int $baisa): void
    {
        [$club, $invoice, $intent] = $this->checkoutFor($price);

        $this->assertSame('processed', self::paymobCallback($intent, $baisa));
        $this->asTenant($club, fn () => $this->assertSame([$price, $price, 1], [
            (string) $invoice->fresh()->paid_total, (string) Payment::where('invoice_id', $invoice->id)->sole()->amount, Payment::count(),
        ]));
    }

    #[DataProvider('amounts')]
    public function test_a_callback_in_another_unit_or_one_baisa_off_is_refused_and_pays_nothing(string $price, int $baisa): void
    {
        [$club, $invoice, $intent] = $this->checkoutFor($price);

        // ×100 (cents of a two-decimal currency), one baisa short, one baisa over: none of them is the invoice's amount.
        foreach ([intdiv($baisa, 10), $baisa - 1, $baisa + 1] as $wrong) {
            $this->assertSame('amount_mismatch', self::paymobCallback($intent, $wrong), "amount_cents $wrong for $price OMR");
        }
        $this->asTenant($club, fn () => $this->assertSame(['0.000', 0], [(string) $invoice->fresh()->paid_total, Payment::count()]));
    }
}
