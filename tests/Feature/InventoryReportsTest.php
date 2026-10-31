<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\PurchaseService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Inventory\Services\SupplierBillService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** The inventory and purchasing reports on the reports page: each opens, exports with the page's figures, for those allowed. */
class InventoryReportsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const KEYS = ['stock-balance', 'low-stock', 'stock-movements', 'stocktakes', 'transfers', 'purchases', 'suppliers', 'supplier-dues', 'goods-receipts'];

    public function test_each_inventory_and_purchasing_report_opens_and_exports_for_those_allowed(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $club = $this->club();
        $owner = $this->owner($club);
        $this->actingAs($owner);
        $this->asTenant($club, function () {
            $branch = Branch::firstOrFail();
            $water = Product::create(['name' => 'Water', 'price' => 0.2, 'reorder_level' => 30]);
            $supplier = Supplier::create(['name' => 'Al Noor']);
            $purchases = app(PurchaseService::class);
            $order = $purchases->approve($purchases->create($supplier, [['product_id' => $water->id, 'quantity' => 2, 'unit_cost' => 4.8, 'unit' => 'كرتون', 'unit_factor' => 12]], ['branch_id' => $branch->id]));
            $purchases->receive($order, [$order->items()->value('id') => ['quantity' => 2]], [], approveNow: true);
            $bills = app(SupplierBillService::class);
            $bills->approve($bills->create($supplier, ['reference' => 'INV-1', 'issue_date' => now()->subDays(40)->toDateString(), 'due_date' => now()->subDays(10)->toDateString(), 'subtotal' => 9.6, 'purchase_order_id' => $order->id]));
            app(InventoryService::class)->adjust($water, $branch->id, 2, 'damaged', 'broken');
            $count = app(InventoryService::class)->startCount($branch->id);
            app(InventoryService::class)->saveCounts($count, [$count->lines()->value('id') => ['counted' => 20]]);
            app(InventoryService::class)->approveCount($count);
            app(StockService::class)->quantity($water);
        });

        $cards = collect($this->get(route('club.reports.index'))->assertOk()->viewData('page')['props']['reports'])->pluck('key');
        foreach (self::KEYS as $key) {
            $this->assertTrue($cards->contains($key), "card {$key}");
            $this->get(route('club.reports.show', $key))->assertOk()->assertInertia(fn ($page) => $page->where('kind', $key)->has('data.figures')->has('data.tables'));
            $this->get(route('club.reports.export', ['report' => $key, 'format' => 'xlsx']))->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }
        $this->get(route('club.reports.show', 'stock-balance'))->assertInertia(fn ($page) => $page->where('data.figures.0.value', 1)->where('data.tables.0.rows.0.3', fn ($q) => (float) $q === 20.0));
        $this->get(route('club.reports.show', 'supplier-dues'))->assertInertia(fn ($page) => $page->where('data.figures.1.value', fn ($v) => (float) $v === 9.6));
        $this->get(route('club.reports.show', 'stocktakes'))->assertInertia(fn ($page) => $page->where('data.figures.0.value', 1));
        $csv = $this->get(route('club.reports.export', ['report' => 'low-stock', 'format' => 'csv']))->streamedContent();
        $this->assertStringContainsString('Water', $csv);

        // Reception has none of them; finance has the purchasing ones and the stock ones (it sees stock).
        $this->actingAs($this->staff($club, 'reception'))->get(route('club.reports.show', 'stock-balance'))->assertForbidden();
        $finance = $this->staff($club, 'finance');
        $this->actingAs($finance)->get(route('club.reports.show', 'supplier-dues'))->assertOk();
        $this->actingAs($finance)->get(route('club.reports.show', 'stock-movements'))->assertOk();
    }
}
