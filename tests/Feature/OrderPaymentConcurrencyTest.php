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
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * A website order's Paymob callback and a cancellation (by staff, or the scheduler at the end of the window) arriving
 * at the same moment, in separate processes on PostgreSQL. Both take the invoice's lock before the order's, so one
 * waits for the other instead of the two deadlocking and one of them failing. Each order is run on purpose: the first
 * holds the invoice until PostgreSQL shows the second blocked by it, so each test proves the overlap and its outcome.
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

    /**
     * $first takes the invoice and holds it until PostgreSQL shows $second blocked by it (RunsConcurrently's barrier):
     * the order of the two is proven, not left to timing. $first's result comes back as [value, blocked].
     *
     * @return list<array{ok: bool, value: mixed, error: ?string}>
     */
    private function race(callable $first, callable $second): array
    {
        return $this->concurrently([
            function (string $dir) use ($first) {
                self::holdUntilAnotherWaits($dir, fn (string $sql) => str_contains($sql, 'from "invoices"'));
                $value = $first();

                return [$value, is_file("$dir/blocked")];
            },
            function (string $dir) use ($second) {
                self::waitFor("$dir/inside");

                return is_file("$dir/inside") ? $second() : 'not at the same time';
            },
        ]);
    }

    private function paymobCallback(array $obj, string $hmac): callable
    {
        return fn () => app(OnlinePaymentService::class)->handleWebhook(['type' => 'TRANSACTION', 'obj' => $obj], $hmac);
    }

    /** @param  list<array{ok: bool, value: mixed, error: ?string}>  $results */
    private function assertOutcomes(array $expected, array $results): void
    {
        foreach ($results as $result) {
            $this->assertStringNotContainsString('deadlock', (string) $result['error'], json_encode($results));
        }
        $this->assertSame($expected, array_map(fn ($r) => [$r['ok'], $r['value']], $results), json_encode($results, JSON_UNESCAPED_UNICODE));
    }

    /** Whatever the order: the money recorded once (never lost), the intent paid once, the books balanced. */
    private function assertMoney(Tenant $club, string $credit): void
    {
        $this->asTenant($club, function () use ($credit) {
            $this->assertSame($credit, Payment::where('kind', Payment::KIND_PAYMENT)->where('gateway', 'paymob')->sole()->credit_amount);
            $this->assertSame(1, PaymentIntent::where('status', PaymentIntent::PAID)->count());
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'), 'the books balance');
        });
    }

    public function test_a_paymob_callback_first_then_a_staff_cancellation_waits_and_follows(): void
    {
        $club = $this->club();
        [$sale, $obj, $hmac, $bar] = $this->paymobOrder($club);

        $results = $this->race($this->paymobCallback($obj, $hmac),
            fn () => $this->asTenant($club, fn () => app(OrderService::class)->cancel($sale, 'العميل ألغى', 'paymob')->status));

        // Paid in time, then cancelled by staff as any paid order: the payment applied, the stock back.
        $this->assertOutcomes([[true, ['processed', true]], [true, Sale::CANCELLED]], $results);
        $this->assertMoney($club, '0.000');
        $this->asTenant($club, fn () => $this->assertSame([Sale::CANCELLED, '10.000'], [$sale->fresh()->status, $bar->fresh()->stock_quantity]));
    }

    public function test_a_staff_cancellation_first_then_the_paymob_callback_waits_and_holds_the_money(): void
    {
        $club = $this->club();
        [$sale, $obj, $hmac, $bar] = $this->paymobOrder($club);

        $results = $this->race(fn () => $this->asTenant($club, fn () => app(OrderService::class)->cancel($sale, 'العميل ألغى', 'paymob')->status),
            $this->paymobCallback($obj, $hmac));

        // Cancelled first (nothing paid yet, so nothing paid back): the late money is recorded in full and held for review
        // on the voided invoice, never applied; the stock is back once.
        $this->assertOutcomes([[true, [Sale::CANCELLED, true]], [true, 'held_for_review']], $results);
        $this->assertMoney($club, '1.500');
        $this->asTenant($club, fn () => $this->assertSame([Sale::CANCELLED, Invoice::VOID, '10.000', 0],
            [$sale->fresh()->status, Invoice::findOrFail($sale->invoice_id)->status, $bar->fresh()->stock_quantity, Payment::where('kind', Payment::KIND_REFUND)->count()]));
    }

    public function test_the_window_expiring_first_then_the_paymob_callback_holds_the_money(): void
    {
        $club = $this->club();
        [$sale, $obj, $hmac, $bar] = $this->paymobOrder($club);
        $this->asTenant($club, fn () => $sale->forceFill(['reserved_until' => now()->subMinutes(10)])->save());

        $results = $this->race(fn () => $this->asTenant($club, fn () => app(OrderService::class)->expireOverdue()), $this->paymobCallback($obj, $hmac));

        $this->assertOutcomes([[true, [1, true]], [true, 'held_for_review']], $results);
        $this->assertMoney($club, '1.500');
        $this->asTenant($club, fn () => $this->assertSame([Sale::CANCELLED, null, '10.000'], [$sale->fresh()->status, $sale->fresh()->paid_at, $bar->fresh()->stock_quantity]));
    }

    public function test_a_paymob_callback_after_the_window_first_then_the_expiry_still_releases_the_order(): void
    {
        $club = $this->club();
        [$sale, $obj, $hmac, $bar] = $this->paymobOrder($club);
        $this->asTenant($club, fn () => $sale->forceFill(['reserved_until' => now()->subMinutes(10)])->save());

        $results = $this->race($this->paymobCallback($obj, $hmac), fn () => $this->asTenant($club, fn () => app(OrderService::class)->expireOverdue()));

        // The callback saw the hold over: held, not applied, so the order is still unpaid and the expiry releases it.
        $this->assertOutcomes([[true, ['held_for_review', true]], [true, 1]], $results);
        $this->assertMoney($club, '1.500');
        $this->asTenant($club, fn () => $this->assertSame([Sale::CANCELLED, null, '10.000'], [$sale->fresh()->status, $sale->fresh()->paid_at, $bar->fresh()->stock_quantity]));
    }

    public function test_a_paymob_callback_in_time_first_then_the_expiry_leaves_the_paid_order_alone(): void
    {
        $club = $this->club();
        [$sale, $obj, $hmac, $bar] = $this->paymobOrder($club);

        // The expiry runs as if its time had come meanwhile (past the hold and its grace), while the callback holds the invoice.
        $results = $this->race($this->paymobCallback($obj, $hmac), function () use ($club) {
            $this->travelTo(now()->addMinutes(60));

            return $this->asTenant($club, fn () => app(OrderService::class)->expireOverdue());
        });

        // Paid in time: the expiry, once it has the lock, sees the order paid and leaves it to staff; no stock released.
        $this->assertOutcomes([[true, ['processed', true]], [true, 0]], $results);
        $this->assertMoney($club, '0.000');
        $this->asTenant($club, fn () => $this->assertSame([Sale::PENDING, true, '9.000'], [$sale->fresh()->status, $sale->fresh()->paid_at !== null, $bar->fresh()->stock_quantity]));
    }
}
