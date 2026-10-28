<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Support\ImageStore;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class ProductController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Inventory/Products/Index', [
            'products' => Product::with('category:id,name')
                ->when($request->string('q')->toString(), fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'ilike', "%{$t}%")->orWhere('sku', $t)->orWhere('barcode', $t)))
                ->when($request->boolean('low'), fn ($q) => $q->lowStock())
                ->orderBy('name')->paginate(30)->withQueryString()
                ->through(fn (Product $p) => $p->only('id', 'name', 'sku', 'barcode', 'description', 'price', 'cost', 'stock_quantity', 'reorder_level', 'track_stock', 'is_active', 'product_category_id') + [
                    'category' => $p->category?->name, 'low' => $p->isLowStock(),
                ]),
            'categories' => ProductCategory::orderBy('name')->get(['id', 'name']),
            'filters' => $request->only('q', 'low'),
        ]);
    }

    public function store(Request $request, StockService $stock): RedirectResponse
    {
        $data = $this->validated($request);
        DB::transaction(function () use ($data, $request, $stock) {
            $product = new Product(collect($data)->except('image', 'opening_stock', 'opening_cost', 'category_name')->all());
            $product->product_category_id = $this->category($data);
            if ($request->hasFile('image')) {
                $product->image_path = ImageStore::store($request->file('image'), 'products', 800);
            }
            $product->save();
            if (! empty($data['opening_stock'])) {
                $stock->move($product, (float) $data['opening_stock'], 'adjustment', null, 'رصيد افتتاحي', (float) ($data['opening_cost'] ?? 0));
            }
        });

        return back()->with('success', 'تمت إضافة المنتج.');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validated($request, $product);
        $product->fill(collect($data)->except('image', 'opening_stock', 'opening_cost', 'category_name')->all());
        $product->product_category_id = $this->category($data);
        if ($request->hasFile('image')) {
            ImageStore::delete($product->image_path);
            $product->image_path = ImageStore::store($request->file('image'), 'products', 800);
        }
        $product->save();

        return back()->with('success', 'تم حفظ المنتج.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->update(['is_active' => false]);
        $product->delete();

        return back()->with('success', 'تم حذف المنتج.');
    }

    public function adjust(Request $request, Product $product, StockService $stock): RedirectResponse
    {
        $data = $request->validate([
            'counted' => ['required', 'numeric', 'min:0', 'max:1000000'],
            'reason' => ['required', 'string', 'max:200'],
        ]);
        $stock->adjustTo($product, (float) $data['counted'], $data['reason']);

        return back()->with('success', 'تم تعديل المخزون.');
    }

    public function movements(Product $product): JsonResponse
    {
        return response()->json(StockMovement::with('user:id,name')->where('product_id', $product->id)->latest('id')->limit(100)->get()
            ->map(fn ($m) => $m->only('id', 'type', 'quantity', 'unit_cost', 'balance_after', 'note') + ['user' => $m->user?->name, 'at' => $m->created_at->format('Y-m-d H:i')]));
    }

    private function category(array $data): ?int
    {
        if (! empty($data['category_name'])) {
            return ProductCategory::firstOrCreate(['name' => trim($data['category_name'])])->id;
        }

        return $data['product_category_id'] ?? null;
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Product $product = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'sku' => ['nullable', 'string', 'max:60', TenantRule::unique('products', 'sku')->ignore($product?->id)],
            'barcode' => ['nullable', 'string', 'max:60', TenantRule::unique('products', 'barcode')->ignore($product?->id)],
            'description' => ['nullable', 'string', 'max:1000'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999'],
            'reorder_level' => ['nullable', 'numeric', 'min:0'],
            'track_stock' => ['boolean'],
            'is_active' => ['boolean'],
            'product_category_id' => ['nullable', TenantRule::exists('product_categories')],
            'category_name' => ['nullable', 'string', 'max:80'],
            'opening_stock' => ['nullable', 'numeric', 'min:0'],
            'opening_cost' => ['nullable', 'numeric', 'min:0'],
            'image' => ['nullable', 'image', 'max:'.config('gym.max_image_kb')],
        ]) + ['reorder_level' => 0];
    }
}
