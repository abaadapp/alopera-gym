<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Suppliers get a view and an add permission of their own beside suppliers.manage (which keeps editing, stopping and
 * deleting). Whoever held suppliers.manage could already do both, so each role — and each person with their own
 * permission list — that holds it gets them; nobody loses or gains an ability by this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $grant = fn (array $current) => in_array('suppliers.manage', $current, true)
            ? array_values(array_unique([...$current, 'suppliers.view', 'suppliers.create']))
            : $current;

        DB::table('roles')->orderBy('id')->each(function ($role) use ($grant) {
            $current = json_decode($role->permissions ?? '[]', true) ?: [];
            if (! in_array('*', $current, true) && $grant($current) !== $current) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($grant($current))]);
            }
        });
        DB::table('users')->whereNotNull('custom_permissions')->orderBy('id')->each(function ($user) use ($grant) {
            $current = json_decode($user->custom_permissions, true) ?: [];
            if ($grant($current) !== $current) {
                DB::table('users')->where('id', $user->id)->update(['custom_permissions' => json_encode($grant($current))]);
            }
        });
    }

    public function down(): void
    {
        // Additive grants, not told apart from ones made by hand afterwards: nothing to undo.
    }
};
