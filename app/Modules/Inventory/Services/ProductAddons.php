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
     * @param  array<int, string>|null  $stock  one branch's quantities by product id (none: the club total)
     * @return array<int, list<array<string, mixed>>> keyed by product id
     */
    public function map(iterable $products, string $channel, bool $availableOnly = false, ?array $stock = null): array
    {
        $addons = $this->offered($channel);
        $map = [];
        foreach ($products as $product) {
            $map[$product->id] = $addons->filter(fn (ProductAddon $a) => $a->fits($product) && (! $availableOnly || $this->available($a, $stock)))
                ->map(fn (ProductAddon $a) => [
                    'id' => $a->id,
                    'name' => $a->name,
                    'description' => $a->description,
                    'price' => $a->price,
                    'max' => max(1, $a->max_quantity),
                    'available' => $this->available($a, $stock),
                ])->values()->all();
        }

        return $map;
    }

    /** Enough of the stock it uses for one more (or it uses none), in the branch given or the whole club. */
    public function available(ProductAddon $addon, ?array $stock = null): bool
    {
        $product = $addon->stockProduct;
        if (! $product || ! $product->track_stock) {
            return true;
        }

        return (float) ($stock === null ? $product->stock_quantity : ($stock[$product->id] ?? 0)) >= (float) $addon->stock_usage;
    }
}
