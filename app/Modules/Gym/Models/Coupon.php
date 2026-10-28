<?php

namespace App\Modules\Gym\Models;

use App\Core\Audit\Auditable;
use App\Core\Support\Money;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    use Auditable, BelongsToTenant;

    protected $table = 'gym_coupons';

    protected $guarded = ['id', 'tenant_id', 'used_count'];

    protected $attributes = ['is_active' => true, 'used_count' => 0, 'min_amount' => 0, 'per_member_limit' => 1];

    protected function casts(): array
    {
        return [
            'value' => 'decimal:3',
            'min_amount' => 'decimal:3',
            'plan_ids' => 'array',
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }

    public function redemptions(): HasMany
    {
        return $this->hasMany(CouponRedemption::class);
    }

    /** Discount this coupon gives on $amount (never more than the amount). */
    public function discountFor(string $amount): string
    {
        $discount = $this->type === 'percent'
            ? Money::percent($amount, (float) $this->value)
            : Money::normalize($this->value);

        return Money::cmp($discount, $amount) > 0 ? Money::normalize($amount) : $discount;
    }
}
