<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Tenant;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Every new till sale belongs to a branch: the device's, else the one asked for (when the seller may sell there), else
 * the seller's own or the one the panel shows, else the only branch the seller has. A club-wide seller with several
 * branches and none chosen is asked to choose, and nothing is sold. The sale's invoice, payment, stock and entries carry
 * that same branch, and the branch's reports count it. Old sales without a branch keep it, said so where they are listed.
 */
class PosBranchTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** @return array{Branch, Branch, Product} */
    private function twoBranches(Tenant $club): array
    {
        return $this->asTenant($club, function () {
            $main = Branch::where('is_main', true)->firstOrFail();
            $khoud = Branch::create(['name' => 'فرع الخوض', 'is_active' => true]);
            $product = Product::create(['name' => 'Water', 'price' => 0.5]);
            app(StockService::class)->move($product, 10, 'adjustment', null, 'opening', 0.2, branchId: $khoud->id);
            app(StockService::class)->move($product, 10, 'adjustment', null, 'opening', 0.2, branchId: $main->id);

            return [$main, $khoud, $product];
        });
    }

    private function sell(Product $product, array $extra = []): array
    {
        return ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'payment_method' => 'cash'] + $extra;
    }

    public function test_a_club_wide_seller_with_several_branches_must_choose_one_and_nothing_is_sold_without(): void
    {
        $club = $this->club();
        [, , $product] = $this->twoBranches($club);

        $this->actingAs($this->owner($club))->post(route('club.pos.checkout'), $this->sell($product))->assertSessionHasErrors('branch_id');
        $this->assertSame(0, $this->asTenant($club, fn () => Sale::count() + Invoice::count()));
    }

    public function test_the_branch_chosen_carries_through_the_invoice_payment_stock_entries_and_report(): void
    {
        $club = $this->club();
        [$main, $khoud, $product] = $this->twoBranches($club);
        $owner = $this->owner($club);

        $this->actingAs($owner)->post(route('club.pos.checkout'), $this->sell($product, ['branch_id' => $khoud->id]))->assertSessionHasNoErrors();

        $this->asTenant($club, function () use ($khoud, $main, $product) {
            $sale = Sale::sole();
            $invoice = Invoice::findOrFail($sale->invoice_id);
            $payment = Payment::where('invoice_id', $invoice->id)->sole();
            $this->assertSame([$khoud->id, $khoud->id, $khoud->id], [$sale->branch_id, $invoice->branch_id, $payment->branch_id]);
            $this->assertSame([$khoud->id], StockMovement::where('reference_type', 'sale')->where('reference_id', $sale->id)->orWhere(fn ($q) => $q->where('reference_type', 'invoice')->where('reference_id', $invoice->id))->pluck('branch_id')->unique()->values()->all());
            $this->assertSame('9.000', app(StockService::class)->quantity($product, $khoud->id));
            $this->assertSame('10.000', app(StockService::class)->quantity($product, $main->id), 'the main shelf untouched');
            $entries = JournalEntry::where(fn ($q) => $q->where('source_type', 'invoice')->where('source_id', $invoice->id))->orWhere(fn ($q) => $q->where('source_type', 'payment')->where('source_id', $payment->id))->pluck('branch_id');
            $this->assertNotEmpty($entries);
            $this->assertSame([$khoud->id], $entries->unique()->values()->all());
        });

        $this->actingAs($owner)->get(route('club.reports.show', ['report' => 'sales', 'branch' => $khoud->id]))
            ->assertInertia(fn ($page) => $page->where('data.comparison.total.current', '0.500'));
        $this->actingAs($owner)->get(route('club.reports.show', ['report' => 'sales', 'branch' => $main->id]))
            ->assertInertia(fn ($page) => $page->where('data.comparison.total.current', '0.000'));
    }

    public function test_a_seller_tied_to_a_branch_cannot_sell_in_another(): void
    {
        $club = $this->club();
        [$main, $khoud, $product] = $this->twoBranches($club);

        $this->actingAs($this->staff($club, 'reception', ['branch_id' => $main->id]))->post(route('club.pos.checkout'), $this->sell($product, ['branch_id' => $khoud->id]))->assertSessionHasErrors('branch_id');
        $this->assertSame(0, $this->asTenant($club, fn () => Sale::count()));
        $this->assertSame('10.000', $this->asTenant($club, fn () => app(StockService::class)->quantity($product, $khoud->id)));
    }

    public function test_the_only_branch_is_taken_and_named_on_the_till(): void
    {
        $club = $this->club();
        $main = $this->asTenant($club, fn () => Branch::where('is_main', true)->firstOrFail());
        $product = $this->asTenant($club, fn () => Product::create(['name' => 'Water', 'price' => 0.5, 'track_stock' => false]));
        $owner = $this->owner($club);

        $this->actingAs($owner)->get(route('club.pos.index'))->assertInertia(fn ($page) => $page->where('branchId', $main->id)->where('branch', $main->name));
        $this->actingAs($owner)->post(route('club.pos.checkout'), $this->sell($product))->assertSessionHasNoErrors();
        $this->assertSame($main->id, $this->asTenant($club, fn () => Sale::sole()->branch_id));
    }

    public function test_the_till_asks_a_club_wide_seller_for_a_branch_and_offers_theirs(): void
    {
        $club = $this->club();
        [$main, $khoud] = $this->twoBranches($club);

        $this->actingAs($this->owner($club))->get(route('club.pos.index'))
            ->assertInertia(fn ($page) => $page->where('branchId', null)->where('branch', null)->where('branches', [['id' => $main->id, 'name' => $main->name], ['id' => $khoud->id, 'name' => 'فرع الخوض']]));
    }

    public function test_an_old_sale_without_a_branch_is_listed_as_such(): void
    {
        $club = $this->club();
        [, , $product] = $this->twoBranches($club);
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.pos.checkout'), $this->sell($product, ['branch_id' => $this->asTenant($club, fn () => Branch::where('is_main', true)->value('id'))]));
        $this->asTenant($club, fn () => Sale::query()->update(['branch_id' => null]));

        $this->actingAs($owner)->get(route('club.sales.index'))->assertInertia(fn ($page) => $page->where('sales.data.0.branch', 'بلا فرع (قبل ربط المبيعات بالفروع)'));
    }
}
