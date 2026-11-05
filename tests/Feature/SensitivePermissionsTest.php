<?php

namespace Tests\Feature;

use App\Core\Auth\PermissionRegistry;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Payroll\Models\Employee;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The sensitive permissions (salaries and bank data, paying and undoing money, the books, stock approvals, supplier
 * purchases, exports, accounts and roles, branches, integrations, backups) are the owner's to give and to take, on the
 * server: someone who holds one may use it, not hand it on or strip it, through a role or a person's own list.
 */
class SensitivePermissionsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function role(Tenant $club, array $permissions): Role
    {
        return $this->asTenant($club, fn () => Role::create(['key' => 'custom_'.Str::lower(Str::random(6)), 'name' => 'دور '.Str::random(4), 'permissions' => $permissions]));
    }

    private function holding(Tenant $club, array $permissions): User
    {
        $user = $this->staff($club, 'reception');
        $user->forceFill(['role_id' => $this->role($club, $permissions)->id])->save();

        return $user->fresh();
    }

    private function employee(Tenant $club, array $attributes = []): Employee
    {
        return $this->asTenant($club, fn () => Employee::create($attributes + ['name' => 'سارة', 'basic_salary' => '400.000', 'status' => 'active']));
    }

    public function test_every_sensitive_permission_is_a_real_one(): void
    {
        $this->assertSame([], array_diff(PermissionRegistry::SENSITIVE, PermissionRegistry::all()));
        foreach (['payroll.salaries', 'payroll.pay', 'accounting.manage', 'accounting.close', 'invoices.void', 'inventory.approve', 'purchases.pay', 'reports.export', 'roles.manage', 'branches.manage', 'integrations.manage', 'backups.manage'] as $key) {
            $this->assertContains($key, PermissionRegistry::sensitive());
        }
        $this->assertNotContains('members.view', PermissionRegistry::sensitive());
        // Each sensitive one says in a line what it lets its holder do, and that line reaches the editors.
        $this->assertSame([], array_diff(PermissionRegistry::SENSITIVE, array_keys(PermissionRegistry::DESCRIPTIONS)));
        $this->assertSame([], array_diff(array_keys(PermissionRegistry::DESCRIPTIONS), PermissionRegistry::all()));
        $hints = collect(PermissionRegistry::editorPayload())->flatMap(fn ($g) => $g['permissions'])->pluck('hint', 'key');
        $this->assertSame(PermissionRegistry::DESCRIPTIONS['payroll.pay'], $hints['payroll.pay']);
    }

    public function test_a_role_editor_holding_a_sensitive_permission_neither_gives_nor_takes_it(): void
    {
        $club = $this->club();
        $editor = $this->holding($club, ['roles.manage', 'members.view', 'checkin.perform', 'invoices.void', 'reports.export']);

        $this->actingAs($editor)->post(route('club.roles.store'), ['name' => 'محاولة', 'permissions' => ['members.view', 'invoices.void']])->assertSessionHasErrors('permissions');
        $this->actingAs($editor)->post(route('club.roles.store'), ['name' => 'استقبال', 'permissions' => ['members.view']])->assertSessionHasNoErrors();

        $role = $this->role($club, ['members.view', 'reports.export']);
        $this->actingAs($editor)->put(route('club.roles.update', $role), ['name' => $role->name, 'permissions' => ['checkin.perform', 'invoices.void']])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['reports.export', 'checkin.perform'], $role->fresh()->permissions, 'reports.export kept, invoices.void not given');

        $owner = $this->owner($club);
        $this->actingAs($owner)->put(route('club.roles.update', $role), ['name' => $role->name, 'permissions' => ['checkin.perform', 'invoices.void']])->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(['checkin.perform', 'invoices.void'], $role->fresh()->permissions);
    }

    public function test_a_role_carrying_a_sensitive_permission_is_not_given_by_someone_else_holding_it(): void
    {
        $club = $this->club();
        $actor = $this->holding($club, ['employees.view', 'employees.access', 'checkin.perform', 'members.view', 'reports.export']);
        $user = $this->holding($club, ['members.view']);
        $employee = $this->employee($club, ['user_id' => $user->id]);
        $exporting = $this->role($club, ['members.view', 'reports.export']);
        $plain = $this->role($club, ['members.view', 'checkin.perform']);

        $this->actingAs($actor)->put(route('club.employees.access', $employee), ['role_id' => $exporting->id, 'is_active' => true, 'custom' => false])->assertRedirect()->assertSessionHasErrors('role_id');
        $this->assertNotSame($exporting->id, $user->fresh()->role_id);
        $this->actingAs($actor)->put(route('club.employees.access', $employee), ['role_id' => $plain->id, 'is_active' => true, 'custom' => false])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame($plain->id, $user->fresh()->role_id);
        $this->actingAs($this->owner($club))->put(route('club.employees.access', $employee), ['role_id' => $exporting->id, 'is_active' => true, 'custom' => false])->assertSessionHasNoErrors();
        $this->assertSame($exporting->id, $user->fresh()->role_id);
    }

    public function test_a_persons_own_list_takes_sensitive_permissions_from_the_owner_only(): void
    {
        $club = $this->club();
        $actor = $this->holding($club, ['employees.view', 'employees.access', 'checkin.perform', 'members.view', 'reports.export']);
        $user = $this->holding($club, ['members.view']);
        $employee = $this->employee($club, ['user_id' => $user->id]);
        $role = $user->role_id;

        $this->actingAs($actor)->put(route('club.employees.access', $employee), ['role_id' => $role, 'is_active' => true, 'custom' => true, 'permissions' => ['checkin.perform', 'reports.export']])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotContains('reports.export', $user->fresh()->permissions());
        $this->assertContains('checkin.perform', $user->fresh()->permissions(), 'the ordinary one given, the sensitive one not');

        $this->actingAs($this->owner($club))->put(route('club.employees.access', $employee), ['role_id' => $role, 'is_active' => true, 'custom' => true, 'permissions' => ['checkin.perform', 'reports.export']])->assertSessionHasNoErrors();
        $this->assertContains('reports.export', $user->fresh()->permissions());
    }
}
