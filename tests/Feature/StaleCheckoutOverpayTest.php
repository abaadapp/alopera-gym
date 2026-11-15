<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\ManualInvoices;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A checkout opened for what the invoice owed, then the invoice paid in part at the desk: the old checkout's page stays
 * payable at Paymob until it expires (nothing here can withdraw it). The link must not hand out a second payable page
 * beside it, and any money above what is owed must stay in the books as the customer's credit, for review.
 */
class StaleCheckoutOverpayTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const HMAC = 'stale-hmac';

    private function setUpClub(): array
    {
        $club = $this->club();
        $this->fundAccounts($club);
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => self::HMAC, 'integration_ids' => '111'],
        ]));
        $this->actingAs($this->owner($club));
        $invoice = $this->asTenant($club, fn () => app(ManualInvoices::class)->issue([
            'customer_mode' => 'new', 'customer_name' => 'عميل', 'customer_phone' => '96891234567',
            'issue_date' => today()->toDateString(), 'due_date' => today()->addDays(7)->toDateString(), 'payment_terms_days' => 7,
            'lines' => [['description' => 'خدمة', 'quantity' => 1, 'unit_price' => 10]],
        ], ['method' => 'credit']));
        $orders = 8000;
        Http::fake(['*/v1/intention/' => function () use (&$orders) {
            return Http::response(['client_secret' => 'cs_'.++$orders, 'intention_order_id' => $orders], 201);
        }]);

        return [$club, $invoice];
    }

    private function start(Tenant $club, Invoice $invoice): PaymentIntent|string
    {
        try {
            return $this->asTenant($club, fn () => app(OnlinePaymentService::class)->start($invoice->fresh(), 'whatsapp'));
        } catch (BusinessRuleException $e) {
            return $e->rule;
        }
    }

    private function pay(Tenant $club, PaymentIntent $intent, int $tx): string
    {
        $obj = [
            'id' => $tx, 'pending' => false, 'amount_cents' => Money::toMinor($intent->amount), 'success' => true, 'is_auth' => false,
            'is_capture' => false, 'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false, 'is_3d_secure' => true,
            'integration_id' => 111, 'has_parent_transaction' => false, 'order' => ['id' => (int) $intent->provider_order_id, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-11-15T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
        ];

        return $this->asTenant($club, fn () => app(OnlinePaymentService::class)->handleWebhook(['type' => 'TRANSACTION', 'obj' => $obj], PaymobSignature::compute($obj, self::HMAC)));
    }

    public function test_after_a_desk_payment_the_old_page_is_not_joined_by_a_second_and_the_extra_is_credit_for_review(): void
    {
        [$club, $invoice] = $this->setUpClub();
        $old = $this->start($club, $invoice);                                                                  // page A: 10.000
        $this->asTenant($club, fn () => app(PaymentService::class)->record($invoice->fresh(), '4.000', 'cash')); // owes 6.000

        // Before: a page B of 6.000 beside A, both payable (16.000 online for 6.000 owed). Now: none while A is payable.
        $this->assertSame('paymob.page_open', $this->start($club, $invoice));
        $this->assertSame(1, $this->asTenant($club, fn () => PaymentIntent::where('invoice_id', $invoice->id)->count()));
        Http::assertSentCount(1);

        // The customer pays the old page anyway: the invoice takes what it owed, the rest is their credit, books balance.
        $this->assertSame('processed', $this->pay($club, $old, 1));
        $this->asTenant($club, function () use ($invoice) {
            $i = $invoice->fresh();
            $this->assertSame([Invoice::PAID, '10.000'], [$i->status, $i->paid_total]);
            $online = Payment::where('gateway', 'paymob')->sole();
            $this->assertSame(['10.000', '4.000'], [$online->amount, $online->credit_amount]);
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'));
        });
        $this->assertSame('invoice.not_open', $this->start($club, $invoice));
    }

    public function test_once_the_old_page_has_ended_the_link_asks_only_what_is_owed(): void
    {
        [$club, $invoice] = $this->setUpClub();
        $old = $this->start($club, $invoice);
        $this->asTenant($club, fn () => app(PaymentService::class)->record($invoice->fresh(), '4.000', 'cash'));

        $this->travelTo($old->expires_at->copy()->addMinutes(OnlinePaymentService::LATE_CALLBACK_MINUTES)->addSecond());
        $new = $this->start($club, $invoice);
        $this->assertInstanceOf(PaymentIntent::class, $new);
        $this->assertSame('6.000', $new->amount);

        // A late callback of the old page (Paymob let it through at the last second) is not lost: held, never applied twice.
        $this->assertSame('held_for_review', $this->pay($club, $old, 3));
        $this->asTenant($club, fn () => $this->assertSame('4.000', $invoice->fresh()->paid_total));
    }

    public function test_a_page_too_close_to_its_end_to_be_given_again_is_not_doubled_either(): void
    {
        [$club, $invoice] = $this->setUpClub();
        $old = $this->start($club, $invoice);
        $this->travelTo($old->expires_at->copy()->subMinutes(5)); // same amount, five minutes left

        $this->assertSame('paymob.page_open', $this->start($club, $invoice));
        $this->travelTo($old->expires_at->copy()->addMinutes(OnlinePaymentService::LATE_CALLBACK_MINUTES)->addSecond());
        $this->assertSame('10.000', $this->start($club, $invoice)->amount);
    }

    public function test_the_refusal_through_the_pay_link_is_a_message_not_a_new_page(): void
    {
        [$club, $invoice] = $this->setUpClub();
        $this->start($club, $invoice);
        $this->asTenant($club, fn () => app(PaymentService::class)->record($invoice->fresh(), '4.000', 'cash'));
        auth()->logout();

        $this->post(route('site.pay.start', ['club' => $club->slug, 'token' => $invoice->public_token]))->assertRedirect()->assertSessionHasErrors();
        Http::assertSentCount(1);
    }

    public function test_the_end_of_a_page_here_is_no_proof_paymob_closed_it(): void
    {
        [$club, $invoice] = $this->setUpClub();
        $old = $this->start($club, $invoice);
        $this->asTenant($club, fn () => app(PaymentService::class)->record($invoice->fresh(), '4.000', 'cash'));

        // Ended here a minute ago, and until the late-callback window has passed: still no second page.
        $this->travelTo($old->expires_at->copy()->addMinute());
        $this->assertSame('paymob.page_open', $this->start($club, $invoice));
        $this->travelTo($old->expires_at->copy()->addMinutes(OnlinePaymentService::LATE_CALLBACK_MINUTES)->subMinute());
        $this->assertSame('paymob.page_open', $this->start($club, $invoice));
        Http::assertSentCount(1);
    }

    public function test_a_declined_card_leaves_its_page_open_the_link_gives_it_again_and_a_later_success_is_recorded_once(): void
    {
        [$club, $invoice] = $this->setUpClub();
        $page = $this->start($club, $invoice);
        $declined = $this->signed($page, 11, ['success' => false, 'data' => ['message' => 'Insufficient funds']]);
        $this->assertSame('failed_payment', $this->deliverObj($club, $declined));
        $this->assertSame(PaymentIntent::FAILED, $this->asTenant($club, fn () => $page->fresh()->status));

        // Opened again: the same page, not a second one beside it.
        $again = $this->start($club, $invoice);
        $this->assertInstanceOf(PaymentIntent::class, $again);
        $this->assertSame($page->id, $again->id);
        Http::assertSentCount(1);

        // Another card on that page succeeds: recorded once, the invoice paid, the books balance.
        $this->assertSame('processed', $this->pay($club, $page, 12));
        $this->assertSame('duplicate', $this->pay($club, $page, 12));
        $this->asTenant($club, function () use ($invoice) {
            $this->assertSame([Invoice::PAID, '10.000'], [$invoice->fresh()->status, $invoice->fresh()->paid_total]);
            $this->assertSame(1, Payment::where('gateway', 'paymob')->count());
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'));
        });
    }

    public function test_a_declined_page_then_a_desk_payment_still_opens_no_second_page(): void
    {
        [$club, $invoice] = $this->setUpClub();
        $page = $this->start($club, $invoice);
        $this->deliverObj($club, $this->signed($page, 21, ['success' => false]));
        $this->asTenant($club, fn () => app(PaymentService::class)->record($invoice->fresh(), '4.000', 'cash'));

        $this->assertSame('paymob.page_open', $this->start($club, $invoice));
        Http::assertSentCount(1);
    }

    public function test_the_message_explains_the_old_link_what_is_owed_and_both_ways_on(): void
    {
        [$club, $invoice] = $this->setUpClub();
        $this->start($club, $invoice);
        $this->asTenant($club, fn () => app(PaymentService::class)->record($invoice->fresh(), '4.000', 'cash'));

        try {
            $this->asTenant($club, fn () => app(OnlinePaymentService::class)->start($invoice->fresh(), 'whatsapp'));
            $this->fail('a second page was opened');
        } catch (BusinessRuleException $e) {
            $m = $e->getMessage();
            foreach (['رابط دفع سابق', '10.000', 'المتبقي الآن 6.000', 'لا تدفع من الرابط السابق', 'انتظر حتى الساعة', 'الاستقبال'] as $part) {
                $this->assertStringContainsString($part, $m);
            }
            $this->assertStringNotContainsString('أكمل الدفع منه', $m, 'never told to pay the old page when it asks more than is owed');
        }
    }

    private function signed(PaymentIntent $intent, int $tx, array $over = []): array
    {
        return array_replace_recursive([
            'id' => $tx, 'pending' => false, 'amount_cents' => Money::toMinor($intent->amount), 'success' => true, 'is_auth' => false,
            'is_capture' => false, 'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false, 'is_3d_secure' => true,
            'integration_id' => 111, 'has_parent_transaction' => false, 'order' => ['id' => (int) $intent->provider_order_id, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-11-15T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
        ], $over);
    }

    private function deliverObj(Tenant $club, array $obj): string
    {
        return $this->asTenant($club, fn () => app(OnlinePaymentService::class)->handleWebhook(['type' => 'TRANSACTION', 'obj' => $obj], PaymobSignature::compute($obj, self::HMAC)));
    }
}
