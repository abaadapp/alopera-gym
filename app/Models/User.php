<?php

namespace App\Models;

use App\Core\Auth\SystemRoles;
use App\Modules\Gym\Models\Trainer;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Staff account. Platform admins have no tenant; club staff belong to exactly
 * one club and get their permissions from their role.
 *
 * Users are deliberately not under the tenant global scope: the session guard
 * has to load them before a tenant is known. Club screens filter explicitly
 * with {@see scopeOfTenant()}.
 */
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    protected $guarded = ['id', 'is_platform_admin'];

    protected $attributes = ['is_active' => true, 'is_platform_admin' => false, 'locale' => 'ar'];

    protected $hidden = ['password', 'remember_token'];

    /** @var list<string>|null */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function trainer(): HasOne
    {
        return $this->hasOne(Trainer::class);
    }

    public function scopeOfTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
    }

    public function isPlatformAdmin(): bool
    {
        return $this->is_platform_admin && $this->tenant_id === null;
    }

    public function isOwner(): bool
    {
        return $this->role?->key === SystemRoles::OWNER;
    }

    public function isTrainer(): bool
    {
        return $this->role?->key === SystemRoles::TRAINER;
    }

    /** @return list<string> */
    public function permissions(): array
    {
        if ($this->permissionCache !== null) {
            return $this->permissionCache;
        }

        if (! $this->is_active || $this->tenant_id === null || ! $this->role || $this->role->tenant_id !== $this->tenant_id) {
            return $this->permissionCache = [];
        }

        return $this->permissionCache = $this->role->expandedPermissions();
    }

    public function hasPermission(string $permission): bool
    {
        return in_array($permission, $this->permissions(), true);
    }

    public function flushPermissionCache(): void
    {
        $this->permissionCache = null;
        $this->unsetRelation('role');
    }

    /** Route name of the screen this user lands on after login. */
    public function homeRoute(): string
    {
        if ($this->isPlatformAdmin()) {
            return 'platform.dashboard';
        }

        $key = $this->role?->key;
        $definitions = SystemRoles::definitions();

        if ($key && isset($definitions[$key])) {
            return $definitions[$key]['home'];
        }

        return $this->hasPermission('dashboard.view') ? 'club.dashboard' : 'club.reception';
    }
}
