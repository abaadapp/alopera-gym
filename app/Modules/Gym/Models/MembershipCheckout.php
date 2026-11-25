<?php

namespace App\Modules\Gym\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Finance\Models\Invoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A membership bought online, waiting for its payment (MembershipCheckouts). Not a sale: its invoice is an unnumbered
 * draft (hidden everywhere by Invoice's «issued» scope) until Paymob confirms the money.
 */
class MembershipCheckout extends Model
{
    use BelongsToTenant;

    public const OPEN = 'open';

    public const COMPLETED = 'completed';

    public const ABANDONED = 'abandoned';

    protected $table = 'gym_membership_checkouts';

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['completed_at' => 'datetime', 'options' => 'array'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id')->withTrashed();
    }

    /** The draft while open (outside the «issued» scope), the sale's invoice once completed. */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class)->withoutGlobalScope('issued');
    }
}
