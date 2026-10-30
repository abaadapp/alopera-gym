<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The products section's categories tab: the club's own categories in its own order, what is in each, renaming, and
 * removing one only when nothing uses it (a product or an add-on offered on it).
 */
class ProductCategoryController extends Controller
{
    public function index(): Response
    {
        $categories = ProductCategory::ordered()
            ->withCount(['products', 'products as active_count' => fn ($q) => $q->where('is_active', true)])
            ->withCount(['products as website_count' => fn ($q) => $q->where('is_active', true)->where('show_on_website', true)])
            ->with(['products' => fn ($q) => $q->select('id', 'name', 'product_category_id', 'image_path')->orderBy('name')])
            ->get();
        $addons = DB::table('product_addon_category as pc')->join('product_addons as a', 'a.id', '=', 'pc.product_addon_id')
            ->whereNull('a.deleted_at')->whereIn('pc.product_category_id', $categories->pluck('id'))
            ->selectRaw('pc.product_category_id, count(*) as n')->groupBy('pc.product_category_id')->pluck('n', 'product_category_id');

        return Inertia::render('Inventory/Categories/Index', [
            'categories' => $categories->map(fn (ProductCategory $c) => [
                'id' => $c->id,
                'name' => $c->name,
                'products' => $c->products_count,
                'active' => $c->active_count,
                'website' => $c->website_count,
                'addons' => (int) ($addons[$c->id] ?? 0),
                // A glimpse of what is in it; the products tab filtered on it shows them all.
                'sample' => $c->products->take(4)->map(fn ($p) => ['name' => $p->name, 'image_url' => $p->imageUrl()])->all(),
            ]),
            'uncategorised' => Product::whereNull('product_category_id')->count(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80', TenantRule::unique('product_categories', 'name')]]);
        ProductCategory::create(['name' => trim($data['name']), 'sort_order' => (int) ProductCategory::max('sort_order') + 1]);

        return back()->with('success', 'أُضيف التصنيف «'.trim($data['name']).'».');
    }

    public function update(Request $request, ProductCategory $category): RedirectResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:80', TenantRule::unique('product_categories', 'name')->ignore($category->id)]]);
        $category->update(['name' => trim($data['name'])]);

        return back()->with('success', 'تم حفظ التصنيف.');
    }

    /** The new order of the categories (every id of the club's, first to last). */
    public function reorder(Request $request): RedirectResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'max:500'], 'ids.*' => ['integer', TenantRule::exists('product_categories')]]);
        DB::transaction(function () use ($data) {
            foreach (array_values(array_unique($data['ids'])) as $i => $id) {
                ProductCategory::whereKey($id)->update(['sort_order' => $i + 1]);
            }
        });

        return back();
    }

    public function destroy(ProductCategory $category): RedirectResponse
    {
        $products = $category->products()->count();
        $addons = DB::table('product_addon_category as pc')->join('product_addons as a', 'a.id', '=', 'pc.product_addon_id')
            ->where('pc.product_category_id', $category->id)->whereNull('a.deleted_at')->count();
        if ($products || $addons) {
            throw new BusinessRuleException('category.in_use', 'لا يمكن حذف «'.$category->name.'»: '.collect([$products ? $products.' منتج' : null, $addons ? $addons.' إضافة' : null])->filter()->join(' و').' مرتبطة به. انقلها إلى تصنيف آخر أولًا.');
        }
        $category->delete();

        return back()->with('success', 'حُذف التصنيف «'.$category->name.'».');
    }
}
