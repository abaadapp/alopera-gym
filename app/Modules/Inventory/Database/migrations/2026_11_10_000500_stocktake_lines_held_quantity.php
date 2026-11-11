<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What website orders held on the shelf when a count line was counted (#132): those units are off the book but still
 * on the shelf, so the count expects book + held. Existing lines start at 0, which is how they were computed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stocktake_lines', function (Blueprint $table) {
            $table->decimal('held_quantity', 12, 3)->default(0)->after('system_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('stocktake_lines', function (Blueprint $table) {
            $table->dropColumn('held_quantity');
        });
    }
};
