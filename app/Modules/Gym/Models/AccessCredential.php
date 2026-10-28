<?php

namespace App\Modules\Gym\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/** What a device reads (card UID, fingerprint user id, PIN) mapped to a member or staff user. */
class AccessCredential extends Model
{
    use Auditable, BelongsToTenant;

    public const TYPES = ['card', 'fingerprint', 'pin', 'barcode'];

    protected $table = 'gym_access_credentials';

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function holder(): MorphTo
    {
        return $this->morphTo();
    }
}
