<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductStock;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Stock by branch in المخزون والأرصدة and المنتجات منخفضة المخزون. One product, two branches: 20 in the main
 * branch, none in the second, whose minimum is 5. Each branch shows its own state; «all branches» keeps the club's
 * real total (20, counted once) and still says the second branch is out, instead of hiding it behind the first's 20.
 */
class StockBranchShortageTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private Tenant $club;

    private User $owner;

    private Branch $main;

    private Branch $second;

    private Product $water;

    protected function setUp(): void
    {
        parent::setUp();
        $this->club = $this->club();
        $this->owner = $this->owner($this->club);
        [$this->main, $this->second, $this->water] = $this->asTenant($this->club, function () {
            $main = Branch::where('is_main', true)->firstOrFail();
            $second = Branch::create(['name' => 'فرع الخوض', 'is_active' => true]);
            $water = Product::create(['name' => 'Water', 'price' => 0.5, 'track_stock' => true, 'reorder_level' => 0]);
            app(StockService::class)->move($water, 20, 'adjustment', null, 'opening', 0.2, branchId: $main->id);
            // The second branch stocked once, then sold out; its own minimum is 5 (min_quantity: no screen sets it yet).
            app(StockService::class)->move($water, 3, 'adjustment', null, 'opening', 0.2, branchId: $second->id);
            app(StockService::class)->move($water, -3, 'adjustment', null, 'sold out', 0.2, branchId: $second->id);
            ProductStock::where('product_id', $water->id)->where('branch_id', $second->id)->update(['min_quantity' => 5]);

            return [$main, $second, $water->fresh()];
        });
    }

    /** The report's only table as [product => [column => value]], and its figures by label. */
    private function report(string $key, string $branch): array
    {
        $data = $this->actingAs($this->owner)->get(route('club.reports.show', ['report' => $key, 'branch' => $branch]))->assertOk()->viewData('page')['props']['data'];
        $table = $data['tables'][0];
        $labels = array_column($table['columns'], 'label');
        $rows = [];
        foreach ($table['rows'] as $row) {
            $rows[$row[0]] = array_combine($labels, $row);
        }

        return [$rows, array_column($data['figures'], 'value', 'label')];
    }

    public function test_each_branch_shows_its_own_stock(): void
    {
        [$rows, $figures] = $this->report('stock-balance', (string) $this->second->id);
        $this->assertSame([0, 5, 'نفد'], [(int) $rows['Water']['الكمية'], (int) $rows['Water']['الحد الأدنى'], $rows['Water']['الحالة']], 'the second branch: out');
        $this->assertSame(1, $figures['نافدة']);
        [$low] = $this->report('low-stock', (string) $this->second->id);
        $this->assertArrayHasKey('Water', $low);

        [$rows] = $this->report('stock-balance', (string) $this->main->id);
        $this->assertSame([20, 'متوفر'], [(int) $rows['Water']['الكمية'], $rows['Water']['الحالة']], 'the main branch: its 20');
        [$low] = $this->report('low-stock', (string) $this->main->id);
        $this->assertArrayNotHasKey('Water', $low);
    }

    public function test_all_branches_keep_the_real_total_and_still_show_the_branch_that_is_out(): void
    {
        [$rows, $figures] = $this->report('stock-balance', 'all');
        $this->assertSame(20, (int) $rows['Water']['الكمية'], 'the club\'s total, counted once');
        $this->assertSame('20.000', $this->asTenant($this->club, fn () => app(StockService::class)->quantity($this->water, $this->main->id)), 'as the stock itself says');
        $this->assertSame('0.000', $this->asTenant($this->club, fn () => app(StockService::class)->quantity($this->water, $this->second->id)));
        $this->assertSame('نافد في فرع', $rows['Water']['الحالة'], 'not «متوفر»: the second branch has none');
        $this->assertStringContainsString('فرع الخوض: 0 من 5', $rows['Water']['الفروع الناقصة']);
        $this->assertSame([1, 0, 0, 1], [$figures['الأصناف'], $figures['نافدة'], $figures['منخفضة'], $figures['ناقصة في فرع']], 'the club is not out; one product is short in a branch');

        [$low] = $this->report('low-stock', 'all');
        $this->assertArrayHasKey('Water', $low, 'the low-stock report keeps it for all branches');
        $this->assertSame(10, (int) $low['Water']['الكمية المقترحة للطلب'], 'what the second branch needs (2 × 5 − 0)');
    }

    public function test_a_product_level_minimum_is_each_branchs_minimum_in_all_branches_too(): void
    {
        $this->asTenant($this->club, function () {
            ProductStock::where('product_id', $this->water->id)->update(['min_quantity' => null]);
            $this->water->forceFill(['reorder_level' => 5])->save();
        });

        [$rows, $figures] = $this->report('stock-balance', 'all');
        $this->assertSame([20, 'نافد في فرع'], [(int) $rows['Water']['الكمية'], $rows['Water']['الحالة']], '20 in all is above 5, but the second branch has none');
        $this->assertSame(1, $figures['ناقصة في فرع']);
    }

    public function test_a_branch_user_sees_only_their_branch_whatever_the_address_says(): void
    {
        $seller = $this->staff($this->club, 'cashier', ['branch_id' => $this->second->id]);
        $seller->forceFill(['branch_id' => $this->second->id])->save();
        $response = $this->actingAs($seller)->get(route('club.reports.show', ['report' => 'stock-balance', 'branch' => 'all']));
        if ($response->status() === 403) {
            $this->assertTrue(true, 'a cashier has no stock reports at all');

            return;
        }
        $rows = $response->assertOk()->viewData('page')['props']['data']['tables'][0]['rows'];
        $this->assertSame([0], array_map(fn ($r) => (int) $r[3], $rows), 'the second branch\'s 0, never the club\'s 20');
    }
}
