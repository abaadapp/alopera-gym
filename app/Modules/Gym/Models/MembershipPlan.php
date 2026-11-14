<?php

namespace App\Modules\Gym\Models;

use App\Core\Audit\Auditable;
use App\Core\Support\SiteLanguage;
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

    /**
     * What each type gives, as the owner sets it on the plan («صلاحيات الباقة»): the door any day of the period, and
     * class bookings. The type is what is stored and sold (plan_snapshot); the two switches are how the owner chooses
     * it. A plan with neither is not a plan the club can sell.
     */
    public const ACCESS = ['gym' => ['gym' => true, 'classes' => false], 'classes' => ['gym' => false, 'classes' => true], 'combo' => ['gym' => true, 'classes' => true]];

    /** The type for the owner's two switches, or null when both are off. */
    public static function typeFor(bool $gym, bool $classes): ?string
    {
        foreach (self::ACCESS as $type => $access) {
            if ($access['gym'] === $gym && $access['classes'] === $classes) {
                return $type;
            }
        }

        return null;
    }

    /** New sales open the door any day of the period (a classes-only plan opens it on a day with a class booked). */
    public function allowsGym(): bool
    {
        return self::ACCESS[$this->type]['gym'] ?? false;
    }

    /** New sales book classes, within the plan's programs and sessions. */
    public function allowsClasses(): bool
    {
        return self::ACCESS[$this->type]['classes'] ?? false;
    }

    protected $table = 'gym_membership_plans';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['type' => 'gym', 'is_active' => true, 'is_public' => true, 'is_featured' => false, 'freeze_allowed' => true, 'signup_fee' => 0, 'max_freeze_days' => 0, 'max_freezes' => 0, 'sort' => 0];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:3',
            'signup_fee' => 'decimal:3',
            'features' => 'array',
            'features_en' => 'array',
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

    /** The plan's length in the website's language: «3 أشهر» on the Arabic site, "3 months" on the English one. */
    public function siteDurationLabel(): string
    {
        if (! SiteLanguage::isEnglish()) {
            return $this->durationLabel();
        }
        $n = (int) $this->duration_value;

        return $n.' '.$this->duration_unit.($n === 1 ? '' : 's');
    }

    /** Arabic number agreement: «شهر»، «شهران»، «3 أشهر»، «12 شهرًا». */
    public function durationLabel(): string
    {
        $forms = [
            'day' => ['يوم', 'يومان', 'أيام', 'يومًا'],
            'week' => ['أسبوع', 'أسبوعان', 'أسابيع', 'أسبوعًا'],
            'month' => ['شهر', 'شهران', 'أشهر', 'شهرًا'],
            'year' => ['سنة', 'سنتان', 'سنوات', 'سنة'],
        ];
        $n = (int) $this->duration_value;
        $f = $forms[$this->duration_unit] ?? null;
        if (! $f) {
            return $n.' '.$this->duration_unit;
        }

        return match (true) {
            $n === 1 => $f[0],
            $n === 2 => $f[1],
            $n >= 3 && $n <= 10 => $n.' '.$f[2],
            default => $n.' '.$f[3],
        };
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
            // Sold with the type as a rule (#70): the door and the classes follow it (Membership::opensDoorAnyDay,
            // booksClasses). Periods sold before have no such key and keep the door and the classes until they end.
            'type_enforced' => true,
        ];
    }
}
