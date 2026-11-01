<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The tax a credit note gives back: its share of the invoice's tax (amount × tax ÷ total). Notes issued before it get
 * that share from their invoice; the ledger then moves it out of «مردودات المبيعات» into the VAT payable once (a
 * correction entry of its own, LedgerProjector::creditNote). Additive: the column goes away on rollback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_notes', function (Blueprint $table) {
            $table->decimal('tax_amount', 12, 3)->default(0)->after('amount');
        });

        DB::statement(<<<'SQL'
            UPDATE credit_notes SET tax_amount = ROUND(credit_notes.amount * invoices.tax_total / invoices.total, 3)
            FROM invoices
            WHERE invoices.id = credit_notes.invoice_id AND invoices.tax_total > 0 AND invoices.total > 0
            SQL);
    }

    public function down(): void
    {
        Schema::table('credit_notes', fn (Blueprint $table) => $table->dropColumn('tax_amount'));
    }
};
