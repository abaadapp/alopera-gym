<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\ManualInvoices;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * One invoice's pay link opened in two tabs at the same moment (two real PostgreSQL processes): one checkout, never two
 * a customer could both pay. The second tab gets the same checkout, or is told one is being opened; Paymob is never
 * called under the invoice's lock, so the second tab is not kept waiting for it.
 */
class PayLinkTwoTabsConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    private const HMAC = 'two-tabs-hmac';

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

    private function claim(Tenant $club, string $price = '12.345'): Invoice
    {
        $this->actingAs($this->owner($club));

        return $this->asTenant($club, fn () => app(ManualInvoices::class)->issue([
            'customer_mode' => 'new', 'customer_name' => 'عميل', 'customer_phone' => '96891234567',
            'issue_date' => today()->toDateString(), 'due_date' => today()->addDays(7)->toDateString(), 'payment_terms_days' => 7,
            'lines' => [['description' => 'خدمة', 'quantity' => 1, 'unit_price' => $price]],
        ], ['method' => 'credit']));
    }

    /** A tab opening the link: the checkout's reference, or the rule it was refused with. */
    private static function open(Tenant $club, Invoice $invoice): string
    {
        try {
            return app(TenantContext::class)->run($club, fn () => app(OnlinePaymentService::class)->start($invoice->fresh(), 'whatsapp')->reference);
        } catch (BusinessRuleException $e) {
            return 'refused:'.$e->rule.':'.$e->getMessage();
        }
    }

    private static function deliver(PaymentIntent $intent, int $tx): string
    {
        $obj = [
            'id' => $tx, 'pending' => false, 'amount_cents' => Money::toMinor($intent->amount), 'success' => true, 'is_auth' => false,
            'is_capture' => false, 'is_standalone_payment' => true, 'is_voided' => false, 'is_refunded' => false, 'is_3d_secure' => true,
            'integration_id' => 111, 'has_parent_transaction' => false, 'order' => ['id' => (int) $intent->provider_order_id, 'merchant_order_id' => $intent->reference],
            'created_at' => '2026-11-15T10:00:00', 'currency' => 'OMR', 'error_occured' => false, 'owner' => 1,
            'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card'],
        ];

        return app(OnlinePaymentService::class)->handleWebhook(['type' => 'TRANSACTION', 'obj' => $obj], PaymobSignature::compute($obj, self::HMAC));
    }

    /** @return Collection<int, PaymentIntent> */
    private function intents(Tenant $club, Invoice $invoice)
    {
        return $this->asTenant($club, fn () => PaymentIntent::where('invoice_id', $invoice->id)->orderBy('id')->get());
    }

    public function test_two_tabs_at_once_get_one_checkout(): void
    {
        $club = $this->paymobClub();
        $invoice = $this->claim($club);
        $orders = [5001, 5002];
        Http::fake(['*/v1/intention/' => function () use (&$orders) {
            return Http::response(['client_secret' => 'cs_'.getmypid(), 'intention_order_id' => getmypid()], 201);
        }]);

        $results = $this->concurrently([
            function (string $dir) use ($club, $invoice) {
                self::pauseAfter('insert into "payment_intents"', $dir, 0.8);

                return self::open($club, $invoice);
            },
            function (string $dir) use ($club, $invoice) {
                self::waitFor("$dir/inside");
                if (! is_file("$dir/inside")) {
                    return 'not at the same time';
                }

                return self::open($club, $invoice);
            },
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $values = array_column($results, 'value');
        $usable = $this->intents($club, $invoice)->filter(fn ($i) => $i->status === PaymentIntent::PENDING && $i->checkout_url !== null);
        $this->assertCount(1, $usable, 'one checkout a customer can pay: '.json_encode($values, JSON_UNESCAPED_UNICODE));
        $this->assertSame($usable->first()->reference, $values[0]);
        $this->assertTrue($values[1] === $values[0] || str_starts_with($values[1], 'refused:paymob.opening:'), json_encode($values, JSON_UNESCAPED_UNICODE));
    }

    public function test_the_second_tab_is_not_kept_waiting_while_paymob_answers_the_first(): void
    {
        $club = $this->paymobClub();
        $invoice = $this->claim($club);
        Http::fake(['*/v1/intention/' => function () {
            usleep(1_500_000); // a slow Paymob

            return Http::response(['client_secret' => 'cs_slow', 'intention_order_id' => 6001], 201);
        }]);

        $results = $this->concurrently([
            function (string $dir) use ($club, $invoice) {
                // Inside the call to Paymob: from here on the first tab holds no lock of the invoice.
                Http::globalRequestMiddleware(function ($request) use ($dir) {
                    touch("$dir/inside");

                    return $request;
                });

                return self::open($club, $invoice);
            },
            function (string $dir) use ($club, $invoice) {
                self::waitFor("$dir/inside");
                $at = microtime(true);
                $value = self::open($club, $invoice);

                return [$value, round(microtime(true) - $at, 2)];
            },
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        [$second, $took] = $results[1]['value'];
        $this->assertStringStartsWith('refused:paymob.opening:', $second);
        $this->assertLessThan(1.0, $took, 'answered without waiting for Paymob');
        $this->assertCount(1, $this->intents($club, $invoice));
        $this->assertNotNull($this->intents($club, $invoice)->first()->checkout_url);
    }

    public function test_after_two_tabs_and_a_retry_the_money_is_recorded_once_and_the_books_balance(): void
    {
        $club = $this->paymobClub();
        $invoice = $this->claim($club);
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_one', 'intention_order_id' => 7001], 201)]);

        $this->concurrently([
            fn (string $dir) => self::open($club, $invoice),
            fn (string $dir) => self::open($club, $invoice),
        ]);
        $again = self::open($club, $invoice); // the second tab, refreshed
        $usable = $this->intents($club, $invoice)->filter(fn ($i) => $i->checkout_url !== null);
        $this->assertCount(1, $usable);
        $this->assertSame($usable->first()->reference, $again);

        $this->assertSame('processed', $this->asTenant($club, fn () => self::deliver($usable->first(), 990001)));
        $this->assertSame('duplicate', $this->asTenant($club, fn () => self::deliver($usable->first(), 990001)));
        $this->asTenant($club, function () use ($invoice) {
            $this->assertSame([Invoice::PAID, '12.345'], [$invoice->fresh()->status, $invoice->fresh()->paid_total]);
            $this->assertSame(1, Payment::where('gateway', 'paymob')->count());
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'));
        });
    }
}
