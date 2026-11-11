<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\User;
use App\Modules\Finance\Services\ManualInvoices;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Models\Stocktake;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * A stock count approved, or a manual invoice issued, while the till sells the same products, in separate processes on
 * PostgreSQL. Both lock their products by id before moving stock, the order every basket takes (#55): one waits for the
 * other instead of the two deadlocking and one failing.
 */
class StockLockOrderTest extends TestCase
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

    /** @return array{status: int} */
    private function sell(User $cashier, array $products): array
    {
        $response = $this->actingAs($cashier)->post(route('club.pos.checkout'), [
            'items' => array_map(fn (Product $p) => ['product_id' => $p->id, 'quantity' => 1], $products),
            'payment_method' => 'cash', 'client_key' => 'till-'.uniqid(),
        ]);

        return ['status' => $response->getStatusCode(), 'exception' => substr((string) $response->exception?->getMessage(), 0, 160)];
    }

    /** Water is made first (lower id), Bar second: by name, Bar comes first. */
    private function twoProducts($club): array
    {
        return $this->asTenant($club, function () {
            $water = Product::create(['name' => 'Water', 'price' => 0.5]);
            $bar = Product::create(['name' => 'Bar', 'price' => 1]);
            foreach ([$water, $bar] as $product) {
                app(StockService::class)->move($product, 10, 'adjustment', null, 'opening', 0.4);
            }

            return [$water, $bar];
        });
    }

    public function test_approving_a_count_while_the_till_sells_the_same_products_does_not_deadlock(): void
    {
        $club = $this->club();
        [$water, $bar] = $this->twoProducts($club);
        $count = $this->asTenant($club, function () use ($water, $bar) {
            $inventory = app(InventoryService::class);
            $count = $inventory->startCount(Branch::value('id'), [$water->id, $bar->id]);
            $inventory->saveCounts($count, $count->lines()->pluck('id')->mapWithKeys(fn ($id) => [$id => ['counted' => 9]])->all());

            return $count;
        });
        $cashier = $this->staff($club, 'cashier');

        $results = $this->concurrently([
            function (string $dir) use ($club, $count) {
                self::holdAfterLockingProducts($dir, 'count');

                return $this->asTenant($club, fn () => app(InventoryService::class)->approveCount(Stocktake::findOrFail($count->id))->status);
            },
            function (string $dir) use ($cashier, $water, $bar) {
                self::waitFor("$dir/count");

                return $this->sell($cashier, [$water, $bar]);
            },
        ]);

        $this->assertStringNotContainsString('eadlock', json_encode($results), json_encode($results));
        $this->assertTrue($results[0]['ok'], json_encode($results));
        $this->assertSame(302, $results[1]['value']['status'] ?? null, json_encode($results));
        $this->asTenant($club, function () use ($water, $bar) {
            $this->assertSame(1, Sale::count());
            $this->assertSame(['8.000', '8.000'], [$water->fresh()->stock_quantity, $bar->fresh()->stock_quantity]);
        });
    }

    public function test_issuing_a_manual_invoice_while_the_till_sells_the_same_products_does_not_deadlock(): void
    {
        $club = $this->club();
        [$water, $bar] = $this->twoProducts($club);
        $cashier = $this->staff($club, 'cashier');
        $owner = $this->owner($club);

        $results = $this->concurrently([
            function (string $dir) use ($club, $owner, $water, $bar) {
                self::holdAfterLockingProducts($dir, 'invoice');
                $this->actingAs($owner);

                return $this->asTenant($club, fn () => app(ManualInvoices::class)->issue([
                    'customer_mode' => 'walk_in', 'issue_date' => today()->toDateString(),
                    // Typed Bar first, then Water: the invoice takes Bar's row, then Water's.
                    'lines' => [
                        ['product_id' => $bar->id, 'description' => 'Bar', 'quantity' => 1, 'unit_price' => 1],
                        ['product_id' => $water->id, 'description' => 'Water', 'quantity' => 1, 'unit_price' => 0.5],
                    ],
                ], ['method' => 'credit'])->number);
            },
            function (string $dir) use ($cashier, $water, $bar) {
                self::waitFor("$dir/invoice");

                return $this->sell($cashier, [$water, $bar]);
            },
        ]);

        $this->assertStringNotContainsString('eadlock', json_encode($results), json_encode($results));
        $this->assertTrue($results[0]['ok'], json_encode($results));
        $this->assertSame(302, $results[1]['value']['status'] ?? null, json_encode($results));
        $this->asTenant($club, function () use ($water, $bar) {
            $this->assertSame(['8.000', '8.000'], [$water->fresh()->stock_quantity, $bar->fresh()->stock_quantity]);
        });
    }
}
