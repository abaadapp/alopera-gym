<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The stock documents of a branch: stock counts (draft → approved, which posts each difference once), transfers
 * between branches (requested → approved → in transit → received, or received short and then settled), and
 * supply requests from a branch (pending → approved into transfers or a purchase order, or rejected).
 * Stock moves only through StockService; these tables say why and who.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocktakes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->constrained()->restrictOnDelete();
            $table->string('number', 40);
            $table->string('status', 20)->default('draft'); // draft|approved|cancelled
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('stocktake_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stocktake_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('system_quantity', 12, 3)->default(0); // the branch's book when counted (again at approval)
            $table->decimal('counted_quantity', 12, 3)->nullable(); // empty: not counted, left as it is
            $table->decimal('difference', 12, 3)->default(0);
            $table->decimal('unit_cost', 12, 3)->default(0);
            $table->string('reason', 30)->nullable(); // count|damaged|lost|expired|internal|correction
            $table->string('note')->nullable();
            $table->timestamps();
            $table->unique(['stocktake_id', 'product_id']);
        });

        Schema::create('stock_transfers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->foreignId('from_branch_id')->constrained('branches')->restrictOnDelete();
            $table->foreignId('to_branch_id')->constrained('branches')->restrictOnDelete();
            $table->string('status', 20)->default('pending'); // pending|approved|in_transit|discrepancy|received|rejected|cancelled
            $table->unsignedBigInteger('supply_request_id')->nullable()->index();
            $table->text('notes')->nullable();
            $table->string('reason')->nullable(); // rejection or cancellation
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('dispatched_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('dispatched_at')->nullable();
            $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('received_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->string('client_key', 64)->nullable(); // one transfer per form submission
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'client_key']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('stock_transfer_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_transfer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('requested_quantity', 12, 3);
            $table->decimal('approved_quantity', 12, 3)->nullable();
            $table->decimal('sent_quantity', 12, 3)->default(0);
            $table->decimal('received_quantity', 12, 3)->default(0);
            $table->decimal('lost_quantity', 12, 3)->default(0);
            $table->decimal('returned_quantity', 12, 3)->default(0);
            $table->decimal('late_quantity', 12, 3)->default(0);
            $table->decimal('unit_cost', 12, 3)->default(0); // at dispatch
            $table->string('note')->nullable();
            $table->timestamps();
        });

        Schema::create('supply_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->foreignId('branch_id')->constrained()->restrictOnDelete(); // the branch asking
            $table->string('status', 20)->default('pending'); // pending|approved|rejected|cancelled
            $table->date('needed_by')->nullable();
            $table->text('notes')->nullable();
            $table->string('reason')->nullable(); // rejection
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->foreignId('purchase_order_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('supply_request_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('supply_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->decimal('approved_quantity', 12, 3)->nullable();
            $table->foreignId('source_branch_id')->nullable()->constrained('branches')->nullOnDelete(); // empty when bought
            $table->string('fulfil_by', 20)->nullable(); // transfer|purchase
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['supply_request_lines', 'supply_requests', 'stock_transfer_lines', 'stock_transfers', 'stocktake_lines', 'stocktakes'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
