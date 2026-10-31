<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * website.manage was the owner's alone: a copy stored on another role or personal list was ignored. It can now be
 * granted by name, so those stored copies — never in effect — are cleared first; otherwise they would switch on by
 * themselves. Nobody gains or loses access by this migration; the owner keeps it through "*".
 */
return new class extends Migration
{
    public function up(): void
    {
        $strip = fn (array $current) => array_values(array_diff($current, ['website.manage']));

        DB::table('roles')->orderBy('id')->each(function ($role) use ($strip) {
            $current = json_decode($role->permissions ?? '[]', true) ?: [];
            if (in_array('website.manage', $current, true)) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($strip($current))]);
            }
        });
        DB::table('users')->whereNotNull('custom_permissions')->orderBy('id')->each(function ($user) use ($strip) {
            $current = json_decode($user->custom_permissions, true) ?: [];
            if (in_array('website.manage', $current, true)) {
                DB::table('users')->where('id', $user->id)->update(['custom_permissions' => json_encode($strip($current))]);
            }
        });
    }

    public function down(): void
    {
        // The cleared copies were never in effect: nothing to restore.
    }
};
