<?php

namespace App\Modules\Gym\Models;

use App\Core\Audit\Auditable;
use App\Core\Support\ImageStore;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use App\Core\Tenancy\OwnedByBranch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Trainer extends Model implements BranchOwned
{
    use Auditable, BelongsToTenant, OwnedByBranch, SoftDeletes;

    protected $table = 'gym_trainers';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['is_active' => true, 'is_public' => true, 'color' => '#be185d', 'sort' => 0];

    protected function casts(): array
    {
        return ['specialties' => 'array', 'specialties_en' => 'array', 'is_public' => 'boolean', 'is_active' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function availabilities(): HasMany
    {
        return $this->hasMany(TrainerAvailability::class)->orderBy('weekday')->orderBy('starts_at');
    }

    public function classSessions(): HasMany
    {
        return $this->hasMany(ClassSession::class);
    }

    public function ptSessions(): HasMany
    {
        return $this->hasMany(PtSession::class);
    }

    public function scopePublic(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('is_public', true);
    }

    public function photoUrl(): ?string
    {
        return ImageStore::url($this->photo_path);
    }

    public function photoThumbUrl(): ?string
    {
        return ImageStore::thumbUrl($this->photo_path);
    }
}
