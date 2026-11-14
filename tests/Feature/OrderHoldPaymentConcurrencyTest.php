<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\OrderService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * Website orders paid through Paymob, with requests that really run at the same time on PostgreSQL (one process each):
 * two customers after the last unit, and Paymob delivering the same callback twice at once. Paymob is faked.
 */
class OrderHoldPaymentConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    private function bar(Tenant $club, int $units): Product
    {
        return $this->asTenant($club, function () use ($units) {
            TenantIntegration::create([
                'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
                'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => 'hmac-test', 'integration_ids' => '111'],
            ]);
            $bar = Product::create(['name' => 'Bar', 'price' => 1.5, 'show_on_website' => true]);
            app(StockService::class)->move($bar, $units, 'adjustment', null, 'opening', 0.8);

            return $bar->fresh();
        });
    }

    /** Inside a task: hold right after the products are locked for the basket, and say so. */
    private static function holdAfterLockingProducts(string $dir, string $signal): void
    {
        $held = false;
        DB::listen(function ($query) use ($dir, $signal, &$held) {
            if (! $held && str_contains($query->sql, 'from "products"') && str_contains($query->sql, 'for update')) {
                $held = true;
                file_put_contents("$dir/$signal", '1');
                usleep(800_000);
            }
        });
    }

    public function test_two_customers_after_the_last_unit_at_once_one_gets_it_and_nothing_is_oversold(): void
    {
        $club = $this->club();
        $bar = $this->bar($club, 1);
        $place = fn (string $who) => $this->asTenant($club, fn () => app(OrderService::class)
            ->place([['product_id' => $bar->id, 'quantity' => 1]], ['name' => $who, 'phone' => '96891234567'], 'paymob')->number);

        $results = $this->concurrently([
            function (string $dir) use ($place) {
                self::holdAfterLockingProducts($dir, 'first');

                return $place('First');
            },
            function (string $dir) use ($place) {
                self::waitFor("$dir/first");
                if (! is_file("$dir/first")) {
                    return 'not at the same time';
                }

                return $place('Second');
            },
        ]);

        $this->assertSame(1, collect($results)->where('ok', true)->count(), json_encode($results));
        $this->assertStringContainsString('BusinessRuleException', (string) collect($results)->firstWhere('ok', false)['error'], json_encode($results));
        $this->asTenant($club, function () use ($bar) {
            $this->assertSame('0.000', $bar->fresh()->stock_quantity, 'the last unit is held once, never below zero');
            $this->assertSame(1, Sale::count());
            $this->assertSame(1, Invoice::count(), 'the refused basket left no invoice behind');
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'));
        });
    }

    public function test_the_same_paymob_callback_twice_at_once_records_the_money_once(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_twice', 'intention_order_id' => 4700], 201)]);
        $club = $this->club();
        $bar = $this->bar($club, 3);
        [$sale, $obj] = $this->asTenant($club, function () use ($bar) {
            $sale = app(OrderService::class)->place([['product_id' => $bar->id, 'quantity' => 1]], ['name' => 'Online', 'phone' => '96891234567'], 'paymob');
            $intent = app(OnlinePaymentService::class)->start(Invoice::findOrFail($sale->invoice_id), 'shop');

            return [$sale, ['id' => 990001, 'pending' => false, 'amount_cents' => 1500, 'success' => true, 'is_voided' => false, 'is_refunded' => false,
                'error_occured' => false, 'currency' => 'OMR', 'order' => ['id' => 4700, 'merchant_order_id' => $intent->reference],
                'created_at' => '2026-11-14T10:00:00', 'is_auth' => false, 'is_capture' => false, 'is_standalone_payment' => true,
                'is_3d_secure' => true, 'integration_id' => 111, 'has_parent_transaction' => false, 'owner' => 1,
                'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card']]];
        });
        $hmac = PaymobSignature::compute($obj, 'hmac-test');
        $deliver = fn () => app(OnlinePaymentService::class)->handleWebhook(['type' => 'TRANSACTION', 'obj' => $obj], $hmac);

        $results = $this->concurrently([
            function (string $dir) use ($deliver) {
                self::pauseAfter('select * from "payment_intents" where "payment_intents"."id" = ? and "payment_intents"."tenant_id" = ? limit 1 for update', $dir, 0.8);

                return $deliver();
            },
            function (string $dir) use ($deliver) {
                self::waitFor("$dir/inside");
                if (! is_file("$dir/inside")) {
                    return 'not at the same time';
                }

                return $deliver();
            },
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertEqualsCanonicalizing(['processed', 'duplicate'], array_column($results, 'value'), json_encode($results));
        $this->asTenant($club, function () use ($sale, $bar) {
            $this->assertSame(1, Payment::where('gateway', 'paymob')->count(), 'the money is recorded once');
            $this->assertSame(Invoice::PAID, Invoice::find($sale->invoice_id)->status);
            $this->assertNotNull($sale->fresh()->paid_at);
            $this->assertSame(PaymentIntent::PAID, PaymentIntent::sole()->status);
            $this->assertSame('2.000', $bar->fresh()->stock_quantity, 'one unit held, once');
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'));
        });
    }
}
