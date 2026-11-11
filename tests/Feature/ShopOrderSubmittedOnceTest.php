<?php

namespace Tests\Feature;

use App\Modules\Finance\Models\Invoice;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\StockService;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * The website checkout sends a key made when the cart opens, as the till and the panel's money forms do: the same cart
 * sent twice (a double click, a retried request, at the same moment or one after the other) places one order, one
 * invoice and one stock hold. Another cart, with another key, is another order.
 */
class ShopOrderSubmittedOnceTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    public function test_the_same_cart_sent_twice_at_once_places_one_order(): void
    {
        $club = $this->club();
        $bar = $this->asTenant($club, function () {
            $bar = Product::create(['name' => 'Bar', 'price' => 1.5, 'show_on_website' => true]);
            app(StockService::class)->move($bar, 10, 'adjustment', null, 'opening', 0.8);

            return $bar->fresh();
        });
        $cart = [
            'items' => [['product_id' => $bar->id, 'quantity' => 2]], 'name' => 'Salim', 'phone' => '91234567',
            'payment_method' => 'pickup', 'client_key' => 'cart-7f3a9c',
        ];

        $results = $this->concurrently([
            fn () => $this->post(route('site.shop.order.store', ['club' => $club->slug]), $cart)->getStatusCode(),
            fn () => $this->post(route('site.shop.order.store', ['club' => $club->slug]), $cart)->getStatusCode(),
        ]);

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($results));
        }
        $this->asTenant($club, function () use ($bar) {
            $this->assertSame(1, Sale::where('source', Sale::WEBSITE)->count(), 'one cart, one order');
            $this->assertSame(1, Invoice::count(), 'one invoice');
            $this->assertSame('8.000', $bar->fresh()->stock_quantity, 'held once');
        });
    }

    public function test_the_same_cart_sent_again_returns_the_first_order_and_another_cart_is_another_order(): void
    {
        $club = $this->club();
        $bar = $this->asTenant($club, function () {
            $bar = Product::create(['name' => 'Bar', 'price' => 1.5, 'show_on_website' => true]);
            app(StockService::class)->move($bar, 10, 'adjustment', null, 'opening', 0.8);

            return $bar->fresh();
        });
        $cart = ['items' => [['product_id' => $bar->id, 'quantity' => 2]], 'name' => 'Salim', 'phone' => '91234567', 'payment_method' => 'pickup', 'client_key' => 'cart-1'];

        $first = $this->post(route('site.shop.order.store', ['club' => $club->slug]), $cart)->assertRedirect()->headers->get('Location');
        $again = $this->post(route('site.shop.order.store', ['club' => $club->slug]), $cart)->assertRedirect()->headers->get('Location');
        $this->assertSame($first, $again, 'the retry lands on the first order');
        $this->assertSame(1, $this->asTenant($club, fn () => Sale::count()));

        $this->post(route('site.shop.order.store', ['club' => $club->slug]), ['client_key' => 'cart-2'] + $cart)->assertRedirect();
        $this->asTenant($club, function () use ($bar) {
            $this->assertSame(2, Sale::count());
            $this->assertSame('6.000', $bar->fresh()->stock_quantity);
        });
    }
}
