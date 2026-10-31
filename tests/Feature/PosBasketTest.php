<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Models\PtSubscription;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Sale;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The point of sale sells a whole basket — products, a membership (new or renewed) and a training package — paid
 * together: each through its own service and invoice, activated only by its payment, all or nothing, once per basket;
 * every sale names who rang it up and where; each kind needs its own permission.
 */
class PosBasketTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** @return array{0: Product, 1: MembershipPlan, 2: PtPackage, 3: Member} */
    private function shop(Tenant $club): array
    {
        $plan = $this->plan($club, ['price' => 30, 'signup_fee' => 5]);
        [$water, $package] = $this->asTenant($club, fn () => [
            Product::create(['name' => 'Water', 'price' => 0.5, 'track_stock' => false]),
            PtPackage::create(['name' => '5 جلسات', 'sessions_count' => 5, 'session_minutes' => 60, 'price' => 40, 'validity_days' => 60]),
        ]);

        return [$water, $plan, $package, $this->member($club, ['full_name' => 'منى سالم'])];
    }

    public function test_a_basket_of_products_a_membership_and_a_package_is_paid_and_activated_together(): void
    {
        $club = $this->club();
        [$water, $plan, $package, $member] = $this->shop($club);
        $cashier = $this->staff($club, 'cashier');

        $this->actingAs($cashier)->post(route('club.pos.checkout'), [
            'items' => [['product_id' => $water->id, 'quantity' => 2]],
            'memberships' => [['plan_id' => $plan->id]],
            'packages' => [['package_id' => $package->id]],
            'member_id' => $member->id, 'payment_method' => 'cash', 'client_key' => 'basket-1',
        ])->assertSessionHasNoErrors()->assertSessionHas('pos_result', fn ($r) => count($r['documents']) === 3 && $r['total'] === '76.000');

        $this->asTenant($club, function () use ($member, $cashier) {
            // The first membership carries the sign-up fee; it is active because its invoice is paid.
            $membership = Membership::where('member_id', $member->id)->sole();
            $this->assertSame([Membership::ACTIVE, '35.000'], [$membership->status, $membership->total]);
            $this->assertSame(PtSubscription::ACTIVE, PtSubscription::where('member_id', $member->id)->sole()->status);
            $this->assertSame(['membership', 'pos', 'pt'], Invoice::orderBy('type')->pluck('type')->all());
            $this->assertSame(0, Invoice::open()->count());
            // Who, where and on what: the cashier on every payment, the point of sale in its note.
            $this->assertSame(3, Payment::where('received_by', $cashier->id)->where('notes', 'نقطة البيع')->count());
            $this->assertSame($cashier->id, Sale::sole()->cashier_id);
        });

        // The same basket again (a double click, a retried request) is the same sale, not a second one.
        $this->actingAs($cashier)->post(route('club.pos.checkout'), [
            'memberships' => [['plan_id' => $plan->id]], 'member_id' => $member->id, 'payment_method' => 'cash', 'client_key' => 'basket-1',
        ])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame(3, Invoice::count()));

        // Renewing at the counter: the new period starts the day after the current one ends.
        $this->actingAs($cashier)->post(route('club.pos.checkout'), ['memberships' => [['plan_id' => $plan->id]], 'member_id' => $member->id, 'payment_method' => 'card', 'card_reference' => 'TRX-9', 'client_key' => 'basket-2'])->assertSessionHasNoErrors();
        $this->asTenant($club, function () use ($member) {
            [$first, $renewal] = Membership::where('member_id', $member->id)->orderBy('id')->get()->all();
            $this->assertSame([Membership::KIND_RENEWAL, '30.000'], [$renewal->kind, $renewal->total]);
            $this->assertSame($first->ends_on->addDay()->toDateString(), $renewal->starts_on->toDateString());
        });

        // Today's receipts list every payment taken here.
        $this->actingAs($cashier)->get(route('club.pos.receipts'))->assertOk()->assertInertia(fn ($page) => $page
            ->component('Inventory/PosReceipts')->has('receipts', 4)->where('total', '106.000'));
    }

    public function test_memberships_need_a_member_and_their_own_permission_and_nothing_is_half_sold(): void
    {
        $club = $this->club();
        [$water, $plan, $package, $member] = $this->shop($club);

        // No member: refused, and the products in the same basket are not sold either.
        $this->actingAs($this->staff($club, 'cashier'))->post(route('club.pos.checkout'), ['items' => [['product_id' => $water->id, 'quantity' => 1]], 'memberships' => [['plan_id' => $plan->id]], 'payment_method' => 'cash'])
            ->assertSessionHasErrors('member_id');
        $this->asTenant($club, fn () => $this->assertSame([0, 0], [Sale::count(), Invoice::count()]));

        // A clerk who may sell products only.
        $clerk = $this->staff($club, 'reception');
        $this->asTenant($club, fn () => $clerk->role->forceFill(['permissions' => ['pos.sell', 'members.view']])->save());
        $clerk = $clerk->fresh();
        $this->actingAs($clerk)->post(route('club.pos.checkout'), ['memberships' => [['plan_id' => $plan->id]], 'member_id' => $member->id, 'payment_method' => 'cash'])->assertForbidden();
        $this->actingAs($clerk)->post(route('club.pos.checkout'), ['packages' => [['package_id' => $package->id]], 'member_id' => $member->id, 'payment_method' => 'cash'])->assertForbidden();
        $this->actingAs($clerk)->get(route('club.pos.index'))->assertInertia(fn ($page) => $page->where('plans', [])->where('packages', [])->where('can.memberships', false));
        // A discount needs its own permission.
        $this->actingAs($clerk)->post(route('club.pos.checkout'), ['items' => [['product_id' => $water->id, 'quantity' => 1]], 'discount' => 0.1, 'payment_method' => 'cash'])->assertSessionHasErrors('discount');

        // Paymob is offered only when it is set up, and never for products.
        $this->actingAs($this->owner($club))->post(route('club.pos.checkout'), ['memberships' => [['plan_id' => $plan->id]], 'member_id' => $member->id, 'payment_method' => 'paymob'])->assertSessionHasErrors('payment_method');
        $this->asTenant($club, fn () => $this->assertSame(0, Membership::count()));
        // A trainer does not reach the point of sale.
        $this->actingAs($this->staff($club, 'trainer'))->get(route('club.pos.index'))->assertForbidden();
    }

    public function test_the_member_card_shows_the_membership_and_what_is_owed(): void
    {
        $club = $this->club();
        [, $plan, , $member] = $this->shop($club);
        $this->asTenant($club, fn () => app(MembershipService::class)->sell($member, $plan));

        $this->actingAs($this->staff($club, 'cashier'))->getJson(route('club.pos.member', $member))->assertOk()
            ->assertJsonPath('full_name', 'منى سالم')->assertJsonPath('balance', '35.000');
        $other = $this->member($this->club('Other'));
        $this->actingAs($this->staff($club, 'cashier'))->getJson(route('club.pos.member', $other))->assertNotFound();
    }
}
