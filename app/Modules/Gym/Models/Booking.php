<?php

namespace App\Modules\Gym\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Booking extends Model implements BranchOwned
{
    use BelongsToTenant;

    /** The branch of its session. */
    public function owningBranchId(): ?int
    {
        return $this->session?->owningBranchId();
    }

    public const BOOKED = 'booked';

    public const CANCELLED = 'cancelled';

    public const ATTENDED = 'attended';

    public const NO_SHOW = 'no_show';

    protected $table = 'gym_bookings';

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'consumed_session' => 'boolean',
            'cancelled_at' => 'datetime',
            'attended_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(ClassSession::class, 'class_session_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withTrashed();
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }

    public function bookedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'booked_by');
    }
}
