<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Closing a PT session before its start time: allowed to managers, with a reason, and recorded. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gym_pt_sessions', function (Blueprint $table) {
            $table->timestamp('closed_at')->nullable();
            $table->boolean('closed_early')->default(false);
            $table->string('early_reason')->nullable();
        });

        foreach (DB::table('roles')->where('is_system', true)->where('key', 'manager')->get(['id', 'permissions']) as $role) {
            $permissions = json_decode($role->permissions, true) ?: [];
            if (! in_array('pt.close_early', $permissions, true)) {
                $permissions[] = 'pt.close_early';
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('gym_pt_sessions', fn (Blueprint $table) => $table->dropColumn(['closed_at', 'closed_early', 'early_reason']));
    }
};
