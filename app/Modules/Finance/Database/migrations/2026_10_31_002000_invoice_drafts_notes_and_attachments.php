<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The invoice editor: a draft is saved without a number (it gets one, in sequence, only when issued, so drafts leave no
 * gaps in the numbering), with its payment terms, a note for the team that is never printed, and supporting files.
 * Additive only: existing invoices keep their numbers and nothing is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->string('number', 40)->nullable()->change();
            // Nor a customer link: a draft is nobody's to open.
            $table->string('public_token', 64)->nullable()->change();
            $table->unsignedSmallInteger('payment_terms_days')->nullable()->after('due_date');
            $table->text('internal_notes')->nullable()->after('notes');
        });

        Schema::create('invoice_attachments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('path');
            $table->string('name', 200);
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size')->default(0);
            $table->foreignId('uploaded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['tenant_id', 'invoice_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoice_attachments');
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn(['payment_terms_days', 'internal_notes']);
        });
    }
};
