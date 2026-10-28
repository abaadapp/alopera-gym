<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Services\PosService;
use App\Modules\Inventory\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** Spec 1.6: products and quantities, suppliers and purchases, selling at reception. */
class PosInventoryTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_receiving_a_purchase_adds_stock_at_weighted_average_cost(): void
    {
        $club = $this->club();

        $this->asTenant($club, function () {
            $product = Product::create(['name' => 'Water', 'price' => 0.2]);
            $supplier = Supplier::create(['name' => 'Supplier']);
            $service = app(PurchaseService::class);

            $service->receive($service->create($supplier, [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 0.1]]));
            $service->receive($service->create($supplier, [['product_id' => $product->id, 'quantity' => 10, 'unit_cost' => 0.2]]));

            $product->refresh();
            $this->assertSame('20.000', $product->stock_quantity);
            $this->assertSame('0.150', $product->cost);
            $this->assertSame(2, $product->movements()->count());
        });
    }

    public function test_a_pos_sale_takes_stock_and_issues_a_paid_invoice_and_void_reverses_it(): void
    {
        $club = $this->club();
        $member = $this->member($club);

        $this->asTenant($club, function () use ($member) {
            $bar = Product::create(['name' => 'Protein bar', 'price' => 1.2]);
            $service = app(PurchaseService::class);
            $service->receive($service->create(Supplier::create(['name' => 'S']), [['product_id' => $bar->id, 'quantity' => 5, 'unit_cost' => 0.7]]));

            $sale = app(PosService::class)->checkout([['product_id' => $bar->id, 'quantity' => 2]], 'cash', $member, 0.4);
            $this->assertSame('2.000', $sale->total);
            $this->assertSame('3.000', $bar->fresh()->stock_quantity);
            $invoice = Invoice::find($sale->invoice_id);
            $this->assertSame(Invoice::PAID, $invoice->status);
            $this->assertSame('member', $invoice->billable_type);

            app(PosService::class)->void($sale, 'mistake');
            $this->assertSame('5.000', $bar->fresh()->stock_quantity);
            $invoice = $invoice->fresh();
            $this->assertSame('0.000', $invoice->paid_total);
            $this->assertSame('2.000', $invoice->total, 'the original invoice is kept');
            $this->assertSame(Invoice::CREDITED, $invoice->status, 'a voided sale leaves no open receivable');
            $this->assertSame('2.000', $invoice->creditNotes()->first()->refunded_total);
            $this->assertSame('voided', $sale->fresh()->status);
        });
    }

    public function test_cannot_sell_more_than_in_stock(): void
    {
        $club = $this->club();

        $this->expectException(BusinessRuleException::class);
        $this->asTenant($club, function () {
            $towel = Product::create(['name' => 'Towel', 'price' => 3]);
            app(PosService::class)->checkout([['product_id' => $towel->id, 'quantity' => 1]], 'card');
        });
    }

    public function test_pos_checkout_over_http_for_reception(): void
    {
        $club = $this->club();
        $product = $this->asTenant($club, function () {
            $p = Product::create(['name' => 'Water', 'price' => 0.2, 'track_stock' => false]);

            return $p;
        });

        $this->actingAs($this->staff($club, 'reception'))
            ->post(route('club.pos.checkout'), ['items' => [['product_id' => $product->id, 'quantity' => 3]], 'payment_method' => 'cash'])
            ->assertSessionHasNoErrors();

        $this->assertSame('0.600', $this->asTenant($club, fn () => Invoice::where('type', 'pos')->value('paid_total')));
    }
}
