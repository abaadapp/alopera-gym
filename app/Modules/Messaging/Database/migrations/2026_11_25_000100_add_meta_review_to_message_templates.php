<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * «رسائل الواتساب» in the settings (owner's decision, 2026-11-25): a message's text is edited here and sent to Meta for
 * review at once. Adds only: the template's id at Meta, why Meta refused it, when it was last sent for review, and when
 * its state was last read from Meta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->string('meta_id', 40)->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('meta_synced_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('message_templates', function (Blueprint $table) {
            $table->dropColumn(['meta_id', 'rejection_reason', 'submitted_at', 'meta_synced_at']);
        });
    }
};
