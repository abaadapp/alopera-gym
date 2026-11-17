<?php

namespace App\Modules\Gym\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use App\Core\Tenancy\OwnedByBranch;
use App\Modules\Finance\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A plan bought for someone else (#192). Lifecycle:
 *
 *   pending ──invoice paid (InvoicePaid)──▶ paid ──redeemed once──▶ redeemed
 *      │                                     │ │
 *      │                                     │ └── window passed (gym:expire-gifts) ──▶ expired
 *      └── invoice voided ──▶ cancelled ◀────┤
 *                                            └── credit note on its invoice ──▶ refunded
 *
 * A paid gift past expires_on is treated as expired at once (isExpired), before the nightly job stores it.
 * Only a fully paid invoice moves a gift out of `pending`; a bank transfer awaiting confirmation does not.
 */
class GiftMembership extends Model implements BranchOwned
{
    use Auditable, BelongsToTenant, OwnedByBranch;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const REDEEMED = 'redeemed';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    public const REFUNDED = 'refunded';

    public const STATUSES = [self::PENDING, self::PAID, self::REDEEMED, self::EXPIRED, self::CANCELLED, self::REFUNDED];

    public const STATUS_LABELS = [
        self::PENDING => 'بانتظار الدفع', self::PAID => 'مدفوعة — لم تُستخدم', self::REDEEMED => 'مستخدمة',
        self::EXPIRED => 'منتهية الصلاحية', self::CANCELLED => 'ملغاة', self::REFUNDED => 'مستردة ماليًا',
    ];

    public const STATUS_LABELS_EN = [
        self::PENDING => 'Awaiting payment', self::PAID => 'Paid — not used', self::REDEEMED => 'Used',
        self::EXPIRED => 'Expired', self::CANCELLED => 'Cancelled', self::REFUNDED => 'Refunded',
    ];

    public const DEFAULT_REDEEM_DAYS = 90;

    protected $table = 'gym_gift_memberships';

    protected $guarded = ['id', 'tenant_id'];

    /** Never in an array or JSON: the link is shown only through linkToken(), to staff allowed to share it. */
    protected $hidden = ['token', 'token_hash'];

    protected function casts(): array
    {
        return [
            'plan_snapshot' => 'array',
            'price' => 'decimal:3',
            'token' => 'encrypted',
            'paid_at' => 'datetime',
            'expires_on' => 'immutable_date',
            'redeemed_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'expired_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /** The gift a link opens, or null; compared by hash, so the token itself is never queried. */
    public static function findByToken(string $token): ?self
    {
        if (strlen($token) < 32 || strlen($token) > 128) {
            return null;
        }

        return self::where('token_hash', self::hashToken($token))->first();
    }

    public function linkToken(): string
    {
        return $this->token;
    }

    public function isExpired(?CarbonImmutable $today = null): bool
    {
        $today ??= CarbonImmutable::today();

        return $this->status === self::EXPIRED
            || ($this->status === self::PAID && $this->expires_on !== null && $this->expires_on->lt($today));
    }

    public function isRedeemable(?CarbonImmutable $today = null): bool
    {
        return $this->status === self::PAID && ! $this->isExpired($today);
    }

    public function displayStatus(?CarbonImmutable $today = null): string
    {
        return $this->isExpired($today) ? self::EXPIRED : $this->status;
    }

    /** The plan's length as it was bought, for working out the redeemed period's end date. */
    public function durationPlan(): MembershipPlan
    {
        return (new MembershipPlan)->forceFill([
            'duration_value' => $this->plan_snapshot['duration_value'],
            'duration_unit' => $this->plan_snapshot['duration_unit'],
        ]);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MembershipPlan::class, 'membership_plan_id');
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function membership(): BelongsTo
    {
        return $this->belongsTo(Membership::class);
    }
}
