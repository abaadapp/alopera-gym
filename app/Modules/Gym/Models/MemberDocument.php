<?php

namespace App\Modules\Gym\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Stored on the private disk under tenants/{id}/members/…; served only through an authorised controller. */
class MemberDocument extends Model
{
    use Auditable, BelongsToTenant;

    public const TYPES = ['contract', 'id', 'medical', 'waiver', 'other'];

    protected $table = 'gym_member_documents';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['expires_on' => 'date'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
