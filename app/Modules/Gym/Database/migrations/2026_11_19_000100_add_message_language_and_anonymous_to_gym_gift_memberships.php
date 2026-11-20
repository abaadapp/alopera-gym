<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The gift's WhatsApp language, chosen by the giver (ar|en), and whether the giver's name is hidden from the recipient.
 * Both only added, with the defaults every gift so far was sent with: Arabic, the giver's name shown. The giver's real
 * name stays in giver_name (the invoice, the panel, the audit trail); `message` stays for the gifts that have one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gym_gift_memberships', function (Blueprint $table) {
            $table->string('message_language', 2)->default('ar');
            $table->boolean('is_anonymous')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('gym_gift_memberships', function (Blueprint $table) {
            $table->dropColumn(['message_language', 'is_anonymous']);
        });
    }
};
