<?php

namespace App\Modules\Gym\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use App\Core\Tenancy\OwnedByBranch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StaffAttendance extends Model implements BranchOwned
{
    use BelongsToTenant, OwnedByBranch;

    protected $table = 'gym_staff_attendances';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['clock_in_at' => 'datetime', 'clock_out_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(AccessDevice::class, 'access_device_id');
    }
}
