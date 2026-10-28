<?php

namespace App\Modules\Gym\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class MembershipPlan extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    public const UNITS = ['day', 'week', 'month', 'year'];

    public const TYPES = ['gym' => 'دخول النادي', 'classes' => 'حصص جماعية', 'combo' => 'شاملة'];

    protected $table = 'gym_membership_plans';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['type' => 'gym', 'is_active' => true, 'is_public' => true, 'is_featured' => false, 'freeze_allowed' => true, 'signup_fee' => 0, 'max_freeze_days' => 0, 'max_freezes' => 0, 'sort' => 0];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:3',
            'signup_fee' => 'decimal:3',
            'features' => 'array',
            'freeze_allowed' => 'boolean',
            'is_public' => 'boolean',
            'is_featured' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function programs(): BelongsToMany
    {
        return $this->belongsToMany(Program::class, 'gym_plan_program', 'membership_plan_id', 'program_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_public', true);
    }

    /** Last day of a membership on this plan that starts on $start (inclusive). */
    public function endDateFrom(CarbonImmutable $start): CarbonImmutable
    {
        $end = match ($this->duration_unit) {
            'day' => $start->addDays($this->duration_value),
            'week' => $start->addWeeks($this->duration_value),
            'month' => $start->addMonthsNoOverflow($this->duration_value),
            'year' => $start->addYearsNoOverflow($this->duration_value),
        };

        return $end->subDay();
    }

    public function durationLabel(): string
    {
        $units = ['day' => 'يوم', 'week' => 'أسبوع', 'month' => 'شهر', 'year' => 'سنة'];

        return $this->duration_value.' '.($units[$this->duration_unit] ?? $this->duration_unit);
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'type' => $this->type,
            'duration_value' => $this->duration_value,
            'duration_unit' => $this->duration_unit,
            'price' => $this->price,
            'sessions_limit' => $this->sessions_limit,
            'freeze_allowed' => $this->freeze_allowed,
            'max_freeze_days' => $this->max_freeze_days,
            'max_freezes' => $this->max_freezes,
            'program_ids' => $this->programs()->pluck('gym_programs.id')->all(),
        ];
    }
}
