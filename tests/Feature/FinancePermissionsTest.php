<?php

namespace Tests\Feature;

use App\Core\Auth\PermissionRegistry;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\AccountingPeriods;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollPayout;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The owner sees, manages, approves and pays; the accountant sees the club's money and salaries and prepares runs, and
 * approves or pays only when the owner grants each by name. Salaries are for whoever may see them, the club's books
 * for whoever reaches the whole club, and nobody hands themselves more.
 */
class FinancePermissionsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function staffWithFile(Tenant $club, string $role, string $name, string $salary, array $attributes = []): User
    {
        $user = $this->staff($club, $role, $attributes);
        $this->asTenant($club, fn () => Employee::create(['name' => $name, 'basic_salary' => $salary, 'status' => 'active', 'user_id' => $user->id, 'branch_id' => $attributes['branch_id'] ?? null]));

        return $user;
    }

    private function employee(Tenant $club, string $name, string $salary): Employee
    {
        return $this->asTenant($club, fn () => Employee::create(['name' => $name, 'basic_salary' => $salary, 'allowances' => [['name' => 'مواصلات', 'amount' => '50.000']], 'status' => 'active']));
    }

    private function holding(Tenant $club, array $permissions, array $attributes = []): User
    {
        $role = $this->asTenant($club, fn () => Role::create(['key' => 'custom_'.Str::lower(Str::random(8)), 'name' => 'مخصص', 'permissions' => $permissions]));
        $user = $this->staff($club, 'reception', $attributes);
        $user->forceFill(['role_id' => $role->id])->save();

        return $user->fresh();
    }

    private function fileOf(Tenant $club, User $user): Employee
    {
        return $this->asTenant($club, fn () => Employee::where('user_id', $user->id)->firstOrFail());
    }

    /** @return list<string> the line notes of the journal as this user reads it */
    private function journalMemos(User $user): array
    {
        $memos = [];
        $this->actingAs($user)->get(route('club.finance.journal'))->assertOk()
            ->assertInertia(function ($page) use (&$memos) {
                $memos = collect($page->toArray()['props']['entries']['data'])->flatMap(fn ($e) => $e['lines'])->pluck('memo')->filter()->values()->all();
            });

        return $memos;
    }

    public function test_the_owner_sees_everything_and_approves_and_pays(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->employee($club, 'سارة', '400');
        $this->actingAs($owner)->post(route('club.payroll.store'), ['month' => now()->format('Y-m')])->assertSessionHasNoErrors();
        $run = $this->asTenant($club, fn () => PayrollRun::sole());
        $item = $this->asTenant($club, fn () => $run->items()->sole());

        foreach (['club.finance.summary', 'club.finance.banks', 'club.finance.journal', 'club.finance.accounts', 'club.finance.periods', 'club.settings.finance', 'club.expenses.index', 'club.dues', 'club.cash-shifts.index', 'club.payroll.index', 'club.payroll.payouts', 'club.audit.index'] as $name) {
            $this->actingAs($owner)->get(route($name))->assertOk();
        }
        foreach (['finance', 'statements', 'movement', 'tax', 'adjustments', 'cash-shifts', 'payroll', 'payouts'] as $report) {
            $this->actingAs($owner)->get(route('club.reports.show', $report))->assertOk();
        }
        $this->actingAs($owner)->get(route('club.payroll.index'))->assertInertia(fn ($page) => $page->where('run.items.0.net', '450.000')->where('can.approve', true)->where('can.pay', true));

        $this->actingAs($owner)->post(route('club.payroll.approve', $run))->assertSessionHasNoErrors();
        $cash = $this->asTenant($club, fn () => app(ChartOfAccounts::class)->account('cash'));
        $this->actingAs($owner)->post(route('club.payroll.pay', $run), ['items' => [$item->id], 'account_id' => $cash->id, 'paid_on' => today()->toDateString()])->assertSessionHasNoErrors();
        $this->assertNotNull($item->fresh()->paid_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'payroll.approved']);
        $this->assertDatabaseHas('audit_logs', ['event' => 'payroll.paid']);
        $this->actingAs($owner)->get(route('club.reports.export', ['report' => 'payroll', 'format' => 'csv']))->assertOk();
        $this->actingAs($owner)->get(route('club.papers.pdf', ['type' => 'payslip', 'id' => $item->id]))->assertOk();
    }

    public function test_the_accountant_reads_and_prepares_and_approves_or_pays_only_what_the_owner_grants_by_name(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $club = $this->club();
        $owner = $this->owner($club);
        $finance = $this->staffWithFile($club, 'finance', 'المحاسب', '600');
        $this->employee($club, 'سارة', '400');
        $cash = $this->asTenant($club, fn () => app(ChartOfAccounts::class)->account('cash'));

        // Sees the club's money, the drawer's shifts and the salaries, reports and files included.
        foreach (['club.finance.summary', 'club.finance.banks', 'club.finance.journal', 'club.finance.accounts', 'club.finance.periods', 'club.expenses.index', 'club.dues', 'club.payments.index', 'club.invoices.index', 'club.cash-shifts.index', 'club.employees.index'] as $name) {
            $this->actingAs($finance)->get(route($name))->assertOk();
        }
        foreach (['finance', 'statements', 'movement', 'dues', 'tax', 'adjustments', 'cash-shifts', 'payroll', 'payouts'] as $report) {
            $this->actingAs($finance)->get(route('club.reports.show', $report))->assertOk();
            $this->actingAs($finance)->get(route('club.reports.export', ['report' => $report, 'format' => 'csv']))->assertOk();
        }
        $this->actingAs($finance)->get(route('club.employees.index'))->assertInertia(fn ($page) => $page->where('confidential', true));

        // Prepares and edits the draft; approving and paying are the owner's.
        $this->actingAs($finance)->post(route('club.payroll.store'), ['month' => now()->format('Y-m')])->assertSessionHasNoErrors();
        $run = $this->asTenant($club, fn () => PayrollRun::sole());
        $sara = $this->asTenant($club, fn () => PayrollItem::where('employee_name', 'سارة')->sole());
        $this->actingAs($finance)->put(route('club.payroll.items.update', $sara), ['overtime' => '20'])->assertSessionHasNoErrors();
        $this->actingAs($finance)->get(route('club.payroll.index'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('can.prepare', true)->where('can.edit', true)->where('can.approve', false)->where('can.pay', false));
        $this->actingAs($finance)->post(route('club.payroll.approve', $run))->assertForbidden();
        $this->actingAs($finance)->get(route('club.payroll.payouts'))->assertOk()->assertInertia(fn ($page) => $page->where('accounts', []));
        $this->assertTrue($run->fresh()->isDraft());

        // The owner grants approving alone, on this accountant's own list: approving, not paying.
        $grant = fn (array $add, array $drop = []) => $this->actingAs($owner)->put(route('club.employees.access', $this->fileOf($club, $finance)), [
            'role_id' => $finance->role_id, 'is_active' => true, 'custom' => true,
            'permissions' => array_values(array_diff([...$this->asTenant($club, fn () => Role::find($finance->role_id))->expandedPermissions(), ...$add], $drop)),
        ])->assertSessionHasNoErrors();
        $grant(['payroll.approve']);
        $this->actingAs($finance->fresh())->post(route('club.payroll.approve', $run))->assertSessionHasNoErrors();
        $this->assertSame(PayrollRun::APPROVED, $run->fresh()->status);
        $payload = ['items' => [$sara->id], 'account_id' => $cash->id, 'paid_on' => today()->toDateString()];
        $this->actingAs($finance->fresh())->post(route('club.payroll.pay', $run), $payload)->assertForbidden();

        // An approved run is not edited in place.
        $this->actingAs($finance->fresh())->put(route('club.payroll.items.update', $sara), ['overtime' => '999'])->assertSessionHasErrors();
        $this->assertSame('20.000', $sara->fresh()->overtime);

        // Paying alone, without approving: pays once, a second time pays nothing.
        $grant(['payroll.pay'], ['payroll.approve']);
        $this->actingAs($finance->fresh())->get(route('club.payroll.index'))->assertInertia(fn ($page) => $page->where('can.approve', false)->where('can.pay', true));
        $this->actingAs($finance->fresh())->post(route('club.payroll.pay', $run), $payload)->assertSessionHasNoErrors();
        $this->actingAs($finance->fresh())->post(route('club.payroll.pay', $run), $payload)->assertSessionHasErrors();
        $this->asTenant($club, function () use ($sara) {
            $this->assertSame(1, PayrollPayout::count());
            $this->assertSame(1, JournalEntry::where('source_type', 'payroll_payout')->count());
            $this->assertNotNull($sara->fresh()->paid_at);
        });
    }

    public function test_the_accountant_gives_nobody_more_and_never_their_own_access_or_the_owners(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $finance = $this->staffWithFile($club, 'finance', 'المحاسب', '600');
        $ownerFile = $this->asTenant($club, fn () => Employee::create(['name' => 'المالك', 'status' => 'active', 'user_id' => $owner->id]));
        $financeRole = $this->asTenant($club, fn () => Role::where('key', 'finance')->firstOrFail());
        $receptionRole = $this->asTenant($club, fn () => Role::where('key', 'reception')->firstOrFail());

        // Without the access and roles permissions: no role editor, no accounts, no own access.
        $this->actingAs($finance)->get(route('club.roles.index'))->assertForbidden();
        $this->actingAs($finance)->put(route('club.roles.update', $financeRole), ['name' => $financeRole->name, 'permissions' => [...$financeRole->permissions, 'payroll.approve', 'payroll.pay']])->assertForbidden();
        $this->actingAs($finance)->put(route('club.employees.access', $this->fileOf($club, $finance)), ['role_id' => $financeRole->id, 'is_active' => true, 'custom' => true, 'permissions' => ['payroll.approve']])->assertForbidden();
        $this->actingAs($finance)->put(route('club.users.update', $owner), ['name' => 'x', 'email' => $owner->email, 'role_id' => $receptionRole->id])->assertForbidden();
        $this->assertNotContains('payroll.approve', $financeRole->fresh()->permissions);

        // Even when the owner lets them manage staff access: not their own, not the owner's, and nothing they lack.
        $finance->forceFill(['custom_permissions' => [...$financeRole->expandedPermissions(), 'employees.access']])->save();
        $finance = $finance->fresh();
        $this->actingAs($finance)->put(route('club.employees.access', $this->fileOf($club, $finance)), ['role_id' => $financeRole->id, 'is_active' => true, 'custom' => true, 'permissions' => ['payroll.approve', 'payroll.pay']])->assertForbidden();
        $this->actingAs($finance)->put(route('club.employees.access', $ownerFile), ['role_id' => $receptionRole->id, 'is_active' => false])->assertForbidden();
        $clerk = $this->holding($club, ['members.view']);
        $this->asTenant($club, fn () => Employee::create(['name' => 'موظف', 'status' => 'active', 'user_id' => $clerk->id]));
        $this->actingAs($finance)->put(route('club.employees.access', $this->fileOf($club, $clerk)), ['role_id' => $clerk->role_id, 'is_active' => true, 'custom' => true, 'permissions' => ['members.view', 'payroll.approve', 'roles.manage']])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['members.view'], $clerk->fresh()->custom_permissions);
        $this->assertTrue($owner->fresh()->isOwner());
        $this->assertNotContains('payroll.approve', $finance->fresh()->custom_permissions);
    }

    public function test_salaries_are_for_whoever_may_see_salaries(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->employee($club, 'سارة', '400');
        $this->employee($club, 'علي', '500');
        $this->actingAs($owner)->post(route('club.payroll.store'), ['month' => now()->format('Y-m')])->assertSessionHasNoErrors();
        $run = $this->asTenant($club, fn () => PayrollRun::sole());
        $this->actingAs($owner)->post(route('club.payroll.approve', $run))->assertSessionHasNoErrors();
        $items = $this->asTenant($club, fn () => $run->items()->pluck('id')->all());
        $cash = $this->asTenant($club, fn () => app(ChartOfAccounts::class)->account('cash'));
        $this->actingAs($owner)->post(route('club.payroll.pay', $run), ['items' => $items, 'account_id' => $cash->id, 'paid_on' => today()->toDateString()])->assertSessionHasNoErrors();
        $sara = $this->asTenant($club, fn () => Employee::where('name', 'سارة')->sole());

        // The manager runs the club, not its salaries: no runs, payouts, payslips or payroll reports, and the journal's
        // payroll entries come as one line per account, without names.
        $manager = $this->staff($club, 'manager');
        $this->assertNotContains('payroll.prepare', $manager->permissions());
        foreach ([route('club.payroll.index'), route('club.payroll.payouts'), route('club.payroll.export', $run), route('club.reports.show', 'payroll'), route('club.papers.show', ['type' => 'payslip', 'id' => $items[0]]), route('club.papers.show', ['type' => 'payroll_sheet', 'id' => $run->id])] as $url) {
            $this->actingAs($manager)->get($url)->assertForbidden();
        }
        $this->actingAs($manager)->get(route('club.employees.show', $sara))->assertOk()
            ->assertInertia(fn ($page) => $page->where('confidential', false)->where('payslips', [])->where('stats.monthly', null)->where('stats.last_net', null)->where('employee.basic_salary', null));
        $managerMemos = $this->journalMemos($manager);
        $this->assertNotContains('سارة', $managerMemos);
        $this->assertNotContains('علي', $managerMemos);
        $this->assertContains('مجموع سطور الموظفين', $managerMemos);
        $this->assertContains('سارة', $this->journalMemos($owner));
        $this->assertContains('علي', $this->journalMemos($this->staff($club, 'finance')));

        // A payroll step without seeing salaries opens nothing, wherever it is stored.
        $preparer = $this->holding($club, ['payroll.prepare', 'payroll.edit', 'payroll.payslips', 'payroll.reports']);
        $this->assertSame([], $preparer->permissions());
        $this->actingAs($preparer)->get(route('club.payroll.index'))->assertForbidden();
        $this->actingAs($preparer)->post(route('club.payroll.store'), ['month' => now()->subMonth()->format('Y-m')])->assertForbidden();
    }

    public function test_the_whole_clubs_money_is_not_a_branchs_without_its_own_permission(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $club = $this->club();
        $khoud = $this->asTenant($club, fn () => Branch::create(['name' => 'فرع الخوض', 'is_active' => true]));
        $books = ['club.finance.summary', 'club.finance.banks', 'club.finance.journal', 'club.finance.accounts', 'club.finance.periods'];

        // A manager tied to Al Khoud: their branch's figures in the reports, not the club's books, banks or runs.
        $branchManager = $this->staff($club, 'manager', ['branch_id' => $khoud->id]);
        foreach ($books as $name) {
            $this->actingAs($branchManager)->get(route($name))->assertForbidden();
        }
        $this->actingAs($branchManager)->get(route('club.reports.show', 'statements'))->assertOk()->assertInertia(fn ($page) => $page->where('filters.branch', $khoud->id));
        $this->actingAs($branchManager)->get(route('club.reports.show', ['report' => 'movement', 'branch' => '']))->assertOk()->assertInertia(fn ($page) => $page->where('filters.branch', $khoud->id));

        // The club-wide manager keeps what the role gives today.
        $this->actingAs($this->staff($club, 'manager'))->get(route('club.finance.banks'))->assertOk();

        // The accountant tied to a branch keeps the club's books and runs (the finance role holds accounting.all_branches).
        $branchFinance = $this->staff($club, 'finance', ['branch_id' => $khoud->id]);
        foreach ([...$books, 'club.settings.finance', 'club.payroll.index'] as $name) {
            $this->actingAs($branchFinance)->get(route($name))->assertOk();
        }
        // ...the whole run with it: the lines and payslips of the main branch's staff too.
        $this->employee($club, 'سارة', '400');
        $this->actingAs($this->owner($club))->post(route('club.payroll.store'), ['month' => now()->format('Y-m')])->assertSessionHasNoErrors();
        $line = $this->asTenant($club, fn () => PayrollItem::sole());
        $this->actingAs($branchFinance)->get(route('club.payroll.print', ['run' => $line->payroll_run_id, 'item' => $line->id]))->assertOk();
        $this->actingAs($branchFinance)->put(route('club.payroll.items.update', $line), ['overtime' => '5'])->assertSessionHasNoErrors();
        $this->assertSame('5.000', $line->fresh()->overtime);
        $this->assertContains($this->actingAs($branchManager)->get(route('club.papers.show', ['type' => 'payslip', 'id' => $line->id]))->getStatusCode(), [403, 404]);

        // A custom role: seeing the books from a branch takes the permission by name; so does acting on them.
        $viewer = $this->holding($club, ['accounting.view', 'accounting.manage'], ['branch_id' => $khoud->id]);
        $this->actingAs($viewer)->get(route('club.finance.banks'))->assertForbidden();
        $this->actingAs($viewer)->post(route('club.finance.banks.store'), ['name' => 'بنك جديد', 'kind' => 'bank'])->assertForbidden();
        $this->actingAs($viewer)->get(route('club.settings.finance'))->assertForbidden();
        $this->actingAs($this->holding($club, ['accounting.view', 'accounting.all_branches'], ['branch_id' => $khoud->id]))->get(route('club.finance.banks'))->assertOk();
    }

    public function test_desk_roles_reach_no_sensitive_money_by_page_file_or_address(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class);
        $club = $this->club();
        $this->employee($club, 'سارة', '400');
        $this->actingAs($this->owner($club))->post(route('club.payroll.store'), ['month' => now()->format('Y-m')])->assertSessionHasNoErrors();
        $run = $this->asTenant($club, fn () => PayrollRun::sole());
        $item = $this->asTenant($club, fn () => $run->items()->sole());

        $urls = [
            route('club.finance.summary'), route('club.finance.banks'), route('club.finance.journal'), route('club.finance.accounts'),
            route('club.finance.statements.export'), route('club.cash-shifts.index'), route('club.expenses.index'), route('club.expenses.export'),
            route('club.payroll.index'), route('club.payroll.payouts'), route('club.payroll.export', $run), route('club.payroll.print', $run),
            route('club.papers.show', ['type' => 'payslip', 'id' => $item->id]), route('club.employees.index'),
            route('club.reports.show', 'finance'), route('club.reports.show', 'statements'), route('club.reports.show', 'payroll'),
            route('club.reports.export', ['report' => 'finance', 'format' => 'csv']), route('club.reports.export', ['report' => 'payroll', 'format' => 'xlsx']),
        ];
        foreach (['reception', 'cashier', 'trainer'] as $role) {
            $user = $this->staff($club, $role);
            foreach ($urls as $url) {
                $status = $this->actingAs($user)->get($url)->getStatusCode();
                $this->assertContains($status, [403, 404], "{$role} reached {$url} ({$status})");
            }
            $this->actingAs($user)->post(route('club.payroll.approve', $run))->assertForbidden();
            $this->actingAs($user)->post(route('club.finance.journal.store'), ['date' => today()->toDateString(), 'description' => 'x', 'lines' => []])->assertForbidden();
            foreach (['payroll.salaries', 'accounting.view', 'reports.financial', 'dues.view'] as $permission) {
                $this->assertNotContains($permission, $user->permissions(), "{$role} holds {$permission}");
            }
        }
        if (PermissionRegistry::moduleEnabled('sales')) {
            $this->actingAs($this->staff($club, 'cashier'))->get(route('club.pos.index'))->assertOk();
        }
    }

    public function test_posted_money_is_corrected_by_reversal_and_closed_months_take_nothing(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $finance = $this->staff($club, 'finance');
        [$cash, $bank] = $this->asTenant($club, fn () => [app(ChartOfAccounts::class)->account('cash'), app(ChartOfAccounts::class)->account('bank')]);
        $entry = fn (string $date) => $this->actingAs($finance)->post(route('club.finance.journal.store'), [
            'client_key' => (string) Str::uuid(), 'date' => $date, 'description' => 'إيداع',
            'lines' => [['account_id' => $bank->id, 'debit' => '10'], ['account_id' => $cash->id, 'credit' => '10']],
        ]);

        $entry(today()->toDateString())->assertSessionHasNoErrors();
        $posted = $this->asTenant($club, fn () => JournalEntry::whereNull('source_type')->sole());
        // No address edits or deletes a posted entry; a manual one is reversed.
        $this->assertContains($this->actingAs($finance)->put('/app/finance/journal/'.$posted->id, ['description' => 'x'])->getStatusCode(), [404, 405]);
        $this->assertContains($this->actingAs($finance)->delete('/app/finance/journal/'.$posted->id)->getStatusCode(), [404, 405]);
        $this->asTenant($club, fn () => $this->assertSame('إيداع', $posted->fresh()->description));
        $this->actingAs($finance)->post(route('club.finance.journal.reverse', $posted))->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertNotNull($posted->fresh()->reversed_at));

        // An entry posted by a document is corrected at its source, not reversed here.
        $this->employee($club, 'سارة', '400');
        $this->actingAs($owner)->post(route('club.payroll.store'), ['month' => now()->format('Y-m')])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.payroll.approve', $this->asTenant($club, fn () => PayrollRun::sole())))->assertSessionHasNoErrors();
        $payroll = $this->asTenant($club, fn () => JournalEntry::where('source_type', 'payroll_run')->sole());
        $this->actingAs($owner)->post(route('club.finance.journal.reverse', $payroll))->assertSessionHasErrors();
        $this->asTenant($club, fn () => $this->assertNull($payroll->fresh()->reversed_at));

        // A closed month takes no entry, and finance cannot close or reopen it.
        $lastMonth = now()->subMonthNoOverflow();
        $this->actingAs($finance)->post(route('club.finance.periods.close'), ['month' => $lastMonth->format('Y-m'), 'client_key' => (string) Str::uuid()])->assertForbidden();
        $this->asTenant($club, fn () => app(AccountingPeriods::class)->closeThrough($lastMonth->format('Y-m'), $owner));
        $entry($lastMonth->endOfMonth()->toDateString())->assertSessionHasErrors('date');
        $this->actingAs($finance)->post(route('club.finance.periods.reopen'), ['month' => $lastMonth->format('Y-m'), 'client_key' => (string) Str::uuid()])->assertForbidden();
    }

    public function test_an_existing_club_keeps_who_sees_the_books_and_its_owner_pays_salaries(): void
    {
        $club = $this->club();
        [$finance, $manager, $reception] = $this->asTenant($club, fn () => [Role::where('key', 'finance')->sole(), Role::where('key', 'manager')->sole(), Role::where('key', 'reception')->sole()]);
        // As a club installed before: finance pays salaries and sees no shifts; nobody holds accounting.all_branches.
        $old = fn (Role $role, array $add = [], array $drop = []) => DB::table('roles')->where('id', $role->id)
            ->update(['permissions' => json_encode(array_values(array_diff([...$role->permissions, ...$add], [...$drop, 'accounting.all_branches'])))]);
        $old($finance, ['payroll.pay'], ['cash_shifts.view']);
        $old($manager);
        $custom = $this->asTenant($club, fn () => Role::create(['key' => 'custom_books', 'name' => 'مراجع', 'permissions' => ['accounting.view']]));
        $person = $this->staff($club, 'reception');
        $person->forceFill(['custom_permissions' => ['members.view', 'accounting.view']])->save();
        $receptionBefore = $reception->fresh()->permissions;

        (require database_path('migrations/2026_11_02_000100_owner_pays_salaries_and_club_finance_by_grant.php'))->up();

        $finance = $finance->fresh()->permissions;
        $this->assertNotContains('payroll.pay', $finance);
        $this->assertContains('cash_shifts.view', $finance);
        $this->assertContains('accounting.all_branches', $finance);
        $this->assertNotContains('accounting.all_branches', $manager->fresh()->permissions);
        $this->assertContains('accounting.view', $manager->fresh()->permissions);
        $this->assertContains('accounting.all_branches', $custom->fresh()->permissions);
        $this->assertContains('accounting.all_branches', $person->fresh()->custom_permissions);
        $this->assertSame($receptionBefore, $reception->fresh()->permissions);
    }
}
