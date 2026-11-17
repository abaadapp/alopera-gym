<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Inventory\Http\Controllers\SalesController;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\OrderService;
use App\Modules\Inventory\Services\PosService;
use App\Modules\Inventory\Services\StockService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * تقرير المبيعات dates each sale by the moment it happened, in Muscat time:
 *
 * - a completed sale when it was completed: at the till (the point of sale) or when the website order was handed over;
 * - a cancelled order or a voided sale when it was cancelled or voided;
 * - an order still waiting (not paid, not handed over) is no sale at all.
 *
 * An order placed on 31 October and handed over on 2 November is a November sale; one cancelled in November is a
 * November cancellation. The summary, the days, the breakdowns and the export say the same.
 */
class SalesReportPeriodTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private Tenant $club;

    private User $owner;

    private Product $bar;

    protected function setUp(): void
    {
        parent::setUp();
        $this->club = $this->club();
        $this->owner = $this->owner($this->club);
        $this->actingAs($this->owner);
        $this->at('2026-10-01 09:00:00');
        $this->bar = $this->asTenant($this->club, function () {
            $product = Product::create(['name' => 'Protein bar', 'price' => 1.5, 'show_on_website' => true]);
            app(StockService::class)->move($product, 50, 'adjustment', null, 'opening', 0.8);

            return $product->fresh();
        });
    }

    private function at(string $muscat): void
    {
        $this->travelTo(CarbonImmutable::parse($muscat, 'Asia/Muscat'));
    }

    private function order(): Sale
    {
        return $this->asTenant($this->club, fn () => app(OrderService::class)->place([['product_id' => $this->bar->id, 'quantity' => 1]], ['name' => 'Salim', 'phone' => '96891234567'], 'pickup'));
    }

    private function till(int $quantity = 1): Sale
    {
        return $this->asTenant($this->club, fn () => app(PosService::class)->checkout([['product_id' => $this->bar->id, 'quantity' => $quantity]], 'cash'));
    }

    private function report(string $from, string $to): array
    {
        return $this->get(route('club.reports.show', ['report' => 'sales', 'from' => $from, 'to' => $to]))->assertOk()->viewData('page')['props']['data'];
    }

    /** [count, total, cancelled/voided count, their total] for the period. */
    private function headline(string $from, string $to): array
    {
        $c = $this->report($from, $to)['comparison'];

        return [$c['count']['current'], $c['total']['current'], $c['voided_count']['current'], $c['voided_total']['current']];
    }

    public function test_a_website_order_placed_in_october_and_handed_over_in_november_is_a_november_sale(): void
    {
        $this->at('2026-10-31 20:00:00');
        $order = $this->order();
        $this->assertSame([0, '0.000', 0, '0.000'], $this->headline('2026-10-01', '2026-10-31'), 'waiting for pickup: no sale yet');

        $this->at('2026-11-02 10:00:00');
        $this->asTenant($this->club, fn () => app(OrderService::class)->deliver($order->fresh(), 'cash'));

        $this->assertSame([0, '0.000', 0, '0.000'], $this->headline('2026-10-01', '2026-10-31'), 'not an October sale');
        $this->assertSame([1, '1.500', 0, '0.000'], $this->headline('2026-11-01', '2026-11-30'), 'a November sale');
        $november = $this->report('2026-11-01', '2026-11-30');
        $this->assertSame([['day' => '2026-11-02', 'count' => 1, 'total' => '1.500']], $november['days']);
        $this->assertSame([['label' => SalesController::SOURCE_LABELS[Sale::WEBSITE], 'count' => 1, 'total' => '1.500']], $november['sources']);
    }

    public function test_an_order_cancelled_and_a_sale_voided_in_a_later_month_are_that_months_cancellations(): void
    {
        $this->at('2026-10-30 18:00:00');
        $order = $this->order();
        $this->at('2026-10-31 21:00:00');
        $sale = $this->till(2);

        $this->at('2026-11-03 11:00:00');
        $this->asTenant($this->club, fn () => app(OrderService::class)->cancel($order->fresh(), 'لم يحضر للاستلام'));
        $this->asTenant($this->club, fn () => app(PosService::class)->void($sale->fresh(), 'مرتجع'));

        // October: the till sale was made then, but it is voided now, so it is no completed sale; neither is a cancellation of October.
        $this->assertSame([0, '0.000', 0, '0.000'], $this->headline('2026-10-01', '2026-10-31'));
        $this->assertSame([0, '0.000', 2, '4.500'], $this->headline('2026-11-01', '2026-11-30'), 'both cancelled in November');
    }

    public function test_till_sales_count_by_the_moment_of_sale_on_muscats_day_boundaries(): void
    {
        $this->at('2026-10-31 23:59:59');
        $this->till();
        $this->at('2026-11-01 00:00:00');
        $this->till(2);

        $this->assertSame([1, '1.500', 0, '0.000'], $this->headline('2026-10-31', '2026-10-31'), 'the last second of 31 October');
        $this->assertSame([1, '3.000', 0, '0.000'], $this->headline('2026-11-01', '2026-11-01'), 'the first second of 1 November');
        $this->assertSame([2, '4.500', 0, '0.000'], $this->headline('2026-10-31', '2026-11-01'));
    }

    public function test_the_summary_the_days_the_breakdowns_and_the_export_say_the_same(): void
    {
        $this->at('2026-10-31 20:00:00');
        $handedOver = $this->order();
        $cancelled = $this->order();
        $this->order(); // still waiting: in no figure, no row

        $this->at('2026-11-01 08:00:00');
        $this->till(3);
        $this->at('2026-11-02 10:00:00');
        $this->asTenant($this->club, fn () => app(OrderService::class)->deliver($handedOver->fresh(), 'card', 'R-1'));
        $this->at('2026-11-03 11:00:00');
        $this->asTenant($this->club, fn () => app(OrderService::class)->cancel($cancelled->fresh(), 'ألغاه العميل'));

        $data = $this->report('2026-11-01', '2026-11-30');
        $this->assertSame(['6.000', 2], [$data['comparison']['total']['current'], $data['comparison']['count']['current']]);
        foreach (['days', 'methods', 'sources', 'staff'] as $part) {
            $this->assertSame(['6.000', 2], [number_format(array_sum(array_column($data[$part], 'total')), 3, '.', ''), array_sum(array_column($data[$part], 'count'))], $part);
        }
        $this->assertSame('6.000', number_format(array_sum(array_column($data['products'], 'total')), 3, '.', ''));

        $csv = $this->get(route('club.reports.export', ['report' => 'sales', 'format' => 'csv', 'from' => '2026-11-01', 'to' => '2026-11-30']))->streamedContent();
        $this->assertStringContainsString($handedOver->number, $csv, 'the order handed over in November is in November\'s rows');
        $this->assertStringContainsString($cancelled->number, $csv, 'and so is the one cancelled in November');
        $waiting = $this->asTenant($this->club, fn () => Sale::where('status', Sale::PENDING)->sole());
        $this->assertStringNotContainsString($waiting->number, $csv, 'an order still waiting is no sale');
        $this->assertStringContainsString('2026-11-02', $csv, 'dated when it was handed over');

        $october = $this->get(route('club.reports.export', ['report' => 'sales', 'format' => 'csv', 'from' => '2026-10-01', 'to' => '2026-10-31']))->streamedContent();
        $this->assertStringNotContainsString($handedOver->number, $october);
        $this->assertStringNotContainsString($cancelled->number, $october);
        $this->assertStringNotContainsString($waiting->number, $october);
    }
}
