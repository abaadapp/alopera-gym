<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An add-on billed on a manual invoice is a line of its own (its price, its quantity, the add-on it was) under the
 * product line it was sold with: parent_id is that line. Empty for every line before it and for every other line.
 * Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->after('invoice_id')->constrained('invoice_items')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', fn (Blueprint $table) => $table->dropConstrainedForeignId('parent_id'));
    }
};
