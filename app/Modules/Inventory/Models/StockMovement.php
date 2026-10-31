<?php

namespace App\Modules\Inventory\Models;

use App\Core\Tenancy\BelongsToTenant;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class StockMovement extends Model
{
    use BelongsToTenant;

    /** What moved the stock, as the stock log names it. */
    public const TYPES = [
        'purchase' => 'استلام مشتريات', 'sale' => 'بيع', 'order' => 'محجوز لطلب من الموقع', 'release' => 'إلغاء طلب من الموقع',
        'void' => 'إلغاء بيع', 'return' => 'مرتجع', 'adjustment' => 'تسوية', 'transfer_out' => 'نقل — صادر', 'transfer_in' => 'نقل — وارد',
        'transfer_return' => 'نقل — عاد للمرسل',
    ];

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return ['quantity' => 'decimal:3', 'unit_cost' => 'decimal:3', 'balance_after' => 'decimal:3', 'branch_balance' => 'decimal:3'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
