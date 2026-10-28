<?php

namespace App\Models;

use App\Core\Audit\Auditable;
use App\Core\Auth\PermissionRegistry;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Role extends Model
{
    use Auditable, BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_system' => 'boolean',
            'is_locked' => 'boolean',
        ];
    }

    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    /** @return list<string> */
    public function expandedPermissions(): array
    {
        $stored = $this->permissions ?? [];

        return in_array('*', $stored, true)
            ? PermissionRegistry::all()
            : PermissionRegistry::sanitize($stored);
    }
}
