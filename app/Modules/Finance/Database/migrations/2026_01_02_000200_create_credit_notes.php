<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Credit notes: the invoice keeps its original total; a numbered credit note reduces what is
 * owed on it. Money paid back is a separate refund payment linked to the note.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('invoice_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->decimal('amount', 12, 3);
            $table->decimal('refunded_total', 12, 3)->default(0);
            $table->string('currency', 3)->default('OMR');
            $table->string('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
            $table->index(['tenant_id', 'invoice_id']);
            $table->index(['tenant_id', 'created_at']);
        });

        Schema::table('invoices', function (Blueprint $table) {
            $table->decimal('credited_total', 12, 3)->default(0);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('credit_note_id')->nullable()->constrained('credit_notes')->nullOnDelete();
        });

        // Existing clubs: the new permission goes to the system roles that handle money.
        foreach (DB::table('roles')->where('is_system', true)->whereIn('key', ['manager', 'finance'])->get(['id', 'permissions']) as $role) {
            $permissions = json_decode($role->permissions, true) ?: [];
            if (! in_array('invoices.credit', $permissions, true)) {
                $permissions[] = 'invoices.credit';
                DB::table('roles')->where('id', $role->id)->update(['permissions' => json_encode($permissions)]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('payments', fn (Blueprint $table) => $table->dropConstrainedForeignId('credit_note_id'));
        Schema::table('invoices', fn (Blueprint $table) => $table->dropColumn('credited_total'));
        Schema::dropIfExists('credit_notes');
    }
};
