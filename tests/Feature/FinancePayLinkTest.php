<?php

namespace Tests\Feature;

use App\Core\Support\Money;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A payment request from the finance section, end to end, through what is already there: the invoice editor issues
 * the claim (deferred payment, nothing recorded), its secure link (/pay/{token}) opens one Paymob checkout however
 * often it is pressed, and only a signed callback records the money, once. Paymob is faked; nothing leaves the test.
 */
class FinancePayLinkTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const HMAC = 'test-hmac-secret';

    private function paymob(Tenant $club): void
    {
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC, 'integration_ids' => '111'],
        ]));
    }

    /** @return array<string, mixed> */
    private function claim(array $extra = []): array
    {
        return array_replace([
            'customer_mode' => 'new', 'customer_name' => 'شركة الواحة', 'customer_phone' => '96891234567', 'customer_email' => 'oasis@example.om',
            'issue_date' => today()->toDateString(), 'due_date' => today()->addDays(7)->toDateString(), 'payment_terms_days' => 7,
            'lines' => [['description' => 'تأجير القاعة', 'quantity' => 1, 'unit_price' => 12.345]],
            'issue' => 1, 'payment_method' => 'credit', 'client_key' => 'claim-1',
        ], $extra);
    }

    private function signedCallback(PaymentIntent $intent, int $order, array $over = []): array
    {
        $obj = array_replace_recursive([
            'id' => 700001, 'pending' => false, 'amount_cents' => Money::toMinor($intent->amount), 'success' => true, 'is_auth' => false,
            'is_capture' => false, 'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false, 'is_3d_secure' => true,
            'integration_id' => 111, 'has_parent_transaction' => false, 'order' => ['id' => $order, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-11-15T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
        ], $over);

        return ['type' => 'TRANSACTION', 'obj' => $obj];
    }

    private function webhook(array $payload, ?string $hmac = null)
    {
        return $this->postJson(route('webhooks.paymob').'?hmac='.($hmac ?? PaymobSignature::compute($payload['obj'], self::HMAC)), $payload);
    }

    private function only(Tenant $club): Invoice
    {
        return $this->asTenant($club, fn () => Invoice::where('customer_name', 'شركة الواحة')->sole());
    }

    public function test_a_claim_from_finance_is_one_invoice_with_a_pay_link_and_no_money_recorded(): void
    {
        $club = $this->club();
        $this->paymob($club);
        $owner = $this->owner($club);

        // Pressed twice with the same form key: one invoice.
        $this->actingAs($owner)->post(route('club.invoices.store'), $this->claim())->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.invoices.store'), $this->claim());
        $invoice = $this->only($club);

        $this->assertSame([Invoice::ISSUED, '0.000'], [$invoice->status, $invoice->paid_total]);
        $this->assertSame(0, $this->asTenant($club, fn () => Payment::where('invoice_id', $invoice->id)->count()), 'issuing the claim records no payment');
        $this->assertSame(48, strlen((string) $invoice->public_token));

        $link = route('site.pay.show', ['club' => $club->slug, 'token' => $invoice->public_token]);
        $this->actingAs($owner)->get(route('club.invoices.show', $invoice))->assertOk()
            ->assertInertia(fn ($page) => $page->where('invoice.pay_link', $link)->where('onlinePayments', true));
    }

    public function test_the_link_opens_one_checkout_for_the_balance_and_only_a_signed_callback_pays_it_once(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_1', 'intention_order_id' => 4401], 201)]);
        $club = $this->club();
        $this->paymob($club);
        $this->actingAs($this->owner($club))->post(route('club.invoices.store'), $this->claim());
        auth()->logout();
        $invoice = $this->only($club);
        $start = route('site.pay.start', ['club' => $club->slug, 'token' => $invoice->public_token]);

        $first = $this->post($start)->assertRedirect()->headers->get('Location');
        $this->assertStringContainsString('unifiedcheckout/?publicKey=pk_test&clientSecret=cs_1', (string) $first);
        $this->assertSame($first, $this->post($start)->headers->get('Location'), 'pressed twice: the same checkout');
        Http::assertSentCount(1);
        $intent = $this->asTenant($club, fn () => PaymentIntent::where('invoice_id', $invoice->id)->sole());
        $this->assertSame($invoice->balance(), $intent->amount);
        Http::assertSent(fn ($r) => $r['amount'] === Money::toMinor($invoice->balance()) && $r['currency'] === 'OMR');

        // The return page proves nothing.
        $this->get(route('site.pay.return', ['club' => $club->slug, 'reference' => $intent->reference]))->assertOk();
        $this->assertSame(Invoice::ISSUED, $this->only($club)->status);

        // Wrong amount, wrong currency, a forged signature: nothing.
        $this->webhook($this->signedCallback($intent, 4401, ['amount_cents' => Money::toMinor($intent->amount) - 1]));
        $this->webhook($this->signedCallback($intent, 4401, ['currency' => 'EGP', 'id' => 700002]));
        $this->webhook($this->signedCallback($intent, 4401, ['id' => 700003]), str_repeat('0', 128));
        $this->assertSame(Invoice::ISSUED, $this->only($club)->status);

        // The right one, twice: paid once.
        $this->webhook($this->signedCallback($intent, 4401))->assertOk();
        $this->webhook($this->signedCallback($intent, 4401))->assertOk();
        $paid = $this->only($club);
        $this->assertSame([Invoice::PAID, $paid->total], [$paid->status, $paid->paid_total]);
        $this->assertSame(1, $this->asTenant($club, fn () => Payment::where('invoice_id', $paid->id)->where('kind', 'payment')->count()));

        // Paid: the link takes nothing more.
        $this->post($start);
        Http::assertSentCount(1);
    }

    public function test_a_partly_paid_claim_is_asked_only_its_balance_and_a_void_one_nothing(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_2', 'intention_order_id' => 4402], 201)]);
        $club = $this->club();
        $this->paymob($club);
        $owner = $this->owner($club);
        $this->fundAccounts($club);
        $this->actingAs($owner)->post(route('club.invoices.store'), $this->claim(['payment_method' => 'cash', 'paid_amount' => 2, 'lines' => [['description' => 'تأجير القاعة', 'quantity' => 1, 'unit_price' => 10]]]))->assertSessionHasNoErrors();
        auth()->logout();
        $invoice = $this->only($club);
        $this->assertSame(Invoice::PARTIALLY_PAID, $invoice->status);

        $this->post(route('site.pay.start', ['club' => $club->slug, 'token' => $invoice->public_token]));
        $intent = $this->asTenant($club, fn () => PaymentIntent::where('invoice_id', $invoice->id)->sole());
        $this->assertSame(Money::sub($invoice->total, '2.000'), $intent->amount);

        // A claim with nothing paid, voided: its link opens nothing.
        $this->actingAs($owner)->post(route('club.invoices.store'), $this->claim(['client_key' => 'claim-2', 'customer_name' => 'عميل آخر']));
        $other = $this->asTenant($club, fn () => Invoice::where('customer_name', 'عميل آخر')->sole());
        $this->actingAs($owner)->post(route('club.invoices.void', $other), ['reason' => 'خطأ'])->assertSessionHasNoErrors();
        auth()->logout();
        $this->post(route('site.pay.start', ['club' => $club->slug, 'token' => $other->public_token]));
        $this->assertSame(0, $this->asTenant($club, fn () => PaymentIntent::where('invoice_id', $other->id)->count()));
    }

    public function test_paymob_down_opens_nothing_and_records_nothing(): void
    {
        Http::fake(fn () => throw new ConnectionException('down'));
        $club = $this->club();
        $this->paymob($club);
        $this->actingAs($this->owner($club))->post(route('club.invoices.store'), $this->claim());
        auth()->logout();
        $invoice = $this->only($club);

        $this->post(route('site.pay.start', ['club' => $club->slug, 'token' => $invoice->public_token]));
        $this->assertSame([PaymentIntent::FAILED], $this->asTenant($club, fn () => PaymentIntent::where('invoice_id', $invoice->id)->pluck('status')->all()));
        $this->assertSame(Invoice::ISSUED, $this->only($club)->status);
    }

    public function test_another_clubs_link_token_and_staff_without_rights_get_nothing(): void
    {
        $club = $this->club();
        $other = $this->club('Other');
        $this->paymob($club);
        $this->actingAs($this->owner($club))->post(route('club.invoices.store'), $this->claim());
        $invoice = $this->only($club);
        auth()->logout();

        $this->get(route('site.pay.show', ['club' => $other->slug, 'token' => $invoice->public_token]))->assertNotFound();
        $this->actingAs($this->staff($club, 'trainer'))->post(route('club.invoices.store'), $this->claim(['client_key' => 'claim-x']))->assertForbidden();
    }

    public function test_a_checkout_whose_time_is_up_shows_expired_but_stays_pending_for_a_late_callback(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_e', 'intention_order_id' => 4409], 201)]);
        $club = $this->club();
        $this->paymob($club);
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.invoices.store'), $this->claim());
        $invoice = $this->only($club);
        $intent = $this->asTenant($club, fn () => app(OnlinePaymentService::class)->start($invoice, 'whatsapp'));

        $this->travelTo($intent->expires_at->copy()->addMinute());
        $this->actingAs($owner)->get(route('club.invoices.show', $invoice))->assertOk()
            ->assertInertia(fn ($page) => $page->where('intents.0.status', PaymentIntent::EXPIRED));
        $this->assertSame(PaymentIntent::PENDING, $this->asTenant($club, fn () => $intent->fresh()->status), 'display only: the row stays pending');

        // Its money arriving late is not lost: recorded and held for review, the invoice untouched.
        $payload = $this->signedCallback($intent, 4409);
        $this->webhook($payload)->assertOk();
        $this->asTenant($club, function () use ($invoice) {
            $this->assertSame('0.000', $invoice->fresh()->paid_total);
            $this->assertSame(1, Payment::where('gateway', 'paymob')->whereColumn('credit_amount', 'amount')->count());
        });
    }
}
