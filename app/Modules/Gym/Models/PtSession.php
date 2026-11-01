<?php

namespace App\Modules\Gym\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PtSession extends Model implements BranchOwned
{
    use BelongsToTenant;

    public const SCHEDULED = 'scheduled';

    public const COMPLETED = 'completed';

    public const CANCELLED = 'cancelled';

    public const NO_SHOW = 'no_show';

    protected $table = 'gym_pt_sessions';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['closed_early' => false];

    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'closed_early' => 'boolean',
        ];
    }

    /** Its branch; one booked while «all branches» was shown belongs where its trainer works. */
    public function owningBranchId(): ?int
    {
        return $this->branch_id ? (int) $this->branch_id : ($this->trainer?->branch_id ? (int) $this->trainer->branch_id : null);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(PtSubscription::class, 'pt_subscription_id');
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class)->withTrashed();
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }
}
