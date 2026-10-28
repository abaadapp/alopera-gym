<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Invoice from the platform to a club. Separate from club invoices to members. */
class PlatformInvoice extends Model
{
    public const UNPAID = 'unpaid';

    public const PAID = 'paid';

    public const VOID = 'void';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'period_start' => 'date',
            'period_end' => 'date',
            'due_on' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(TenantSubscription::class, 'tenant_subscription_id');
    }
}
