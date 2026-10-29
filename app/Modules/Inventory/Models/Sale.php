<?php

namespace App\Modules\Inventory\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One record per sale, from either source: the point of sale (completed on the spot) or an order placed on the
 * club's website (pending until handed over, or cancelled). The money side lives on the linked invoice and its
 * payments; the books are posted from those, never from the sale, so revenue is counted once.
 */
class Sale extends Model
{
    use BelongsToTenant;

    public const POS = 'pos';

    public const WEBSITE = 'website';

    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const VOIDED = 'voided';

    public const CANCELLED = 'cancelled';

    /** Website order steps, in order. */
    public const FULFILLMENT = ['new', 'preparing', 'ready', 'delivered'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:3',
            'discount' => 'decimal:3',
            'tax' => 'decimal:3',
            'total' => 'decimal:3',
            'voided_at' => 'datetime',
            'reserved_until' => 'datetime',
            'paid_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function customer(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function cashier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cashier_id');
    }

    public function deliveredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public function isOrder(): bool
    {
        return $this->source === self::WEBSITE;
    }

    /** Name shown for the buyer: the member, the name typed at checkout, or a walk-in. */
    public function buyerName(): string
    {
        return $this->customer?->full_name ?? $this->customer?->name ?? $this->customer_name ?? 'عميل نقدي';
    }
}
