<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment links v2, part one — adds only (owner's decision M1, 2026-11-20): each link its own secure address (token),
 * the moment a newer link took its place (replaced_at), and «one live link per invoice» as a partial unique index (not
 * cancelled, not replaced). The rows made so far have no token: their address stays their invoice's pay page, as sent.
 * Every row there is today is the only one of its invoice (the unique key the next migration lifts), so the new index
 * holds for them as they are; nothing is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_links', function (Blueprint $table) {
            $table->string('token', 64)->nullable()->unique();
            $table->timestamp('replaced_at')->nullable();
            // Each link of an invoice, once an invoice may have several (the next migration).
            $table->index('invoice_id');
        });

        DB::statement('CREATE UNIQUE INDEX payment_links_one_live_per_invoice ON payment_links (invoice_id) WHERE cancelled_at IS NULL AND replaced_at IS NULL');
    }

    /**
     * Refused once a link was made with its own address or replaced: going back would lose where those links lead and
     * which were replaced. The code can go back without this (scripts/rollback-code.sh leaves the columns in place).
     */
    public function down(): void
    {
        if (DB::table('payment_links')->whereNotNull('token')->orWhereNotNull('replaced_at')->exists()) {
            throw new RuntimeException('payment_links: links with their own address or replaced exist; dropping token/replaced_at would lose them. Roll the code back only (scripts/rollback-code.sh).');
        }
        DB::statement('DROP INDEX IF EXISTS payment_links_one_live_per_invoice');
        Schema::table('payment_links', function (Blueprint $table) {
            $table->dropIndex(['invoice_id']);
            $table->dropUnique(['token']);
            $table->dropColumn(['token', 'replaced_at']);
        });
    }
};
