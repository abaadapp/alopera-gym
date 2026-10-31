<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Models\Branch;
use App\Models\Tenant;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductAddon;
use App\Modules\Inventory\Models\ProductStock;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Models\Stocktake;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\SupplyRequest;
use App\Modules\Inventory\Services\OrderService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\TransferService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Stock per branch: sales, orders, voids and add-ons on the right shelf, the club total never counted twice, counts
 * approved once, damage and loss, transfers between branches (dispatch, receipt, shortage settled), supply requests,
 * permissions and isolation.
 */
class InventoryTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** @return array{0: Branch, 1: Branch} the main branch and a second one */
    private function twoBranches(Tenant $club): array
    {
        return $this->asTenant($club, fn () => [Branch::orderByDesc('is_main')->orderBy('id')->firstOrFail(), Branch::create(['name' => 'فرع الخوض', 'is_active' => true])]);
    }

    private function stocked(Tenant $club, array $perBranch, array $attributes = []): Product
    {
        return $this->asTenant($club, function () use ($perBranch, $attributes) {
            $product = Product::create($attributes + ['name' => 'Protein bar', 'price' => 1.5, 'show_on_website' => true]);
            foreach ($perBranch as $branch => $qty) {
                app(StockService::class)->move($product, $qty, 'adjustment', null, 'opening', 0.8, branchId: $branch);
            }

            return $product->fresh();
        });
    }

    private function qty(Tenant $club, Product $product, int $branch): string
    {
        return $this->asTenant($club, fn () => app(StockService::class)->quantity($product, $branch));
    }

    public function test_each_branch_holds_its_own_stock_and_the_club_total_is_their_sum(): void
    {
        $club = $this->club();
        [$main, $khoud] = $this->twoBranches($club);
        $bar = $this->stocked($club, [$main->id => 10, $khoud->id => 3]);
        $this->assertSame(['10.000', '3.000', '13.000'], [$this->qty($club, $bar, $main->id), $this->qty($club, $bar, $khoud->id), $bar->stock_quantity]);

        // A sale in Al Khoud takes Al Khoud's shelf; more than it holds is refused there even if the club has enough.
        $khoudSeller = $this->staff($club, 'reception', ['branch_id' => $khoud->id]);
        $this->actingAs($khoudSeller)->post(route('club.pos.checkout'), ['items' => [['product_id' => $bar->id, 'quantity' => 2]], 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $this->actingAs($khoudSeller)->post(route('club.pos.checkout'), ['items' => [['product_id' => $bar->id, 'quantity' => 2]], 'payment_method' => 'cash'])->assertSessionHasErrors('items');
        $this->assertSame(['10.000', '1.000', '11.000'], [$this->qty($club, $bar, $main->id), $this->qty($club, $bar, $khoud->id), $this->asTenant($club, fn () => $bar->fresh()->stock_quantity)]);

        // Voiding it puts it back on Al Khoud's shelf.
        $sale = $this->asTenant($club, fn () => Sale::firstOrFail());
        $this->actingAs($this->owner($club))->post(route('club.pos.void', $sale), ['reason' => 'mistake'])->assertSessionHasNoErrors();
        $this->assertSame(['10.000', '3.000'], [$this->qty($club, $bar, $main->id), $this->qty($club, $bar, $khoud->id)]);

        // The point of sale shows the seller's branch.
        $this->actingAs($khoudSeller)->get(route('club.pos.index'))->assertInertia(fn ($page) => $page->where('products.0.stock_quantity', '3.000')->where('branch', 'فرع الخوض'));
        $this->assertSame(0, Artisan::call('inventory:check'));
    }

    public function test_a_website_order_holds_stock_at_its_pickup_branch_and_cancelling_returns_it_there(): void
    {
        $club = $this->club();
        [$main, $khoud] = $this->twoBranches($club);
        $bar = $this->stocked($club, [$main->id => 5, $khoud->id => 2]);

        $order = $this->asTenant($club, fn () => app(OrderService::class)->place([['product_id' => $bar->id, 'quantity' => 2]], ['name' => 'Salim', 'phone' => '96891234567'], 'pickup', null, $khoud->id));
        $this->assertSame(['5.000', '0.000'], [$this->qty($club, $bar, $main->id), $this->qty($club, $bar, $khoud->id)]);
        $this->expectExceptionOnce(fn () => $this->asTenant($club, fn () => app(OrderService::class)->place([['product_id' => $bar->id, 'quantity' => 1]], ['name' => 'Ali', 'phone' => '96891234568'], 'pickup', null, $khoud->id)), 'فرع الخوض');

        $this->actingAs($this->owner($club))->post(route('club.sales.orders.cancel', $order), ['reason' => 'Customer called'])->assertSessionHasNoErrors();
        $this->assertSame(['5.000', '2.000'], [$this->qty($club, $bar, $main->id), $this->qty($club, $bar, $khoud->id)]);
    }

    public function test_an_addon_takes_its_stock_from_the_selling_branch(): void
    {
        $club = $this->club();
        [$main, $khoud] = $this->twoBranches($club);
        $shake = $this->stocked($club, [$khoud->id => 5], ['name' => 'Shake', 'price' => 2]);
        $banana = $this->stocked($club, [$main->id => 10, $khoud->id => 4], ['name' => 'Banana', 'price' => 0.2]);
        $addon = $this->asTenant($club, function () use ($banana, $shake) {
            $a = ProductAddon::create(['name' => 'Add banana', 'price' => 0.3, 'applies_to' => 'products', 'stock_product_id' => $banana->id, 'stock_usage' => 1]);
            $a->products()->sync([$shake->id]);

            return $a;
        });
        $this->actingAs($this->staff($club, 'reception', ['branch_id' => $khoud->id]))->post(route('club.pos.checkout'), [
            'items' => [['product_id' => $shake->id, 'quantity' => 2, 'addons' => [['addon_id' => $addon->id, 'quantity' => 1]]]], 'payment_method' => 'cash',
        ])->assertSessionHasNoErrors();
        $this->assertSame(['10.000', '2.000', '3.000'], [$this->qty($club, $banana, $main->id), $this->qty($club, $banana, $khoud->id), $this->qty($club, $shake, $khoud->id)]);
    }

    public function test_a_count_is_approved_once_and_posts_its_differences_with_their_reasons(): void
    {
        $club = $this->club();
        [$main] = $this->twoBranches($club);
        $bar = $this->stocked($club, [$main->id => 10]);
        $water = $this->stocked($club, [$main->id => 6], ['name' => 'Water', 'price' => 0.2]);
        $owner = $this->owner($club);

        $this->actingAs($owner)->post(route('club.inventory.counts.store'), ['branch_id' => $main->id])->assertSessionHasNoErrors();
        $count = $this->asTenant($club, fn () => Stocktake::with('lines')->firstOrFail());
        $lines = $count->lines->keyBy('product_id');
        // Saving a draft changes nothing on the shelf.
        $this->actingAs($owner)->put(route('club.inventory.counts.save', $count), ['counts' => [$lines[$bar->id]->id => ['counted' => 8, 'reason' => 'damaged', 'note' => 'broken'], $lines[$water->id]->id => ['counted' => 6]]])->assertSessionHasNoErrors();
        $this->assertSame('10.000', $this->qty($club, $bar, $main->id));
        // A sale after the count started: approval reads the book again, so the difference is against today's book.
        $this->asTenant($club, fn () => app(StockService::class)->move($bar, -1, 'sale', null, branchId: $main->id));

        $this->actingAs($owner)->put(route('club.inventory.counts.save', $count), ['counts' => [], 'approve' => true])->assertSessionHasNoErrors();
        $this->actingAs($owner)->put(route('club.inventory.counts.save', $count), ['counts' => [], 'approve' => true])->assertSessionHasErrors();
        $this->asTenant($club, function () use ($count, $bar, $main) {
            $this->assertSame(Stocktake::APPROVED, $count->fresh()->status);
            $movement = StockMovement::where('reference_type', 'stocktake')->sole();
            $this->assertSame(['-1.000', 'damaged', $count->number, $main->id], [$movement->quantity, $movement->reason, $movement->document, $movement->branch_id]);
            $this->assertSame('8.000', $bar->fresh()->stock_quantity);
        });

        // Approving needs its own permission: a user who counts but may not approve only saves.
        $counter = $this->staff($club, 'reception');
        $counter->role->update(['permissions' => ['inventory.view', 'inventory.adjust']]);
        $this->actingAs($counter->fresh())->post(route('club.inventory.counts.store'), ['branch_id' => $main->id])->assertSessionHasNoErrors();
        $second = $this->asTenant($club, fn () => Stocktake::latest('id')->firstOrFail());
        $this->actingAs($counter->fresh())->put(route('club.inventory.counts.save', $second), ['counts' => [], 'approve' => true])->assertForbidden();
    }

    public function test_damage_and_loss_only_take_away_and_each_adjustment_has_its_own_number(): void
    {
        $club = $this->club();
        [$main] = $this->twoBranches($club);
        $bar = $this->stocked($club, [$main->id => 10]);
        $owner = $this->owner($club);

        $this->actingAs($owner)->post(route('club.inventory.adjust'), ['product_id' => $bar->id, 'branch_id' => $main->id, 'direction' => 'in', 'quantity' => 2, 'reason' => 'damaged'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.inventory.adjust'), ['product_id' => $bar->id, 'branch_id' => $main->id, 'direction' => 'out', 'quantity' => 1, 'reason' => 'lost'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.inventory.adjust'), ['product_id' => $bar->id, 'branch_id' => $main->id, 'direction' => 'in', 'quantity' => 1, 'reason' => 'correction'])->assertSessionHasNoErrors();
        $this->asTenant($club, function () use ($bar) {
            $this->assertSame('8.000', $bar->fresh()->stock_quantity);
            $docs = StockMovement::where('type', 'adjustment')->where('reason', '!=', 'opening')->orderBy('id')->get();
            $this->assertSame(['-2.000', '-1.000', '1.000'], $docs->pluck('quantity')->all());
            $this->assertCount(3, $docs->pluck('document')->filter(fn ($d) => str_starts_with((string) $d, 'SA-'))->unique());
        });
        $this->actingAs($this->staff($club, 'reception'))->post(route('club.inventory.adjust'), ['product_id' => $bar->id, 'branch_id' => $main->id, 'direction' => 'out', 'quantity' => 1, 'reason' => 'lost'])->assertForbidden();
    }

    public function test_a_transfer_moves_stock_only_when_it_leaves_and_arrives_and_a_shortage_is_settled(): void
    {
        $club = $this->club();
        [$main, $khoud] = $this->twoBranches($club);
        $bar = $this->stocked($club, [$main->id => 10]);
        $manager = $this->staff($club, 'manager');
        $owner = $this->owner($club);

        // Asked for by the manager; the manager cannot approve their own request; the owner may.
        $this->actingAs($manager)->post(route('club.inventory.transfers.store'), ['from_branch_id' => $main->id, 'to_branch_id' => $khoud->id, 'lines' => [['product_id' => $bar->id, 'quantity' => 6]], 'client_key' => 'tr-1'])->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('club.inventory.transfers.store'), ['from_branch_id' => $main->id, 'to_branch_id' => $khoud->id, 'lines' => [['product_id' => $bar->id, 'quantity' => 6]], 'client_key' => 'tr-1'])->assertSessionHasNoErrors();
        $transfer = $this->asTenant($club, fn () => StockTransfer::sole()); // one transfer for the repeated form
        $this->actingAs($manager)->post(route('club.inventory.transfers.step', [$transfer, 'approve']))->assertSessionHasErrors();
        $this->actingAs($owner)->post(route('club.inventory.transfers.step', [$transfer, 'approve']))->assertSessionHasNoErrors();
        $this->assertSame('10.000', $this->qty($club, $bar, $main->id), 'approval moves nothing');

        // Dispatched: it leaves the main branch and is on no shelf; dispatching again is refused.
        $this->actingAs($manager)->post(route('club.inventory.transfers.step', [$transfer, 'dispatch']))->assertSessionHasNoErrors();
        $this->actingAs($manager)->post(route('club.inventory.transfers.step', [$transfer, 'dispatch']))->assertSessionHasErrors();
        $this->assertSame(['4.000', '0.000', '4.000'], [$this->qty($club, $bar, $main->id), $this->qty($club, $bar, $khoud->id), $this->asTenant($club, fn () => $bar->fresh()->stock_quantity)]);

        // Only the receiving branch receives; 5 of 6 arrive; receiving twice is refused.
        $mainOnly = $this->staff($club, 'manager', ['branch_id' => $main->id]);
        $this->actingAs($mainOnly)->post(route('club.inventory.transfers.step', [$transfer, 'receive']))->assertSessionHasErrors();
        $khoudManager = $this->staff($club, 'manager', ['branch_id' => $khoud->id]);
        $line = $this->asTenant($club, fn () => $transfer->lines()->sole());
        $this->actingAs($khoudManager)->post(route('club.inventory.transfers.step', [$transfer, 'receive']), ['quantities' => [$line->id => 7]])->assertSessionHasErrors();
        $this->actingAs($khoudManager)->post(route('club.inventory.transfers.step', [$transfer, 'receive']), ['quantities' => [$line->id => 5]])->assertSessionHasNoErrors();
        $this->actingAs($khoudManager)->post(route('club.inventory.transfers.step', [$transfer, 'receive']), ['quantities' => [$line->id => 5]])->assertSessionHasErrors();
        $this->asTenant($club, fn () => $this->assertSame(StockTransfer::DISCREPANCY, $transfer->fresh()->status));
        $this->assertSame(['4.000', '5.000'], [$this->qty($club, $bar, $main->id), $this->qty($club, $bar, $khoud->id)]);

        // The missing piece came back to the main branch: settled, the transfer is complete.
        $this->actingAs($owner)->post(route('club.inventory.transfers.step', [$transfer, 'resolve']), ['line_id' => $line->id, 'outcome' => 'returned', 'quantity' => 1, 'reason' => 'Left in the van'])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame(StockTransfer::RECEIVED, $transfer->fresh()->status));
        $this->assertSame(['5.000', '5.000', '10.000'], [$this->qty($club, $bar, $main->id), $this->qty($club, $bar, $khoud->id), $this->asTenant($club, fn () => $bar->fresh()->stock_quantity)]);
        $this->assertSame(0, Artisan::call('inventory:check'));

        // A user of a branch not concerned does not reach it.
        $third = $this->asTenant($club, fn () => Branch::create(['name' => 'صلالة', 'is_active' => true]));
        $this->actingAs($this->staff($club, 'manager', ['branch_id' => $third->id]))->get(route('club.inventory.transfers.show', $transfer))->assertNotFound();
    }

    public function test_two_hands_on_one_transfer_move_the_stock_once(): void
    {
        $club = $this->club();
        [$main, $khoud] = $this->twoBranches($club);
        $bar = $this->stocked($club, [$main->id => 10]);
        $this->actingAs($this->owner($club));
        $transfer = $this->asTenant($club, fn () => app(TransferService::class)->create($main->id, $khoud->id, [['product_id' => $bar->id, 'quantity' => 4]], direct: true));
        $this->asTenant($club, function () use ($transfer) {
            app(TransferService::class)->receive($transfer);
            $this->expectException(BusinessRuleException::class);
            app(TransferService::class)->receive($transfer);
        });
        $this->assertSame(['6.000', '4.000'], [$this->qty($club, $bar, $main->id), $this->qty($club, $bar, $khoud->id)]);
    }

    public function test_a_branch_asks_for_stock_and_the_management_supplies_it_by_transfer_and_purchase(): void
    {
        $club = $this->club();
        [$main, $khoud] = $this->twoBranches($club);
        $bar = $this->stocked($club, [$main->id => 10]);
        $water = $this->stocked($club, [], ['name' => 'Water', 'price' => 0.2]);
        $supplier = $this->asTenant($club, fn () => Supplier::create(['name' => 'Al Noor']));
        $khoudManager = $this->staff($club, 'manager', ['branch_id' => $khoud->id]);

        $this->actingAs($khoudManager)->post(route('club.inventory.supply.store'), ['branch_id' => $main->id, 'lines' => [['product_id' => $bar->id, 'quantity' => 3]]])->assertSessionHasErrors('branch_id');
        $this->actingAs($khoudManager)->post(route('club.inventory.supply.store'), ['branch_id' => $khoud->id, 'lines' => [['product_id' => $bar->id, 'quantity' => 3], ['product_id' => $water->id, 'quantity' => 24]]])->assertSessionHasNoErrors();
        $request = $this->asTenant($club, fn () => SupplyRequest::with('lines')->sole());
        [$barLine, $waterLine] = [$request->lines->firstWhere('product_id', $bar->id), $request->lines->firstWhere('product_id', $water->id)];

        $this->actingAs($this->owner($club))->post(route('club.inventory.supply.review', [$request, 'approve']), [
            'decisions' => [$barLine->id => ['quantity' => 3, 'source' => (string) $main->id], $waterLine->id => ['quantity' => 24, 'source' => 'purchase']], 'supplier_id' => $supplier->id,
        ])->assertSessionHasNoErrors();
        $this->asTenant($club, function () use ($request, $khoud) {
            $request->refresh();
            $this->assertSame(SupplyRequest::APPROVED, $request->status);
            $this->assertSame('in_progress', $request->load('transfers', 'purchaseOrder')->progress());
            $transfer = $request->transfers->sole();
            $this->assertSame([StockTransfer::APPROVED, $khoud->id], [$transfer->status, $transfer->to_branch_id]);
            $this->assertSame([$khoud->id, 'draft'], [$request->purchaseOrder->branch_id, $request->purchaseOrder->status]);
        });
        $this->actingAs($this->owner($club))->post(route('club.inventory.supply.review', [$request, 'reject']), ['reason' => 'too late'])->assertSessionHasErrors();
    }

    public function test_inventory_screens_follow_permissions_and_stay_in_their_club(): void
    {
        $club = $this->club();
        [$main, $khoud] = $this->twoBranches($club);
        $this->stocked($club, [$main->id => 10, $khoud->id => 2]);
        $owner = $this->owner($club);
        foreach (['club.inventory.index', 'club.inventory.movements', 'club.inventory.counts', 'club.inventory.receipts', 'club.inventory.transfers', 'club.inventory.supply'] as $name) {
            $this->actingAs($owner)->get(route($name))->assertOk();
        }
        $this->actingAs($this->staff($club, 'reception'))->get(route('club.inventory.index'))->assertForbidden();
        $this->actingAs($this->staff($club, 'finance'))->get(route('club.inventory.index'))->assertOk();
        $this->actingAs($this->staff($club, 'finance'))->post(route('club.inventory.adjust'), [])->assertForbidden();

        // A manager tied to Al Khoud sees Al Khoud's balance, whatever they ask for.
        $this->actingAs($this->staff($club, 'manager', ['branch_id' => $khoud->id]))->get(route('club.inventory.index', ['branch' => 'all']))
            ->assertInertia(fn ($page) => $page->where('rows.data.0.quantity', fn ($q) => (float) $q === 2.0)->where('branch.locked', true));
        $this->actingAs($owner)->get(route('club.inventory.index', ['branch' => 'all']))->assertInertia(fn ($page) => $page->where('rows.data.0.quantity', fn ($q) => (float) $q === 12.0));

        // Another club's records are out of reach.
        $other = $this->club('Other');
        $theirs = $this->stocked($other, [$this->asTenant($other, fn () => Branch::value('id')) => 5]);
        $this->actingAs($owner)->post(route('club.inventory.adjust'), ['product_id' => $theirs->id, 'branch_id' => $main->id, 'direction' => 'out', 'quantity' => 1, 'reason' => 'lost'])->assertSessionHasErrors('product_id');
        $this->assertSame(1, $this->asTenant($club, fn () => ProductStock::where('branch_id', $khoud->id)->count()));
    }

    private function expectExceptionOnce(callable $call, string $contains): void
    {
        try {
            $call();
            $this->fail('A business rule was expected.');
        } catch (BusinessRuleException $e) {
            $this->assertStringContainsString($contains, $e->getMessage());
        }
    }
}
