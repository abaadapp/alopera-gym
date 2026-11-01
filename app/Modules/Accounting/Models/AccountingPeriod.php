<?php

namespace App\Modules\Accounting\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A month or a year whose books were closed (and perhaps reopened since). AccountingPeriods keeps the rules. */
class AccountingPeriod extends Model
{
    use BelongsToTenant;

    public const MONTH = 'month';

    public const YEAR = 'year';

    public const CLOSED = 'closed';

    public const REOPENED = 'reopened';

    protected $table = 'gym_accounting_periods';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'summary' => 'array',
            'warnings' => 'array',
            'closed_at' => 'datetime',
            'reopened_at' => 'datetime',
            'times_closed' => 'integer',
        ];
    }

    public function isClosed(): bool
    {
        return $this->status === self::CLOSED;
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }
}
