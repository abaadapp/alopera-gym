<?php

namespace App\Modules\Finance\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Payment extends Model
{
    use Auditable, BelongsToTenant;

    public const PENDING = 'pending';

    public const COMPLETED = 'completed';

    public const FAILED = 'failed';

    public const REJECTED = 'rejected';

    public const KIND_PAYMENT = 'payment';

    public const KIND_REFUND = 'refund';

    public const METHODS = ['cash', 'bank_transfer', 'card', 'paymob', 'other'];

    /** Arabic labels for exports (the UI has its own copy in resources/js/lib/labels.ts). */
    public const METHOD_LABELS = ['cash' => 'نقدًا', 'bank_transfer' => 'تحويل بنكي', 'card' => 'بطاقة (جهاز الشبكة)', 'paymob' => 'دفع إلكتروني', 'other' => 'أخرى'];

    public const KIND_LABELS = [self::KIND_PAYMENT => 'دفعة', self::KIND_REFUND => 'رد مبلغ'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:3',
            'paid_at' => 'datetime',
            'confirmed_at' => 'datetime',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function payer(): MorphTo
    {
        return $this->morphTo()->withTrashed();
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function refundOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'refund_of_id');
    }

    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where($query->qualifyColumn('status'), self::COMPLETED);
    }

    /** Signed amount for cash-flow sums: refunds count negative. */
    public function signedAmount(): float
    {
        return $this->kind === self::KIND_REFUND ? -(float) $this->amount : (float) $this->amount;
    }
}
