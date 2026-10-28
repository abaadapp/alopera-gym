<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 40);
            // Who is billed. Finance does not know about gym members; it bills a "billable".
            $table->nullableMorphs('billable');
            $table->string('customer_name');
            $table->string('customer_phone', 32)->nullable();
            $table->string('customer_email')->nullable();
            $table->string('type', 20)->default('other'); // membership|pt|pos|other
            $table->string('status', 20)->default('issued')->index(); // draft|issued|partially_paid|paid|void
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->decimal('subtotal', 12, 3)->default(0);
            $table->decimal('discount_total', 12, 3)->default(0);
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax_total', 12, 3)->default(0);
            $table->decimal('total', 12, 3)->default(0);
            $table->decimal('paid_total', 12, 3)->default(0);
            $table->string('currency', 3)->default('OMR');
            $table->text('notes')->nullable();
            $table->string('public_token', 64)->unique(); // signed links for WhatsApp / portal
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('voided_at')->nullable();
            $table->string('void_reason')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'status', 'issue_date']);
        });

        Schema::create('invoice_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('description');
            $table->decimal('quantity', 10, 3)->default(1);
            $table->decimal('unit_price', 12, 3);
            $table->decimal('discount', 12, 3)->default(0);
            $table->decimal('line_total', 12, 3);
            $table->nullableMorphs('itemable');
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->nullable()->constrained()->restrictOnDelete();
            $table->nullableMorphs('payer');
            $table->string('receipt_number', 40)->nullable();
            $table->string('kind', 10)->default('payment'); // payment|refund
            $table->string('method', 20); // cash|bank_transfer|card|paymob|other
            $table->string('status', 20)->index(); // pending|completed|failed|rejected
            $table->decimal('amount', 12, 3);
            $table->string('currency', 3)->default('OMR');
            $table->string('reference')->nullable();
            $table->string('gateway', 20)->nullable();
            $table->string('gateway_transaction_id')->nullable();
            $table->string('proof_path')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('refund_of_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->string('rejection_reason')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'receipt_number']);
            // A gateway transaction can be recorded once, ever: duplicate webhooks are no-ops.
            $table->unique(['gateway', 'gateway_transaction_id']);
            $table->index(['tenant_id', 'status', 'paid_at']);
        });

        Schema::create('expense_categories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 40);
            $table->string('description');
            $table->decimal('amount', 12, 3);
            $table->date('expense_date')->index();
            $table->string('payment_method', 20)->default('cash');
            $table->string('payee')->nullable();
            $table->string('reference')->nullable();
            $table->string('attachment_path')->nullable();
            $table->unsignedBigInteger('supplier_id')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
            $table->unique(['tenant_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('expense_categories');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('invoice_items');
        Schema::dropIfExists('invoices');
    }
};
