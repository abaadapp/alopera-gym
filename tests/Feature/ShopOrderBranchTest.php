<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\OrderService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Payments\Models\PaymentIntent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * A website order is picked up at a branch the cart offers — one of the club's active branches — even when the
 * request is written by hand. Without a branch it goes to the main one, as before.
 */
class ShopOrderBranchTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function shop(Tenant $club): Product
    {
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => 'hmac-test', 'integration_ids' => '111'],
        ]));

        return $this->asTenant($club, function () {
            $product = Product::create(['name' => 'Protein bar', 'price' => 1.5, 'show_on_website' => true]);
            foreach (Branch::pluck('id') as $branch) {
                app(StockService::class)->move($product, 10, 'adjustment', null, 'opening', 0.8, branchId: $branch);
            }

            return $product->fresh();
        });
    }

    private function branch(Tenant $club, array $attributes = []): Branch
    {
        return $this->asTenant($club, fn () => Branch::create($attributes + ['name' => 'فرع الخوض', 'is_active' => true]));
    }

    private function checkout(Tenant $club, Product $product, array $extra = []): TestResponse
    {
        return $this->post(route('site.shop.order.store', ['club' => $club->slug]), $extra + [
            'items' => [['product_id' => $product->id, 'quantity' => 1]], 'name' => 'Salim', 'phone' => '91234567', 'payment_method' => 'paymob',
        ]);
    }

    /** @return array{sales: int, invoices: int, movements: int, intents: int} */
    private function counts(Tenant $club): array
    {
        return $this->asTenant($club, fn () => [
            'sales' => Sale::count(), 'invoices' => Invoice::count(), 'movements' => StockMovement::count(), 'intents' => PaymentIntent::count(),
        ]);
    }

    public function test_a_hand_written_order_for_an_inactive_branch_is_refused_and_creates_nothing(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_abc', 'intention_order_id' => 5501], 201)]);
        $club = $this->club();
        $closed = $this->branch($club, ['name' => 'فرع مغلق']);
        $bar = $this->shop($club);
        $this->asTenant($club, fn () => $closed->forceFill(['is_active' => false])->save());
        $before = $this->counts($club);

        // The cart does not offer it…
        $this->get(route('site.shop.cart', ['club' => $club->slug]))->assertOk()
            ->assertInertia(fn ($page) => $page->where('branches', fn ($branches) => ! collect($branches)->pluck('id')->contains($closed->id)));

        // …and a request naming it anyway is refused on the branch field, by Paymob or at pickup.
        $this->checkout($club, $bar, ['branch_id' => $closed->id])->assertSessionHasErrors('branch_id');
        $this->checkout($club, $bar, ['branch_id' => $closed->id, 'payment_method' => 'pickup'])->assertSessionHasErrors('branch_id');

        $this->assertSame($before, $this->counts($club), 'no order, invoice, stock movement or checkout');
        Http::assertNothingSent();

        // The service holds the rule itself, whoever calls it.
        $this->asTenant($club, function () use ($bar, $closed) {
            try {
                app(OrderService::class)->place([['product_id' => $bar->id, 'quantity' => 1]], ['name' => 'Ali', 'phone' => '96891234567'], 'pickup', null, $closed->id);
                $this->fail('an inactive branch was accepted');
            } catch (BusinessRuleException $e) {
                $this->assertSame(['order.branch', 'branch_id'], [$e->rule, $e->field]);
            }
        });
        $this->assertSame($before, $this->counts($club));
    }

    public function test_another_clubs_branch_or_a_branch_that_does_not_exist_is_refused(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_abc', 'intention_order_id' => 5502], 201)]);
        $club = $this->club('A');
        $other = $this->branch($this->club('B'), ['name' => 'فرع النادي الآخر']);
        $bar = $this->shop($club);
        $before = $this->counts($club);

        $this->checkout($club, $bar, ['branch_id' => $other->id])->assertSessionHasErrors('branch_id');
        $this->checkout($club, $bar, ['branch_id' => 999999])->assertSessionHasErrors('branch_id');

        foreach ([$other->id, 999999] as $branchId) {
            $this->asTenant($club, function () use ($bar, $branchId) {
                try {
                    app(OrderService::class)->place([['product_id' => $bar->id, 'quantity' => 1]], ['name' => 'Ali', 'phone' => '96891234567'], 'pickup', null, $branchId);
                    $this->fail('branch '.$branchId.' was accepted');
                } catch (BusinessRuleException $e) {
                    $this->assertSame('branch_id', $e->field);
                }
            });
        }

        $this->assertSame($before, $this->counts($club));
        Http::assertNothingSent();
    }

    public function test_an_active_branch_chosen_in_the_cart_takes_the_order(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_abc', 'intention_order_id' => 5503], 201)]);
        $club = $this->club();
        $khoud = $this->branch($club);
        $bar = $this->shop($club);

        $this->checkout($club, $bar, ['branch_id' => $khoud->id])->assertRedirectContains('clientSecret=cs_abc');
        $this->checkout($club, $bar, ['branch_id' => $khoud->id, 'payment_method' => 'pickup'])->assertSessionHasNoErrors()->assertRedirect();

        $this->asTenant($club, function () use ($khoud, $bar) {
            $this->assertSame([$khoud->id, $khoud->id], Sale::orderBy('id')->pluck('branch_id')->all());
            $this->assertSame([$khoud->id, $khoud->id], Invoice::orderBy('id')->pluck('branch_id')->all());
            $this->assertSame('8.000', app(StockService::class)->quantity($bar, $khoud->id), 'held at the chosen branch');
            $this->assertSame(1, PaymentIntent::count());
        });
    }

    public function test_without_a_branch_the_order_still_goes_to_the_main_branch(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_abc', 'intention_order_id' => 5504], 201)]);
        $club = $this->club();
        $this->branch($club);
        $bar = $this->shop($club);
        $main = $this->asTenant($club, fn () => Branch::where('is_main', true)->firstOrFail());

        $this->checkout($club, $bar, ['payment_method' => 'pickup'])->assertSessionHasNoErrors()->assertRedirect();
        $this->asTenant($club, fn () => $this->assertSame($main->id, Sale::sole()->branch_id));

        // A caller passing no branch at all (the service's own default) is unchanged too.
        $sale = $this->asTenant($club, fn () => app(OrderService::class)->place([['product_id' => $bar->id, 'quantity' => 1]], ['name' => 'Ali', 'phone' => '96891234567'], 'pickup'));
        $this->assertNull($sale->branch_id);
    }
}
