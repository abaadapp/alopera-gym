<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Inventory\Models\GoodsReceipt;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\StockMovement;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * `php artisan migrate:fresh --seed` gives a working demo club: the supplier's order is received in full through a goods
 * receipt, so the shelves hold what was bought.
 */
class DemoSeederTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_the_demo_data_seeds_a_club_whose_shelves_hold_what_was_bought(): void
    {
        $this->seed(DatabaseSeeder::class);

        $club = Tenant::sole();
        [$order, $receipts, $mats] = $this->asTenant($club, fn () => [
            PurchaseOrder::sole()->status,
            GoodsReceipt::where('status', GoodsReceipt::APPROVED)->count(),
            (float) StockMovement::where('type', 'purchase')->where('product_id', Product::where('name', 'حصيرة يوغا')->value('id'))->sum('quantity'),
        ]);

        $this->assertSame([PurchaseOrder::RECEIVED, 1, 6.0], [$order, $receipts, $mats]);
    }
}
