<?php

namespace App\Modules\Payroll\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** One month's payroll: a draft is edited, approval posts the salaries owed, payouts settle them line by line. */
class PayrollRun extends Model
{
    use BelongsToTenant;

    protected $table = 'gym_payroll_runs';

    protected $fillable = ['number', 'period', 'status', 'gross', 'deductions', 'net', 'notes', 'created_by'];

    public const DRAFT = 'draft';

    public const APPROVED = 'approved';

    public const PAID = 'paid';

    public const STATUSES = [self::DRAFT => 'مسودة', self::APPROVED => 'معتمدة', self::PAID => 'مصروفة'];

    protected function casts(): array
    {
        return ['period' => 'immutable_date', 'gross' => 'decimal:3', 'deductions' => 'decimal:3', 'net' => 'decimal:3', 'approved_at' => 'datetime'];
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(PayrollPayout::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    /** «أكتوبر 2026» */
    public function label(): string
    {
        return $this->period->locale('ar')->translatedFormat('F Y');
    }
}
