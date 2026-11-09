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
 * A website order's Paymob callback and a cancellation (by staff, or the scheduler at the end of the window) arriving
 * at the same moment, in separate processes on PostgreSQL. Both take the invoice's lock before the order's, so one
 * waits for the other instead of the two deadlocking and one of them failing.
 */
class OrderPaymentConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    /** @return array{0: Sale, 1: array<string, mixed>, 2: string, 3: Product} the order, the signed callback, its hmac, the product */
    private function paymobOrder(Tenant $club): array
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_abc', 'intention_order_id' => 4411], 201)]);

        return $this->asTenant($club, function () {
            TenantIntegration::create([
                'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
                'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => 'hmac-test', 'integration_ids' => '111'],
            ]);
            $bar = Product::create(['name' => 'Bar', 'price' => 1.5, 'show_on_website' => true]);
            app(StockService::class)->move($bar, 10, 'adjustment', null, 'opening', 0.8);
            $sale = app(OrderService::class)->place([['product_id' => $bar->id, 'quantity' => 1]], ['name' => 'Online', 'phone' => '96891234567'], 'paymob');
            $intent = app(OnlinePaymentService::class)->start(Invoice::findOrFail($sale->invoice_id), 'shop');

            $obj = ['id' => 880001, 'pending' => false, 'amount_cents' => 1500, 'success' => true, 'is_voided' => false, 'is_refunded' => false,
                'error_occured' => false, 'currency' => 'OMR', 'order' => ['id' => 4411, 'merchant_order_id' => $intent->reference],
                'created_at' => '2026-11-08T10:00:00', 'is_auth' => false, 'is_capture' => false, 'is_standalone_payment' => true,
                'is_3d_secure' => true, 'integration_id' => 111, 'has_parent_transaction' => false, 'owner' => 1,
                'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card']];

            return [$sale, $obj, PaymobSignature::compute($obj, 'hmac-test'), $bar];
        });
    }

    /** Inside a task: hold right after the first row lock taken on $table, and say so. */
    private static function holdAfterLocking(string $table, string $dir, string $signal): void
    {
        $held = false;
        DB::listen(function ($query) use ($table, $dir, $signal, &$held) {
            if (! $held && str_contains($query->sql, 'from "'.$table.'"') && str_contains($query->sql, 'for update')) {
                $held = true;
                file_put_contents("$dir/$signal", '1');
                usleep(800_000);
            }
        });
    }

    public function test_a_paymob_callback_and_a_staff_cancellation_at_once_do_not_deadlock(): void
    {
        $club = $this->club();
        [$sale, $obj, $hmac, $bar] = $this->paymobOrder($club);

        $results = $this->concurrently([
            function (string $dir) use ($club, $sale) {
                self::holdAfterLocking('sales', $dir, 'cancel');

                return $this->asTenant($club, fn () => app(OrderService::class)->cancel($sale, 'العميل ألغى', 'paymob')->status);
            },
            function (string $dir) use ($obj, $hmac) {
                self::holdAfterLocking('invoices', $dir, 'webhook');

                return app(OnlinePaymentService::class)->handleWebhook(['type' => 'TRANSACTION', 'obj' => $obj], $hmac);
            },
        ]);

        foreach ($results as $result) {
            $this->assertStringNotContainsString('deadlock', (string) $result['error'], json_encode($results));
            $this->assertTrue($result['ok'], json_encode($results));
        }
        $this->assertSame('processed', $results[1]['value'], 'the payment is recorded');

        $this->asTenant($club, function () use ($sale, $bar) {
            $this->assertSame(Sale::CANCELLED, $sale->fresh()->status);
            $this->assertSame(1, Payment::where('kind', Payment::KIND_PAYMENT)->where('gateway', 'paymob')->count(), 'the money is recorded once');
            $this->assertSame('10.000', $bar->fresh()->stock_quantity, 'the held stock is back');
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'), 'the books balance');
        });
    }

    public function test_a_paymob_callback_and_the_window_expiring_at_once_do_not_deadlock(): void
    {
        $club = $this->club();
        [$sale, $obj, $hmac] = $this->paymobOrder($club);
        $this->asTenant($club, fn () => $sale->forceFill(['reserved_until' => now()->subMinutes(10)])->save());

        $results = $this->concurrently([
            function (string $dir) use ($club) {
                self::holdAfterLocking('sales', $dir, 'expire');

                return $this->asTenant($club, fn () => app(OrderService::class)->expireOverdue());
            },
            function (string $dir) use ($obj, $hmac) {
                self::holdAfterLocking('invoices', $dir, 'webhook');

                return app(OnlinePaymentService::class)->handleWebhook(['type' => 'TRANSACTION', 'obj' => $obj], $hmac);
            },
        ]);

        foreach ($results as $result) {
            $this->assertStringNotContainsString('deadlock', (string) $result['error'], json_encode($results));
            $this->assertTrue($result['ok'], json_encode($results));
        }
        $this->assertSame('processed', $results[1]['value']);

        $this->asTenant($club, function () use ($sale) {
            $sale = $sale->fresh();
            $payment = Payment::where('gateway', 'paymob')->sole();
            // Whichever went first, the money is never lost: either the order is paid, or it was released and the
            // payment is the customer's credit.
            if ($sale->status === Sale::PENDING) {
                $this->assertNotNull($sale->paid_at);
                $this->assertSame(Invoice::PAID, Invoice::find($sale->invoice_id)->status);
            } else {
                $this->assertSame(Sale::CANCELLED, $sale->status);
                $this->assertSame('1.500', $payment->credit_amount);
            }
            $this->assertSame(1, PaymentIntent::where('status', PaymentIntent::PAID)->count());
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'), 'the books balance');
        });
    }
}
