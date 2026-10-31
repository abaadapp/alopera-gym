<?php

use App\Core\Auth\SystemRoles;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * The gym's own roles and two new permissions, without anyone losing or gaining an ability they had:
 * - papers.print (printing and PDF) goes to every role and personal permission list — everyone could print before;
 * - pt.sell (selling training packages, now also at the point of sale) goes to whoever held pt.manage;
 * - each gym gets the «موظف المبيعات ونقطة البيع» role if it has none;
 * - system roles still carrying their old default name get the new one (a name changed by hand is kept).
 */
return new class extends Migration
{
    private const RENAMED = [
        SystemRoles::OWNER => ['مالك النادي', 'صاحب النشاط'],
        SystemRoles::MANAGER => ['الإدارة', 'مدير النشاط أو الفرع'],
        SystemRoles::FINANCE => ['المالية', 'المالية والمحاسبة'],
    ];

    public function up(): void
    {
        $grant = function (array $current): array {
            $next = [...$current, 'papers.print'];
            if (in_array('pt.manage', $current, true)) {
                $next[] = 'pt.sell';
            }

            return array_values(array_unique($next));
        };

        DB::table('roles')->orderBy('id')->each(function ($role) use ($grant) {
            $current = json_decode($role->permissions ?? '[]', true) ?: [];
            if (! in_array('*', $current, true) && $role->key !== SystemRoles::TRAINER && $grant($current) !== $current) {
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($grant($current))]);
            }
        });
        DB::table('users')->whereNotNull('custom_permissions')->orderBy('id')->each(function ($user) use ($grant) {
            $current = json_decode($user->custom_permissions, true) ?: [];
            if ($grant($current) !== $current) {
                DB::table('users')->where('id', $user->id)->update(['custom_permissions' => json_encode($grant($current))]);
            }
        });

        foreach (self::RENAMED as $key => [$old, $new]) {
            DB::table('roles')->where('key', $key)->where('is_system', true)->where('name', $old)->update(['name' => $new]);
        }

        $cashier = SystemRoles::definitions()[SystemRoles::CASHIER];
        $have = DB::table('roles')->where('key', SystemRoles::CASHIER)->pluck('tenant_id')->all();
        DB::table('tenants')->whereNotIn('id', $have)->orderBy('id')->pluck('id')->each(fn ($tenantId) => DB::table('roles')->insert([
            'tenant_id' => $tenantId, 'key' => SystemRoles::CASHIER, 'name' => $cashier['name'], 'description' => $cashier['description'],
            'permissions' => json_encode($cashier['permissions']), 'is_system' => true, 'is_locked' => false, 'created_at' => now(), 'updated_at' => now(),
        ]));
    }

    public function down(): void
    {
        // Additive grants and a role that may already hold staff: nothing is taken back.
    }
};
