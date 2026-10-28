<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** The club's subscription to the platform (SaaS billing). */
class TenantSubscription extends Model
{
    public const MONTHLY = 'monthly';

    public const YEARLY = 'yearly';

    public const TRIALING = 'trialing';

    public const ACTIVE = 'active';

    public const PAST_DUE = 'past_due';

    public const CANCELLED = 'cancelled';

    public const EXPIRED = 'expired';

    protected $guarded = ['id'];

    protected $attributes = ['auto_renew' => true];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:3',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'trial_ends_on' => 'date',
            'auto_renew' => 'boolean',
            'cancelled_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(PlatformPlan::class, 'platform_plan_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(PlatformInvoice::class);
    }
}
