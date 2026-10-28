<?php

namespace App\Modules\Payroll\Models;

use App\Core\Support\Money;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollItem extends Model
{
    use BelongsToTenant;

    protected $table = 'gym_payroll_items';

    protected $fillable = ['payroll_run_id', 'employee_id', 'employee_name', 'job_title', 'basic', 'allowances', 'allowance_lines', 'overtime_hours', 'overtime', 'deductions', 'deduction_notes', 'net', 'attendance_days', 'attendance_hours', 'payment_method', 'notes'];

    protected function casts(): array
    {
        return [
            'basic' => 'decimal:3', 'allowances' => 'decimal:3', 'overtime' => 'decimal:3', 'deductions' => 'decimal:3', 'net' => 'decimal:3',
            'overtime_hours' => 'decimal:2', 'attendance_hours' => 'decimal:2', 'allowance_lines' => 'array', 'paid_at' => 'datetime',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function payout(): BelongsTo
    {
        return $this->belongsTo(PayrollPayout::class, 'payroll_payout_id');
    }

    /** What she earned before deductions: basic + allowances + overtime. */
    public function earnings(): string
    {
        return Money::add($this->basic, $this->allowances, $this->overtime);
    }
}
