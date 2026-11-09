<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A class made from the weekly timetable keeps the slot it was made for (slot_starts_at), whatever time it is moved
 * to: the timetable finds its occurrences by that slot, so a class moved from 18:00 to 19:00 is not made again at
 * 18:00. A new column and index; the existing sessions get their current time as their slot. Nothing is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gym_class_sessions', function (Blueprint $table) {
            $table->timestamp('slot_starts_at')->nullable()->after('starts_at');
            $table->unique(['class_schedule_id', 'slot_starts_at']);
        });

        DB::table('gym_class_sessions')->whereNotNull('class_schedule_id')->update(['slot_starts_at' => DB::raw('starts_at')]);
    }

    public function down(): void
    {
        Schema::table('gym_class_sessions', function (Blueprint $table) {
            $table->dropUnique(['class_schedule_id', 'slot_starts_at']);
            $table->dropColumn('slot_starts_at');
        });
    }
};
