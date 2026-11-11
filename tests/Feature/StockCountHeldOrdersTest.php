<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Models\Stocktake;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\OrderService;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A website order takes its units off the book when it is placed, but they stay on the shelf until handed over (the
 * owner's rule for #132: held units are on the shelf and are counted with it). A count expects the shelf to hold the
 * book plus what orders hold, so counting exactly what is there changes nothing, and releasing or handing over the
 * order afterwards leaves the book equal to the shelf: the held units are never added twice, nor kept after pickup.
 */
class StockCountHeldOrdersTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** 10 bars on the shelf of the club's (main) branch. */
    private function bar(Tenant $club): Product
    {
        return $this->asTenant($club, function () {
            TenantIntegration::create([
                'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
                'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => 'hmac-test', 'integration_ids' => '111'],
            ]);
            $bar = Product::create(['name' => 'Bar', 'price' => 1.5, 'show_on_website' => true]);
            app(StockService::class)->move($bar, 10, 'adjustment', null, 'opening', 0.8);

            return $bar->fresh();
        });
    }

    private function order(Tenant $club, Product $product, int $qty, string $method, ?int $branchId = null): Sale
    {
        return $this->asTenant($club, fn () => app(OrderService::class)->place([['product_id' => $product->id, 'quantity' => $qty]], ['name' => 'Online', 'phone' => '96891234567'], $method, branchId: $branchId));
    }

    /** Count the bars of the main branch: start, enter what is on the shelf, and (unless told not to) approve. */
    private function countShelf(Tenant $club, Product $product, float $counted, bool $approve = true): Stocktake
    {
        return $this->asTenant($club, function () use ($product, $counted, $approve) {
            $inventory = app(InventoryService::class);
            $count = $inventory->startCount(Branch::orderBy('id')->value('id'), [$product->id]);
            $inventory->saveCounts($count, [$count->lines()->value('id') => ['counted' => $counted]]);

            return $approve ? $inventory->approveCount($count) : $count;
        });
    }

    private function book(Tenant $club, Product $product): string
    {
        return $this->asTenant($club, fn () => $product->fresh()->stock_quantity);
    }

    public function test_an_unpaid_online_order_counted_on_the_shelf_then_expired_leaves_the_book_equal_to_the_shelf(): void
    {
        $club = $this->club();
        $bar = $this->bar($club);
        $sale = $this->order($club, $bar, 2, 'paymob');
        $this->assertSame('8.000', $this->book($club, $bar), 'held for the order: off the book, still on the shelf');

        $count = $this->countShelf($club, $bar, 10); // exactly what is there, the 2 held bars included
        $this->asTenant($club, function () use ($count) {
            $line = $count->lines()->firstOrFail();
            $this->assertSame(['8.000', '2.000', '0.000'], [$line->system_quantity, $line->held_quantity, $line->difference]);
        });
        $this->assertSame('8.000', $this->book($club, $bar), 'nothing was missing or found: the count changes nothing');

        $this->travel(2)->hours();
        $this->asTenant($club, fn () => app(OrderService::class)->expireOverdue());
        $this->asTenant($club, fn () => $this->assertSame(Sale::CANCELLED, $sale->fresh()->status));
        $this->assertSame('10.000', $this->book($club, $bar), 'the shelf holds 10: the released bars are added once, not twice');
    }

    public function test_a_pay_at_pickup_order_counted_on_the_shelf_then_handed_over_is_taken_off_once(): void
    {
        $club = $this->club();
        $bar = $this->bar($club);
        $sale = $this->order($club, $bar, 2, 'pickup'); // pickup orders have no payment window: they can wait for days

        $this->countShelf($club, $bar, 10);
        $this->actingAs($this->staff($club, 'reception'))->post(route('club.sales.orders.deliver', $sale), ['method' => 'cash'])->assertSessionHasNoErrors();

        $this->asTenant($club, fn () => $this->assertSame(Sale::COMPLETED, $sale->fresh()->status));
        $this->assertSame('8.000', $this->book($club, $bar), 'the customer took 2 of the 10: the shelf holds 8');
    }

    public function test_a_paid_online_order_counted_on_the_shelf_then_cancelled_returns_its_units_once(): void
    {
        $club = $this->club();
        $bar = $this->bar($club);
        $sale = $this->order($club, $bar, 3, 'paymob');
        $this->asTenant($club, fn () => app(PaymentService::class)->record(Invoice::find($sale->invoice_id), '4.500', 'cash'));
        $this->asTenant($club, fn () => $this->assertNotNull($sale->fresh()->paid_at));

        $this->countShelf($club, $bar, 10);
        $this->actingAs($this->owner($club))->post(route('club.sales.orders.cancel', $sale), ['reason' => 'Out of flavour', 'refund_method' => 'cash'])->assertSessionHasNoErrors();

        $this->asTenant($club, fn () => $this->assertSame(Sale::CANCELLED, $sale->fresh()->status));
        $this->assertSame('10.000', $this->book($club, $bar));
    }

    public function test_a_real_shortage_is_still_found_while_an_order_holds_units(): void
    {
        $club = $this->club();
        $bar = $this->bar($club);
        $sale = $this->order($club, $bar, 2, 'pickup');

        $count = $this->countShelf($club, $bar, 9); // one bar is missing
        $this->asTenant($club, fn () => $this->assertSame('-1.000', $count->lines()->value('difference')));
        $this->assertSame('7.000', $this->book($club, $bar), 'what can still be sold: 9 on the shelf less the 2 held');

        $this->actingAs($this->staff($club, 'reception'))->post(route('club.sales.orders.deliver', $sale), ['method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertSame('7.000', $this->book($club, $bar), 'and 7 stay on the shelf after pickup');
    }

    /**
     * A count left open while the shop goes on (#27: the book is the one seen when counting): an order placed after
     * counting takes its units off the book, an order handed over takes its units off the shelf, and the approval
     * posts only what the count found.
     */
    public function test_orders_placed_handed_over_or_cancelled_while_a_count_is_open_are_not_counted_twice(): void
    {
        $club = $this->club();
        $bar = $this->bar($club);
        $first = $this->order($club, $bar, 2, 'pickup');
        $count = $this->countShelf($club, $bar, 10, approve: false);

        $second = $this->order($club, $bar, 2, 'pickup'); // placed after counting: book 6, shelf still 10
        $this->actingAs($this->staff($club, 'reception'))->post(route('club.sales.orders.deliver', $first), ['method' => 'cash'])->assertSessionHasNoErrors(); // shelf 8

        $this->asTenant($club, fn () => app(InventoryService::class)->approveCount(Stocktake::findOrFail($count->id)));
        $this->assertSame('6.000', $this->book($club, $bar), 'shelf 8, of which the second order holds 2');

        $this->actingAs($this->owner($club))->post(route('club.sales.orders.cancel', $second), ['reason' => 'Customer called'])->assertSessionHasNoErrors();
        $this->assertSame('8.000', $this->book($club, $bar), 'the shelf holds 8');
    }

    public function test_the_product_page_count_takes_held_units_as_on_the_shelf(): void
    {
        $club = $this->club();
        $bar = $this->bar($club);
        $this->order($club, $bar, 2, 'pickup');
        $owner = $this->owner($club);

        $this->actingAs($owner)->post(route('club.products.adjust', $bar), ['counted' => 10, 'reason' => 'Shelf check'])->assertSessionHasNoErrors();
        $this->assertSame('8.000', $this->book($club, $bar), '10 on the shelf, 2 of them held: nothing to correct');

        $this->actingAs($owner)->post(route('club.products.adjust', $bar), ['counted' => 9, 'reason' => 'Shelf check'])->assertSessionHasNoErrors();
        $this->assertSame('7.000', $this->book($club, $bar), 'one missing');
    }

    public function test_the_count_screen_shows_what_orders_hold_and_only_in_their_own_branch(): void
    {
        $club = $this->club();
        $bar = $this->bar($club);
        $this->order($club, $bar, 2, 'pickup');
        $other = $this->asTenant($club, fn () => Branch::create(['name' => 'فرع الخوض', 'is_active' => true]));
        $this->asTenant($club, fn () => app(StockService::class)->move($bar, 5, 'adjustment', null, 'opening', 0.8, branchId: $other->id));
        $this->order($club, $bar, 1, 'pickup', $other->id);

        $count = $this->countShelf($club, $bar, 10, approve: false);
        $this->actingAs($this->owner($club))->get(route('club.inventory.counts.show', $count))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Inventory/Stock/CountShow')
                ->where('count.lines.0.system_quantity', '8.000')
                ->where('count.lines.0.held_quantity', '2.000')
                ->where('count.lines.0.difference', '0.000'));

        $elsewhere = $this->asTenant($club, fn () => app(InventoryService::class)->startCount($other->id, [$bar->id]));
        $this->asTenant($club, fn () => $this->assertSame(['4.000', '1.000'], [$elsewhere->lines()->value('system_quantity'), $elsewhere->lines()->value('held_quantity')]));
    }
}
