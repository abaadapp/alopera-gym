<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The member's preferred language for WhatsApp messages (owner's decision, 2026-11-21): «ar» or «en». Adds only; every
 * member there is takes Arabic, as every message was until now. A template not approved in the member's language falls
 * back to the approved Arabic one (InvoiceMessage).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gym_members', function (Blueprint $table) {
            $table->string('message_language', 2)->default('ar');
        });
    }

    public function down(): void
    {
        Schema::table('gym_members', function (Blueprint $table) {
            $table->dropColumn('message_language');
        });
    }
};
