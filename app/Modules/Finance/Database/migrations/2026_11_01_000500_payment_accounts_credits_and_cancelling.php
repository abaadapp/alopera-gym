<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Money that stays where it went, credit that is owed back, and payments that can be undone:
 *
 * - payments.account_id / expenses.account_id: the cash box or bank account the money actually went into or left from,
 *   kept with the record (finance settings changing later no longer move it). Filled for existing records from their own
 *   journal entry, so nothing in the books changes.
 * - payments.credit_amount / credit_used: money received but not applied to its invoice (an online payment arriving
 *   after the invoice was paid at the desk) is the customer's credit — applied to another invoice or paid back. The
 *   existing payments flagged for review are that credit; the ledger moves them to their own account once.
 * - payments.from_credit: a refund paid out of that credit rather than against a credit note.
 * - payments.cancelled_*: a payment entered by mistake is cancelled with a reason; its entry is reversed.
 * - payment_allocations: credit applied to another invoice of the same customer.
 *
 * Additive: new columns and a new table; rollback drops them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('method')->constrained('gym_accounts')->nullOnDelete();
            $table->decimal('credit_amount', 12, 3)->default(0)->after('amount');
            $table->decimal('credit_used', 12, 3)->default(0)->after('credit_amount');
            $table->boolean('from_credit')->default(false)->after('credit_note_id');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('cancel_reason', 500)->nullable();
        });

        Schema::table('expenses', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('payment_method')->constrained('gym_accounts')->nullOnDelete();
        });

        Schema::create('payment_allocations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 3);
            $table->foreignId('allocated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'invoice_id']);
        });

        // The money side of each payment's own entry: the debit of a receipt, the credit of a refund.
        DB::statement(<<<'SQL'
            UPDATE payments SET account_id = l.account_id
            FROM gym_journal_entries e JOIN gym_journal_lines l ON l.journal_entry_id = e.id
            WHERE e.source_type = 'payment' AND e.source_id = payments.id AND e.revision = 1
              AND ((e.source_event = 'receive' AND l.debit > 0) OR (e.source_event = 'refund' AND l.credit > 0))
              AND payments.account_id IS NULL
            SQL);
        // An expense's standing entry (not reversed): its credit line is the money that paid it.
        DB::statement(<<<'SQL'
            UPDATE expenses SET account_id = l.account_id
            FROM gym_journal_entries e JOIN gym_journal_lines l ON l.journal_entry_id = e.id
            WHERE e.source_type = 'expense' AND e.source_id = expenses.id AND e.source_event = 'record'
              AND e.reversed_at IS NULL AND l.credit > 0 AND expenses.account_id IS NULL
            SQL);
        // Online payments recorded but never applied (the invoice was paid or voided meanwhile).
        DB::statement(<<<'SQL'
            UPDATE payments SET credit_amount = amount
            WHERE kind = 'payment' AND status = 'completed' AND gateway IS NOT NULL
              AND notes LIKE 'دفعة إلكترونية تتجاوز رصيد الفاتورة%'
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_allocations');
        Schema::table('expenses', fn (Blueprint $table) => $table->dropConstrainedForeignId('account_id'));
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn(['credit_amount', 'credit_used', 'from_credit', 'cancelled_at', 'cancel_reason']);
        });
    }
};
