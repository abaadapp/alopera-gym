<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The cash drawer's shift: opened with the cash counted in it, closed with the cash counted again. What the drawer
 * should hold is worked out from what passed through it (cash payments in, cash refunds and expenses out, money moved
 * to or from the bank); the difference is posted to the books when the shift closes. Payments and expenses made during
 * a shift carry it (cash_shift_id), so the closing reads them, not a time window. One open shift per cash box.
 * New tables and nullable columns: nothing existing changes; rollback drops them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gym_cash_shifts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('account_id')->constrained('gym_accounts'); // the cash box
            $table->string('number', 40);
            $table->string('status', 10); // open|closed
            $table->decimal('opening_float', 12, 3)->default(0);
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('opened_at');
            $table->decimal('expected_cash', 12, 3)->nullable();
            $table->decimal('counted_cash', 12, 3)->nullable();
            $table->decimal('difference', 12, 3)->nullable();
            $table->json('totals')->nullable(); // by method, at closing
            $table->string('close_note', 500)->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
        });
        DB::statement("CREATE UNIQUE INDEX gym_cash_shifts_one_open_per_box ON gym_cash_shifts (tenant_id, account_id) WHERE status = 'open'");

        Schema::create('gym_cash_shift_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_shift_id')->constrained('gym_cash_shifts')->cascadeOnDelete();
            $table->string('direction', 4); // in|out
            $table->decimal('amount', 12, 3);
            $table->foreignId('counter_account_id')->constrained('gym_accounts'); // the bank account or safe on the other side
            $table->string('reason', 255);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        foreach (['payments', 'expenses'] as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->foreignId('cash_shift_id')->nullable()->constrained('gym_cash_shifts')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (['payments', 'expenses'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('cash_shift_id'));
        }
        Schema::dropIfExists('gym_cash_shift_movements');
        Schema::dropIfExists('gym_cash_shifts');
    }
};
