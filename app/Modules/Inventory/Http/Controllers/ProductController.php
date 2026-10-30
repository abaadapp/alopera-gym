<?php

namespace App\Modules\Inventory\Http\Controllers;

use App\Core\Support\ImageStore;
use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductAddon;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The products section's first tab, and the one place products are created and changed: the list (table or cards)
 * with its filters, the add/edit page with photos, stock and the product's add-ons, quick on/off switches, stock
 * counts and the stock card. Selling them (point of sale, website shop) needs no right to change them.
 */
class ProductController extends Controller
{
    /** Photos per product: the main one and up to five more. */
    private const PHOTOS = 6;

    public function index(Request $request): Response
    {
        $filters = [
            'q' => $request->string('q')->trim()->toString(),
            'category' => $request->string('category')->toString(),
            'status' => in_array($request->query('status'), ['active', 'inactive'], true) ? $request->query('status') : '',
            // ?low=1 and ?website=1 are the addresses older links (dashboard, sales summary) still use.
            'stock' => in_array($request->query('stock'), ['low', 'out', 'untracked'], true) ? $request->query('stock') : ($request->boolean('low') ? 'low' : ''),
            'website' => $request->boolean('website') ? '1' : ($request->query('website') === '0' ? '0' : ''),
            'sort' => in_array($request->query('sort'), ['price', 'stock', 'newest'], true) ? $request->query('sort') : 'name',
        ];

        $base = fn () => Product::query()
            ->when($filters['q'], fn ($q, $t) => $q->where(fn ($w) => $w->where('name', 'ilike', '%'.$t.'%')->orWhere('sku', 'ilike', $t)->orWhere('barcode', $t)))
            ->when($filters['category'] === 'none', fn ($q) => $q->whereNull('product_category_id'))
            ->when(ctype_digit($filters['category']), fn ($q) => $q->where('product_category_id', (int) $filters['category']))
            ->when($filters['stock'] === 'low', fn ($q) => $q->lowStock())
            ->when($filters['stock'] === 'out', fn ($q) => $q->where('track_stock', true)->where('stock_quantity', '<=', 0))
            ->when($filters['stock'] === 'untracked', fn ($q) => $q->where('track_stock', false))
            ->when($filters['website'] !== '', fn ($q) => $q->where('show_on_website', $filters['website'] === '1'));

        $counts = $base()->toBase()->selectRaw('count(*) as total, count(*) filter (where is_active) as active, count(*) filter (where not is_active) as inactive')->first();
        $addons = ProductAddon::where('is_active', true)->with(['products:products.id', 'categories:product_categories.id'])->get();

        $products = $base()->with('category:id,name')
            ->when($filters['status'] !== '', fn ($q) => $q->where('is_active', $filters['status'] === 'active'))
            ->tap(fn (Builder $q) => match ($filters['sort']) {
                'price' => $q->orderByDesc('price'),
                'stock' => $q->orderByRaw('track_stock desc, stock_quantity asc'),
                'newest' => $q->latest('id'),
                default => $q->orderBy('name'),
            })
            ->paginate(30)->withQueryString()
            ->through(fn (Product $p) => $this->row($p) + ['addons' => $addons->filter(fn (ProductAddon $a) => $a->fits($p))->count()]);

        return Inertia::render('Inventory/Products/Index', [
            'products' => $products,
            'counts' => ['all' => (int) $counts->total, 'active' => (int) $counts->active, 'inactive' => (int) $counts->inactive],
            'categories' => ProductCategory::ordered()->get(['id', 'name']),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        return $this->form(null);
    }

    public function edit(Product $product): Response
    {
        return $this->form($product);
    }

    public function store(Request $request, StockService $stock): RedirectResponse
    {
        $data = $this->validated($request);
        $product = DB::transaction(function () use ($data, $request, $stock) {
            $product = new Product($this->attributes($data));
            $product->product_category_id = $this->category($data);
            $paths = $this->storePhotos($request->file('photos', []), self::PHOTOS);
            $product->image_path = array_shift($paths);
            $product->images = $paths ?: null;
            $product->save();
            if ($request->boolean('link_addons')) {
                $this->syncAddons($product, $data['addon_ids'] ?? []);
            }
            if (! empty($data['opening_stock']) && $product->track_stock) {
                $stock->move($product, (float) $data['opening_stock'], 'adjustment', null, 'رصيد افتتاحي', (float) ($data['opening_cost'] ?? 0));
            }

            return $product;
        });

        return redirect()->route('club.products.index')->with('success', 'تمت إضافة المنتج «'.$product->name.'».');
    }

    public function update(Request $request, Product $product): RedirectResponse
    {
        $data = $this->validated($request, $product);
        DB::transaction(function () use ($data, $request, $product) {
            $product->fill($this->attributes($data));
            $product->product_category_id = $this->category($data);
            $room = self::PHOTOS - count($product->imageUrls());
            $paths = $this->storePhotos($request->file('photos', []), max(0, $room));
            if ($paths && ! $product->image_path) {
                $product->image_path = array_shift($paths);
            }
            $product->images = array_values([...($product->images ?? []), ...$paths]) ?: null;
            $product->save();
            if ($request->boolean('link_addons')) {
                $this->syncAddons($product, $data['addon_ids'] ?? []);
            }
        });

        return redirect()->route('club.products.index')->with('success', 'تم حفظ «'.$product->name.'».');
    }

    /** The switches on the list: available for sale, shown in the website shop. */
    public function toggle(Request $request, Product $product): RedirectResponse
    {
        $data = $request->validate(['field' => ['required', 'in:is_active,show_on_website'], 'value' => ['required', 'boolean']]);
        $product->update([$data['field'] => (bool) $data['value']]);

        $message = $data['field'] === 'is_active'
            ? ($data['value'] ? 'أصبح «'.$product->name.'» متاحًا للبيع.' : 'أُوقف بيع «'.$product->name.'».')
            : ($data['value'] ? 'يظهر «'.$product->name.'» الآن في متجر الموقع.' : 'أُخفي «'.$product->name.'» من متجر الموقع.');

        return back()->with('success', $message);
    }

    /** A photo of the product (0 is the main one): made the main photo, or removed. */
    public function photo(Request $request, Product $product, int $index): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', 'in:main,remove']]);
        $paths = array_values(array_filter([$product->image_path, ...($product->images ?? [])]));
        abort_unless(isset($paths[$index]), 404);

        if ($data['action'] === 'remove') {
            ImageStore::delete($paths[$index]);
            array_splice($paths, $index, 1);
        } else {
            array_unshift($paths, ...array_splice($paths, $index, 1));
        }
        $product->image_path = $paths[0] ?? null;
        $product->images = array_slice($paths, 1) ?: null;
        $product->save();

        return back()->with('success', $data['action'] === 'remove' ? 'حُذفت الصورة.' : 'أصبحت الصورة الرئيسية.');
    }

    public function destroy(Product $product): RedirectResponse
    {
        $product->update(['is_active' => false]);
        $product->delete();

        return back()->with('success', 'تم حذف «'.$product->name.'». تبقى مبيعاته وحركات مخزونه في السجلات والتقارير.');
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

    private function form(?Product $product): Response
    {
        $linked = $product ? $product->addons()->pluck('product_addons.id')->all() : [];
        $addons = ProductAddon::ordered()->with(['categories:product_categories.id,name'])->get();

        return Inertia::render('Inventory/Products/Form', [
            'product' => $product ? $this->row($product->load('category:id,name')) + [
                'description' => $product->description,
                'reorder_level' => $product->reorder_level,
                'photos' => $product->imageUrls(),
            ] : null,
            'categories' => ProductCategory::ordered()->get(['id', 'name']),
            // Add-ons chosen product by product are switched here; the others follow their own rule (all products,
            // or categories) and are changed on the add-ons tab.
            'addons' => $addons->map(fn (ProductAddon $a) => [
                'id' => $a->id,
                'name' => $a->name,
                'price' => $a->price,
                'is_active' => $a->is_active,
                'applies_to' => $a->applies_to,
                'categories' => $a->categories->pluck('id')->all(),
                'category_names' => $a->categories->pluck('name')->all(),
                'linked' => in_array($a->id, $linked, true),
            ]),
            'maxPhotos' => self::PHOTOS,
        ]);
    }

    /** @return array<string, mixed> */
    private function row(Product $p): array
    {
        $price = (float) $p->price;

        return $p->only('id', 'name', 'sku', 'barcode', 'price', 'cost', 'stock_quantity', 'track_stock', 'is_active', 'show_on_website', 'product_category_id') + [
            'category' => $p->category?->name,
            'image_url' => $p->imageUrl(),
            'photos_count' => count($p->imageUrls()),
            'low' => $p->isLowStock(),
            'out' => $p->track_stock && (float) $p->stock_quantity <= 0,
            'margin' => $price > 0 ? round(($price - (float) $p->cost) / $price * 100, 1) : null,
        ];
    }

    /** @return array<string, mixed> */
    private function attributes(array $data): array
    {
        return collect($data)->only(['name', 'sku', 'barcode', 'description', 'price', 'reorder_level', 'track_stock', 'is_active', 'show_on_website'])->all();
    }

    private function category(array $data): ?int
    {
        if (! empty($data['category_name'])) {
            return ProductCategory::firstOrCreate(['name' => trim($data['category_name'])])->id;
        }

        return $data['product_category_id'] ?? null;
    }

    /**
     * Only add-ons offered on chosen products are linked from here; the rest keep their own rule. The form says it
     * sent the list (link_addons), since an empty list is not sent at all.
     */
    private function syncAddons(Product $product, array $ids): void
    {
        $chosen = ProductAddon::where('applies_to', ProductAddon::PRODUCTS)->pluck('id');
        $product->addons()->detach($chosen->diff($ids)->all());
        $product->addons()->syncWithoutDetaching($chosen->intersect($ids)->all());
    }

    /**
     * @param  array<int, UploadedFile>  $files
     * @return list<string>
     */
    private function storePhotos(array $files, int $room): array
    {
        return array_map(fn ($file) => ImageStore::store($file, 'products', 1000), array_slice($files, 0, $room));
    }

    /** @return array<string, mixed> */
    private function validated(Request $request, ?Product $product = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:160'],
            'sku' => ['nullable', 'string', 'max:60', TenantRule::unique('products', 'sku')->ignore($product?->id)],
            'barcode' => ['nullable', 'string', 'max:60', TenantRule::unique('products', 'barcode')->ignore($product?->id)],
            'description' => ['nullable', 'string', 'max:2000'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999'],
            'reorder_level' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'track_stock' => ['boolean'],
            'is_active' => ['boolean'],
            'show_on_website' => ['boolean'],
            'product_category_id' => ['nullable', TenantRule::exists('product_categories')],
            'category_name' => ['nullable', 'string', 'max:80'],
            'opening_stock' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'opening_cost' => ['nullable', 'numeric', 'min:0', 'max:99999'],
            'photos' => ['nullable', 'array', 'max:'.self::PHOTOS],
            'photos.*' => ['image', 'mimes:jpg,jpeg,png,webp', 'max:'.config('gym.max_image_kb')],
            'addon_ids' => ['nullable', 'array'],
            'addon_ids.*' => ['integer', TenantRule::exists('product_addons')->whereNull('deleted_at')],
        ]);
        $data['reorder_level'] ??= 0;

        return $data;
    }
}
