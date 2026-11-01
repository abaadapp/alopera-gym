<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Gym\Models\AccessDevice;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Payroll\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Who reaches what: a user tied to a branch keeps the accounts and branches they manage in that branch; ending an
 * employee's service closes their sign-in; only the gym this system serves is served; a count from the product page is
 * a numbered stock adjustment needing its own right; the browser's history is encrypted and cleared on sign-out; a
 * manual entry and a transfer post once and check their accounts; and only the owner restores a backup.
 */
class AccessHardeningTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** @return array{0: Branch, 1: Branch} the main branch and another */
    private function branches(Tenant $club): array
    {
        return $this->asTenant($club, fn () => [Branch::where('is_main', true)->firstOrFail(), Branch::create(['name' => 'فرع الخوض', 'is_active' => true])]);
    }

    private function roleId(Tenant $club, string $key): int
    {
        return $this->asTenant($club, fn () => Role::where('key', $key)->value('id'));
    }

    public function test_a_user_tied_to_a_branch_keeps_the_accounts_and_branches_they_manage_in_it(): void
    {
        $club = $this->club();
        [$main, $khoud] = $this->branches($club);
        $manager = $this->staff($club, 'manager', ['branch_id' => $khoud->id]);
        $reception = $this->roleId($club, 'reception');
        $account = fn (array $extra = []) => ['name' => 'موظفة', 'email' => Str::random(8).'@staff.test', 'role_id' => $reception, 'is_active' => true, 'password' => 'Secret-pass-123'] + $extra;

        // Their own account: not to every branch, not another role, not off.
        $this->actingAs($manager)->put(route('club.users.update', $manager), ['name' => $manager->name, 'email' => $manager->email, 'role_id' => $manager->role_id, 'branch_id' => null, 'is_active' => true])
            ->assertSessionHasErrors('role_id');
        $this->assertSame($khoud->id, $manager->fresh()->branch_id);
        // Renaming themselves still works.
        $this->actingAs($manager)->put(route('club.users.update', $manager), ['name' => 'مديرة الخوض', 'email' => $manager->email, 'role_id' => $manager->role_id, 'branch_id' => $khoud->id, 'is_active' => true])
            ->assertSessionHasNoErrors();

        // New accounts stay in their branch.
        $this->actingAs($manager)->post(route('club.users.store'), $account(['branch_id' => null]))->assertSessionHasErrors('branch_id');
        $this->actingAs($manager)->post(route('club.users.store'), $account(['branch_id' => $main->id]))->assertSessionHasErrors('branch_id');
        $this->actingAs($manager)->post(route('club.users.store'), $account(['branch_id' => $khoud->id]))->assertSessionHasNoErrors();

        // Another branch's account, or one reaching every branch, is not theirs to edit — nor listed.
        $other = $this->staff($club, 'reception', ['branch_id' => $main->id]);
        $everywhere = $this->staff($club, 'reception');
        foreach ([$other, $everywhere] as $target) {
            $this->actingAs($manager)->put(route('club.users.update', $target), ['name' => 'x', 'email' => $target->email, 'role_id' => $reception, 'branch_id' => $khoud->id, 'is_active' => true])->assertForbidden();
        }
        $listed = collect($this->actingAs($manager)->get(route('club.users.index'))->viewData('page')['props']['users'])->pluck('id');
        $this->assertNotContains($other->id, $listed);
        $this->assertContains($manager->id, $listed);

        // From an employee's file: no account to every branch, no reach changed for another branch's account.
        $employee = $this->asTenant($club, fn () => Employee::create(['name' => 'موظف الخوض', 'branch_id' => $khoud->id]));
        $this->actingAs($manager)->put(route('club.employees.update', $employee), ['name' => 'موظف الخوض', 'branch_id' => $khoud->id, 'status' => 'active', 'account_mode' => 'new', 'account_email' => 'new@staff.test', 'account_role_id' => $reception, 'account_branch_id' => null])
            ->assertSessionHasErrors('account_branch_id');
        $linked = $this->asTenant($club, fn () => Employee::create(['name' => 'موظفة الرئيسي', 'branch_id' => $main->id, 'user_id' => $other->id]));
        $this->actingAs($manager)->put(route('club.employees.access', $linked), ['role_id' => $reception, 'branch_id' => $khoud->id, 'is_active' => true])->assertNotFound();

        // Branches: they keep their own branch's details; opening, stopping or editing another is not theirs.
        $this->actingAs($manager)->post(route('club.branches.store'), ['name' => 'فرع جديد'])->assertForbidden();
        $this->actingAs($manager)->put(route('club.branches.update', $main), ['name' => 'الرئيسي'])->assertForbidden();
        $this->actingAs($manager)->delete(route('club.branches.destroy', $khoud))->assertForbidden();
        $this->actingAs($manager)->put(route('club.branches.update', $khoud), ['name' => 'فرع الخوض الجديد', 'is_active' => false])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame(['فرع الخوض الجديد', true], [$khoud->fresh()->name, $khoud->fresh()->is_active]));
        // A club-wide manager still opens branches.
        $this->actingAs($this->staff($club, 'manager'))->post(route('club.branches.store'), ['name' => 'فرع صحار'])->assertSessionHasNoErrors();
    }

    public function test_ending_an_employees_service_switches_off_their_sign_in(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $staff = $this->staff($club, 'reception');
        $employee = $this->asTenant($club, fn () => Employee::create(['name' => 'سارة', 'status' => 'active', 'user_id' => $staff->id]));
        $this->actingAs($staff)->get(route($staff->homeRoute()))->assertOk();

        $this->actingAs($owner)->put(route('club.employees.update', $employee), ['name' => 'سارة', 'status' => 'terminated', 'basic_salary' => 0, 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $this->assertFalse($staff->fresh()->is_active);
        $this->asTenant($club, fn () => $this->assertDatabaseHas('audit_logs', ['event' => 'user.deactivated', 'subject_id' => $staff->id]));
        $this->actingAs($staff->fresh())->get(route($staff->homeRoute()))->assertRedirect(route('login'));

        // Nobody ends their own service; the last owner is never switched off this way.
        $ownerFile = $this->asTenant($club, fn () => Employee::create(['name' => 'المالك', 'status' => 'active', 'user_id' => $owner->id]));
        $this->actingAs($owner)->put(route('club.employees.update', $ownerFile), ['name' => 'المالك', 'status' => 'terminated', 'basic_salary' => 0, 'payment_method' => 'cash'])->assertSessionHasErrors('status');
        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_only_the_gym_this_system_serves_is_served(): void
    {
        $home = $this->club('Alopera');
        $old = $this->club('Old club');
        $oldOwner = $this->owner($old);
        [, $token] = $this->asTenant($old, function () {
            $device = new AccessDevice(['name' => 'Gate', 'type' => 'turnstile', 'purpose' => 'both', 'direction' => 'in']);
            $token = $device->issueToken();
            $device->save();

            return [$device, $token];
        });

        // Two clubs on record and none named (a development copy): nothing is limited.
        $this->get(route('site.home', ['club' => $old->slug]))->assertOk();

        config(['gym.home_club' => $home->slug]);
        $this->post(route('login'), ['email' => $oldOwner->email, 'password' => 'password'])->assertSessionHasErrors('email');
        $this->assertGuest('web');
        $this->actingAs($oldOwner)->get(route('club.dashboard'))->assertRedirect(route('login'));
        $this->get(route('site.home', ['club' => $old->slug]))->assertNotFound();
        $this->withToken($token)->postJson(route('devices.access'), ['credential_type' => 'qr', 'credential' => 'x'])->assertForbidden();
        $this->get(route('site.home', ['club' => $home->slug]))->assertOk();
        $this->actingAs($this->owner($home))->get(route('club.dashboard'))->assertOk();

        // Scheduled jobs run for the gym alone.
        Artisan::call('notifications:sync');
        $this->assertStringContainsString("[{$home->slug}]", Artisan::output());
        $this->assertStringNotContainsString("[{$old->slug}]", Artisan::output());
    }

    public function test_a_count_from_the_product_page_is_a_numbered_adjustment_needing_its_right(): void
    {
        $club = $this->club();
        $product = $this->asTenant($club, function () {
            $product = Product::create(['name' => 'منشفة', 'price' => 3]);
            app(StockService::class)->move($product, 10, 'adjustment', null, 'opening', 1);

            return $product;
        });
        $custom = $this->asTenant($club, fn () => Role::create(['name' => 'المنتجات', 'key' => 'products_only', 'permissions' => ['products.view', 'products.manage']]));
        $clerk = $this->staff($club, 'products_only');
        $this->assertSame($custom->id, $clerk->role_id);

        $this->actingAs($clerk)->post(route('club.products.adjust', $product), ['counted' => 7, 'reason' => 'جرد'])->assertForbidden();
        $this->actingAs($this->owner($club))->post(route('club.products.adjust', $product), ['counted' => 7, 'reason' => 'جرد شهري'])->assertSessionHasNoErrors();
        $this->asTenant($club, function () use ($product) {
            $moved = StockMovement::where('product_id', $product->id)->where('type', 'adjustment')->latest('id')->first();
            $this->assertSame(['-3.000', 'count', 'جرد شهري', '7.000'], [$moved->quantity, $moved->reason, $moved->note, $product->fresh()->stock_quantity]);
            $this->assertNotNull($moved->document);
            $this->assertDatabaseHas('audit_logs', ['event' => 'stock.adjusted', 'subject_id' => $product->id]);
        });
    }

    public function test_the_browsers_history_is_encrypted_and_cleared_on_sign_out(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);

        $this->assertTrue($this->actingAs($owner)->get(route('club.dashboard'))->viewData('page')['encryptHistory']);
        $this->actingAs($owner)->post(route('logout'))->assertRedirect(route('login'));
        $this->assertTrue($this->get(route('login'))->viewData('page')['clearHistory']);
        $this->asTenant($club, fn () => $this->assertDatabaseHas('audit_logs', ['event' => 'auth.logout', 'subject_id' => $owner->id]));
    }

    public function test_a_manual_entry_and_a_transfer_post_once_and_check_their_accounts(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $chart = fn (string $key) => app(ChartOfAccounts::class)->account($key);
        [$cash, $bank, $expense] = $this->asTenant($club, fn () => [$chart('cash'), $chart('bank'), $chart('general_expense')]);
        $stranger = $this->asTenant($this->club('Other'), fn () => $chart('cash'));
        $entry = fn (array $lines, string $key) => ['client_key' => $key, 'date' => today()->toDateString(), 'description' => 'تسوية', 'lines' => $lines];
        $lines = [['account_id' => $expense->id, 'debit' => 5], ['account_id' => $cash->id, 'credit' => 5]];

        $key = (string) Str::uuid();
        $this->actingAs($owner)->post(route('club.finance.journal.store'), $entry($lines, $key))->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.finance.journal.store'), $entry($lines, $key))->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame(1, JournalEntry::whereNull('source_type')->where('description', 'تسوية')->count()));
        $this->asTenant($club, fn () => $this->assertDatabaseHas('audit_logs', ['event' => 'journal.posted']));
        $this->actingAs($owner)->post(route('club.finance.journal.store'), $entry([['account_id' => $stranger->id, 'debit' => 5], ['account_id' => $cash->id, 'credit' => 5]], (string) Str::uuid()))
            ->assertSessionHasErrors('lines.0.account_id');

        $transfer = ['client_key' => (string) Str::uuid(), 'from_id' => $cash->id, 'to_id' => $bank->id, 'amount' => 2, 'date' => today()->toDateString()];
        $this->actingAs($owner)->post(route('club.finance.transfers.store'), $transfer)->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.finance.transfers.store'), $transfer)->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame(1, JournalEntry::where('description', 'like', 'تحويل من%')->count()));
        $this->actingAs($owner)->post(route('club.finance.transfers.store'), ['from_id' => $stranger->id] + $transfer + ['client_key' => (string) Str::uuid()])->assertSessionHasErrors('from_id');
    }

    public function test_only_the_owner_restores_a_backup(): void
    {
        $club = $this->club();
        $this->asTenant($club, fn () => Role::create(['name' => 'النسخ', 'key' => 'backups_only', 'permissions' => ['backups.manage']]));
        $keeper = $this->staff($club, 'backups_only');

        $this->actingAs($keeper)->post(route('club.backups.restore'), ['confirm' => $club->slug])->assertForbidden();
    }
}
