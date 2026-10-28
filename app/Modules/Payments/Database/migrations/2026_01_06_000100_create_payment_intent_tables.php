<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One attempt to pay an invoice online. Only a verified webhook marks it paid.
        Schema::create('payment_intents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20)->default('paymob');
            $table->string('reference', 64)->unique(); // our id, sent as special_reference
            $table->decimal('amount', 12, 3);
            $table->string('currency', 3);
            $table->string('status', 20)->index(); // pending|paid|failed|expired
            $table->string('channel', 20)->default('portal'); // portal|website|whatsapp|reception
            $table->string('provider_order_id')->nullable()->index();
            $table->string('provider_transaction_id')->nullable();
            $table->text('checkout_url')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        // Raw log of every callback received, valid or not, for investigation.
        Schema::create('payment_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('provider', 20);
            $table->string('transaction_id')->nullable()->index();
            $table->string('reference')->nullable();
            $table->boolean('signature_valid');
            $table->string('outcome', 30); // processed|duplicate|rejected_signature|unknown_intent|amount_mismatch|failed_payment
            $table->json('payload');
            $table->string('ip_address', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_webhook_events');
        Schema::dropIfExists('payment_intents');
    }
};
