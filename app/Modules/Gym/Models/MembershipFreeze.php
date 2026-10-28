<?php

namespace App\Modules\Gym\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MembershipFreeze extends Model
{
    use BelongsToTenant;

    protected $table = 'gym_membership_freezes';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'planned_end_on' => 'immutable_date',
            'ended_on' => 'immutable_date',
        ];
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }
}
