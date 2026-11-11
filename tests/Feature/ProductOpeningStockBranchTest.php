<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductStock;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A product's opening stock, entered on the new-product form, goes on the shelf of the branch the user works in (as a
 * stock adjustment from the product page does), and on the main branch's when the user works for the whole club.
 */
class ProductOpeningStockBranchTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_opening_stock_goes_on_the_shelf_of_the_branch_the_manager_works_in(): void
    {
        $club = $this->club();
        $khoudh = $this->asTenant($club, fn () => Branch::create(['name' => 'فرع الخوض', 'is_active' => true]));
        $main = $this->asTenant($club, fn () => Branch::where('id', '!=', $khoudh->id)->value('id'));
        $manager = $this->staff($club, 'manager', ['branch_id' => $khoudh->id]);

        $this->actingAs($manager)->post(route('club.products.store'), [
            'name' => 'Protein bar', 'price' => 1.5, 'track_stock' => true, 'is_active' => true,
            'opening_stock' => 12, 'opening_cost' => 0.8,
        ])->assertRedirect();

        $this->asTenant($club, function () use ($khoudh, $main) {
            $bar = Product::where('name', 'Protein bar')->sole();
            $this->assertSame('12.000', $bar->stock_quantity);
            $this->assertNull(ProductStock::where('product_id', $bar->id)->where('branch_id', $main)->value('quantity'), 'nothing on the main branch shelf');
            $this->assertSame('12.000', ProductStock::where('product_id', $bar->id)->where('branch_id', $khoudh->id)->value('quantity'), 'on the manager\'s own branch shelf');
        });
    }

    public function test_a_club_wide_owner_puts_it_on_the_main_branch_shelf_as_before(): void
    {
        $club = $this->club();
        $this->asTenant($club, fn () => Branch::create(['name' => 'فرع الخوض', 'is_active' => true]));
        $main = $this->asTenant($club, fn () => Branch::where('is_main', true)->value('id'));

        $this->actingAs($this->owner($club))->post(route('club.products.store'), [
            'name' => 'Shaker', 'price' => 3, 'track_stock' => true, 'is_active' => true, 'opening_stock' => 5, 'opening_cost' => 1,
        ])->assertRedirect();

        $this->asTenant($club, fn () => $this->assertSame('5.000', ProductStock::where('product_id', Product::where('name', 'Shaker')->sole()->id)->where('branch_id', $main)->value('quantity')));
    }
}
