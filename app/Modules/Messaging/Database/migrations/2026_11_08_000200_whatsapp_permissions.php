<?php

use App\Modules\Messaging\Support\WhatsAppPermissionUpgrade;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The inbox's two permissions become nine (WhatsAppPermissionUpgrade says how each stored list is read): every role
 * and every person's own list is rewritten once. The owner keeps "*".
 */
return new class extends Migration
{
    public function up(): void
    {
        $keys = DB::table('roles')->pluck('key', 'id');

        DB::table('roles')->orderBy('id')->each(function ($role) {
            $current = json_decode($role->permissions ?? '[]', true) ?: [];
            $next = WhatsAppPermissionUpgrade::upgrade($current, $role->key);
            if ($next !== $current) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($next)]);
            }
        });

        DB::table('users')->whereNotNull('custom_permissions')->orderBy('id')->each(function ($user) use ($keys) {
            $current = json_decode($user->custom_permissions, true) ?: [];
            $next = WhatsAppPermissionUpgrade::upgrade($current, $keys[$user->role_id] ?? null);
            if ($next !== $current) {
                DB::table('users')->where('id', $user->id)->update(['custom_permissions' => json_encode($next)]);
            }
        });
    }

    public function down(): void
    {
        // The new lists are kept: the old keys no longer exist in the registry.
    }
};
