<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\OrderService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Paymob\PaymobSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** Sales: products, the point of sale by role, the website shop and its orders, the books, isolation. */
class SalesTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function product(Tenant $club, array $attributes = [], int $stock = 10): Product
    {
        return $this->asTenant($club, function () use ($attributes, $stock) {
            $product = Product::create($attributes + ['name' => 'Protein bar', 'price' => 1.5, 'show_on_website' => true]);
            if ($stock) {
                app(StockService::class)->move($product, $stock, 'adjustment', null, 'opening', 0.8);
            }

            return $product->fresh();
        });
    }

    private function balance(Tenant $club, string $key): string
    {
        return $this->asTenant($club, fn () => app(FinanceReports::class)->balanceOf($key));
    }

    private function order(Tenant $club, Product $product, int $qty = 2, string $method = 'pickup'): Sale
    {
        return $this->asTenant($club, fn () => app(OrderService::class)->place([['product_id' => $product->id, 'quantity' => $qty]], ['name' => 'Walk-in', 'phone' => '96891234567'], $method));
    }

    public function test_the_owner_adds_a_product_shown_on_the_website(): void
    {
        $club = $this->club();
        $this->actingAs($this->owner($club))->post(route('club.products.store'), [
            'name' => 'Shaker', 'price' => 3.25, 'track_stock' => true, 'is_active' => true, 'show_on_website' => true, 'opening_stock' => 4, 'opening_cost' => 1,
        ])->assertSessionHasNoErrors();

        $product = $this->asTenant($club, fn () => Product::firstOrFail());
        $this->assertTrue($product->show_on_website);
        $this->assertSame('4.000', $product->stock_quantity);
    }

    public function test_the_owner_sells_at_the_point_of_sale_and_revenue_is_posted_once(): void
    {
        $club = $this->club();
        $bar = $this->product($club);

        $this->actingAs($this->owner($club))->post(route('club.pos.checkout'), [
            'items' => [['product_id' => $bar->id, 'quantity' => 2]], 'payment_method' => 'cash', 'discount' => 0.5, 'customer_name' => 'Ahmed',
        ])->assertSessionHasNoErrors();

        $this->asTenant($club, function () use ($bar) {
            $sale = Sale::with('invoice')->firstOrFail();
            $this->assertSame([Sale::POS, Sale::COMPLETED, '2.500', 'Ahmed'], [$sale->source, $sale->status, $sale->total, $sale->customer_name]);
            $this->assertNotNull($sale->cashier_id);
            $this->assertSame(Invoice::PAID, $sale->invoice->status);
            $this->assertSame('8.000', $bar->fresh()->stock_quantity);
            $this->assertSame(1, Payment::count());
        });
        // The sale record itself posts nothing: the invoice credits sales revenue once, the payment fills the till.
        $this->assertSame('2.500', $this->balance($club, 'revenue_pos'));
        $this->assertSame('2.500', $this->balance($club, 'cash'));
        $this->assertSame('0.000', $this->balance($club, 'receivable'));
    }

    public function test_reception_sells_but_needs_the_discount_permission_and_a_trainer_cannot_sell(): void
    {
        $club = $this->club();
        $bar = $this->product($club);
        $line = ['items' => [['product_id' => $bar->id, 'quantity' => 1]], 'payment_method' => 'cash'];

        $reception = $this->staff($club, 'reception');
        $this->actingAs($reception)->post(route('club.pos.checkout'), $line)->assertSessionHasNoErrors();
        $this->actingAs($reception)->post(route('club.pos.checkout'), $line + ['discount' => 0.2])->assertSessionHasErrors('discount');
        $this->actingAs($reception)->get(route('club.sales.index'))->assertForbidden();

        $this->actingAs($this->staff($club, 'manager'))->post(route('club.pos.checkout'), $line + ['discount' => 0.2])->assertSessionHasNoErrors();

        $trainer = $this->staff($club, 'trainer');
        $this->actingAs($trainer)->post(route('club.pos.checkout'), $line)->assertForbidden();
        $this->actingAs($trainer)->get(route('club.pos.index'))->assertForbidden();

        $this->asTenant($club, fn () => $this->assertSame(2, Sale::count()));
    }

    public function test_voiding_a_sale_puts_stock_back_and_records_the_refund(): void
    {
        $club = $this->club();
        $bar = $this->product($club);
        $this->actingAs($this->owner($club))->post(route('club.pos.checkout'), ['items' => [['product_id' => $bar->id, 'quantity' => 3]], 'payment_method' => 'cash']);
        $sale = $this->asTenant($club, fn () => Sale::firstOrFail());

        $this->actingAs($this->staff($club, 'reception'))->post(route('club.pos.void', $sale), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($this->owner($club))->post(route('club.pos.void', $sale), ['reason' => 'Wrong item'])->assertSessionHasNoErrors();

        $this->asTenant($club, function () use ($sale, $bar) {
            $this->assertSame(Sale::VOIDED, $sale->fresh()->status);
            $this->assertSame('10.000', $bar->fresh()->stock_quantity);
            $this->assertSame(1, Payment::where('kind', Payment::KIND_REFUND)->count());
        });
        $this->assertSame('0.000', $this->balance($club, 'cash'));
    }

    public function test_only_products_chosen_for_the_website_appear_in_the_shop(): void
    {
        $club = $this->club();
        $shown = $this->product($club, ['name' => 'Shown bottle']);
        $hidden = $this->product($club, ['name' => 'Back-office item', 'show_on_website' => false]);

        $this->get(route('site.shop', ['club' => $club->slug]))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Site/Shop')->has('products', 1)->where('products.0.name', 'Shown bottle')->where('site.shop_open', true));
        $this->get(route('site.shop.product', ['club' => $club->slug, 'product' => $shown->id]))->assertOk();
        $this->get(route('site.shop.product', ['club' => $club->slug, 'product' => $hidden->id]))->assertNotFound();

        // The club can switch the whole shop off.
        $club->forceFill(['settings' => array_merge($club->settings ?? [], ['sales' => ['shop_enabled' => false]])])->save();
        $this->get(route('site.shop', ['club' => $club->slug]))->assertNotFound();
    }

    public function test_a_website_order_is_paid_at_hand_over_once_and_posted_once(): void
    {
        $club = $this->club();
        $bar = $this->product($club);

        $response = $this->post(route('site.shop.order.store', ['club' => $club->slug]), [
            'items' => [['product_id' => $bar->id, 'quantity' => 2]], 'name' => 'Salim', 'phone' => '91234567', 'payment_method' => 'pickup',
        ]);
        $sale = $this->asTenant($club, fn () => Sale::firstOrFail());
        $response->assertRedirect(route('site.shop.order', ['club' => $club->slug, 'token' => $sale->public_token]));

        $this->asTenant($club, function () use ($sale, $bar) {
            $this->assertSame([Sale::WEBSITE, Sale::PENDING, 'new'], [$sale->source, $sale->status, $sale->fulfillment]);
            $this->assertSame('8.000', $bar->fresh()->stock_quantity, 'held for the order');
            $this->assertSame(Invoice::ISSUED, Invoice::find($sale->invoice_id)->status);
        });
        $this->get(route('site.shop.order', ['club' => $club->slug, 'token' => $sale->public_token]))->assertOk();

        $reception = $this->staff($club, 'reception');
        $this->actingAs($reception)->get(route('club.sales.orders.index'))->assertOk()->assertInertia(fn ($page) => $page->has('orders.data', 1));
        $this->actingAs($reception)->post(route('club.sales.orders.status', $sale), ['status' => 'ready'])->assertSessionHasNoErrors();
        $this->actingAs($reception)->post(route('club.sales.orders.deliver', $sale), [])->assertSessionHasErrors('method');
        $this->actingAs($reception)->post(route('club.sales.orders.deliver', $sale), ['method' => 'cash'])->assertSessionHasNoErrors();
        $this->actingAs($reception)->post(route('club.sales.orders.deliver', $sale), ['method' => 'cash'])->assertSessionHasErrors();

        $this->asTenant($club, function () use ($sale, $reception) {
            $sale->refresh();
            $this->assertSame([Sale::COMPLETED, 'delivered', 'cash', $reception->id], [$sale->status, $sale->fulfillment, $sale->payment_method, $sale->delivered_by]);
            $this->assertNotNull($sale->paid_at);
            $this->assertSame(1, Payment::count());
        });
        $this->assertSame('3.000', $this->balance($club, 'revenue_pos'));
        $this->assertSame('0.000', $this->balance($club, 'receivable'));
    }

    public function test_cancelling_an_order_releases_stock_and_a_paid_one_needs_the_refund_permission(): void
    {
        $club = $this->club();
        $bar = $this->product($club);
        $unpaid = $this->order($club, $bar, 3);
        $reception = $this->staff($club, 'reception');

        $this->actingAs($reception)->post(route('club.sales.orders.cancel', $unpaid), ['reason' => 'Customer called'])->assertSessionHasNoErrors();
        $this->asTenant($club, function () use ($unpaid, $bar) {
            $this->assertSame(Sale::CANCELLED, $unpaid->fresh()->status);
            $this->assertSame(Invoice::VOID, Invoice::find($unpaid->invoice_id)->status);
            $this->assertSame('10.000', $bar->fresh()->stock_quantity);
        });

        $paid = $this->order($club, $bar, 1);
        $this->asTenant($club, fn () => app(PaymentService::class)->record(Invoice::find($paid->invoice_id), '1.500', 'cash'));
        $this->asTenant($club, fn () => $this->assertNotNull($paid->fresh()->paid_at, 'marked paid when the invoice is paid'));

        $this->actingAs($reception)->post(route('club.sales.orders.cancel', $paid), ['reason' => 'x', 'refund_method' => 'cash'])->assertForbidden();
        $this->actingAs($this->owner($club))->post(route('club.sales.orders.cancel', $paid), ['reason' => 'Out of flavour'])->assertSessionHasErrors('refund_method');
        $this->actingAs($this->owner($club))->post(route('club.sales.orders.cancel', $paid), ['reason' => 'Out of flavour', 'refund_method' => 'cash'])->assertSessionHasNoErrors();

        $this->asTenant($club, function () use ($paid, $bar) {
            $this->assertSame(Sale::CANCELLED, $paid->fresh()->status);
            $this->assertSame('10.000', $bar->fresh()->stock_quantity);
            $this->assertSame(1, Payment::where('kind', Payment::KIND_REFUND)->count());
        });
        // Revenue stays as sold; the credit note sits in sales returns, which nets it to zero.
        $this->assertSame('1.500', $this->balance($club, 'revenue_pos'));
        $this->assertSame('1.500', $this->balance($club, 'sales_returns'));
        $this->assertSame('0.000', $this->balance($club, 'cash'));
    }

    public function test_stock_cannot_be_oversold_by_orders(): void
    {
        $club = $this->club();
        $bar = $this->product($club, [], 3);
        $this->order($club, $bar, 2);

        $this->expectException(BusinessRuleException::class);
        $this->order($club, $bar, 2);
    }

    public function test_an_online_payment_order_is_paid_only_by_the_signed_webhook_and_expires_unpaid(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_abc', 'intention_order_id' => 4411], 201)]);
        $club = $this->club();
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => 'hmac-test', 'integration_ids' => '111'],
        ]));
        $bar = $this->product($club);

        $this->post(route('site.shop.order.store', ['club' => $club->slug]), [
            'items' => [['product_id' => $bar->id, 'quantity' => 1]], 'name' => 'Online', 'phone' => '91234567', 'payment_method' => 'paymob',
        ])->assertRedirectContains('clientSecret=cs_abc'); // off to Paymob's page
        [$sale, $intent] = $this->asTenant($club, fn () => [Sale::firstOrFail(), PaymentIntent::firstOrFail()]);
        $this->assertNotNull($sale->reserved_until);

        // Coming back from the payment page changes nothing.
        $this->get(route('site.pay.return', ['club' => $club->slug, 'reference' => $intent->reference]))->assertOk()
            ->assertInertia(fn ($page) => $page->where('order', $sale->public_token));
        $this->asTenant($club, fn () => $this->assertNull($sale->fresh()->paid_at));

        $obj = ['id' => 777001, 'pending' => false, 'amount_cents' => 1500, 'success' => true, 'is_voided' => false, 'is_refunded' => false, 'error_occured' => false,
            'currency' => 'OMR', 'order' => ['id' => 4411, 'merchant_order_id' => $intent->reference], 'created_at' => '2026-10-29T10:00:00',
            'is_auth' => false, 'is_capture' => false, 'is_standalone_payment' => true, 'is_3d_secure' => true, 'integration_id' => 111,
            'has_parent_transaction' => false, 'owner' => 1, 'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card']];
        $hmac = PaymobSignature::compute($obj, 'hmac-test');
        $this->postJson(route('webhooks.paymob').'?hmac='.$hmac, ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
        $this->postJson(route('webhooks.paymob').'?hmac='.$hmac, ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();

        $this->asTenant($club, function () use ($sale) {
            $this->assertNotNull($sale->fresh()->paid_at);
            $this->assertNull($sale->fresh()->reserved_until);
            $this->assertSame(1, Payment::count(), 'the repeated callback records nothing');
        });

        // A second online order left unpaid past its window is released by the scheduler.
        $late = $this->order($club, $bar, 4, 'paymob');
        $this->asTenant($club, fn () => $late->forceFill(['reserved_until' => now()->subMinute()])->save());
        Artisan::call('sales:expire-orders');
        $this->asTenant($club, function () use ($late, $bar, $sale) {
            $this->assertSame(Sale::CANCELLED, $late->fresh()->status);
            $this->assertSame(Invoice::VOID, Invoice::find($late->invoice_id)->status);
            $this->assertSame('9.000', $bar->fresh()->stock_quantity);
            $this->assertSame(Sale::PENDING, $sale->fresh()->status, 'the paid order is untouched');
        });
    }

    public function test_sales_and_orders_of_two_clubs_stay_apart(): void
    {
        $a = $this->club('A');
        $b = $this->club('B');
        $order = $this->order($a, $this->product($a));
        $this->product($b, ['name' => 'B item']);

        $ownerB = $this->owner($b);
        $this->actingAs($ownerB)->get(route('club.sales.orders.index'))->assertOk()->assertInertia(fn ($page) => $page->has('orders.data', 0));
        $this->actingAs($ownerB)->post(route('club.sales.orders.deliver', $order), ['method' => 'cash'])->assertNotFound();
        $this->actingAs($ownerB)->get(route('club.sales.receipt', $order))->assertNotFound();
        $this->get(route('site.shop.order', ['club' => $b->slug, 'token' => $order->public_token]))->assertNotFound();
        $this->get(route('site.shop', ['club' => $b->slug]))->assertInertia(fn ($page) => $page->has('products', 1)->where('products.0.name', 'B item'));
    }

    public function test_the_sales_screens_open_for_the_owner(): void
    {
        $club = $this->club();
        $bar = $this->product($club);
        $owner = $this->owner($club);
        // Several rows of each kind, so a relation loaded lazily in a list fails here and not in production.
        foreach ([1, 2] as $qty) {
            $this->actingAs($owner)->post(route('club.pos.checkout'), ['items' => [['product_id' => $bar->id, 'quantity' => $qty]], 'payment_method' => 'cash']);
            $this->order($club, $bar, $qty);
        }
        foreach (['club.sales.summary', 'club.sales.index', 'club.sales.reports', 'club.pos.index', 'club.products.index', 'club.sales.orders.index', 'club.settings.sales'] as $name) {
            $this->actingAs($owner)->get(route($name))->assertOk();
        }
        $this->actingAs($owner)->get(route('club.sales.reports.export'))->assertOk();
        $sale = $this->asTenant($club, fn () => Sale::firstOrFail());
        $this->actingAs($owner)->get(route('club.sales.receipt', $sale))->assertOk();
    }
}
