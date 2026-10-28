<?php

namespace App\Modules\Finance\Models;

use App\Core\Audit\Auditable;
use App\Core\Support\Money;
use App\Core\Tenancy\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reduces what is owed on an invoice without touching the invoice itself.
 *
 * @property string $amount
 * @property string $refunded_total
 */
class CreditNote extends Model
{
    use Auditable, BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['refunded_total' => 0];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'refunded_total' => 'decimal:3',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    /** Money actually paid back against this note. */
    public function refunds(): HasMany
    {
        return $this->hasMany(Payment::class)->where('kind', Payment::KIND_REFUND);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** What this note can still pay back. */
    public function refundable(): string
    {
        return Money::max('0', Money::sub($this->amount, $this->refunded_total));
    }
}
