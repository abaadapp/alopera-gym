<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clubs that already exist get the new accounting and payroll screens for their system roles, as new clubs do
 * (SystemRoles). Only adds: nothing a role already holds is taken away. The owner ("*") needs nothing.
 */
return new class extends Migration
{
    private const GRANTS = [
        'manager' => ['accounting.view', 'employees.view', 'employees.manage', 'payroll.prepare', 'payroll.reports'],
        'finance' => ['accounting.view', 'accounting.manage', 'employees.view', 'payroll.salaries', 'payroll.prepare', 'payroll.pay', 'payroll.reports'],
    ];

    public function up(): void
    {
        foreach (self::GRANTS as $key => $grants) {
            DB::table('roles')->where('key', $key)->where('is_system', true)->orderBy('id')->each(function ($role) use ($grants) {
                $current = json_decode($role->permissions, true) ?: [];
                if (in_array('*', $current, true)) {
                    return;
                }
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode(array_values(array_unique([...$current, ...$grants])))]);
            });
        }
    }

    public function down(): void
    {
        // Additive only; removing would also strip grants a club made by hand.
    }
};
