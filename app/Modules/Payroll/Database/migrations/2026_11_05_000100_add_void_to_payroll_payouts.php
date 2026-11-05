<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A salary payout made by mistake is voided, never deleted: who, when and why stay on it. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gym_payroll_payouts', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable();
            $table->foreignId('voided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('void_reason', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gym_payroll_payouts', function (Blueprint $table) {
            $table->dropConstrainedForeignId('voided_by');
            $table->dropColumn(['voided_at', 'void_reason']);
        });
    }
};
