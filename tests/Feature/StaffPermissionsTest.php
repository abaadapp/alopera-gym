<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Gym\Models\StaffAttendance;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Staff files and their permissions, checked on the server: seeing a file is not seeing its salary, editing a file
 * is not changing a salary, a payslip is not reached by its address without its permission, files stay in their club
 * and branch, and nobody hands out more than they hold — through a role, an assignment or a person's own list.
 */
class StaffPermissionsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function role(Tenant $club, array $permissions): Role
    {
        return $this->asTenant($club, fn () => Role::create(['key' => 'custom_'.Str::lower(Str::random(6)), 'name' => 'دور '.Str::random(4), 'permissions' => $permissions]));
    }

    private function holding(Tenant $club, array $permissions, array $attributes = []): User
    {
        $user = $this->staff($club, 'reception', $attributes);
        $user->forceFill(['role_id' => $this->role($club, $permissions)->id])->save();

        return $user->fresh();
    }

    private function employee(Tenant $club, array $attributes = []): Employee
    {
        return $this->asTenant($club, fn () => Employee::create($attributes + [
            'name' => 'سارة', 'basic_salary' => '400.000', 'allowances' => [['name' => 'مواصلات', 'amount' => '50.000']], 'status' => 'active',
            'payment_method' => 'bank', 'bank_name' => 'بنك مسقط', 'iban' => 'OM810180000001299123456',
        ]));
    }

    public function test_each_system_role_sees_only_its_part_of_the_staff_files(): void
    {
        $club = $this->club();
        $employee = $this->employee($club);

        foreach (['reception', 'trainer'] as $key) {
            $user = $this->staff($club, $key);
            $this->actingAs($user)->get(route('club.employees.index'))->assertForbidden();
            $this->actingAs($user)->get(route('club.employees.show', $employee))->assertForbidden();
            $this->actingAs($user)->get(route('club.payroll.index'))->assertForbidden();
        }

        // The manager runs the staff files but does not read salaries.
        $manager = $this->staff($club, 'manager');
        $this->actingAs($manager)->get(route('club.employees.show', $employee))->assertOk()->assertInertia(fn ($page) => $page
            ->component('Payroll/EmployeeShow')->where('confidential', false)->where('employee.basic_salary', null)->where('employee.iban', null)->where('employee.bank_masked', null)->where('stats.monthly', null));

        // Finance reads salaries but does not edit files or change salaries.
        $finance = $this->staff($club, 'finance');
        $this->actingAs($finance)->get(route('club.employees.show', $employee))->assertOk()->assertInertia(fn ($page) => $page->where('employee.basic_salary', '400.000')->where('employee.bank_masked', '•••• 3456'));
        $this->actingAs($finance)->put(route('club.employees.update', $employee), ['basic_salary' => '999', 'payment_method' => 'bank'])->assertForbidden();
        $this->assertSame('400.000', $employee->fresh()->basic_salary);
    }

    public function test_viewing_a_file_is_not_editing_it(): void
    {
        $club = $this->club();
        $employee = $this->employee($club);
        $viewer = $this->holding($club, ['employees.view']);

        $this->actingAs($viewer)->get(route('club.employees.index'))->assertOk();
        $this->actingAs($viewer)->get(route('club.employees.show', $employee))->assertOk();
        $this->actingAs($viewer)->get(route('club.employees.edit', $employee))->assertForbidden();
        $this->actingAs($viewer)->put(route('club.employees.update', $employee), ['name' => 'غيرها', 'status' => 'active'])->assertForbidden();
        $this->actingAs($viewer)->get(route('club.employees.create'))->assertForbidden();
        $this->actingAs($viewer)->post(route('club.employees.store'), ['name' => 'جديد', 'status' => 'active'])->assertForbidden();
        $this->assertSame('سارة', $employee->fresh()->name);
    }

    public function test_editing_a_file_neither_shows_nor_changes_the_salary(): void
    {
        $club = $this->club();
        $employee = $this->employee($club);
        $editor = $this->holding($club, ['employees.view', 'employees.manage']);

        $page = $this->actingAs($editor)->get(route('club.employees.edit', $employee))->assertOk();
        $page->assertInertia(fn ($p) => $p->component('Payroll/EmployeeForm')->where('employee.basic_salary', null)->where('employee.iban', null)->where('can.compensation', false));
        // Not in the page at all: not hidden by the screen, never sent.
        $this->assertStringNotContainsString('OM810180000001299123456', $page->getContent());
        $this->assertStringNotContainsString('400.000', $page->getContent());

        $this->actingAs($editor)->put(route('club.employees.update', $employee), ['name' => 'سارة علي', 'status' => 'active', 'basic_salary' => '9999', 'iban' => 'HACK', 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $fresh = $employee->fresh();
        $this->assertSame('سارة علي', $fresh->name);
        $this->assertSame(['400.000', 'OM810180000001299123456', 'bank'], [$fresh->basic_salary, $fresh->iban, $fresh->payment_method]);
    }

    public function test_salaries_are_read_with_one_permission_and_changed_with_another(): void
    {
        $club = $this->club();
        $employee = $this->employee($club);

        $reader = $this->holding($club, ['employees.view', 'payroll.salaries']);
        $this->actingAs($reader)->get(route('club.employees.index'))->assertInertia(fn ($p) => $p->where('employees.0.basic_salary', '400.000')->where('stats.monthly', '450.000'));
        $this->actingAs($reader)->put(route('club.employees.update', $employee), ['basic_salary' => '500', 'payment_method' => 'bank'])->assertForbidden();

        // Changing the salary does not need — nor allow — changing the basic data.
        $payroll = $this->holding($club, ['employees.view', 'payroll.salaries', 'payroll.compensation']);
        $this->actingAs($payroll)->put(route('club.employees.update', $employee), ['name' => 'اسم آخر', 'status' => 'terminated', 'basic_salary' => '500', 'allowances' => [['name' => 'سكن', 'amount' => '60']], 'payment_method' => 'bank', 'iban' => 'OM00NEW'])->assertSessionHasNoErrors();
        $fresh = $employee->fresh();
        $this->assertSame(['سارة', 'active', '500.000', 'OM00NEW'], [$fresh->name, $fresh->status, $fresh->basic_salary, $fresh->iban]);
        $this->assertSame([['name' => 'سكن', 'amount' => '60.000']], $fresh->allowances);

        // The history keeps that the salary changed, not to what.
        $entry = $this->asTenant($club, fn () => DB::table('audit_logs')->where('event', 'employee.updated')->latest('id')->first());
        $this->assertStringNotContainsString('500', (string) $entry->new_values.$entry->old_values);
        $this->assertStringContainsString('basic_salary', (string) $entry->properties);
    }

    public function test_payslips_are_not_reached_by_their_address_without_the_permission(): void
    {
        $club = $this->club();
        $this->employee($club);
        $this->actingAs($this->owner($club))->post(route('club.payroll.store'), ['month' => now()->format('Y-m')])->assertSessionHasNoErrors();
        [$run, $item] = $this->asTenant($club, fn () => [$run = PayrollRun::firstOrFail(), $run->items()->firstOrFail()]);

        $nosy = $this->holding($club, ['employees.view', 'payroll.salaries', 'reports.view']);
        foreach ([
            route('club.papers.show', ['type' => 'payslip', 'id' => $item->id]),
            route('club.papers.pdf', ['type' => 'payslip', 'id' => $item->id]),
            route('club.papers.show', ['type' => 'payroll_sheet', 'id' => $run->id]),
            route('club.payroll.print', ['run' => $run->id, 'item' => $item->id]),
            route('club.payroll.print', $run),
            route('club.payroll.export', $run),
        ] as $url) {
            $this->actingAs($nosy)->get($url)->assertForbidden();
        }

        $clerk = $this->holding($club, ['payroll.payslips']);
        $this->actingAs($clerk)->get(route('club.papers.show', ['type' => 'payslip', 'id' => $item->id]))->assertOk()->assertSee('كشف راتب')->assertSee('450.000');
        $pdf = $this->actingAs($clerk)->get(route('club.papers.pdf', ['type' => 'payslip', 'id' => $item->id]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
        // The bank line is for whoever may also read bank details.
        $this->actingAs($clerk)->get(route('club.papers.show', ['type' => 'payslip', 'id' => $item->id]))->assertDontSee('3456');
        $this->actingAs($this->owner($club))->get(route('club.papers.show', ['type' => 'payslip', 'id' => $item->id]))->assertSee('•••• 3456');
    }

    public function test_staff_files_stay_in_their_club_and_branch(): void
    {
        $club = $this->club();
        $other = $this->club('Other');
        $foreign = $this->employee($other, ['name' => 'من نادٍ آخر']);
        $this->actingAs($this->owner($club))->get(route('club.employees.show', $foreign))->assertNotFound();
        $this->actingAs($this->owner($club))->put(route('club.employees.update', $foreign), ['name' => 'x', 'status' => 'active'])->assertNotFound();

        [$east, $west] = $this->asTenant($club, fn () => [Branch::create(['name' => 'الشرق', 'is_active' => true]), Branch::create(['name' => 'الغرب', 'is_active' => true])]);
        $westEmployee = $this->employee($club, ['name' => 'موظف الغرب', 'branch_id' => $west->id]);
        $this->employee($club, ['name' => 'موظف الشرق', 'branch_id' => $east->id]);
        $tied = $this->holding($club, ['employees.view'], ['branch_id' => $east->id]);
        $this->actingAs($tied)->get(route('club.employees.show', $westEmployee))->assertNotFound();
        $this->actingAs($tied)->get(route('club.employees.index'))->assertOk()->assertInertia(fn ($p) => $p->has('employees', 1)->where('employees.0.name', 'موظف الشرق'));
    }

    public function test_nobody_hands_out_a_role_or_a_permission_they_do_not_hold(): void
    {
        $club = $this->club();
        $manager = $this->staff($club, 'manager');
        [$ownerRole, $financeRole, $receptionRole] = $this->asTenant($club, fn () => [Role::where('key', 'owner')->value('id'), Role::where('key', 'finance')->value('id'), Role::where('key', 'reception')->value('id')]);
        $base = ['name' => 'جديد', 'password' => 'Password123!', 'is_active' => true];

        // The owner role, and a role opening salaries the manager cannot read, are not the manager's to give.
        $this->actingAs($manager)->post(route('club.users.store'), $base + ['email' => 'a@x.test', 'role_id' => $ownerRole])->assertSessionHasErrors('role_id');
        $this->actingAs($manager)->post(route('club.users.store'), $base + ['email' => 'b@x.test', 'role_id' => $financeRole])->assertSessionHasErrors('role_id');
        $this->actingAs($manager)->post(route('club.users.store'), $base + ['email' => 'c@x.test', 'role_id' => $receptionRole])->assertSessionHasNoErrors();

        // An account opening more than the manager is not the manager's to edit or switch off.
        $owner = $this->owner($club);
        $this->actingAs($manager)->put(route('club.users.update', $owner), ['name' => 'x', 'email' => $owner->email, 'role_id' => $receptionRole])->assertForbidden();
        $this->actingAs($manager)->delete(route('club.users.destroy', $owner))->assertForbidden();
        $this->assertTrue($owner->fresh()->isOwner());

        // A role editor gives only what they hold, and leaves untouched what they do not.
        $editor = $this->holding($club, ['roles.manage', 'members.view', 'checkin.perform']);
        $this->actingAs($editor)->post(route('club.roles.store'), ['name' => 'محاولة', 'permissions' => ['members.view', 'payroll.salaries']])->assertSessionHasErrors('permissions');
        $role = $this->role($club, ['members.view', 'payroll.pay']);
        $this->actingAs($editor)->put(route('club.roles.update', $role), ['name' => $role->name, 'permissions' => ['checkin.perform', 'payroll.salaries']])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['payroll.pay', 'checkin.perform'], $role->fresh()->permissions);
    }

    public function test_a_persons_own_permissions_replace_their_roles_under_the_same_rule(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $user = $this->staff($club, 'reception');
        $employee = $this->employee($club, ['user_id' => $user->id]);
        $receptionRole = $user->role_id;

        $this->actingAs($owner)->put(route('club.employees.access', $employee), ['role_id' => $receptionRole, 'is_active' => true, 'custom' => true, 'permissions' => ['checkin.perform', 'employees.view']])->assertSessionHasNoErrors();
        $fresh = $user->fresh();
        $this->assertTrue($fresh->hasOwnPermissions());
        $this->assertEqualsCanonicalizing(['checkin.perform', 'employees.view'], $fresh->permissions());
        $this->actingAs($fresh)->get(route('club.members.index'))->assertForbidden();
        $this->actingAs($fresh)->get(route('club.employees.index'))->assertOk();

        // Someone holding employees.access gives only what they hold.
        $hr = $this->holding($club, ['employees.view', 'employees.access', 'checkin.perform', 'checkin.view']);
        $this->actingAs($hr)->put(route('club.employees.access', $employee), ['role_id' => $receptionRole, 'is_active' => true, 'custom' => true, 'permissions' => ['checkin.view', 'payroll.salaries']])->assertSessionHasNoErrors();
        // hr holds checkin.perform and employees.view, so unticking them removes them; payroll.salaries is not added.
        $this->assertEqualsCanonicalizing(['checkin.view'], $user->fresh()->permissions());

        // Nobody edits their own access.
        $hrEmployee = $this->employee($club, ['name' => 'شؤون', 'user_id' => $hr->id]);
        $this->actingAs($hr)->put(route('club.employees.access', $hrEmployee), ['role_id' => $hr->role_id, 'is_active' => true, 'custom' => true, 'permissions' => ['checkin.perform']])->assertForbidden();
        // And the account of someone who opens more is out of reach.
        $finance = $this->staff($club, 'finance');
        $financeEmployee = $this->employee($club, ['name' => 'محاسب', 'user_id' => $finance->id]);
        $this->actingAs($hr)->post(route('club.employees.password', $financeEmployee))->assertForbidden();
    }

    public function test_a_new_sign_in_account_is_opened_from_the_file_and_its_password_shown_once(): void
    {
        $club = $this->club();
        $receptionRole = $this->asTenant($club, fn () => Role::where('key', 'reception')->value('id'));

        $this->actingAs($this->owner($club))->post(route('club.employees.store'), [
            'name' => 'منى', 'status' => 'active', 'basic_salary' => '300', 'payment_method' => 'cash',
            'account_mode' => 'new', 'account_email' => 'Mona@Club.test', 'account_role_id' => $receptionRole,
        ])->assertSessionHasNoErrors()->assertSessionHas('password');
        $password = session('password');
        $employee = $this->asTenant($club, fn () => Employee::where('name', 'منى')->firstOrFail());
        $user = User::findOrFail($employee->user_id);
        $this->assertSame(['mona@club.test', $receptionRole, $club->id], [$user->email, $user->role_id, $user->tenant_id]);
        $this->assertTrue(Hash::check($password, $user->password));

        $this->actingAs($this->owner($club))->post(route('club.employees.password', $employee))->assertSessionHas('password');
        $this->assertFalse(Hash::check($password, $user->fresh()->password));
    }

    public function test_attendance_is_corrected_only_with_its_permission_and_never_twice_over(): void
    {
        $club = $this->club();
        $user = $this->staff($club, 'reception');
        $employee = $this->employee($club, ['user_id' => $user->id]);
        $entry = ['date' => now()->subDay()->toDateString(), 'in' => '08:00', 'out' => '16:00', 'notes' => 'نسي البصمة'];

        $this->actingAs($this->holding($club, ['employees.view', 'staff_attendance.view']))->post(route('club.employees.attendance', $employee), $entry)->assertForbidden();

        $supervisor = $this->holding($club, ['employees.view', 'staff_attendance.view', 'staff_attendance.manage']);
        $this->actingAs($supervisor)->post(route('club.employees.attendance', $employee), $entry)->assertSessionHasNoErrors();
        $this->actingAs($supervisor)->post(route('club.employees.attendance', $employee), ['in' => '10:00', 'out' => '12:00'] + $entry)->assertSessionHasErrors('in');
        $this->asTenant($club, fn () => $this->assertSame(1, StaffAttendance::where('user_id', $user->id)->where('method', 'correction')->count()));
        $this->actingAs($supervisor)->get(route('club.employees.show', $employee))->assertInertia(fn ($p) => $p->where('attendance.0.in', '08:00')->where('attendance.0.out', '16:00')->where('attendance.0.notes', 'نسي البصمة'));
    }

    public function test_salary_values_in_the_history_reach_only_whoever_reads_salaries(): void
    {
        $club = $this->club();
        $employee = $this->employee($club);
        // An entry written the old way, with the values in it.
        $this->asTenant($club, fn () => DB::table('audit_logs')->insert([
            'tenant_id' => $club->id, 'actor_type' => 'user', 'event' => 'employee.updated', 'subject_type' => 'Employee', 'subject_id' => $employee->id,
            'old_values' => json_encode(['basic_salary' => '350.000']), 'new_values' => json_encode(['basic_salary' => '400.000']), 'created_at' => now(),
        ]));

        $manager = $this->staff($club, 'manager');
        $this->actingAs($manager)->get(route('club.audit.index'))->assertInertia(fn ($p) => $p->where('logs.data.0.new_values.basic_salary', '•••'));
        $this->actingAs($manager)->get(route('club.employees.show', $employee))->assertInertia(fn ($p) => $p->where('history.0.new.basic_salary', '•••'));
        $this->actingAs($this->owner($club))->get(route('club.employees.show', $employee))->assertInertia(fn ($p) => $p->where('history.0.new.basic_salary', '400.000'));
    }
}
