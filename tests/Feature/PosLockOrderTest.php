<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\OrderService;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * The till and a website order taking the same two products at the same moment, in opposite order, in separate
 * processes on PostgreSQL (two tills wait for each other already, on the sale numbers' counter; a website order has a
 * counter of its own): a basket takes its products' locks in one order (by id) before moving any stock, so one waits
 * for the other instead of the two deadlocking and one of them failing.
 */
class PosLockOrderTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    /** Inside a task: hold right after the first row lock taken on the products, and say so. */
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

    /** @return array{status: int, errors: list<string>} */
    private function sell(User $cashier, array $products, string $key): array
    {
        $response = $this->actingAs($cashier)->post(route('club.pos.checkout'), [
            'items' => array_map(fn (Product $p) => ['product_id' => $p->id, 'quantity' => 1], $products),
            'payment_method' => 'cash', 'client_key' => $key,
        ]);

        return ['status' => $response->getStatusCode(), 'errors' => session('errors')?->all() ?? []];
    }

    public function test_the_till_and_a_website_order_taking_the_same_products_in_opposite_order_do_not_deadlock(): void
    {
        $club = $this->club();
        [$a, $b] = $this->asTenant($club, function () {
            $made = [Product::create(['name' => 'Bar', 'price' => 1, 'show_on_website' => true]), Product::create(['name' => 'Water', 'price' => 0.5, 'show_on_website' => true])];
            foreach ($made as $product) {
                app(StockService::class)->move($product, 10, 'adjustment', null, 'opening', 0.4);
            }

            return $made;
        });
        $one = $this->staff($club, 'cashier');

        $results = $this->concurrently([
            function (string $dir) use ($one, $a, $b) {
                self::holdAfterLockingProducts($dir, 'one');

                return $this->sell($one, [$a, $b], 'till-one');
            },
            function (string $dir) use ($club, $a, $b) {
                self::holdAfterLockingProducts($dir, 'web');

                return $this->asTenant($club, fn () => app(OrderService::class)->place(
                    [['product_id' => $b->id, 'quantity' => 1], ['product_id' => $a->id, 'quantity' => 1]], ['name' => 'Online', 'phone' => '96891234567'], 'pickup'
                )->number);
            },
        ]);

        foreach ($results as $result) {
            $this->assertTrue($result['ok'], json_encode($results));
        }
        $this->assertSame(302, $results[0]['value']['status'], json_encode($results));
        $this->assertSame([], $results[0]['value']['errors'], json_encode($results));
        $this->asTenant($club, function () use ($a, $b) {
            $this->assertSame(2, Sale::count());
            $this->assertSame(['8.000', '8.000'], [$a->fresh()->stock_quantity, $b->fresh()->stock_quantity]);
            $this->assertSame(JournalLine::sum('debit'), JournalLine::sum('credit'));
        });
    }
}
