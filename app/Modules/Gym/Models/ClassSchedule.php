<?php

namespace App\Modules\Gym\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use App\Models\Branch;
use App\Modules\Gym\Services\ScheduleService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Weekly recurring slot. {@see ScheduleService} turns it into sessions. */
class ClassSchedule extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'gym_class_schedules';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return [
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }

    public function program(): BelongsTo
    {
        return $this->belongsTo(Program::class)->withTrashed();
    }

    public function trainer(): BelongsTo
    {
        return $this->belongsTo(Trainer::class)->withTrashed();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }
}
