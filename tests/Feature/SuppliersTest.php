<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\PurchaseOrder;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\SupplierBill;
use App\Modules\Inventory\Services\PurchaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Suppliers: seeing, adding and editing are separate permissions checked on the server; their purchases and money are
 * sent only with purchases.view; the list filters, sorts and finds on the server; a supplier with records is stopped,
 * not deleted; and each club sees only its own.
 */
class SuppliersTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function holding(Tenant $club, array $permissions): User
    {
        $role = $this->asTenant($club, fn () => Role::create(['key' => 'custom_'.Str::lower(Str::random(6)), 'name' => 'دور '.Str::random(4), 'permissions' => $permissions]));
        $user = $this->staff($club, 'reception');
        $user->forceFill(['role_id' => $role->id])->save();

        return $user->fresh();
    }

    private function supplier(Tenant $club, array $attributes = []): Supplier
    {
        return $this->asTenant($club, fn () => Supplier::create($attributes + ['name' => 'مؤسسة الصحة', 'phone' => '96899112233', 'payment_terms_days' => 30, 'is_active' => true]));
    }

    /** An approved order received in full, its approved bill of 25.200, and 10.000 paid on it. */
    private function bought(Tenant $club, Supplier $supplier): void
    {
        $owner = $this->owner($club);
        [$water, $branch] = $this->asTenant($club, fn () => [Product::create(['name' => 'Water', 'price' => 0.2]), Branch::create(['name' => 'فرع الخوض', 'is_active' => true])]);
        $this->actingAs($owner)->post(route('club.purchases.store'), ['supplier_id' => $supplier->id, 'branch_id' => $branch->id, 'order_date' => now()->toDateString(), 'tax_rate' => 5, 'items' => [['product_id' => $water->id, 'quantity' => 5, 'unit_cost' => 4.8]]])->assertSessionHasNoErrors();
        $order = $this->asTenant($club, fn () => PurchaseOrder::with('items')->where('supplier_id', $supplier->id)->latest('id')->firstOrFail());
        $this->asTenant($club, fn () => app(PurchaseService::class)->approve($order));
        $this->actingAs($owner)->post(route('club.purchases.receive', $order), ['lines' => [$order->items->first()->id => ['quantity' => 5]], 'received_on' => now()->toDateString(), 'approve' => true])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.purchases.bills.store'), ['supplier_id' => $supplier->id, 'purchase_order_id' => $order->id, 'reference' => 'A-'.$supplier->id, 'issue_date' => now()->toDateString(), 'subtotal' => 24, 'tax' => 1.2, 'approve' => true])->assertSessionHasNoErrors();
        $bill = $this->asTenant($club, fn () => SupplierBill::where('supplier_id', $supplier->id)->latest('id')->firstOrFail());
        $bank = $this->asTenant($club, fn () => app(ChartOfAccounts::class)->account('bank'));
        $this->actingAs($owner)->post(route('club.purchases.bills.pay', $bill), ['account_id' => $bank->id, 'amount' => 10, 'paid_on' => now()->toDateString()])->assertSessionHasNoErrors();
    }

    public function test_seeing_adding_and_editing_suppliers_are_separate_permissions(): void
    {
        $club = $this->club();
        $supplier = $this->supplier($club);
        $form = ['name' => 'مورد جديد', 'phone' => '91234567', 'tax_number' => 'OM1100', 'payment_terms_days' => 15, 'is_active' => true];

        $viewer = $this->holding($club, ['suppliers.view']);
        // A role that may only see suppliers lands on them after login, not on a screen it cannot open.
        $this->assertSame('club.purchases.suppliers', $viewer->homeRoute());
        $this->assertSame('club.account.show', $this->holding($club, [])->homeRoute());
        $this->actingAs($viewer)->get(route('club.purchases.suppliers'))->assertOk();
        $this->actingAs($viewer)->get(route('club.purchases.suppliers.show', $supplier))->assertOk()->assertInertia(fn ($page) => $page->where('may.edit', false));
        $this->actingAs($viewer)->post(route('club.purchases.suppliers.store'), $form)->assertForbidden();
        $this->actingAs($viewer)->put(route('club.purchases.suppliers.update', $supplier), $form)->assertForbidden();
        $this->actingAs($viewer)->delete(route('club.purchases.suppliers.destroy', $supplier))->assertForbidden();

        $adder = $this->holding($club, ['suppliers.view', 'suppliers.create']);
        $this->actingAs($adder)->post(route('club.purchases.suppliers.store'), $form)->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame('OM1100', Supplier::where('name', 'مورد جديد')->value('tax_number')));
        $this->actingAs($adder)->put(route('club.purchases.suppliers.update', $supplier), $form)->assertForbidden();

        $editor = $this->holding($club, ['suppliers.view', 'suppliers.manage']);
        $this->actingAs($editor)->put(route('club.purchases.suppliers.update', $supplier), ['name' => 'مؤسسة الصحة للتجهيزات', 'is_active' => false])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertFalse(Supplier::find($supplier->id)->is_active));

        // Purchases staff without the suppliers permission do not reach the suppliers section by its address.
        $buyer = $this->holding($club, ['purchases.view']);
        $this->actingAs($buyer)->get(route('club.purchases.suppliers'))->assertForbidden();
        $this->actingAs($buyer)->get(route('club.purchases.suppliers.show', $supplier))->assertForbidden();
        // The system roles: finance and the manager see, add and edit; reception does not see suppliers at all.
        $this->actingAs($this->staff($club, 'finance'))->post(route('club.purchases.suppliers.store'), ['name' => 'مورد المالية'] + $form)->assertSessionHasNoErrors();
        $this->actingAs($this->staff($club, 'reception'))->get(route('club.purchases.suppliers'))->assertForbidden();
    }

    public function test_purchases_and_money_are_not_sent_without_the_purchases_permission(): void
    {
        $club = $this->club();
        $supplier = $this->supplier($club);
        $this->bought($club, $supplier);

        $viewer = $this->holding($club, ['suppliers.view']);
        $page = $this->actingAs($viewer)->get(route('club.purchases.suppliers.show', $supplier))->assertOk()->viewData('page')['props'];
        foreach (['orders', 'bills', 'payments', 'products', 'log', 'totals'] as $key) {
            $this->assertArrayNotHasKey($key, $page, "$key is not sent without purchases.view");
        }
        $list = $this->actingAs($viewer)->get(route('club.purchases.suppliers', ['status' => 'due', 'sort' => 'due']))->assertOk()->viewData('page')['props'];
        $this->assertNull($list['summary']['due']);
        $this->assertNull($list['suppliers']['data'][0]['due']);

        $owner = $this->owner($club);
        $this->actingAs($owner)->get(route('club.purchases.suppliers.show', $supplier))->assertOk()->assertInertia(fn ($page) => $page
            ->where('totals.purchased', 25.2)->where('totals.paid', 10)->where('totals.due', 15.2)->where('totals.orders', 1)
            ->has('orders', 1)->has('bills', 1)->has('payments', 1)->has('products', 1)
            ->where('products.0.name', 'Water')->where('log.0.kind', 'payment')->has('log', 3)
            ->where('may.order', true)->where('may.edit', true));
        $this->actingAs($owner)->get(route('club.purchases.suppliers'))->assertInertia(fn ($page) => $page->where('summary.due', 15.2)->where('suppliers.data.0.due', 15.2)->where('suppliers.data.0.can_delete', false));
    }

    public function test_the_list_filters_sorts_and_finds_on_the_server(): void
    {
        $club = $this->club();
        $owing = $this->supplier($club, ['name' => 'ألف', 'tax_number' => 'OM55501']);
        $this->bought($club, $owing);
        $this->supplier($club, ['name' => 'باء', 'is_active' => false, 'phone' => '96890000001']);
        $this->supplier($club, ['name' => 'جيم', 'phone' => '96890000002', 'email' => 'jeem@example.test']);
        $owner = $this->owner($club);
        $names = fn (array $query) => collect($this->actingAs($owner)->get(route('club.purchases.suppliers', $query))->assertOk()->viewData('page')['props']['suppliers']['data'])->pluck('name')->all();

        $this->assertSame(['ألف', 'جيم'], $names(['status' => 'active']));
        $this->assertSame(['باء'], $names(['status' => 'inactive']));
        $this->assertSame(['ألف'], $names(['status' => 'due']));
        $this->assertSame('ألف', $names(['sort' => 'due', 'dir' => 'desc'])[0]);
        $this->assertSame(['جيم', 'باء', 'ألف'], $names(['sort' => 'name', 'dir' => 'desc']));
        $this->assertSame(['ألف'], $names(['q' => 'OM555']));
        $this->assertSame(['جيم'], $names(['q' => 'jeem']));
        $this->assertSame(['باء'], $names(['q' => '90000001']));
    }

    public function test_a_supplier_with_records_is_stopped_not_deleted_and_clubs_keep_their_own(): void
    {
        $club = $this->club();
        $used = $this->supplier($club);
        $this->bought($club, $used);
        $unused = $this->supplier($club, ['name' => 'بلا تعامل']);
        $owner = $this->owner($club);

        $this->actingAs($owner)->delete(route('club.purchases.suppliers.destroy', $used))->assertSessionHasErrors();
        $this->actingAs($owner)->from(route('club.purchases.suppliers.show', $unused))->delete(route('club.purchases.suppliers.destroy', $unused))->assertRedirect(route('club.purchases.suppliers'));
        $this->asTenant($club, function () use ($used, $unused) {
            $this->assertNotNull(Supplier::find($used->id));
            $this->assertNull(Supplier::find($unused->id));
        });

        $other = $this->club('Other');
        $this->actingAs($this->owner($other))->get(route('club.purchases.suppliers.show', $used))->assertNotFound();
        $this->actingAs($this->owner($other))->put(route('club.purchases.suppliers.update', $used), ['name' => 'x'])->assertNotFound();
        $this->assertSame(0, $this->actingAs($this->owner($other))->get(route('club.purchases.suppliers'))->viewData('page')['props']['summary']['total']);
    }

    public function test_the_split_gives_view_and_add_to_whoever_could_manage_suppliers(): void
    {
        $club = $this->club();
        [$managing, $buying] = $this->asTenant($club, fn () => [
            Role::create(['key' => 'old_managing', 'name' => 'قديم', 'permissions' => ['suppliers.manage', 'purchases.view']]),
            Role::create(['key' => 'old_buying', 'name' => 'مشتريات', 'permissions' => ['purchases.view']]),
        ]);
        $person = $this->staff($club, 'reception');
        DB::table('users')->where('id', $person->id)->update(['custom_permissions' => json_encode(['suppliers.manage'])]);

        (require base_path('app/Modules/Inventory/Database/migrations/2026_10_31_000800_split_supplier_permissions.php'))->up();

        $this->asTenant($club, function () use ($managing, $buying) {
            $this->assertEqualsCanonicalizing(['suppliers.manage', 'purchases.view', 'suppliers.view', 'suppliers.create'], Role::find($managing->id)->permissions);
            $this->assertSame(['purchases.view'], Role::find($buying->id)->permissions);
        });
        $this->assertEqualsCanonicalizing(['suppliers.manage', 'suppliers.view', 'suppliers.create'], json_decode(DB::table('users')->where('id', $person->id)->value('custom_permissions'), true));
    }
}
