<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchasing, extended from the existing purchase orders: an order in purchase units with its discount, shipping
 * and tax, approved before it is sent; goods receipt notes (recorded, then approved — only then does the branch's
 * stock go up), supplier bills (approved, they create the debt in the books) and the payments made on them.
 * Issuing an order moves no stock and posts nothing. Existing orders keep their numbers and status; an order
 * already received counts as received in full. No old receipt is invented.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('suppliers', function (Blueprint $table) {
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('tax_number');
        });

        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->decimal('subtotal', 12, 3)->default(0)->after('expected_on');
            $table->decimal('discount', 12, 3)->default(0)->after('subtotal');
            $table->decimal('shipping', 12, 3)->default(0)->after('discount');
            $table->decimal('tax_rate', 5, 2)->default(0)->after('shipping');
            $table->decimal('tax', 12, 3)->default(0)->after('tax_rate');
            $table->string('supplier_reference', 60)->nullable()->after('tax'); // the supplier's quote number
            $table->unsignedSmallInteger('payment_terms_days')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->string('client_key', 64)->nullable();
            $table->unique(['tenant_id', 'client_key']);
        });

        Schema::table('purchase_order_items', function (Blueprint $table) {
            $table->string('unit', 30)->nullable()->after('product_id'); // the purchase unit: كرتون، علبة…
            $table->decimal('unit_factor', 12, 3)->default(1)->after('unit'); // stock units in one purchase unit
            $table->decimal('received_quantity', 12, 3)->default(0)->after('quantity'); // in purchase units
        });

        DB::table('purchase_orders')->update(['subtotal' => DB::raw('total')]);
        DB::table('purchase_order_items')->whereIn('purchase_order_id', DB::table('purchase_orders')->where('status', 'received')->select('id'))
            ->update(['received_quantity' => DB::raw('quantity')]);

        Schema::create('goods_receipts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->foreignId('purchase_order_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('status', 20)->default('pending'); // pending|approved|rejected
            $table->date('received_on');
            $table->string('delivery_reference', 60)->nullable(); // the supplier's delivery note
            $table->text('notes')->nullable();
            $table->string('reason')->nullable(); // rejection
            $table->decimal('total', 12, 3)->default(0);
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('rejected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('rejected_at')->nullable();
            $table->string('client_key', 64)->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'client_key']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('goods_receipt_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('goods_receipt_id')->constrained()->cascadeOnDelete();
            $table->foreignId('purchase_order_item_id')->constrained()->restrictOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('ordered_quantity', 12, 3); // what was still due on the order, in purchase units
            $table->decimal('quantity', 12, 3); // received now, in purchase units
            $table->decimal('unit_factor', 12, 3)->default(1);
            $table->decimal('unit_cost', 12, 3); // per purchase unit
            $table->decimal('line_total', 12, 3);
            $table->string('note')->nullable(); // why less (or damaged) arrived
            $table->timestamps();
        });

        Schema::create('supplier_bills', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('reference', 60); // the supplier's invoice number
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->decimal('subtotal', 12, 3);
            $table->decimal('discount', 12, 3)->default(0);
            $table->decimal('tax', 12, 3)->default(0);
            $table->decimal('total', 12, 3);
            $table->decimal('paid_total', 12, 3)->default(0);
            $table->string('status', 20)->default('pending'); // pending|approved|rejected|void
            $table->string('attachment_path')->nullable(); // private disk
            $table->string('attachment_name')->nullable();
            $table->text('notes')->nullable();
            $table->string('reason')->nullable(); // rejection or void
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'supplier_id', 'reference']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('supplier_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->foreignId('supplier_bill_id')->constrained()->restrictOnDelete();
            $table->foreignId('supplier_id')->constrained()->restrictOnDelete();
            $table->foreignId('account_id')->constrained('gym_accounts')->restrictOnDelete(); // the cash box or bank it left
            $table->decimal('amount', 12, 3);
            $table->date('paid_on');
            $table->string('reference', 120)->nullable(); // transfer or cheque number
            $table->string('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reverse_reason')->nullable();
            $table->string('client_key', 64)->nullable(); // one payment per form submission
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'client_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('supplier_payments');
        Schema::dropIfExists('supplier_bills');
        Schema::dropIfExists('goods_receipt_lines');
        Schema::dropIfExists('goods_receipts');
        Schema::table('purchase_order_items', fn (Blueprint $table) => $table->dropColumn(['unit', 'unit_factor', 'received_quantity']));
        Schema::table('purchase_orders', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'client_key']);
            $table->dropConstrainedForeignId('approved_by');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['subtotal', 'discount', 'shipping', 'tax_rate', 'tax', 'supplier_reference', 'payment_terms_days', 'approved_at', 'cancelled_at', 'cancel_reason', 'client_key']);
        });
        Schema::table('suppliers', fn (Blueprint $table) => $table->dropColumn('payment_terms_days'));
    }
};
