<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Staff and payroll: job titles, employee files (linked to a sign-in account and to the trainer profile when
 * there is one), monthly payroll runs whose lines copy each salary at the time, and the payouts that settle them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gym_job_titles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('gym_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('job_title_id')->nullable()->constrained('gym_job_titles')->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('trainer_id')->nullable()->constrained('gym_trainers')->nullOnDelete();
            $table->string('name');
            $table->string('phone', 32)->nullable();
            $table->string('email')->nullable();
            $table->date('hired_at')->nullable();
            $table->string('status', 12)->default('active'); // active|on_leave|terminated
            $table->decimal('basic_salary', 12, 3)->default(0);
            $table->json('allowances')->nullable(); // [{name, amount}]
            $table->string('payment_method', 12)->default('bank'); // bank|cash
            $table->string('bank_name')->nullable();
            $table->text('iban')->nullable(); // encrypted
            $table->text('account_number')->nullable(); // encrypted
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id']);
            $table->unique(['tenant_id', 'trainer_id']);
        });

        Schema::create('gym_payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('number', 40);
            $table->date('period'); // first day of the month
            $table->string('status', 12)->default('draft'); // draft|approved|paid
            $table->decimal('gross', 12, 3)->default(0);
            $table->decimal('deductions', 12, 3)->default(0);
            $table->decimal('net', 12, 3)->default(0);
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'period']);
            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('gym_payroll_payouts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('gym_payroll_runs')->restrictOnDelete();
            $table->string('number', 40);
            $table->date('paid_on');
            $table->foreignId('account_id')->constrained('gym_accounts')->restrictOnDelete();
            $table->decimal('total', 12, 3);
            $table->string('reference')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['tenant_id', 'number']);
        });

        Schema::create('gym_payroll_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payroll_run_id')->constrained('gym_payroll_runs')->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained('gym_employees')->restrictOnDelete();
            $table->string('employee_name'); // as it was: a later rename does not rewrite an old run
            $table->string('job_title')->nullable();
            $table->decimal('basic', 12, 3)->default(0);
            $table->decimal('allowances', 12, 3)->default(0);
            $table->json('allowance_lines')->nullable();
            $table->decimal('overtime_hours', 6, 2)->default(0);
            $table->decimal('overtime', 12, 3)->default(0);
            $table->decimal('deductions', 12, 3)->default(0);
            $table->string('deduction_notes')->nullable();
            $table->decimal('net', 12, 3)->default(0);
            $table->unsignedSmallInteger('attendance_days')->nullable();
            $table->decimal('attendance_hours', 7, 2)->nullable();
            $table->string('payment_method', 12)->default('bank');
            $table->foreignId('payroll_payout_id')->nullable()->constrained('gym_payroll_payouts')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('notes')->nullable();
            $table->timestamps();
            $table->unique(['payroll_run_id', 'employee_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gym_payroll_items');
        Schema::dropIfExists('gym_payroll_payouts');
        Schema::dropIfExists('gym_payroll_runs');
        Schema::dropIfExists('gym_employees');
        Schema::dropIfExists('gym_job_titles');
    }
};
