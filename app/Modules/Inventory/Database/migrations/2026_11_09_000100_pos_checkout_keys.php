<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One basket, one sale, kept in the database: the key a till basket is sent with, what the basket held (a fingerprint)
 * and the documents it produced. Only a sale that went through leaves a row (it is written in the sale's transaction).
 * A new table; nothing existing is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_checkout_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('client_key', 64);
            $table->string('fingerprint', 64);
            $table->json('documents')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'client_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_checkout_keys');
    }
};
