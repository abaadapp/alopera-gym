<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A membership bought online and not paid yet (owner's decision, 2026-11-24: the sale happens after the payment). While
 * the member pays, only this row and an unnumbered draft invoice exist (the amount Paymob is asked for): no membership,
 * no number, nothing owed, no message. Paymob's confirmed payment makes the sale (MembershipCheckouts::complete).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gym_membership_checkouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('member_id')->constrained('gym_members')->cascadeOnDelete();
            $table->foreignId('membership_plan_id')->constrained('gym_membership_plans')->restrictOnDelete();
            $table->string('kind', 10); // new | renewal (as MembershipService sells or renews)
            $table->string('channel', 10); // website | portal | link (a payment link reception sent)
            $table->string('coupon_code', 40)->nullable();
            // What reception chose with a payment link: a manual discount, a start date, a note, the branch it sells in.
            $table->json('options')->nullable();
            // A payment link's address (link channel): opens the page that pays it, nothing else.
            $table->string('token', 48)->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // The unnumbered draft while open; the sale's invoice once completed.
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->nullOnDelete();
            $table->foreignId('membership_id')->nullable()->constrained('gym_memberships')->nullOnDelete();
            $table->string('status', 12)->default('open'); // open | completed | abandoned
            $table->string('problem', 40)->nullable(); // why the money was held for review on completion
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->index(['tenant_id', 'member_id', 'status']);
            $table->unique('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gym_membership_checkouts');
    }
};
