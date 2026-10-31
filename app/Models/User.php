<?php

namespace App\Models;

use App\Core\Auth\PermissionRegistry;
use App\Core\Auth\SystemRoles;
use App\Core\Tenancy\TenantScope;
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

    /** Club membership and role are always assigned explicitly, never mass-assigned from a request. */
    protected $guarded = ['id', 'is_platform_admin', 'tenant_id', 'role_id', 'custom_permissions'];

    protected $attributes = ['is_active' => true, 'is_platform_admin' => false, 'locale' => 'ar'];

    protected $hidden = ['password', 'remember_token'];

    /** @var list<string>|null */
    private ?array $permissionCache = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'preferences' => 'array',
            'password' => 'hashed',
            'is_platform_admin' => 'boolean',
            'is_active' => 'boolean',
            'custom_permissions' => 'array',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * Loaded without the tenant scope: it is needed at login and in console
     * commands before a tenant is set. permissions() still refuses a role that
     * belongs to another club.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class)->withoutGlobalScope(TenantScope::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class)->withoutGlobalScope(TenantScope::class);
    }

    public function trainer(): HasOne
    {
        return $this->hasOne(Trainer::class);
    }

    public function scopeOfTenant(Builder $query, int $tenantId): Builder
    {
        return $query->where('tenant_id', $tenantId);
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

        // A list set for this person replaces the role's (the owner role always keeps everything).
        if (is_array($this->custom_permissions) && ! $this->isOwner()) {
            return $this->permissionCache = PermissionRegistry::sanitize($this->custom_permissions);
        }

        return $this->permissionCache = $this->role->expandedPermissions();
    }

    /** Does this person have a list of their own instead of their role's? */
    public function hasOwnPermissions(): bool
    {
        return is_array($this->custom_permissions) && ! $this->isOwner();
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
        $key = $this->role?->key;
        $definitions = SystemRoles::definitions();

        if ($key && isset($definitions[$key])) {
            return $definitions[$key]['home'];
        }

        // A custom role: the first of these screens it may open, else the account page (every user may open it).
        foreach (self::LANDINGS as $permission => $route) {
            if ($this->hasPermission($permission)) {
                return $route;
            }
        }

        return 'club.account.show';
    }

    /** Where a custom role lands after login, in order: the first permission it holds decides. */
    private const LANDINGS = [
        'dashboard.view' => 'club.dashboard',
        'checkin.perform' => 'club.reception',
        'pos.sell' => 'club.pos.index',
        'members.view' => 'club.members.index',
        'invoices.view' => 'club.invoices.index',
        'purchases.view' => 'club.purchases.register',
        'suppliers.view' => 'club.purchases.suppliers',
        'employees.view' => 'club.employees.index',
    ];
}
