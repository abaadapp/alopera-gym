<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clubs that already exist get the inventory, purchasing and point-of-sale device permissions for their system
 * roles, as new clubs do (SystemRoles): the management runs stock and purchasing and approves them, finance sees
 * them and pays suppliers. Only adds; the owner ("*") needs nothing.
 */
return new class extends Migration
{
    private const GRANTS = [
        'manager' => ['inventory.view', 'inventory.adjust', 'inventory.transfer', 'inventory.approve', 'purchases.view', 'purchases.manage', 'purchases.approve', 'purchases.receive', 'suppliers.manage', 'pos_devices.manage'],
        'finance' => ['inventory.view', 'purchases.view', 'purchases.pay'],
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
