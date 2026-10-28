<?php

namespace App\Modules\Gym\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

/** A training program or class type (Zumba, Pilates, Strength…). */
class Program extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    public const LEVELS = ['all', 'beginner', 'intermediate', 'advanced'];

    protected $table = 'gym_programs';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['is_active' => true, 'is_public' => true, 'category' => 'class', 'level' => 'all', 'duration_minutes' => 60, 'default_capacity' => 15, 'color' => '#db2777', 'sort' => 0];

    protected function casts(): array
    {
        return ['is_public' => 'boolean', 'is_active' => 'boolean'];
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(ClassSchedule::class);
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_public', true);
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}
