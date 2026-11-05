<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Stocktake;
use App\Modules\Inventory\Models\StockTransfer;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** A stock step either happens whole or not at all, and a refused step leaves nothing behind. */
class StockIntegrityTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** @return array{0: Branch, 1: Branch, 2: Product} */
    private function setUpStock(Tenant $club, int $onShelf): array
    {
        return $this->asTenant($club, function () use ($onShelf) {
            $main = Branch::orderByDesc('is_main')->orderBy('id')->firstOrFail();
            $other = Branch::create(['name' => 'فرع الخوض', 'is_active' => true]);
            $bar = Product::create(['name' => 'Protein bar', 'price' => 1.5]);
            app(StockService::class)->move($bar, $onShelf, 'adjustment', null, 'opening', 0.8, branchId: $main->id);

            return [$main, $other, $bar->fresh()];
        });
    }

    public function test_a_direct_transfer_refused_for_stock_leaves_no_half_done_transfer_and_the_retry_really_sends(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        [$main, $other, $bar] = $this->setUpStock($club, 4);
        $payload = fn (int $qty) => ['from_branch_id' => $main->id, 'to_branch_id' => $other->id, 'lines' => [['product_id' => $bar->id, 'quantity' => $qty]], 'direct' => true, 'client_key' => 'direct-1'];

        $this->actingAs($owner)->post(route('club.inventory.transfers.store'), $payload(6))->assertSessionHasErrors();
        $this->asTenant($club, function () use ($bar, $main) {
            $this->assertSame(0, StockTransfer::count(), 'nothing of the refused transfer stays');
            $this->assertSame('4.000', app(StockService::class)->quantity($bar, $main->id));
        });

        // The same dialog, its quantity lowered: this time it is created, approved and sent.
        $this->actingAs($owner)->post(route('club.inventory.transfers.store'), $payload(4))->assertSessionHasNoErrors()->assertSessionHas('success');
        $this->asTenant($club, function () use ($bar, $main) {
            $this->assertSame(StockTransfer::IN_TRANSIT, StockTransfer::sole()->status);
            $this->assertSame('0.000', app(StockService::class)->quantity($bar, $main->id));
        });
    }

    public function test_a_count_sent_for_approval_by_someone_who_may_not_approve_is_refused_whole(): void
    {
        $club = $this->club();
        [$main, , $bar] = $this->setUpStock($club, 10);
        $counter = $this->staff($club, 'reception');
        $counter->role->update(['permissions' => ['inventory.view', 'inventory.adjust']]);

        $this->actingAs($counter->fresh())->post(route('club.inventory.counts.store'), ['branch_id' => $main->id])->assertSessionHasNoErrors();
        $count = $this->asTenant($club, fn () => Stocktake::with('lines')->latest('id')->firstOrFail());
        $line = $count->lines->firstWhere('product_id', $bar->id);

        $this->actingAs($counter->fresh())->put(route('club.inventory.counts.save', $count), ['counts' => [$line->id => ['counted' => 7]], 'approve' => true])->assertForbidden();

        $this->asTenant($club, fn () => $this->assertNull($line->fresh()->counted_quantity, 'a refused request saves nothing'));
    }
}
