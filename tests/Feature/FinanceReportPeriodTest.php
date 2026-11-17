<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\CreditNoteService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\OrderService;
use App\Modules\Inventory\Services\StockService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * التقرير المالي across two months: an invoice issued on 20 October and paid on 5 November is October's issued
 * invoice and November's collection, never October's. Its «نسبة التحصيل حتى اليوم» is what the period's invoices
 * have been paid until today (it says so, and never passes 100%), and «مبيعات المنتجات» dates a website order by
 * when it was handed over, as تقرير المبيعات does.
 */
class FinanceReportPeriodTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private Tenant $club;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->club = $this->club();
        $this->owner = $this->owner($this->club);
        $this->actingAs($this->owner);
        $this->fundAccounts($this->club);
    }

    private function at(string $muscat): void
    {
        $this->travelTo(CarbonImmutable::parse($muscat, 'Asia/Muscat'));
    }

    /** @return array<string, mixed> each figure's current value */
    private function figures(string $from, string $to): array
    {
        $comparison = $this->get(route('club.reports.show', ['report' => 'finance', 'from' => $from, 'to' => $to]))->assertOk()->viewData('page')['props']['data']['report']['comparison'];

        return array_map(fn ($m) => $m['current'], $comparison);
    }

    private function invoice(string $price): Invoice
    {
        $member = $this->member($this->club);
        $plan = $this->plan($this->club, ['price' => $price]);

        return $this->asTenant($this->club, fn () => app(MembershipService::class)->sell($member, $plan)->invoice);
    }

    public function test_an_invoice_issued_in_october_and_paid_in_november_is_novembers_collection(): void
    {
        $this->at('2026-10-20 10:00:00');
        $invoice = $this->invoice('100.000');
        $this->at('2026-11-05 10:00:00');
        $this->asTenant($this->club, fn () => app(PaymentService::class)->record($invoice->fresh(), '100.000', 'cash'));

        $october = $this->figures('2026-10-01', '2026-10-31');
        $november = $this->figures('2026-11-01', '2026-11-30');
        $this->assertEquals([100.0, 0.0], [$october['invoiced'], $october['revenue']], 'October: issued, nothing collected');
        $this->assertEquals([0.0, 100.0], [$november['invoiced'], $november['revenue']], 'November: the payment');
        $this->assertEquals(100.0, $october['collection_rate'], 'October\'s invoices are paid until today: 100%');

        $csv = $this->get(route('club.reports.export', ['report' => 'finance', 'format' => 'csv', 'from' => '2026-10-01', 'to' => '2026-10-31']))->streamedContent();
        $this->assertStringContainsString('نسبة التحصيل حتى اليوم', $csv, 'the export says what the rate is');
    }

    public function test_the_collection_rate_never_passes_100_percent_after_a_credit_note_without_a_refund(): void
    {
        $this->at('2026-11-02 10:00:00');
        $invoice = $this->invoice('100.000');
        $this->asTenant($this->club, fn () => app(PaymentService::class)->record($invoice->fresh(), '100.000', 'cash'));
        $this->asTenant($this->club, fn () => app(CreditNoteService::class)->issue($invoice->fresh(), '40.000', 'خصم لاحق'));

        $november = $this->figures('2026-11-01', '2026-11-30');
        $this->assertEquals(60.0, $november['invoiced'], 'what the invoice is worth after the credit note');
        $this->assertEquals(100.0, $november['collection_rate'], 'paid in full, not 166.7%');
    }

    public function test_product_sales_date_a_website_order_when_it_was_handed_over(): void
    {
        $this->at('2026-10-01 09:00:00');
        $bar = $this->asTenant($this->club, function () {
            $product = Product::create(['name' => 'Protein bar', 'price' => 1.5, 'show_on_website' => true]);
            app(StockService::class)->move($product, 10, 'adjustment', null, 'opening', 0.8);

            return $product->fresh();
        });
        $this->at('2026-10-31 20:00:00');
        $order = $this->asTenant($this->club, fn () => app(OrderService::class)->place([['product_id' => $bar->id, 'quantity' => 1]], ['name' => 'Salim', 'phone' => '96891234567'], 'pickup'));
        $this->at('2026-11-02 10:00:00');
        $this->asTenant($this->club, fn () => app(OrderService::class)->deliver($order->fresh(), 'cash'));

        $this->assertEquals(0.0, $this->figures('2026-10-01', '2026-10-31')['product_sales'], 'not October');
        $this->assertEquals(1.5, $this->figures('2026-11-01', '2026-11-30')['product_sales'], 'November');
    }
}
