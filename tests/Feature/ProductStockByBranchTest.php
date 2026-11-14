<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The product list counts the same shelf as the stock screen: the branch in view (the user's own, or the one a
 * club-wide user chose in the top bar), and the whole club only when no branch is in view. Its quantity, its «نفد» and
 * «وصل لحد إعادة الطلب» filters and its sort by stock follow that shelf; nothing here moves or changes stock.
 */
class ProductStockByBranchTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** @return array{0: Tenant, 1: Branch, 2: Branch} water: 10 in A, none in B; juice: 5 in A, 1 in B (reorder level 2) */
    private function stocked(): array
    {
        $club = $this->club();
        [$a, $b] = $this->asTenant($club, function () {
            $a = Branch::create(['name' => 'فرع الخوض', 'is_active' => true]);
            $b = Branch::create(['name' => 'فرع صلالة', 'is_active' => true]);
            $water = Product::create(['name' => 'ماء', 'price' => 0.5, 'track_stock' => true, 'reorder_level' => 2]);
            $juice = Product::create(['name' => 'عصير', 'price' => 1, 'track_stock' => true, 'reorder_level' => 2]);
            Product::create(['name' => 'منشفة', 'price' => 3, 'track_stock' => false]);
            app(StockService::class)->move($water, 10, 'adjustment', null, 'opening', 0.2, branchId: $a->id);
            app(StockService::class)->move($juice, 5, 'adjustment', null, 'opening', 0.4, branchId: $a->id);
            app(StockService::class)->move($juice, 1, 'adjustment', null, 'opening', 0.4, branchId: $b->id);

            return [$a, $b];
        });

        return [$club, $a, $b];
    }

    private function props(TestResponse $response): array
    {
        $props = [];
        $response->assertOk()->assertInertia(function ($page) use (&$props) {
            $props = $page->toArray()['props'];
        });

        return $props;
    }

    /** name => [quantity, low, out] as the product list shows them */
    private function list($user, array $query = []): array
    {
        return collect($this->props($this->actingAs($user)->get(route('club.products.index', $query)))['products']['data'])
            ->filter(fn ($p) => $p['track_stock'])
            ->mapWithKeys(fn ($p) => [$p['name'] => [(float) $p['stock_quantity'], $p['low'], $p['out']]])->all();
    }

    /** name => [quantity, status] as the stock screen shows them */
    private function stockScreen($user, array $query = []): array
    {
        return collect($this->props($this->actingAs($user)->get(route('club.inventory.index', $query)))['rows']['data'])
            ->mapWithKeys(fn ($r) => [$r['name'] => [(float) $r['quantity'], $r['status']]])->all();
    }

    public function test_a_user_of_a_branch_sees_and_filters_that_branchs_shelf_as_the_stock_screen_does(): void
    {
        [$club, $a, $b] = $this->stocked();
        $inB = $this->staff($club, 'manager', ['branch_id' => $b->id]);
        $inA = $this->staff($club, 'manager', ['branch_id' => $a->id]);

        // Branch B: water is out there although the club holds 10; juice is at its reorder level.
        $this->assertEquals(['ماء' => [0.0, true, true], 'عصير' => [1.0, true, false]], $this->list($inB));
        $this->assertEquals(['ماء' => [0.0, 'out'], 'عصير' => [1.0, 'low']], $this->stockScreen($inB));
        $this->assertSame(['ماء'], array_keys($this->list($inB, ['stock' => 'out'])));
        $this->assertEqualsCanonicalizing(['ماء', 'عصير'], array_keys($this->list($inB, ['stock' => 'low'])), 'reached the reorder level, out included');
        $this->assertSame(['ماء', 'عصير'], array_keys($this->list($inB, ['sort' => 'stock'])), 'least first, on this shelf');

        // Branch A: the same products are in stock.
        $this->assertEquals(['ماء' => [10.0, false, false], 'عصير' => [5.0, false, false]], $this->list($inA));
        $this->assertEquals(['ماء' => [10.0, 'in'], 'عصير' => [5.0, 'in']], $this->stockScreen($inA));
        $this->assertSame([], $this->list($inA, ['stock' => 'out']));
        $this->assertSame([], $this->list($inA, ['stock' => 'low']));
        $this->assertSame(['عصير', 'ماء'], array_keys($this->list($inA, ['sort' => 'stock'])));
    }

    public function test_a_club_wide_user_sees_the_whole_club_until_choosing_a_branch(): void
    {
        [$club, , $b] = $this->stocked();
        $owner = $this->owner($club);

        $this->assertEquals(['ماء' => [10.0, false, false], 'عصير' => [6.0, false, false]], $this->list($owner));
        $this->assertEquals(['ماء' => [10.0, 'in'], 'عصير' => [6.0, 'in']], $this->stockScreen($owner));
        $this->assertSame([], $this->list($owner, ['stock' => 'out']));

        $this->actingAs($owner)->post(route('club.branch.switch'), ['branch_id' => $b->id]);
        $this->assertEquals(['ماء' => [0.0, true, true], 'عصير' => [1.0, true, false]], $this->list($owner));
        $this->assertEquals(['ماء' => [0.0, 'out'], 'عصير' => [1.0, 'low']], $this->stockScreen($owner));
        $this->assertSame(['ماء'], array_keys($this->list($owner, ['stock' => 'out'])));

        // Nothing was moved by looking: the club still holds what it held.
        $this->asTenant($club, fn () => $this->assertSame(['10.000', '6.000'], [Product::where('name', 'ماء')->value('stock_quantity'), Product::where('name', 'عصير')->value('stock_quantity')]));
    }
}
