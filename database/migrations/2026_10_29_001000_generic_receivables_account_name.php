<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/** The members' receivables account gets its generic name, where it still carries the built-in one. */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('gym_accounts')->where('system_key', 'receivable')->where('name', 'ذمم المشتركات')->update(['name' => 'ذمم الأعضاء']);
    }

    public function down(): void
    {
        // Wording only.
    }
};
