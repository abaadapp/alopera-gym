<?php

namespace App\Modules\Inventory\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;

class Product extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    /** Stock and cost only change through StockService so every change leaves a movement row. */
    protected $guarded = ['id', 'tenant_id', 'stock_quantity', 'cost'];

    protected $attributes = ['is_active' => true, 'show_on_website' => false, 'track_stock' => true, 'stock_quantity' => 0, 'cost' => 0, 'reorder_level' => 0];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:3',
            'cost' => 'decimal:3',
            'stock_quantity' => 'decimal:3',
            'reorder_level' => 'decimal:3',
            'track_stock' => 'boolean',
            'is_active' => 'boolean',
            'show_on_website' => 'boolean',
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

    /** On sale in the club's website shop: available and chosen to be shown there. */
    public function scopeOnWebsite(Builder $query): Builder
    {
        return $query->where('is_active', true)->where('show_on_website', true);
    }

    /** What a customer can still order (null: not counted). */
    public function available(): ?float
    {
        return $this->track_stock ? max(0, (float) $this->stock_quantity) : null;
    }

    public function scopeLowStock(Builder $query): Builder
    {
        return $query->where('track_stock', true)->whereColumn('stock_quantity', '<=', 'reorder_level');
    }

    public function isLowStock(): bool
    {
        return $this->track_stock && (float) $this->stock_quantity <= (float) $this->reorder_level;
    }

    public function imageUrl(): ?string
    {
        return $this->image_path ? Storage::disk('public')->url($this->image_path) : null;
    }
}
