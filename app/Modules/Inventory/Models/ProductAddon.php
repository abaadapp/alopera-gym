<?php

namespace App\Modules\Inventory\Models;

use App\Core\Audit\Auditable;
use App\Core\Tenancy\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * An extra a customer buys with a product: an extra scoop, a banana, a flavour. Offered on every product, on the
 * products of chosen categories, or on chosen products; at the point of sale and/or in the website shop. Its price
 * is always taken from here by the server, and copied onto the sale line when sold. It may use up stock of another
 * product (stock_usage of it per add-on).
 */
class ProductAddon extends Model
{
    use Auditable, BelongsToTenant, SoftDeletes;

    public const ALL = 'all';

    public const CATEGORIES = 'categories';

    public const PRODUCTS = 'products';

    public const SCOPES = [self::ALL, self::CATEGORIES, self::PRODUCTS];

    protected $guarded = ['id', 'tenant_id'];

    protected $attributes = ['is_active' => true, 'show_in_pos' => true, 'show_on_website' => true, 'applies_to' => self::PRODUCTS, 'max_quantity' => 1, 'stock_usage' => 1, 'sort_order' => 0];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:3',
            'stock_usage' => 'decimal:3',
            'max_quantity' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'show_in_pos' => 'boolean',
            'show_on_website' => 'boolean',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'product_addon_product');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(ProductCategory::class, 'product_addon_category');
    }

    /** The product whose stock it uses up (none: nothing is counted). */
    public function stockProduct(): BelongsTo
    {
        return $this->belongsTo(Product::class, 'stock_product_id');
    }

    /** Active and offered where the sale happens: at the point of sale ("pos") or in the website shop ("website"). */
    public function scopeOffered(Builder $query, string $channel): Builder
    {
        return $query->where('is_active', true)->where($channel === 'website' ? 'show_on_website' : 'show_in_pos', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    /** Whether it goes with this product (its products and categories loaded). */
    public function fits(Product $product): bool
    {
        return match ($this->applies_to) {
            self::ALL => true,
            self::CATEGORIES => $product->product_category_id !== null && $this->categories->contains('id', $product->product_category_id),
            default => $this->products->contains('id', $product->id),
        };
    }
}
