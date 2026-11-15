<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\ManualInvoices;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** The claim that keeps a second checkout from opening: never a reason to refuse once its request is gone or failed. */
class PayLinkOpeningTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function setUpClub(): array
    {
        $club = $this->club();
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => 'h', 'integration_ids' => '111'],
        ]));
        $this->actingAs($this->owner($club));
        $invoice = $this->asTenant($club, fn () => app(ManualInvoices::class)->issue([
            'customer_mode' => 'new', 'customer_name' => 'عميل', 'customer_phone' => '96891234567',
            'issue_date' => today()->toDateString(), 'due_date' => today()->addDays(7)->toDateString(), 'payment_terms_days' => 7,
            'lines' => [['description' => 'خدمة', 'quantity' => 1, 'unit_price' => 10]],
        ], ['method' => 'credit']));

        return [$club, $invoice];
    }

    private function start(Tenant $club, Invoice $invoice): PaymentIntent
    {
        return $this->asTenant($club, fn () => app(OnlinePaymentService::class)->start($invoice->fresh(), 'whatsapp'));
    }

    public function test_a_claim_being_opened_refuses_a_second_checkout_with_a_clear_message(): void
    {
        [$club, $invoice] = $this->setUpClub();
        $this->asTenant($club, fn () => PaymentIntent::create(['invoice_id' => $invoice->id, 'provider' => 'paymob', 'reference' => 'PT-CLAIMED', 'amount' => '10.000',
            'currency' => 'OMR', 'status' => PaymentIntent::PENDING, 'channel' => 'whatsapp', 'expires_at' => now()->addHour()]));
        Http::fake();

        try {
            $this->start($club, $invoice);
            $this->fail('a second checkout was opened');
        } catch (BusinessRuleException $e) {
            $this->assertSame('paymob.opening', $e->rule);
            $this->assertStringContainsString('نافذة أخرى', $e->getMessage());
        }
        Http::assertNothingSent();

        // Through the pay link: a page with the message, not an error page.
        $this->post(route('site.pay.start', ['club' => $club->slug, 'token' => $invoice->public_token]))->assertRedirect();
        Http::assertNothingSent();
    }

    public function test_a_claim_left_by_a_request_that_died_does_not_block_the_link(): void
    {
        [$club, $invoice] = $this->setUpClub();
        $this->asTenant($club, function () use ($invoice) {
            $dead = PaymentIntent::create(['invoice_id' => $invoice->id, 'provider' => 'paymob', 'reference' => 'PT-DEAD', 'amount' => '10.000',
                'currency' => 'OMR', 'status' => PaymentIntent::PENDING, 'channel' => 'whatsapp', 'expires_at' => now()->addHour()]);
            $dead->forceFill(['created_at' => now()->subSeconds(OnlinePaymentService::OPENING_SECONDS + 1)])->save();
        });
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs', 'intention_order_id' => 1], 201)]);

        $this->assertNotNull($this->start($club, $invoice)->checkout_url);
    }

    public function test_paymob_unreachable_or_timing_out_fails_the_claim_and_the_retry_opens_one(): void
    {
        [$club, $invoice] = $this->setUpClub();
        $calls = 0;
        Http::fake(function () use (&$calls) {
            return match (++$calls) {
                1 => throw new ConnectionException('Could not resolve host'),
                2 => throw new ConnectionException('cURL error 28: Operation timed out after 20001 milliseconds'),
                default => Http::response(['client_secret' => 'cs', 'intention_order_id' => 2], 201),
            };
        });
        foreach ([1, 2] as $attempt) {
            try {
                $this->start($club, $invoice);
                $this->fail('opened without Paymob');
            } catch (BusinessRuleException $e) {
                $this->assertSame('paymob.connection', $e->rule);
            }
        }
        $this->assertSame([PaymentIntent::FAILED, PaymentIntent::FAILED], $this->asTenant($club, fn () => PaymentIntent::where('invoice_id', $invoice->id)->pluck('status')->all()));

        $opened = $this->start($club, $invoice);
        $this->assertNotNull($opened->checkout_url);
        $this->assertSame($opened->reference, $this->start($club, $invoice)->reference, 'opened again: the same checkout');
        $this->assertSame(3, $calls, 'two failures, one checkout, and opening it again asks Paymob nothing');
    }

    public function test_a_payment_at_the_desk_changes_what_is_owed_and_the_link_asks_only_the_rest(): void
    {
        [$club, $invoice] = $this->setUpClub();
        $this->fundAccounts($club);
        Http::fake(['*/v1/intention/' => Http::sequence()->push(['client_secret' => 'a', 'intention_order_id' => 3], 201)->push(['client_secret' => 'b', 'intention_order_id' => 4], 201)]);
        $this->start($club, $invoice);
        $this->asTenant($club, fn () => app(PaymentService::class)->record($invoice->fresh(), '4.000', 'cash'));

        $this->assertSame('6.000', $this->start($club, $invoice)->amount);
    }

    public function test_a_paid_or_void_invoice_opens_nothing(): void
    {
        [$club, $invoice] = $this->setUpClub();
        Http::fake();
        $this->asTenant($club, fn () => $invoice->forceFill(['status' => Invoice::VOID])->save());

        $this->expectExceptionObject(new BusinessRuleException('invoice.not_open', 'لا يوجد مبلغ مستحق على هذه الفاتورة.'));
        try {
            $this->start($club, $invoice);
        } finally {
            Http::assertNothingSent();
        }
    }
}
