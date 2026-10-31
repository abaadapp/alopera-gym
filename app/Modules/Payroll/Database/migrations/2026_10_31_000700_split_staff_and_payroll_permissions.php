<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finer staff and payroll permissions, and per-person permissions.
 *
 * Each new permission is given to the roles that could already do that thing under the broader permission it was
 * split from, so nobody loses or gains an ability by this migration:
 *
 *   employees.create        ← employees.manage (adding a file was part of managing files)
 *   employees.access        ← employees.manage + users.manage (sign-in accounts were the users screen's)
 *   payroll.compensation    ← employees.manage + payroll.salaries (changing a salary needed both)
 *   payroll.edit            ← payroll.prepare (editing a draft was part of preparing it)
 *   payroll.payslips        ← any payroll permission (each could open the printed run and payslips)
 *   papers.manage           ← settings.manage (the invoice footer was a club setting)
 *   staff_attendance.manage ← the manager system role only (new ability)
 *
 * users.custom_permissions: a list that replaces the role's for one person (null = follow the role). Only adds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('custom_permissions')->nullable()->after('role_id');
        });

        DB::table('roles')->orderBy('id')->each(function ($role) {
            $current = json_decode($role->permissions ?? '[]', true) ?: [];
            if (in_array('*', $current, true)) {
                return;
            }
            $has = fn (string $p) => in_array($p, $current, true);
            $add = [];
            if ($has('employees.manage')) {
                $add[] = 'employees.create';
            }
            if ($has('employees.manage') && $has('users.manage')) {
                $add[] = 'employees.access';
            }
            if ($has('employees.manage') && $has('payroll.salaries')) {
                $add[] = 'payroll.compensation';
            }
            if ($has('payroll.prepare')) {
                $add[] = 'payroll.edit';
            }
            if ($has('payroll.prepare') || $has('payroll.approve') || $has('payroll.pay') || $has('payroll.reports')) {
                $add[] = 'payroll.payslips';
            }
            if ($has('settings.manage')) {
                $add[] = 'papers.manage';
            }
            if ($role->key === 'manager' && $role->is_system) {
                $add[] = 'staff_attendance.manage';
            }
            if ($add !== []) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique([...$current, ...$add])))]);
            }
        });
    }

    public function down(): void
    {
        // The grants are additive and cannot be told apart from ones made by hand afterwards; only the column goes.
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('custom_permissions'));
    }
};
