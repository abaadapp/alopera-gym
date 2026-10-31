<?php

namespace Tests\Feature;

use App\Core\Support\Period;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\OrderService;
use App\Modules\Inventory\Services\StockService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * المبيعات: one list of the sales of both sources — the period, search, filters, sort and the export of what is
 * filtered — each sale on its own page, and whoever only handles website orders seeing those alone.
 */
class SalesListTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function product(Tenant $club): Product
    {
        return $this->asTenant($club, function () {
            $product = Product::create(['name' => 'Protein bar', 'price' => 1.5, 'show_on_website' => true]);
            app(StockService::class)->move($product, 50, 'adjustment', null, 'opening', 0.8);

            return $product->fresh();
        });
    }

    /** @return array{0: Tenant, 1: User, 2: Sale, 3: Sale, 4: Sale} two till sales (1 and 3 bars) and a website order */
    private function sales(): array
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $bar = $this->product($club);
        foreach ([1, 3] as $qty) {
            $this->actingAs($owner)->post(route('club.pos.checkout'), ['items' => [['product_id' => $bar->id, 'quantity' => $qty]], 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        }
        $order = $this->asTenant($club, fn () => app(OrderService::class)->place([['product_id' => $bar->id, 'quantity' => 2]], ['name' => 'سالم', 'phone' => '96891234567'], 'pickup'));
        [$small, $big] = $this->asTenant($club, fn () => Sale::where('source', Sale::POS)->orderBy('total')->get()->all());

        return [$club, $owner, $small, $big, $order];
    }

    public function test_one_list_with_its_period_search_filters_sort_and_totals(): void
    {
        [$club, $owner, $small, $big, $order] = $this->sales();
        $list = fn (array $query = []) => $this->actingAs($owner)->get(route('club.sales.index', $query))->assertOk();

        $list()->assertInertia(fn ($page) => $page->component('Sales/Index')->has('sales.data', 3)->where('period.range', 'all')
            ->where('totals.count', 3)->where('totals.sold', '6.000')->where('totals.website_count', 1)->where('can.export', true)->where('can.pos', true));
        $list(['source' => 'website'])->assertInertia(fn ($page) => $page->has('sales.data', 1)->where('sales.data.0.number', $order->number)->where('sales.data.0.cashier', null));
        $list(['status' => 'new'])->assertInertia(fn ($page) => $page->has('sales.data', 1)->where('sales.data.0.state_label', 'جديد'));
        $list(['status' => 'completed'])->assertInertia(fn ($page) => $page->has('sales.data', 2));
        $list(['method' => 'pickup'])->assertInertia(fn ($page) => $page->has('sales.data', 1));

        // By number, by the buyer's phone, by the employee who sold.
        $list(['q' => $order->number])->assertInertia(fn ($page) => $page->has('sales.data', 1));
        $list(['q' => '91234567'])->assertInertia(fn ($page) => $page->has('sales.data', 1)->where('sales.data.0.id', $order->id));
        $list(['q' => $owner->name])->assertInertia(fn ($page) => $page->has('sales.data', 2));

        $list(['sort' => 'total', 'dir' => 'asc'])->assertInertia(fn ($page) => $page->where('sales.data.0.id', $small->id));
        $list(['sort' => 'total', 'dir' => 'desc'])->assertInertia(fn ($page) => $page->where('sales.data.0.id', $big->id));

        // The period: one sale moved two months back leaves «this month», stays in «all» and in its own dates.
        $old = CarbonImmutable::today()->subMonths(2)->setTime(10, 0);
        $this->asTenant($club, fn () => Sale::whereKey($small->id)->update(['created_at' => $old]));
        $list(['range' => 'month'])->assertInertia(fn ($page) => $page->has('sales.data', 2)->where('period.range', 'month'));
        $list(['range' => 'today'])->assertInertia(fn ($page) => $page->has('sales.data', 2));
        $list(['range' => 'custom', 'from' => $old->toDateString(), 'to' => $old->toDateString()])->assertInertia(fn ($page) => $page->has('sales.data', 1)->where('period.from', $old->toDateString()));
    }

    public function test_the_export_is_the_list_as_filtered_and_needs_the_export_permission(): void
    {
        [$club, $owner, $small, $big, $order] = $this->sales();

        $csv = $this->actingAs($owner)->get(route('club.sales.export', ['format' => 'csv', 'source' => 'pos', 'range' => 'today']))->assertOk()->streamedContent();
        $this->assertStringContainsString($small->number, $csv);
        $this->assertStringContainsString($big->number, $csv);
        $this->assertStringNotContainsString($order->number, $csv);
        $this->assertStringContainsString('اليوم', $csv);
        $this->actingAs($owner)->get(route('club.sales.export', ['format' => 'xlsx']))->assertOk()->assertHeader('content-type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        // Reception handles orders but may not export.
        $this->actingAs($this->staff($club, 'reception'))->get(route('club.sales.export', ['format' => 'csv']))->assertForbidden();
        $this->actingAs($this->staff($club, 'trainer'))->get(route('club.sales.index'))->assertForbidden();
    }

    public function test_website_orders_only_for_whoever_handles_orders_and_each_sale_on_its_page(): void
    {
        [$club, $owner, $small, , $order] = $this->sales();
        $reception = $this->staff($club, 'reception');

        $this->actingAs($reception)->get(route('club.sales.index', ['source' => 'pos']))->assertInertia(fn ($page) => $page->has('sales.data', 1)->where('sales.data.0.id', $order->id)->where('options.sources', []));
        $this->actingAs($reception)->get(route('club.sales.show', $small))->assertForbidden();
        $this->actingAs($reception)->get(route('club.sales.show', $order))->assertOk()->assertInertia(fn ($page) => $page->where('sale.state', 'new')->has('sale.items', 1)->where('can.refund', false));

        $this->actingAs($owner)->get(route('club.sales.show', $small))->assertOk()->assertInertia(fn ($page) => $page->where('sale.source', 'pos')->where('sale.cashier', $owner->name)->has('sale.payments', 1)->where('can.refund', true));
        $this->actingAs($owner)->get(route('club.sales.orders.index', ['tab' => 'completed']))->assertRedirect(route('club.sales.index', ['source' => 'website', 'status' => 'completed']));
        $this->actingAs($this->owner($this->club('Other')))->get(route('club.sales.show', $order))->assertNotFound();
    }

    public function test_every_screen_with_a_period_gets_the_same_period_from_the_address(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $week = CarbonImmutable::today()->startOfWeek(CarbonImmutable::SATURDAY)->toDateString();
        foreach (['club.sales.index', 'club.invoices.index', 'club.payments.index', 'club.expenses.index', 'club.finance.summary', 'club.finance.journal', 'club.checkins.index', 'club.staff-attendance.index', 'club.inventory.movements', 'club.audit.index'] as $name) {
            $this->actingAs($owner)->get(route($name, ['range' => 'week']))->assertOk()
                ->assertInertia(fn ($page) => $page->where('period.range', 'week')->where('period.from', $week));
            $this->actingAs($owner)->get(route($name, ['range' => 'custom', 'from' => '2026-01-05', 'to' => '2026-01-09']))->assertOk()
                ->assertInertia(fn ($page) => $page->where('period.range', 'custom')->where('period.to', '2026-01-09'));
        }
        $this->actingAs($owner)->get(route('club.reports.show', ['report' => 'sales', 'range' => 'week']))->assertInertia(fn ($page) => $page->where('filters.range', 'week')->where('filters.from', $week));
        // An older link's single day on the check-ins log is that day.
        $this->actingAs($owner)->get(route('club.checkins.index', ['date' => '2026-02-03']))->assertInertia(fn ($page) => $page->where('period.from', '2026-02-03')->where('period.to', '2026-02-03'));
    }

    public function test_the_period_is_read_the_same_way_everywhere(): void
    {
        $today = CarbonImmutable::today();
        $read = fn (string $query, string $default = 'all', array $allowed = Period::RANGES) => Period::fromRequest(Request::create('/x?'.$query), $default, $allowed);

        $this->assertSame(['all', null, null], array_values($read('')->toArray()));
        $this->assertSame(['today', $today->toDateString(), $today->toDateString()], array_values($read('range=today')->toArray()));
        $this->assertSame($today->startOfWeek(CarbonImmutable::SATURDAY)->toDateString(), $read('range=week')->toArray()['from']);
        $this->assertSame($today->startOfMonth()->toDateString(), $read('', 'month')->toArray()['from']);
        $this->assertSame(['custom', '2026-01-01', '2026-02-01'], array_values($read('from=2026-02-01&to=2026-01-01')->toArray()), 'dates the wrong way round are swapped');
        $this->assertSame('all', $read('range=custom&from=not-a-date')->range, 'a malformed date is ignored');
        $this->assertSame('month', $read('range=all', 'month', ['today', 'week', 'month', 'year'])->range, 'a period the screen does not offer falls back');
        $this->assertSame(['range' => 'custom', 'from' => '2026-01-01', 'to' => '2026-02-01'], $read('from=2026-01-01&to=2026-02-01')->query());
        $this->assertSame('هذا الشهر ('.$today->startOfMonth()->toDateString().' – '.$today->toDateString().')', $read('range=month')->label());
    }
}
