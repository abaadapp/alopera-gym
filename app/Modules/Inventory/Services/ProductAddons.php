<?php

namespace App\Modules\Inventory\Services;

use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductAddon;
use Illuminate\Support\Collection;

/** Which add-ons go with which product where the sale happens: the point of sale ("pos") or the website ("website"). */
final class ProductAddons
{
    /** @return Collection<int, ProductAddon> */
    public function offered(string $channel): Collection
    {
        return ProductAddon::offered($channel)->ordered()
            ->with(['products:products.id', 'categories:product_categories.id', 'stockProduct:id,name,stock_quantity,track_stock,cost'])
            ->get();
    }

    /**
     * Each product's add-ons, as the screen needs them (no costs, no stock figures).
     *
     * @param  iterable<Product>  $products
     * @return array<int, list<array<string, mixed>>> keyed by product id
     */
    public function map(iterable $products, string $channel, bool $availableOnly = false): array
    {
        $addons = $this->offered($channel);
        $map = [];
        foreach ($products as $product) {
            $map[$product->id] = $addons->filter(fn (ProductAddon $a) => $a->fits($product) && (! $availableOnly || $this->available($a)))
                ->map(fn (ProductAddon $a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'description' => $a->description,
                    'price' => $a->price,
                    'max' => max(1, $a->max_quantity),
                    'available' => $this->available($a),
                ])->values()->all();
        }

        return $map;
    }

    /** Enough of the stock it uses for one more (or it uses none). */
    public function available(ProductAddon $addon): bool
    {
        $stock = $addon->stockProduct;

        return ! $stock || ! $stock->track_stock || (float) $stock->stock_quantity >= (float) $addon->stock_usage;
    }
}
