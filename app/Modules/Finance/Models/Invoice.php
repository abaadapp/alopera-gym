<?php

namespace App\Modules\Finance\Models;

use App\Core\Audit\Auditable;
use App\Core\Support\Money;
use App\Core\Tenancy\BelongsToTenant;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * @property string $total
 * @property string $paid_total
 */
class Invoice extends Model
{
    use Auditable, BelongsToTenant;

    public const DRAFT = 'draft';

    public const ISSUED = 'issued';

    public const PARTIALLY_PAID = 'partially_paid';

    public const PAID = 'paid';

    public const VOID = 'void';

    public const OPEN_STATUSES = [self::ISSUED, self::PARTIALLY_PAID];

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['paid_total' => 0, 'discount_total' => 0, 'tax_total' => 0, 'tax_rate' => 0];

    protected $hidden = ['public_token'];

    protected function casts(): array
    {
        return [
            'issue_date' => 'immutable_date',
            'due_date' => 'immutable_date',
            'subtotal' => 'decimal:3',
            'discount_total' => 'decimal:3',
            'tax_rate' => 'decimal:2',
            'tax_total' => 'decimal:3',
            'total' => 'decimal:3',
            'paid_total' => 'decimal:3',
            'paid_at' => 'datetime',
            'voided_at' => 'datetime',
        ];
    }

    public function billable(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class)->latest('id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', self::OPEN_STATUSES);
    }

    public function scopeOverdue(Builder $query): Builder
    {
        return $query->open()->whereNotNull('due_date')->where('due_date', '<', today());
    }

    public function balance(): string
    {
        return Money::sub($this->total, $this->paid_total);
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isPaid(): bool
    {
        return $this->status === self::PAID;
    }
}
