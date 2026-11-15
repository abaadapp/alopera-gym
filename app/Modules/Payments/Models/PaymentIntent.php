<?php

namespace App\Modules\Payments\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentIntent extends Model
{
    use BelongsToTenant;

    public const PENDING = 'pending';

    public const PAID = 'paid';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    protected $guarded = ['id', 'tenant_id'];

    protected $hidden = ['checkout_url'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'paid_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * What the screens show: a checkout still pending whose time is up reads «expired». Display only — the row stays
     * pending, since its end here is no proof Paymob closed it: a late callback is still read (and held for review).
     */
    public function displayStatus(): string
    {
        return $this->status === self::PENDING && $this->expires_at !== null && $this->expires_at->isPast() ? self::EXPIRED : $this->status;
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
