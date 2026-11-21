<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A payment link made inside Paymob (QuickLink, owner's decision 2026-11-21) is cancelled there by its own id: kept on
 * the attempt that carries its order. Adds only; every earlier attempt has none.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->string('provider_link_id', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('payment_intents', function (Blueprint $table) {
            $table->dropColumn('provider_link_id');
        });
    }
};
