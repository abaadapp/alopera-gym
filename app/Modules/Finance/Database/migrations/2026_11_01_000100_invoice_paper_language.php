<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The face an invoice's paper prints in when it was chosen in the editor: «ar» (Arabic first, right to left) or «en»
 * (English first, left to right). Empty for every invoice before it and for invoices issued elsewhere: those print as
 * the invoice template says. Additive only.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('paper_lang', 2)->nullable()->after('internal_notes');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('paper_lang'));
    }
};
