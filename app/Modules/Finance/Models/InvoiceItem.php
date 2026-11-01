<?php

namespace App\Modules\Finance\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A line of an invoice. An add-on sold with a product on a manual invoice is a line of its own under that product's
 * line (parent): its price, its quantity (per piece × the product's) and the add-on it was (itemable).
 */
class InvoiceItem extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:3',
            'discount' => 'decimal:3',
            'line_total' => 'decimal:3',
        ];
    }

    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }

    public function itemable(): MorphTo
    {
        return $this->morphTo();
    }

    /** The product line this add-on line was sold with (none: a line of its own). */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }
}
