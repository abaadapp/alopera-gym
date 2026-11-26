<?php

namespace Tests\Feature;

use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Inventory\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * One section for every sale (owner's decision, 2026-11-25): each sale is an invoice, chosen by what it sold — a mixed
 * invoice under each of its kinds — and where it was sold, with the period's figures.
 */
class OneSalesSectionTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_sales_are_chosen_by_what_they_sold_and_where_with_the_periods_figures(): void
    {
        $club = $this->club();
        $this->fundAccounts($club);
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 25]);

        [$membership, $products, $service] = $this->asTenant($club, function () use ($member, $plan) {
            $membership = app(MembershipService::class)->sell($member, $plan, ['invoice_message' => false])->invoice()->first();
            app(PaymentService::class)->record($membership, $membership->balance(), 'cash');
            $product = Product::create(['name' => 'ماء', 'price' => 1, 'is_active' => true, 'track_stock' => false]);
            $products = app(InvoiceService::class)->issue(null, [['description' => 'ماء', 'unit_price' => 1, 'quantity' => 2, 'itemable' => $product]], ['type' => 'other'], 'عميل');
            $service = app(InvoiceService::class)->issue(null, [['description' => 'استشارة', 'unit_price' => 5]], ['type' => 'other'], 'عميل');

            return [$membership, $products, $service];
        });

        $list = fn (array $query) => $this->actingAs($this->owner($club))->get(route('club.invoices.index', $query))->assertOk();

        $list([])->assertInertia(fn (AssertableInertia $p) => $p->component('Finance/Invoices/Index')
            ->where('summary.count', 3)->where('summary.sold', '32.000')->where('summary.collected', '25.000')
            ->where('categories.membership', 'اشتراكات')
            ->where('invoices.data', fn ($rows) => collect($rows)->firstWhere('id', $membership->id)['categories'] === ['membership']
                && collect($rows)->firstWhere('id', $products->id)['categories'] === ['products']
                && collect($rows)->firstWhere('id', $service->id)['categories'] === ['other']));
        $list(['category' => 'membership'])->assertInertia(fn (AssertableInertia $p) => $p->has('invoices.data', 1)->where('invoices.data.0.id', $membership->id)->where('summary.sold', '25.000'));
        $list(['category' => 'products'])->assertInertia(fn (AssertableInertia $p) => $p->has('invoices.data', 1)->where('invoices.data.0.id', $products->id));
        $list(['category' => 'other'])->assertInertia(fn (AssertableInertia $p) => $p->has('invoices.data', 1)->where('invoices.data.0.id', $service->id));
        // None of them is a till sale or a website order: all from the desk.
        $list(['source' => 'desk'])->assertInertia(fn (AssertableInertia $p) => $p->has('invoices.data', 3));
        $list(['source' => 'pos'])->assertInertia(fn (AssertableInertia $p) => $p->has('invoices.data', 0)->where('summary.count', 0));

        $this->actingAs($this->owner($club))->get(route('club.invoices.show', $membership))
            ->assertInertia(fn (AssertableInertia $p) => $p->where('invoice.categories', ['membership'])->where('invoice.sale', null));
        $this->assertSame(Invoice::PAID, $membership->fresh()->status);
    }
}
