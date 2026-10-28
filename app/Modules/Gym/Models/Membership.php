<?php

namespace App\Modules\Gym\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use App\Models\Branch;
use App\Modules\Finance\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One period of membership. Lifecycle:
 *
 *   pending ──paid──▶ scheduled ──start date──▶ active ──end date──▶ expired
 *      │                  │                      │  ▲
 *      └──────cancel──────┴───────cancel─────────┤  │ resume (end date extended)
 *                                                ▼  │
 *                                               frozen
 *
 * Only a confirmed payment moves a membership out of `pending`.
 */
class Membership extends Model
{
    use Auditable, BelongsToTenant;

    public const PENDING = 'pending';

    public const SCHEDULED = 'scheduled';

    public const ACTIVE = 'active';

    public const FROZEN = 'frozen';

    public const EXPIRED = 'expired';

    public const CANCELLED = 'cancelled';

    public const KIND_NEW = 'new';

    public const KIND_RENEWAL = 'renewal';

    public const KIND_UPGRADE = 'upgrade';

    protected $table = 'gym_memberships';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['sessions_used' => 0, 'freeze_days_used' => 0, 'freezes_count' => 0, 'signup_fee' => 0, 'discount' => 0, 'credit_applied' => 0, 'kind' => 'new'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'price' => 'decimal:3',
            'signup_fee' => 'decimal:3',
            'discount' => 'decimal:3',
            'credit_applied' => 'decimal:3',
            'total' => 'decimal:3',
            'plan_snapshot' => 'array',
            'activated_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id')->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function previous(): BelongsTo
    {
        return $this->belongsTo(self::class, 'previous_membership_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function freezes(): HasMany
    {
        return $this->hasMany(MembershipFreeze::class);
    }

    public function openFreeze(): HasOne
    {
        return $this->hasOne(MembershipFreeze::class)->whereNull('ended_on')->latestOfMany();
    }

    public function events(): HasMany
    {
        return $this->hasMany(MembershipEvent::class)->latest('id');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', [self::ACTIVE, self::FROZEN, self::SCHEDULED]);
    }

    public function scopeExpiringWithin(Builder $query, int $days, ?CarbonImmutable $today = null): Builder
    {
        $today ??= CarbonImmutable::today();

        return $query->where('status', self::ACTIVE)
            ->whereBetween('ends_on', [$today->toDateString(), $today->addDays($days)->toDateString()]);
    }

    public function coversDate(CarbonImmutable $date): bool
    {
        return $date->betweenIncluded($this->starts_on, $this->ends_on);
    }

    public function hasSessionsLeft(): bool
    {
        return $this->sessions_total === null || $this->sessions_used < $this->sessions_total;
    }

    public function sessionsRemaining(): ?int
    {
        return $this->sessions_total === null ? null : max(0, $this->sessions_total - $this->sessions_used);
    }

    public function daysRemaining(?CarbonImmutable $today = null): int
    {
        $today ??= CarbonImmutable::today();

        return $this->ends_on->lt($today) ? 0 : (int) $today->diffInDays($this->ends_on) + 1;
    }

    public function totalDays(): int
    {
        return (int) $this->starts_on->diffInDays($this->ends_on) + 1;
    }

    /** @return list<int> empty = every program */
    public function allowedProgramIds(): array
    {
        return array_map('intval', $this->plan_snapshot['program_ids'] ?? []);
    }
}
