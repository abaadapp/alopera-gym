<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Clubs that already exist get the documents section for their management role, as new clubs do
 * (SystemRoles). Confidential documents stay with the owner. Only adds; the owner ("*") needs nothing.
 */
return new class extends Migration
{
    private const GRANTS = [
        'manager' => ['documents.view', 'documents.create', 'documents.renew', 'documents.manage'],
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
