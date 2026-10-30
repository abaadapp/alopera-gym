<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductAddon;
use App\Modules\Inventory\Models\ProductCategory;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The products section's add-ons tab: every add-on in one list — its price, which products it goes with, where it is
 * offered (point of sale, website shop) and the stock it uses up. A product's own add-ons can also be switched from
 * the product's page; there is no second place to manage them.
 */
class ProductAddonController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'q' => $request->string('q')->trim()->toString(),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : '',
            'channel' => in_array($request->query('channel'), ['pos', 'website'], true) ? $request->query('channel') : '',
        ];
        $base = fn () => ProductAddon::query()
            ->when($filters['q'], fn ($q, $t) => $q->where('name', 'ilike', '%'.$t.'%'))
            ->when($filters['channel'] === 'pos', fn ($q) => $q->where('show_in_pos', true))
            ->when($filters['channel'] === 'website', fn ($q) => $q->where('show_on_website', true));
        $counts = $base()->toBase()->selectRaw('count(*) as total, count(*) filter (where is_active) as active, count(*) filter (where not is_active) as inactive')->first();

        $addons = $base()->ordered()
            ->when($filters['status'] !== '', fn ($q) => $q->where('is_active', $filters['status'] === 'active'))
            ->with(['products:products.id,name', 'categories:product_categories.id,name', 'stockProduct:id,name,stock_quantity,track_stock'])
            ->get();
        $sold = DB::table('sale_item_addons as sa')->join('sale_items as si', 'si.id', '=', 'sa.sale_item_id')->join('sales as s', 's.id', '=', 'si.sale_id')
            ->where('s.status', 'completed')->whereIn('sa.product_addon_id', $addons->pluck('id'))
            ->selectRaw('sa.product_addon_id, sum(sa.quantity * si.quantity) as n')->groupBy('sa.product_addon_id')->pluck('n', 'product_addon_id');

        return Inertia::render('Inventory/Addons/Index', [
            'addons' => $addons->map(fn (ProductAddon $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'description' => $a->description,
                'price' => $a->price,
                'max_quantity' => $a->max_quantity,
                'applies_to' => $a->applies_to,
                'product_ids' => $a->products->pluck('id')->all(),
                'product_names' => $a->products->pluck('name')->all(),
                'category_ids' => $a->categories->pluck('id')->all(),
                'category_names' => $a->categories->pluck('name')->all(),
                'show_in_pos' => $a->show_in_pos,
                'show_on_website' => $a->show_on_website,
                'is_active' => $a->is_active,
                'stock_product_id' => $a->stock_product_id,
                'stock_product' => $a->stockProduct?->name,
                'stock_usage' => $a->stock_usage,
                'stock_left' => $a->stockProduct?->track_stock ? $a->stockProduct->stock_quantity : null,
                'sold' => round((float) ($sold[$a->id] ?? 0), 3),
            ]),
            'counts' => ['all' => (int) $counts->total, 'active' => (int) $counts->active, 'inactive' => (int) $counts->inactive],
            'products' => Product::with('category:id,name')->orderBy('name')->get(['id', 'name', 'product_category_id', 'is_active', 'track_stock', 'stock_quantity'])
                ->map(fn (Product $p) => ['id' => $p->id, 'name' => $p->name, 'category' => $p->category?->name, 'is_active' => $p->is_active, 'track_stock' => $p->track_stock]),
            'categories' => ProductCategory::ordered()->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($data) {
            $addon = ProductAddon::create($this->attributes($data) + ['sort_order' => (int) ProductAddon::withTrashed()->max('sort_order') + 1]);
            $this->links($addon, $data);
        });

        return back()->with('success', 'أُضيفت الإضافة «'.$data['name'].'».');
    }

    public function update(Request $request, ProductAddon $addon): RedirectResponse
    {
        $data = $this->validated($request, $addon);
        DB::transaction(function () use ($addon, $data) {
            $addon->update($this->attributes($data));
            $this->links($addon, $data);
        });

        return back()->with('success', 'تم حفظ الإضافة «'.$addon->name.'».');
    }

    /** The switches on the list: active, at the point of sale, in the website shop. */
    public function toggle(Request $request, ProductAddon $addon): RedirectResponse
    {
        $data = $request->validate(['field' => ['required', 'in:is_active,show_in_pos,show_on_website'], 'value' => ['required', 'boolean']]);
        $addon->update([$data['field'] => (bool) $data['value']]);

        return back()->with('success', 'تم تحديث «'.$addon->name.'».');
    }

    /** Removed from sale; sales that included it keep its name and price as sold. */
    public function destroy(ProductAddon $addon): RedirectResponse
    {
        $addon->update(['is_active' => false]);
        $addon->delete();

        return back()->with('success', 'حُذفت الإضافة «'.$addon->name.'». تبقى في المبيعات السابقة كما بيعت.');
    }

    /** @return array<string, mixed> */
    private function attributes(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'description' => $data['description'] ?? null,
            'price' => $data['price'],
            'max_quantity' => $data['max_quantity'] ?? 1,
            'applies_to' => $data['applies_to'],
            'show_in_pos' => $data['show_in_pos'] ?? true,
            'show_on_website' => $data['show_on_website'] ?? true,
            'is_active' => $data['is_active'] ?? true,
            'stock_product_id' => $data['stock_product_id'] ?? null,
            'stock_usage' => ! empty($data['stock_product_id']) ? $data['stock_usage'] : 1,
        ];
    }

    private function links(ProductAddon $addon, array $data): void
    {
        $addon->products()->sync($data['applies_to'] === ProductAddon::PRODUCTS ? $data['product_ids'] ?? [] : []);
        $addon->categories()->sync($data['applies_to'] === ProductAddon::CATEGORIES ? $data['category_ids'] ?? [] : []);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?ProductAddon $addon = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:120', TenantRule::unique('product_addons', 'name')->ignore($addon?->id)->whereNull('deleted_at')],
            'description' => ['nullable', 'string', 'max:500'],
            'price' => ['required', 'numeric', 'min:0', 'max:9999'],
            'max_quantity' => ['nullable', 'integer', 'min:1', 'max:20'],
            'applies_to' => ['required', 'in:'.implode(',', ProductAddon::SCOPES)],
            'product_ids' => ['required_if:applies_to,products', 'array', 'max:1000'],
            'product_ids.*' => ['integer', TenantRule::exists('products')->whereNull('deleted_at')],
            'category_ids' => ['required_if:applies_to,categories', 'array', 'max:200'],
            'category_ids.*' => ['integer', TenantRule::exists('product_categories')],
            'show_in_pos' => ['boolean'],
            'show_on_website' => ['boolean'],
            'is_active' => ['boolean'],
            'stock_product_id' => ['nullable', 'integer', TenantRule::exists('products')->whereNull('deleted_at')],
            'stock_usage' => ['required_with:stock_product_id', 'nullable', 'numeric', 'min:0.001', 'max:1000'],
        ], [
            'product_ids.required_if' => 'اختر منتجًا واحدًا على الأقل.',
            'category_ids.required_if' => 'اختر تصنيفًا واحدًا على الأقل.',
            'stock_usage.required_with' => 'أدخل الكمية التي تُخصم من المخزون مع كل إضافة.',
        ]);
    }
}
