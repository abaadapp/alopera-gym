<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Models\Stocktake;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\OrderService;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * Counting while website orders are placed or released at the same moment, in separate processes on PostgreSQL (#132):
 * the book and what orders hold are read at one moment, so the count neither adds the held units back nor misses them.
 */
class StockCountHeldOrdersConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    /** Inside a task: hold right after the first product row lock, and say so. */
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

    private function bar(Tenant $club): Product
    {
        return $this->asTenant($club, function () {
            $bar = Product::create(['name' => 'Bar', 'price' => 1.5, 'show_on_website' => true]);
            app(StockService::class)->move($bar, 10, 'adjustment', null, 'opening', 0.8);

            return $bar->fresh();
        });
    }

    public function test_a_product_page_count_while_an_order_is_being_placed_counts_its_units_once(): void
    {
        $club = $this->club();
        $bar = $this->bar($club);
        $owner = $this->owner($club);

        $results = $this->concurrently([
            function (string $dir) use ($club, $bar) {
                self::holdAfterLockingProducts($dir, 'order');

                return $this->asTenant($club, fn () => app(OrderService::class)->place([['product_id' => $bar->id, 'quantity' => 2]], ['name' => 'Online', 'phone' => '96891234567'], 'pickup')->number);
            },
            function (string $dir) use ($owner, $bar) {
                self::waitFor("$dir/order");
                // The shelf is counted while the order is being placed: 10 bars, none picked yet.
                $response = $this->actingAs($owner)->post(route('club.products.adjust', $bar), ['counted' => 10, 'reason' => 'Shelf check']);

                return ['status' => $response->getStatusCode(), 'errors' => session('errors')?->all()];
            },
        ]);

        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->assertSame(302, $results[1]['value']['status'] ?? null, json_encode($results));
        $this->asTenant($club, function () use ($bar) {
            $this->assertSame(1, Sale::count());
            $this->assertSame('8.000', $bar->fresh()->stock_quantity, '10 on the shelf, 2 held: the count corrects nothing');
        });
    }

    public function test_approving_a_count_while_the_held_order_is_cancelled_adds_its_units_once(): void
    {
        $club = $this->club();
        $bar = $this->bar($club);
        [$sale, $count] = $this->asTenant($club, function () use ($bar) {
            $sale = app(OrderService::class)->place([['product_id' => $bar->id, 'quantity' => 2]], ['name' => 'Online', 'phone' => '96891234567'], 'pickup');
            $inventory = app(InventoryService::class);
            $count = $inventory->startCount(Branch::value('id'), [$bar->id]);
            $inventory->saveCounts($count, [$count->lines()->value('id') => ['counted' => 10]]);

            return [$sale, $count];
        });

        $results = $this->concurrently([
            function (string $dir) use ($club, $count) {
                self::holdAfterLockingProducts($dir, 'count');

                return $this->asTenant($club, fn () => app(InventoryService::class)->approveCount(Stocktake::findOrFail($count->id))->status);
            },
            function (string $dir) use ($club, $sale) {
                self::waitFor("$dir/count");

                return $this->asTenant($club, fn () => app(OrderService::class)->cancel(Sale::findOrFail($sale->id), 'Customer called')->status);
            },
        ]);

        $this->assertStringNotContainsString('eadlock', json_encode($results), json_encode($results));
        $this->assertTrue($results[0]['ok'] && $results[1]['ok'], json_encode($results));
        $this->asTenant($club, fn () => $this->assertSame('10.000', $bar->fresh()->stock_quantity, 'the shelf holds 10'));
    }
}
