<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gift memberships (#192): an existing plan bought for someone else. The gift is paid through its own invoice (an
 * ordinary one, item `gift_membership`) and becomes redeemable only when InvoicePaid reaches ActivatePaidItems. It is
 * redeemed once, into a membership sold to the recipient with a zero invoice, so activation keeps its only path.
 * The link's token is looked up by its SHA-256 (unique) and kept encrypted, for staff allowed to share it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gym_gift_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 30);
            $table->foreignId('membership_plan_id')->constrained('gym_membership_plans')->restrictOnDelete();
            $table->json('plan_snapshot');
            $table->decimal('price', 12, 3);
            $table->string('source', 10); // site | staff
            $table->string('giver_name', 120);
            $table->string('giver_phone', 30)->nullable();
            $table->string('giver_email', 190)->nullable();
            $table->string('recipient_name', 120);
            // Optional: when the giver gives it, only an account with this number redeems the gift from its link.
            // The recipient's WhatsApp number (Phone::normalize), required: the gift is sent there once paid. It is
            // confirmed only by the recipient's own message from it (GiftWhatsApp): the code's hash finds the gift, the
            // code is kept encrypted to show again, and the inbound message is the evidence (phone_verified_message_id).
            $table->string('recipient_phone', 30);
            $table->text('phone_verify_code')->nullable();
            $table->char('phone_verify_hash', 64)->nullable()->unique();
            $table->timestamp('phone_verify_expires_at')->nullable();
            $table->timestamp('phone_verified_at')->nullable();
            $table->foreignId('phone_verified_message_id')->nullable()->constrained('messages')->nullOnDelete();
            // The gift's WhatsApp message (the latest attempt) and how many were queued: each attempt has its own
            // dedupe key, so a webhook replayed or a nightly run never sends it twice.
            $table->foreignId('whatsapp_message_id')->nullable()->constrained('messages')->nullOnDelete();
            $table->unsignedSmallInteger('whatsapp_attempts')->default(0);
            $table->string('message', 300)->nullable();
            $table->foreignId('invoice_id')->nullable()->unique()->constrained('invoices')->restrictOnDelete();
            $table->string('status', 20)->default('pending');
            $table->char('token_hash', 64)->unique();
            $table->text('token');
            $table->timestamp('paid_at')->nullable();
            $table->date('expires_on')->nullable();
            $table->timestamp('redeemed_at')->nullable();
            $table->foreignId('member_id')->nullable()->constrained('gym_members')->restrictOnDelete();
            // One membership per gift and one gift per membership.
            $table->foreignId('membership_id')->nullable()->unique()->constrained('gym_memberships')->restrictOnDelete();
            $table->string('redeemed_via', 10)->nullable(); // link | staff
            $table->foreignId('redeemed_by')->nullable()->constrained('users')->nullOnDelete();
            // Expired: its window passed unredeemed (gym:expire-gifts). Refunded: finance credited its invoice
            // (refund_note_id), so it can no longer be used. Both close the gift in the books (LedgerProjector::gift).
            $table->timestamp('expired_at')->nullable();
            $table->timestamp('refunded_at')->nullable();
            $table->foreignId('refund_note_id')->nullable()->constrained('credit_notes')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason', 250)->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('gym_membership_plans', function (Blueprint $table) {
            $table->boolean('giftable')->default(true);
        });
    }

    public function down(): void
    {
        Schema::table('gym_membership_plans', fn (Blueprint $table) => $table->dropColumn('giftable'));
        Schema::dropIfExists('gym_gift_memberships');
    }
};
