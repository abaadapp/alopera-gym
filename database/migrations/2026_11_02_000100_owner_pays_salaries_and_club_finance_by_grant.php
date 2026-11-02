<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Who pays salaries and who opens the whole club's money, for a club that already exists (as new clubs get them in
 * SystemRoles):
 *
 *   payroll.pay              taken off the finance system role: approving and paying salaries are the owner's until the
 *                            owner grants either one by name (on the role, or on one person's own list).
 *   cash_shifts.view         given to the finance system role: the cash closing report is one of the financial reports
 *                            finance reads; acting on shifts (cash_shifts.manage) stays the owner's.
 *   accounting.all_branches  new: someone tied to a branch now opens the club's books, banks and payroll runs only with
 *                            it. Given to every role and own list that sees the books today (accounting.view), so
 *                            nobody loses that view by this migration, except the manager system role: a manager tied
 *                            to a branch keeps their branch's figures (the reports), not the club's banks.
 *
 * Users reaching every branch see what they saw before. Custom roles and own lists change only by the added
 * accounting.all_branches.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->orderBy('id')->each(function ($role) {
            $current = json_decode($role->permissions ?? '[]', true) ?: [];
            if (in_array('*', $current, true)) {
                return;
            }
            $next = $current;
            if ($role->key === 'finance' && $role->is_system) {
                $next = [...array_diff($next, ['payroll.pay']), 'cash_shifts.view'];
            }
            if (in_array('accounting.view', $next, true) && ! ($role->key === 'manager' && $role->is_system)) {
                $next[] = 'accounting.all_branches';
            }
            $next = array_values(array_unique($next));
            if ($next !== $current) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($next)]);
            }
        });

        DB::table('users')->whereNotNull('custom_permissions')->orderBy('id')->each(function ($user) {
            $current = json_decode($user->custom_permissions, true) ?: [];
            if (in_array('accounting.view', $current, true) && ! in_array('accounting.all_branches', $current, true)) {
                DB::table('users')->where('id', $user->id)->update(['custom_permissions' => json_encode([...$current, 'accounting.all_branches'])]);
            }
        });
    }

    public function down(): void
    {
        $strip = fn (array $list) => array_values(array_diff($list, ['accounting.all_branches']));

        DB::table('roles')->orderBy('id')->each(function ($role) use ($strip) {
            $current = json_decode($role->permissions ?? '[]', true) ?: [];
            if (in_array('*', $current, true)) {
                return;
            }
            $next = $strip($current);
            if ($role->key === 'finance' && $role->is_system) {
                $next = array_values(array_unique([...array_diff($next, ['cash_shifts.view']), 'payroll.pay']));
            }
            DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($next)]);
        });
        DB::table('users')->whereNotNull('custom_permissions')->orderBy('id')->each(function ($user) use ($strip) {
            $current = json_decode($user->custom_permissions, true) ?: [];
            DB::table('users')->where('id', $user->id)->update(['custom_permissions' => json_encode($strip($current))]);
        });
    }
};
