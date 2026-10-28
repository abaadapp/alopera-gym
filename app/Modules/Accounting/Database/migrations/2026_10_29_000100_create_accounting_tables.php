<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Double-entry books for each club: a chart of accounts (cash, banks and payment clearing accounts among them),
 * and balanced journal entries. Business records (invoices, payments, credit notes, expenses, payroll) post to
 * it; the source key makes each posting happen once. Balances are always summed from the lines.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gym_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('gym_accounts')->nullOnDelete();
            $table->string('code', 20);
            $table->string('name');
            $table->string('type', 12); // asset|liability|equity|revenue|expense
            $table->string('normal_side', 6); // debit|credit — a contra account takes the other side
            $table->boolean('is_group')->default(false); // a heading in the tree; never posted to
            $table->string('system_key', 40)->nullable(); // accounts the system posts to by itself
            $table->string('kind', 10)->nullable(); // money accounts: cash|bank|card|gateway
            $table->string('bank_name')->nullable();
            $table->text('account_number')->nullable(); // encrypted
            $table->text('iban')->nullable(); // encrypted
            $table->foreignId('expense_category_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'code']);
            $table->unique(['tenant_id', 'system_key']);
            $table->unique(['tenant_id', 'expense_category_id']);
        });

        Schema::create('gym_journal_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number', 40);
            $table->date('entry_date')->index();
            $table->string('description');
            // What it records: (invoice 12, issue), (payroll_run 3, approve)… unique, so a retry posts nothing twice.
            $table->string('source_type', 40)->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_event', 20)->nullable();
            $table->unsignedSmallInteger('revision')->default(1);
            $table->foreignId('reverses_id')->nullable()->unique()->constrained('gym_journal_entries')->nullOnDelete();
            $table->timestamp('reversed_at')->nullable();
            $table->decimal('total', 12, 3)->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->unique(['tenant_id', 'source_type', 'source_id', 'source_event', 'revision'], 'gym_journal_entries_source_unique');
            $table->index(['source_type', 'source_id']);
        });

        Schema::create('gym_journal_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('journal_entry_id')->constrained('gym_journal_entries')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('gym_accounts')->restrictOnDelete();
            $table->decimal('debit', 12, 3)->default(0);
            $table->decimal('credit', 12, 3)->default(0);
            $table->string('memo')->nullable();
            $table->index(['account_id', 'journal_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gym_journal_lines');
        Schema::dropIfExists('gym_journal_entries');
        Schema::dropIfExists('gym_accounts');
    }
};
