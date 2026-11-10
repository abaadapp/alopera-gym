<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Services\OrderService;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A website order with nothing to pay (a free product): its invoice is paid when it is issued, and the order is noted
 * paid as OrderService::markPaid says it is when there is nothing to pay. The expiry job then leaves it alone (it is not
 * «unpaid»), and its paid invoice is never voided.
 */
class FreeWebsiteOrderTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** @return array{0: Sale, 1: Product} */
    private function freeOrder(Tenant $club): array
    {
        return $this->asTenant($club, function () {
            TenantIntegration::create([
                'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
                'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => 'hmac-test', 'integration_ids' => '111'],
            ]);
            $gift = Product::create(['name' => 'Free sample', 'price' => 0, 'show_on_website' => true]);
            app(StockService::class)->move($gift, 5, 'adjustment', null, 'opening', 0.2);
            $sale = app(OrderService::class)->place([['product_id' => $gift->id, 'quantity' => 1]], ['name' => 'Online', 'phone' => '96891234567'], 'paymob');

            return [$sale, $gift];
        });
    }

    public function test_a_free_order_is_noted_paid(): void
    {
        $club = $this->club();
        [$sale] = $this->freeOrder($club);

        $this->asTenant($club, function () use ($sale) {
            $this->assertSame(Invoice::PAID, Invoice::find($sale->invoice_id)->status, 'nothing to pay: the invoice is paid on issue');
            $this->assertNotNull($sale->fresh()->paid_at, 'the order is noted paid, as markPaid() says it is when there is nothing to pay');
        });
    }

    public function test_the_expiry_job_does_not_cancel_a_free_order(): void
    {
        $club = $this->club();
        [$sale, $gift] = $this->freeOrder($club);

        // The payment window passes: an order with nothing to pay is not «unpaid», it must not be cancelled.
        $this->travel(2)->hours();
        $expired = $this->asTenant($club, fn () => app(OrderService::class)->expireOverdue());

        $this->asTenant($club, function () use ($sale, $gift, $expired) {
            $this->assertSame(0, $expired, 'no order was cancelled');
            $this->assertSame(Sale::PENDING, $sale->fresh()->status, 'the free order is still waiting to be picked up');
            $this->assertSame(Invoice::PAID, Invoice::find($sale->invoice_id)->status, 'its paid invoice is not voided');
            $this->assertSame('4.000', $gift->fresh()->stock_quantity);
        });
    }
}
