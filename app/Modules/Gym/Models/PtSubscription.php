<?php

namespace App\Modules\Gym\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Finance\Models\Invoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PtSubscription extends Model
{
    use Auditable, BelongsToTenant;

    public const PENDING = 'pending';

    public const ACTIVE = 'active';

    public const COMPLETED = 'completed';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    protected $table = 'gym_pt_subscriptions';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['sessions_used' => 0];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:3',
            'starts_on' => 'immutable_date',
            'expires_on' => 'immutable_date',
            'activated_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function package(): BelongsTo
    {
        return $this->belongsTo(PtPackage::class, 'pt_package_id')->withTrashed();
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class)->withTrashed();
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(PtSession::class);
    }

    public function sessionsRemaining(): int
    {
        return max(0, $this->sessions_total - $this->sessions_used);
    }
}
