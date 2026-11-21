<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Payment links v2, design B (owner's decision, 2026-11-21) — adds only, nothing dropped or rewritten.
 *
 * payment_links keeps one row per invoice (its unique key stays): the invoice's current link — live, past its time, or
 * cancelled — as the code before (v0.139) reads it. What this adds to it is what only a v2 link has: its own secure
 * address (token), when it was issued (issued_at), what the invoice owed then (amount), and the Paymob references the
 * staff confirmed when it was cancelled (paymob_checked). Rows made before have none of these: their address stays
 * their invoice's pay page, as sent.
 *
 * payment_link_history keeps each earlier link of an invoice, written when a new link takes the row's place: its
 * address, what it asked, when it was issued, until when it ran, and how it ended (cancelled or replaced, when and by
 * whom). It is written once and never changed. The code before neither reads nor writes it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payment_links', function (Blueprint $table) {
            $table->string('token', 64)->nullable()->unique();
            $table->timestamp('issued_at')->nullable();
            $table->decimal('amount', 12, 3)->nullable();
            $table->json('paymob_checked')->nullable();
        });

        Schema::create('payment_link_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // Not cascaded: an issued invoice is voided, never deleted, and a link row is never deleted. A delete that does
            // not know this table — the code before v2 restoring a backup — is refused, the whole of it, rather than
            // taking the earlier links with it. A club removed still takes them (tenant_id).
            $table->foreignId('invoice_id')->constrained('invoices');
            $table->foreignId('payment_link_id')->constrained('payment_links');
            $table->string('token', 64)->nullable()->unique();
            $table->string('description', 250);
            $table->decimal('amount', 12, 3)->nullable();
            $table->decimal('balance_at_end', 12, 3)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('issued_at');
            $table->timestamp('expires_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('replaced_at')->nullable();
            $table->foreignId('replaced_by')->nullable()->constrained('users')->nullOnDelete();
            $table->json('paymob_checked')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'id']);
            $table->index('invoice_id');
        });
    }

    /**
     * Refused once a v2 link was made or ended: going back would lose where those links lead and every earlier link.
     * The code can go back without this (scripts/rollback-code.sh leaves the tables in place, and the code before reads
     * payment_links as it always did).
     */
    public function down(): void
    {
        if (DB::table('payment_link_history')->exists() || DB::table('payment_links')->whereNotNull('token')->exists()) {
            throw new RuntimeException('payment_link_history: v2 links exist; dropping it (or payment_links.token) would lose them. Roll the code back only (scripts/rollback-code.sh).');
        }
        Schema::dropIfExists('payment_link_history');
        Schema::table('payment_links', function (Blueprint $table) {
            $table->dropUnique(['token']);
            $table->dropColumn(['token', 'issued_at', 'amount', 'paymob_checked']);
        });
    }
};
