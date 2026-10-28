<?php

namespace App\Modules\Payroll\Models;

use App\Core\Support\Money;
use App\Core\Tenancy\BelongsToTenant;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Gym\Models\Trainer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A member of staff's file. Salary and bank details are confidential (payroll.salaries); the bank numbers are
 * stored encrypted.
 */
class Employee extends Model
{
    use BelongsToTenant;

    protected $table = 'gym_employees';

    protected $fillable = ['branch_id', 'job_title_id', 'user_id', 'trainer_id', 'name', 'phone', 'email', 'hired_at', 'status', 'basic_salary', 'allowances', 'payment_method', 'bank_name', 'iban', 'account_number', 'notes'];

    protected $attributes = ['status' => 'active', 'basic_salary' => 0, 'payment_method' => 'bank'];

    public const STATUSES = ['active' => 'على رأس العمل', 'on_leave' => 'في إجازة', 'terminated' => 'انتهت خدمتها'];

    /** Fields only payroll.salaries may read or change. */
    public const CONFIDENTIAL = ['basic_salary', 'allowances', 'payment_method', 'bank_name', 'iban', 'account_number'];

    protected function casts(): array
    {
        return [
            'hired_at' => 'immutable_date',
            'basic_salary' => 'decimal:3',
            'allowances' => 'array',
            'iban' => 'encrypted',
            'account_number' => 'encrypted',
        ];
    }

    public function jobTitle(): BelongsTo
    {
        return $this->belongsTo(JobTitle::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class);
    }

    public function payrollItems(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function allowancesTotal(): string
    {
        return Money::add('0', ...array_map(fn ($a) => $a['amount'] ?? 0, $this->allowances ?? []));
    }
}
