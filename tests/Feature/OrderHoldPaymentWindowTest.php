<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Core\Support\Money;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Finance\Events\InvoicePaid;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\OrderService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Models\PaymentWebhookEvent;
use App\Modules\Payments\Paymob\PaymobSignature;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A website order paid through Paymob: its stock is held until reserved_until (the shop's payment window), its checkout
 * never outlives that hold, and whatever Paymob says, in whatever order and however late, leaves the stock, the order,
 * the invoice and the money consistent. Paymob is faked: no request leaves, no money moves.
 */
class OrderHoldPaymentWindowTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const ON_SHELF = '5.000';

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_hold', 'intention_order_id' => 4600], 201)]);
    }

    private function shop(Tenant $club, int $units = 5): Product
    {
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => 'hmac-test', 'integration_ids' => '111'],
        ]));

        return $this->asTenant($club, function () use ($units) {
            $product = Product::create(['name' => 'Protein bar', 'price' => 1.5, 'show_on_website' => true]);
            app(StockService::class)->move($product, $units, 'adjustment', null, 'opening', 0.8);

            return $product->fresh();
        });
    }

    /** @return array{0: Sale, 1: PaymentIntent} placed on the website, off to Paymob's page */
    private function onlineOrder(Tenant $club, Product $product): array
    {
        $this->post(route('site.shop.order.store', ['club' => $club->slug]), [
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'name' => 'Online', 'phone' => '91234567', 'payment_method' => 'paymob',
        ])->assertRedirectContains('clientSecret=cs_hold');

        return $this->asTenant($club, fn () => [Sale::latest('id')->firstOrFail(), PaymentIntent::latest('id')->firstOrFail()]);
    }

    /** Paymob's signed callback for $intent: paid, or declined. */
    private function paymob(PaymentIntent $intent, int $transaction, bool $paid = true): TestResponse
    {
        $obj = ['id' => $transaction, 'pending' => false, 'amount_cents' => (int) round((float) $intent->amount * 1000), 'success' => $paid,
            'is_voided' => false, 'is_refunded' => false, 'error_occured' => false, 'currency' => 'OMR',
            'order' => ['id' => 4600, 'merchant_order_id' => $intent->reference], 'created_at' => '2026-11-14T10:00:00', 'is_auth' => false,
            'is_capture' => false, 'is_standalone_payment' => true, 'is_3d_secure' => true, 'integration_id' => 111, 'has_parent_transaction' => false,
            'owner' => 1, 'data' => ['message' => $paid ? 'Approved' : 'Do not honour'], 'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card']];

        $this->app->forgetScopedInstances(); // each callback is a request of its own, as on the server

        // As Paymob posts it: a JSON body, without asking for JSON back.
        return $this->call('POST', route('webhooks.paymob').'?hmac='.PaymobSignature::compute($obj, 'hmac-test'), [], [], [],
            ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => '*/*'], json_encode(['type' => 'TRANSACTION', 'obj' => $obj]));
    }

    /** The hold of $sale ended $minutes ago, then the scheduler ran. */
    private function holdEnded(Tenant $club, Sale $sale, int $minutes): void
    {
        $this->asTenant($club, fn () => $sale->forceFill(['reserved_until' => now()->subMinutes($minutes)])->save());
        Artisan::call('sales:expire-orders');
    }

    private function pastGrace(): int
    {
        return OrderService::PAYMENT_GRACE_MINUTES + 1;
    }

    /** Stock, order, invoice, payments, intent and books, read together. */
    private function state(Tenant $club, Sale $sale, Product $product, PaymentIntent $intent): array
    {
        return $this->asTenant($club, function () use ($sale, $product, $intent) {
            $sale = $sale->fresh();
            $invoice = Invoice::findOrFail($sale->invoice_id);

            return [
                'stock' => $product->fresh()->stock_quantity,
                'order' => $sale->status,
                'paid_at' => $sale->paid_at !== null,
                'reserved' => $sale->reserved_until !== null,
                'invoice' => $invoice->status,
                'payments' => Payment::where('gateway', 'paymob')->count(),
                'credit' => Money::normalize((string) Payment::where('gateway', 'paymob')->sum('credit_amount')),
                'intent' => $intent->fresh()->status,
                'books' => JournalLine::sum('debit') === JournalLine::sum('credit'),
            ];
        });
    }

    // 1
    public function test_paid_before_the_hold_ends_the_order_is_paid_once_and_the_scheduler_leaves_it(): void
    {
        $club = $this->club();
        $bar = $this->shop($club);
        [$sale, $intent] = $this->onlineOrder($club, $bar);
        $this->assertSame('4.000', $this->asTenant($club, fn () => $bar->fresh()->stock_quantity), 'held while the customer pays');

        $this->paymob($intent, 900001)->assertOk()->assertJson(['outcome' => 'processed']);

        $this->travel(2)->days();
        Artisan::call('sales:expire-orders');
        $state = $this->state($club, $sale, $bar, $intent);
        $this->assertSame(['stock' => '4.000', 'order' => Sale::PENDING, 'paid_at' => true, 'reserved' => false, 'invoice' => Invoice::PAID,
            'payments' => 1, 'credit' => '0.000', 'intent' => PaymentIntent::PAID, 'books' => true], $state);
    }

    // 2
    public function test_a_declined_payment_keeps_the_hold_for_another_try_then_the_stock_goes_back_once(): void
    {
        $club = $this->club();
        $bar = $this->shop($club);
        [$sale, $intent] = $this->onlineOrder($club, $bar);

        $this->paymob($intent, 900002, paid: false)->assertOk()->assertJson(['outcome' => 'failed_payment']);
        $this->assertSame(['4.000', Sale::PENDING, PaymentIntent::FAILED], $this->asTenant($club, fn () => [$bar->fresh()->stock_quantity, $sale->fresh()->status, $intent->fresh()->status]),
            'the window is still open: the customer may try another card');

        $this->holdEnded($club, $sale, $this->pastGrace());
        Artisan::call('sales:expire-orders'); // and again: nothing is put back twice
        $state = $this->state($club, $sale, $bar, $intent);
        $this->assertSame([self::ON_SHELF, Sale::CANCELLED, Invoice::VOID, 0], [$state['stock'], $state['order'], $state['invoice'], $state['payments']]);
    }

    // 3
    public function test_a_hold_ending_unpaid_is_released_after_the_grace_and_only_once(): void
    {
        $club = $this->club();
        $bar = $this->shop($club);
        [$sale, $intent] = $this->onlineOrder($club, $bar);
        $this->assertTrue($intent->expires_at->lte($sale->reserved_until), 'the checkout Paymob was given ends with the hold');

        $this->holdEnded($club, $sale, 1);
        $this->assertSame(Sale::PENDING, $this->asTenant($club, fn () => $sale->fresh()->status), 'a last-moment payment\'s callback is waited for');

        $this->holdEnded($club, $sale, $this->pastGrace());
        Artisan::call('sales:expire-orders');
        $state = $this->state($club, $sale, $bar, $intent);
        $this->assertSame([self::ON_SHELF, Sale::CANCELLED, Invoice::VOID, 0, PaymentIntent::PENDING, true],
            [$state['stock'], $state['order'], $state['invoice'], $state['payments'], $state['intent'], $state['books']]);
    }

    // 4
    public function test_money_arriving_after_the_release_is_kept_as_credit_and_takes_no_stock(): void
    {
        $club = $this->club();
        $bar = $this->shop($club, 1); // the last one
        [$sale, $intent] = $this->onlineOrder($club, $bar);
        $this->holdEnded($club, $sale, $this->pastGrace());
        $this->asTenant($club, fn () => $this->assertSame('1.000', $bar->fresh()->stock_quantity));

        $this->paymob($intent, 900004)->assertOk()->assertJson(['outcome' => 'held_for_review']);

        $state = $this->state($club, $sale, $bar, $intent);
        $this->assertSame(['stock' => '1.000', 'order' => Sale::CANCELLED, 'paid_at' => false, 'reserved' => false, 'invoice' => Invoice::VOID,
            'payments' => 1, 'credit' => $intent->amount, 'intent' => PaymentIntent::PAID, 'books' => true], $state,
            'the order stays released, the bar stays on the shelf, and the money is the customer\'s credit, not lost');
        $this->asTenant($club, fn () => $this->assertSame(1, PaymentWebhookEvent::where('transaction_id', '900004')->where('outcome', 'held_for_review')->count()));
    }

    // 5
    public function test_a_repeated_callback_records_nothing_more(): void
    {
        $club = $this->club();
        $bar = $this->shop($club);
        [$sale, $intent] = $this->onlineOrder($club, $bar);

        $this->paymob($intent, 900005)->assertOk()->assertJson(['outcome' => 'processed']);
        $this->paymob($intent, 900005)->assertOk()->assertJson(['outcome' => 'duplicate']);
        $this->paymob($intent, 900005)->assertOk()->assertJson(['outcome' => 'duplicate']);

        $state = $this->state($club, $sale, $bar, $intent);
        $this->assertSame(['4.000', true, 1, '0.000', Invoice::PAID, true], [$state['stock'], $state['paid_at'], $state['payments'], $state['credit'], $state['invoice'], $state['books']]);
    }

    // 6
    public function test_callbacks_in_any_order_end_the_same_way(): void
    {
        $club = $this->club();
        $bar = $this->shop($club);

        // A decline that arrives after the success it preceded does not undo it.
        [$first, $firstIntent] = $this->onlineOrder($club, $bar);
        $this->paymob($firstIntent, 900061)->assertOk();
        $this->paymob($firstIntent, 900060, paid: false)->assertOk();
        $state = $this->state($club, $first, $bar, $firstIntent);
        $this->assertSame([PaymentIntent::PAID, true, Invoice::PAID, 1], [$state['intent'], $state['paid_at'], $state['invoice'], $state['payments']]);

        // A success that arrives after a decline (another card, same checkout) pays the order.
        [$second, $secondIntent] = $this->onlineOrder($club, $bar);
        $this->paymob($secondIntent, 900062, paid: false)->assertOk();
        $this->paymob($secondIntent, 900063)->assertOk()->assertJson(['outcome' => 'processed']);
        $this->assertSame([PaymentIntent::PAID, true, Invoice::PAID], $this->asTenant($club, fn () => [
            $secondIntent->fresh()->status, $second->fresh()->paid_at !== null, Invoice::findOrFail($second->invoice_id)->status,
        ]));
        $this->asTenant($club, fn () => $this->assertSame('3.000', $bar->fresh()->stock_quantity, 'two orders, two units held, none twice'));
    }

    // 8
    public function test_paying_again_once_the_hold_is_over_opens_no_checkout(): void
    {
        $club = $this->club();
        $bar = $this->shop($club);
        [$sale, $intent] = $this->onlineOrder($club, $bar);

        // The hold is over but the scheduler has not released it yet: the order page and every channel refuse.
        $this->holdEnded($club, $sale, 1);
        $this->post(route('site.shop.order.pay', ['club' => $club->slug, 'token' => $sale->public_token]))->assertSessionHasErrors();
        $this->asTenant($club, function () use ($sale) {
            $invoice = Invoice::findOrFail($sale->invoice_id);
            foreach (['shop', 'portal', 'whatsapp'] as $channel) {
                try {
                    app(OnlinePaymentService::class)->start($invoice, $channel);
                    $this->fail("a checkout opened on $channel after the hold");
                } catch (BusinessRuleException $e) {
                    $this->assertSame('order.not_payable', $e->rule);
                }
            }
        });

        // Released: still nothing to pay.
        $this->holdEnded($club, $sale, $this->pastGrace());
        $this->post(route('site.shop.order.pay', ['club' => $club->slug, 'token' => $sale->public_token]))->assertSessionHasErrors();
        Http::assertSentCount(1); // the first checkout, when the order was placed
        $this->asTenant($club, fn () => $this->assertSame(1, PaymentIntent::count(), 'only the first checkout was ever opened'));
    }

    // 9
    public function test_a_step_failing_while_the_payment_is_recorded_undoes_it_all_keeps_the_callback_and_the_retry_records_it(): void
    {
        $club = $this->club();
        $bar = $this->shop($club);
        [$sale, $intent] = $this->onlineOrder($club, $bar);

        // A business rule refusing, then a plain error: a step after the payment is applied fails either way.
        $failures = [new BusinessRuleException('test.refused', 'رُفض أثناء التسجيل'), new RuntimeException('a step after the payment failed')];
        $fail = null;
        Event::listen(InvoicePaid::class, function () use (&$fail) {
            if ($fail) {
                throw $fail;
            }
        });

        foreach ($failures as $i => $fail) {
            $this->paymob($intent, 900009)->assertStatus(500); // not 2xx, nor a redirect: Paymob is told it was not taken
            $state = $this->state($club, $sale, $bar, $intent);
            $this->assertSame(['stock' => '4.000', 'order' => Sale::PENDING, 'paid_at' => false, 'reserved' => true, 'invoice' => Invoice::ISSUED,
                'payments' => 0, 'credit' => '0.000', 'intent' => PaymentIntent::PENDING, 'books' => true], $state, 'nothing half-written');

            // The signed callback itself is not lost: its transaction, reference and payload are on record.
            $kept = $this->asTenant($club, fn () => PaymentWebhookEvent::where('transaction_id', '900009')->orderBy('id')->get());
            $this->assertCount($i + 1, $kept);
            $this->assertSame(['error', true, $intent->reference, 900009], [$kept->last()->outcome, $kept->last()->signature_valid, $kept->last()->reference, $kept->last()->payload['obj']['id']]);
        }

        $fail = null;
        $this->paymob($intent, 900009)->assertOk()->assertJson(['outcome' => 'processed']); // Paymob sends it again
        $state = $this->state($club, $sale, $bar, $intent);
        $this->assertSame(['4.000', true, Invoice::PAID, 1, PaymentIntent::PAID], [$state['stock'], $state['paid_at'], $state['invoice'], $state['payments'], $state['intent']]);
    }

    // 10
    public function test_finished_and_older_orders_are_left_as_they_are(): void
    {
        $club = $this->club();
        $bar = $this->shop($club);
        [$paid, $paidIntent] = $this->onlineOrder($club, $bar);
        $this->paymob($paidIntent, 900010)->assertOk();
        $this->actingAs($this->owner($club));
        [$pickup, $delivered] = $this->asTenant($club, function () use ($bar, $paid) {
            $orders = app(OrderService::class);
            $pickup = $orders->place([['product_id' => $bar->id, 'quantity' => 1]], ['name' => 'Desk', 'phone' => '91234568'], 'pickup');
            $delivered = $orders->deliver($orders->place([['product_id' => $bar->id, 'quantity' => 1]], ['name' => 'Done', 'phone' => '91234569'], 'pickup'), 'cash');
            // An order from before the hold was bounded: paid, its hold long gone.
            $paid->forceFill(['reserved_until' => null, 'created_at' => now()->subMonths(2)])->save();

            return [$pickup, $delivered];
        });
        $before = $this->asTenant($club, fn () => [Sale::orderBy('id')->get(['id', 'status', 'paid_at', 'reserved_until'])->toArray(), $bar->fresh()->stock_quantity, Payment::count()]);

        $this->travel(3)->days();
        Artisan::call('sales:expire-orders');

        $this->assertSame($before, $this->asTenant($club, fn () => [Sale::orderBy('id')->get(['id', 'status', 'paid_at', 'reserved_until'])->toArray(), $bar->fresh()->stock_quantity, Payment::count()]));
        $this->assertSame([Sale::PENDING, Sale::COMPLETED], $this->asTenant($club, fn () => [$pickup->fresh()->status, $delivered->fresh()->status]));
    }
}
