<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One form, one result, kept in the database (SubmittedOnce): the key a form was sent with and what its work returned.
 * The row is written in the work's own transaction, so only a submission that went through leaves one, and a cleared
 * cache or a worker stopped after the commit cannot make a retry record the payment twice.
 * A new table; nothing existing is changed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submitted_once_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('scope', 120);
            $table->string('client_key', 64);
            $table->json('result')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'scope', 'client_key']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submitted_once_keys');
    }
};
