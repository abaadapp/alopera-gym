<?php

namespace App\Modules\Inventory\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Product extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    /** Stock and cost only change through StockService so every change leaves a movement row. */
    protected $guarded = ['id', 'tenant_id', 'stock_quantity', 'cost'];

    protected $attributes = ['is_active' => true, 'track_stock' => true, 'stock_quantity' => 0, 'cost' => 0, 'reorder_level' => 0];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:3',
            'cost' => 'decimal:3',
            'stock_quantity' => 'decimal:3',
            'reorder_level' => 'decimal:3',
            'track_stock' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ProductCategory::class, 'product_category_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class)->latest('id');
    }

    public function scopeLowStock(Builder $query): Builder
    {
        return $query->where('track_stock', true)->whereColumn('stock_quantity', '<=', 'reorder_level');
    }

    public function isLowStock(): bool
    {
        return $this->track_stock && (float) $this->stock_quantity <= (float) $this->reorder_level;
    }
}
