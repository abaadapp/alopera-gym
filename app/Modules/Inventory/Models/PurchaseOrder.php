<?php

namespace App\Modules\Inventory\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use App\Core\Tenancy\BranchOwned;
use App\Core\Tenancy\OwnedByBranch;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model implements BranchOwned
{
    use Auditable, BelongsToTenant, OwnedByBranch;

    public const DRAFT = 'draft';

    public const ORDERED = 'ordered';

    public const PARTIAL = 'partial';

    public const RECEIVED = 'received';

    public const CANCELLED = 'cancelled';

    /** Received in part and nothing more is expected. */
    public const CLOSED = 'closed';

    public const LABELS = [self::DRAFT => 'مسودة', self::ORDERED => 'معتمد — أُرسل للمورد', self::PARTIAL => 'مستلم جزئيًا', self::RECEIVED => 'مستلم', self::CANCELLED => 'ملغى', self::CLOSED => 'مغلق'];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'order_date' => 'immutable_date',
            'expected_on' => 'immutable_date',
            'subtotal' => 'decimal:3',
            'discount' => 'decimal:3',
            'shipping' => 'decimal:3',
            'tax_rate' => 'decimal:2',
            'tax' => 'decimal:3',
            'total' => 'decimal:3',
            'received_at' => 'datetime',
            'approved_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(GoodsReceipt::class)->orderBy('id');
    }

    public function bills(): HasMany
    {
        return $this->hasMany(SupplierBill::class)->orderBy('id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /** Still expecting goods. */
    public function isReceivable(): bool
    {
        return in_array($this->status, [self::ORDERED, self::PARTIAL], true);
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class)->orderBy('id');
    }
}
