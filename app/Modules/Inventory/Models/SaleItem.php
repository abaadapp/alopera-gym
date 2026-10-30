<?php

namespace App\Modules\Inventory\Models;

use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A line of a sale: the product at its price on the day, and its add-ons (addons_total is part of line_total). */
class SaleItem extends Model
{
    use BelongsToTenant;

    protected $guarded = ['id', 'tenant_id'];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:3',
            'unit_price' => 'decimal:3',
            'unit_cost' => 'decimal:3',
            'addons_total' => 'decimal:3',
            'addons_cost' => 'decimal:3',
            'line_total' => 'decimal:3',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function addons(): HasMany
    {
        return $this->hasMany(SaleItemAddon::class)->orderBy('id');
    }

    /** What a receipt, an order page or a sales list shows of the line (add-ons loaded). No costs. */
    public function summary(): array
    {
        return $this->only('name', 'quantity', 'unit_price', 'line_total') + [
            'addons' => $this->addons->map(fn (SaleItemAddon $a) => $a->only('name', 'quantity', 'unit_price', 'line_total'))->all(),
        ];
    }
}
