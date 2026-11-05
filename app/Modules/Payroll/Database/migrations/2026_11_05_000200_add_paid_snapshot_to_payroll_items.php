<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The branch and bank a line was paid with, frozen when it is paid (encrypted: it holds the account number), so a
 * payslip reprinted later shows them as they were. Lines paid before stay without one; nothing is filled in after.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gym_payroll_items', function (Blueprint $table) {
            $table->text('paid_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('gym_payroll_items', function (Blueprint $table) {
            $table->dropColumn('paid_snapshot');
        });
    }
};
