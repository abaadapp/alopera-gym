<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Paymob money confirmed (a signed, live, captured success) that could not be recorded as its invoice's payment: the
 * amount, the currency, the signed order or the integration does not match. Nothing here is money in the books — it is
 * what staff need to look it up at Paymob and decide; the books change only through the existing payment screens.
 * One row per Paymob transaction, however often the callback comes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            // As payment_intents: an issued invoice is voided, never deleted; a club removed takes its rows with it.
            $table->foreignId('payment_intent_id')->constrained('payment_intents')->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained('invoices')->cascadeOnDelete();
            $table->string('gateway', 20);
            $table->string('transaction_id', 64);
            $table->string('provider_order_id', 64)->nullable();
            $table->string('integration_id', 32)->nullable();
            $table->string('reason', 20); // amount | currency | order | integration
            $table->bigInteger('expected_minor');
            $table->bigInteger('received_minor')->nullable();
            $table->string('expected_currency', 3);
            $table->string('received_currency', 8)->nullable();
            // The card's network as Paymob names it (Visa, MasterCard…), never its number.
            $table->string('card_brand', 30)->nullable();
            $table->string('status', 20)->default('open');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('close_note', 500)->nullable();
            $table->timestamps();
            $table->unique(['gateway', 'transaction_id']);
            $table->index(['tenant_id', 'status']);
            $table->index('invoice_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reviews');
    }
};
