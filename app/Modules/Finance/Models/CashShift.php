<?php

namespace App\Modules\Finance\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use App\Core\Tenancy\OwnedByBranch;
use App\Models\Branch;
use App\Models\User;
use App\Modules\Accounting\Models\Account;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A cash drawer's shift, from the cash counted at opening to the cash counted at closing. CashShifts keeps the rules. */
class CashShift extends Model implements BranchOwned
{
    use BelongsToTenant, OwnedByBranch;

    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected $table = 'gym_cash_shifts';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'opening_float' => 'decimal:3',
            'expected_cash' => 'decimal:3',
            'counted_cash' => 'decimal:3',
            'difference' => 'decimal:3',
            'totals' => 'array',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashShiftMovement::class);
    }
}
