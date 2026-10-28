<?php

namespace App\Modules\Payroll\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Models\User;
use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Salaries paid out of one cash or bank account on one day. Recording it here books the payment; it does not
 * move money — the transfer itself is made at the bank.
 */
class PayrollPayout extends Model
{
    use BelongsToTenant;

    protected $table = 'gym_payroll_payouts';

    protected $fillable = ['payroll_run_id', 'number', 'paid_on', 'account_id', 'total', 'reference', 'created_by'];

    protected function casts(): array
    {
        return ['paid_on' => 'immutable_date', 'total' => 'decimal:3'];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayrollRun::class, 'payroll_run_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayrollItem::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
