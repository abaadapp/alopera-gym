<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Finance\Models\CashShift;
use App\Modules\Finance\Models\Expense;
use App\Modules\Gym\Models\Coupon;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Models\PtSubscription;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\SupplierBill;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** What a withdrawn sale, a resent sale, a moved expense, a blocked member and a branch user each leave behind. */
class FollowUpIntegrityTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_a_sale_withdrawn_before_payment_gives_its_coupon_back(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 20]);

        $this->asTenant($club, function () use ($member, $plan) {
            $coupon = Coupon::create(['code' => 'ONCE10', 'type' => 'percent', 'value' => 10, 'per_member_limit' => 1, 'plan_ids' => [$plan->id]]);
            $first = app(MembershipService::class)->sell($member, $plan, ['coupon_code' => 'ONCE10']);
            app(MembershipService::class)->cancel($first, 'wrong plan length');

            $this->assertSame(0, $coupon->fresh()->used_count);
            $again = app(MembershipService::class)->sell($member, $plan, ['coupon_code' => 'ONCE10']);
            $this->assertSame('2.000', $again->discount, 'the coupon works on the sale that goes ahead');
        });
    }

    public function test_a_personal_training_sale_sent_twice_is_one_subscription_and_one_invoice(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club);
        $package = $this->asTenant($club, fn () => PtPackage::create(['name' => 'PT 4', 'sessions_count' => 4, 'price' => 40, 'validity_days' => 30]));

        $this->actingAs($owner)->post(route('club.pt.store', $member), ['pt_package_id' => $package->id])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.pt.store', $member), ['pt_package_id' => $package->id])->assertSessionHasErrors('pt_package_id');

        $this->asTenant($club, fn () => $this->assertSame(1, PtSubscription::where('member_id', $member->id)->count()));
    }

    public function test_an_expense_moved_onto_a_drawer_with_an_open_shift_is_counted_in_that_shift(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        [$cash, $bank] = $this->asTenant($club, fn () => [app(ChartOfAccounts::class)->account('cash'), app(ChartOfAccounts::class)->account('bank')]);
        $this->actingAs($owner)->post(route('club.expenses.store'), ['description' => 'ماء', 'amount' => '30', 'expense_date' => today()->toDateString(), 'payment_method' => 'bank_transfer', 'account_id' => $bank->id, 'client_key' => (string) Str::uuid()])->assertSessionHasNoErrors();
        $expense = $this->asTenant($club, fn () => Expense::sole());
        $this->actingAs($owner)->post(route('club.cash-shifts.store'), ['account_id' => $cash->id, 'opening_float' => 100])->assertSessionHasNoErrors();
        $shift = $this->asTenant($club, fn () => CashShift::sole());

        // Paid from the drawer after all.
        $this->actingAs($owner)->put(route('club.expenses.update', $expense), ['description' => 'ماء', 'amount' => '30', 'expense_date' => today()->toDateString(), 'payment_method' => 'cash', 'account_id' => $cash->id])->assertSessionHasNoErrors();
        $this->assertSame($shift->id, $expense->fresh()->cash_shift_id);

        $this->actingAs($owner)->post(route('club.cash-shifts.close', $shift), ['counted_cash' => 70])->assertSessionHasNoErrors();
        $this->assertSame('0.000', $shift->fresh()->difference);
    }

    public function test_a_member_blocked_by_staff_is_out_of_the_portal_at_once(): void
    {
        $club = $this->club();
        $member = $this->member($club);

        $this->actingAs($member, 'member')->get(route('portal.dashboard', ['club' => $club->slug]))->assertOk();
        $this->asTenant($club, fn () => $member->forceFill(['status' => Member::BLOCKED])->save());

        $this->get(route('portal.dashboard', ['club' => $club->slug]))->assertRedirect(route('portal.login', ['club' => $club->slug]));
        $this->assertGuest('member');
    }

    public function test_a_new_portal_password_ends_the_sessions_remembered_with_the_old_one(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $this->asTenant($club, fn () => $member->forceFill(['remember_token' => 'old-remembered-token'])->save());

        $this->actingAs($this->owner($club))->post(route('club.members.portal-password', $member), ['password' => 'a-new-pass-123'])->assertSessionHasNoErrors();

        $this->assertNotSame('old-remembered-token', $member->fresh()->remember_token);
    }

    public function test_a_branch_user_reads_only_their_branchs_dealings_with_a_supplier_and_moves_of_a_product(): void
    {
        $club = $this->club();
        [$main, $khoud] = $this->asTenant($club, fn () => [Branch::orderByDesc('is_main')->orderBy('id')->firstOrFail(), Branch::create(['name' => 'فرع الخوض', 'is_active' => true])]);
        $product = $this->asTenant($club, function () use ($main, $khoud) {
            $p = Product::create(['name' => 'Water', 'price' => 0.3]);
            app(StockService::class)->move($p, 10, 'adjustment', null, 'main shelf', 0.1, branchId: $main->id);
            app(StockService::class)->move($p, 4, 'adjustment', null, 'khoud shelf', 0.1, branchId: $khoud->id);

            return $p;
        });
        $supplier = $this->asTenant($club, function () use ($main) {
            $s = Supplier::create(['name' => 'مورد']);
            $order = PurchaseOrder::create(['number' => 'PO-MAIN', 'supplier_id' => $s->id, 'branch_id' => $main->id, 'status' => 'approved', 'order_date' => today(), 'subtotal' => 10, 'total' => 10]);
            SupplierBill::create(['number' => 'SB-MAIN', 'supplier_id' => $s->id, 'purchase_order_id' => $order->id, 'branch_id' => $main->id, 'reference' => 'MAIN-1', 'issue_date' => today(), 'subtotal' => 10, 'total' => 10, 'status' => SupplierBill::APPROVED]);

            return $s;
        });
        $finance = $this->staff($club, 'finance', ['branch_id' => $khoud->id]);

        $this->actingAs($finance)->get(route('club.purchases.suppliers.show', $supplier))->assertOk()
            ->assertInertia(fn ($page) => $page->where('orders', [])->where('bills', [])->where('totals.due', 0));

        $moves = $this->actingAs($finance)->getJson(route('club.products.movements', $product))->assertOk()->json();
        $this->assertSame(['khoud shelf'], array_column($moves, 'note'));
    }
}
