<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A program's price on a customer invoice (owner's decision, 2026-11-21): adds only. Programs made before have none
 * (null) and are not billed until one is set, or priced on the invoice by whoever manages programs (classes.manage).
 * Selling a program is not booking a class: nothing here reads or writes a booking.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gym_programs', function (Blueprint $table) {
            $table->decimal('price', 12, 3)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gym_programs', function (Blueprint $table) {
            $table->dropColumn('price');
        });
    }
};
