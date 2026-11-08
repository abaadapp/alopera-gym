<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Models\PtSubscription;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * One till basket, one sale, whatever happens to the answer: sent twice, sent again once the cache has lost the first
 * result, sent again after it failed. The basket's key answers only the basket it was made for. (The same basket at
 * once, and a sale outlasting its lock, are in ConcurrencyTest.)
 */
class PosCheckoutOnceTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** @return array{0: User, 1: array<string, list<array<string, int>>>, 2: int} a cashier, the lines of each kind, the member */
    private function shop(Tenant $club): array
    {
        $plan = $this->plan($club, ['price' => 30]);
        $member = $this->member($club);
        [$water, $package] = $this->asTenant($club, fn () => [
            Product::create(['name' => 'Water', 'price' => 0.5, 'track_stock' => false]),
            PtPackage::create(['name' => 'PT', 'sessions_count' => 4, 'session_minutes' => 60, 'price' => 40, 'validity_days' => 60]),
        ]);

        return [$this->staff($club, 'cashier'), [
            'items' => [['product_id' => $water->id, 'quantity' => 2]],
            'memberships' => [['plan_id' => $plan->id]],
            'packages' => [['package_id' => $package->id]],
        ], $member->id];
    }

    /** @return list<int> sales, memberships, packages, invoices, payments */
    private function sold(Tenant $club): array
    {
        return $this->asTenant($club, fn () => [Sale::count(), Membership::count(), PtSubscription::count(), Invoice::count(), Payment::count()]);
    }

    /** @return array<string, array{0: list<string>, 1: list<int>}> */
    public static function baskets(): array
    {
        return [
            'products only' => [['items'], [1, 0, 0, 1, 1]],
            'a membership only' => [['memberships'], [0, 1, 0, 1, 1]],
            'a personal-training package only' => [['packages'], [0, 0, 1, 1, 1]],
            'products, a membership and a package' => [['items', 'memberships', 'packages'], [1, 1, 1, 3, 3]],
        ];
    }

    #[DataProvider('baskets')]
    public function test_a_basket_is_sold_once_however_often_it_is_sent_even_after_the_cache_lost_its_answer(array $kinds, array $once): void
    {
        $club = $this->club();
        [$cashier, $lines, $member] = $this->shop($club);
        $basket = array_intersect_key($lines, array_flip($kinds)) + ['member_id' => $member, 'payment_method' => 'cash', 'client_key' => 'basket-1'];

        $this->actingAs($cashier)->post(route('club.pos.checkout'), $basket)->assertSessionHasNoErrors()->assertSessionHas('pos_result');
        $documents = session('pos_result')['documents'];
        $this->actingAs($cashier)->post(route('club.pos.checkout'), $basket)->assertSessionHasNoErrors(); // a double click
        $this->assertSame($once, $this->sold($club));

        Cache::flush(); // the cache lost the first answer (cleared, evicted, another server)
        $this->actingAs($cashier)->post(route('club.pos.checkout'), $basket)->assertSessionHasNoErrors()
            ->assertSessionHas('pos_result', fn ($r) => $r['documents'] === $documents);

        $this->assertSame($once, $this->sold($club), 'sales, memberships, packages, invoices, payments');
    }

    public function test_the_same_key_with_another_basket_is_refused_not_answered_with_the_first_sale(): void
    {
        $club = $this->club();
        [$cashier, $lines, $member] = $this->shop($club);
        $this->actingAs($cashier)->post(route('club.pos.checkout'), ['items' => $lines['items'], 'payment_method' => 'cash', 'client_key' => 'basket-1'])->assertSessionHasNoErrors();

        $this->actingAs($cashier)->post(route('club.pos.checkout'), [
            'memberships' => $lines['memberships'], 'member_id' => $member, 'payment_method' => 'cash', 'client_key' => 'basket-1',
        ])->assertSessionHasErrors('items');
        // The first sale may be the one whose answer was lost: the cashier is sent to check it, not to sell again.
        $message = session('errors')->first('items');
        $this->assertStringContainsString('مبيعات اليوم', $message);
        $this->assertStringNotContainsString('أرسل السلة من جديد', $message);

        $this->assertSame([1, 0, 0, 1, 1], $this->sold($club), 'nothing sold for the second basket, and no other sale handed back');
    }

    public function test_two_clubs_may_use_the_same_basket_key_each_selling_their_own(): void
    {
        [$one, $two] = [$this->club(), $this->club()];
        [$cashierOne, $linesOne] = $this->shop($one);
        [$cashierTwo, $linesTwo] = $this->shop($two);

        $this->actingAs($cashierOne)->post(route('club.pos.checkout'), ['items' => $linesOne['items'], 'payment_method' => 'cash', 'client_key' => 'basket-1'])->assertSessionHasNoErrors();
        $this->actingAs($cashierTwo)->post(route('club.pos.checkout'), ['items' => $linesTwo['items'], 'payment_method' => 'cash', 'client_key' => 'basket-1'])
            ->assertSessionHasNoErrors()->assertSessionHas('pos_result');

        $this->assertSame([[1, 0, 0, 1, 1], [1, 0, 0, 1, 1]], [$this->sold($one), $this->sold($two)], 'each club its own sale, neither answered with the other\'s');
    }

    public function test_a_basket_refused_can_be_corrected_and_sent_again_with_the_same_key(): void
    {
        $club = $this->club();
        [$cashier, $lines, $member] = $this->shop($club);
        $basket = ['memberships' => $lines['memberships'], 'payment_method' => 'cash', 'client_key' => 'basket-1'];

        $this->actingAs($cashier)->post(route('club.pos.checkout'), $basket)->assertSessionHasErrors('member_id'); // no member chosen
        $this->actingAs($cashier)->post(route('club.pos.checkout'), $basket + ['member_id' => $member])->assertSessionHasNoErrors();

        $this->assertSame([0, 1, 0, 1, 1], $this->sold($club));
    }
}
