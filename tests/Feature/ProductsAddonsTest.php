<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\TenantIntegration;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\ProductAddon;
use App\Modules\Inventory\Models\ProductCategory;
use App\Modules\Inventory\Models\Sale;
use App\Modules\Inventory\Models\SaleItemAddon;
use App\Modules\Inventory\Models\StockMovement;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Payments\Models\PaymentIntent;
use App\Modules\Payments\Paymob\PaymobSignature;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The products section (products, categories, add-ons) as the one place products are managed, and add-ons sold with
 * products at the point of sale and in the website shop: priced on the server, kept on the sale at the price of the
 * day, billed on the invoice, stock taken and put back, and kept apart per club.
 */
class ProductsAddonsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function product(Tenant $club, array $attributes = [], int $stock = 10): Product
    {
        return $this->asTenant($club, function () use ($attributes, $stock) {
            $product = Product::create($attributes + ['name' => 'Protein shake', 'price' => 2, 'show_on_website' => true]);
            if ($stock) {
                app(StockService::class)->move($product, $stock, 'adjustment', null, 'opening', 0.8);
            }

            return $product->fresh();
        });
    }

    private function addon(Tenant $club, array $attributes, array $products = [], array $categories = []): ProductAddon
    {
        return $this->asTenant($club, function () use ($attributes, $products, $categories) {
            $addon = ProductAddon::create($attributes + ['price' => 0.5, 'applies_to' => $products ? 'products' : ($categories ? 'categories' : 'all')]);
            $addon->products()->sync(collect($products)->pluck('id'));
            $addon->categories()->sync(collect($categories)->pluck('id'));

            return $addon->fresh();
        });
    }

    private function balance(Tenant $club, string $key): string
    {
        return $this->asTenant($club, fn () => app(FinanceReports::class)->balanceOf($key));
    }

    /** A shake with an extra scoop (up to 2 per shake) and a banana that takes one banana off the shelf. */
    private function shakeWithAddons(Tenant $club): array
    {
        $shake = $this->product($club);
        $banana = $this->product($club, ['name' => 'Banana', 'price' => 0.2, 'show_on_website' => false], 10);
        $scoop = $this->addon($club, ['name' => 'Extra scoop', 'price' => 0.5, 'max_quantity' => 2], [$shake]);
        $fruit = $this->addon($club, ['name' => 'Add banana', 'price' => 0.3, 'stock_product_id' => $banana->id, 'stock_usage' => 1], [$shake]);

        return [$shake, $banana, $scoop, $fruit];
    }

    public function test_the_products_section_manages_products_categories_and_addons_and_selling_needs_none_of_it(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);

        foreach (['club.products.index' => 'Inventory/Products/Index', 'club.products.create' => 'Inventory/Products/Form', 'club.product-categories.index' => 'Inventory/Categories/Index', 'club.product-addons.index' => 'Inventory/Addons/Index'] as $name => $component) {
            $this->actingAs($owner)->get(route($name))->assertOk()->assertInertia(fn ($page) => $page->component($component));
        }

        $this->actingAs($owner)->post(route('club.product-categories.store'), ['name' => 'Drinks'])->assertSessionHasNoErrors();
        $drinks = $this->asTenant($club, fn () => ProductCategory::firstOrFail());
        $this->actingAs($owner)->post(route('club.product-addons.store'), [
            'name' => 'Peanut butter', 'price' => 0.4, 'applies_to' => 'products', 'product_ids' => [], 'show_in_pos' => true, 'show_on_website' => true,
        ])->assertSessionHasErrors('product_ids');
        $bar = $this->product($club, ['name' => 'Bar']);
        $this->actingAs($owner)->post(route('club.product-addons.store'), [
            'name' => 'Peanut butter', 'price' => 0.4, 'applies_to' => 'products', 'product_ids' => [$bar->id], 'max_quantity' => 2,
        ])->assertSessionHasNoErrors();
        $butter = $this->asTenant($club, fn () => ProductAddon::firstOrFail());

        // A product is created with its category and its own add-on, then changed; switches work from the list.
        $this->actingAs($owner)->post(route('club.products.store'), [
            'name' => 'Oat shake', 'price' => 2.25, 'product_category_id' => $drinks->id, 'track_stock' => true, 'is_active' => true, 'show_on_website' => false,
            'opening_stock' => 5, 'opening_cost' => 1, 'addon_ids' => [$butter->id], 'link_addons' => true,
        ])->assertRedirect(route('club.products.index'));
        $shake = $this->asTenant($club, fn () => Product::where('name', 'Oat shake')->firstOrFail());
        $this->asTenant($club, function () use ($shake, $drinks, $butter) {
            $this->assertSame([$drinks->id, '5.000'], [$shake->product_category_id, $shake->stock_quantity]);
            $this->assertTrue($butter->fresh()->products->contains($shake));
        });
        $this->actingAs($owner)->put(route('club.products.update', $shake), ['name' => 'Oat shake', 'price' => 2.5, 'link_addons' => true])->assertSessionHasNoErrors();
        $this->actingAs($owner)->patch(route('club.products.toggle', $shake), ['field' => 'show_on_website', 'value' => true])->assertSessionHasNoErrors();
        $this->asTenant($club, function () use ($shake, $butter) {
            $this->assertSame('2.500', $shake->fresh()->price);
            $this->assertTrue($shake->fresh()->show_on_website);
            $this->assertFalse($butter->fresh()->products->contains($shake), 'unlinked when the form sends no add-ons');
        });
        $this->actingAs($owner)->get(route('club.products.edit', $shake))->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/Products/Form')->where('product.name', 'Oat shake')->has('addons', 1));

        // Reception sells but cannot touch products, prices, categories or add-ons.
        $reception = $this->staff($club, 'reception');
        foreach (['club.products.index', 'club.products.create', 'club.product-categories.index', 'club.product-addons.index'] as $name) {
            $this->actingAs($reception)->get(route($name))->assertForbidden();
        }
        $this->actingAs($reception)->put(route('club.products.update', $shake), ['name' => 'Cheap', 'price' => 0.1])->assertForbidden();
        $this->actingAs($reception)->patch(route('club.products.toggle', $shake), ['field' => 'is_active', 'value' => false])->assertForbidden();
        $this->actingAs($reception)->put(route('club.product-addons.update', $butter), ['name' => 'Free', 'price' => 0, 'applies_to' => 'all'])->assertForbidden();
        $this->actingAs($reception)->post(route('club.product-categories.store'), ['name' => 'X'])->assertForbidden();
        $this->actingAs($reception)->post(route('club.pos.checkout'), ['items' => [['product_id' => $bar->id, 'quantity' => 1, 'addons' => [['addon_id' => $butter->id, 'quantity' => 2]]]], 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame('2.800', Sale::firstOrFail()->total));
    }

    public function test_an_addon_goes_only_with_its_products_and_where_it_is_offered(): void
    {
        $club = $this->club();
        $drinks = $this->asTenant($club, fn () => ProductCategory::create(['name' => 'Drinks']));
        $shake = $this->product($club, ['product_category_id' => $drinks->id]);
        $bar = $this->product($club, ['name' => 'Bar']);
        $scoop = $this->addon($club, ['name' => 'Scoop'], [$shake]);
        $ice = $this->addon($club, ['name' => 'Ice'], [], [$drinks]);
        $bag = $this->addon($club, ['name' => 'Gift bag', 'price' => 0.1]);
        $online = $this->addon($club, ['name' => 'Online only', 'show_in_pos' => false], [$shake]);
        $this->addon($club, ['name' => 'Retired', 'is_active' => false], [$shake]);

        $this->actingAs($this->owner($club))->get(route('club.pos.index'))->assertOk()->assertInertia(fn ($page) => $page->component('Inventory/Pos')
            ->where('products', fn ($products) => collect(collect($products)->firstWhere('name', 'Protein shake')['addons'])->pluck('name')->sort()->values()->all() === ['Gift bag', 'Ice', 'Scoop']
                && collect(collect($products)->firstWhere('name', 'Bar')['addons'])->pluck('name')->all() === ['Gift bag']));

        $owner = $this->owner($club);
        $sell = fn (Product $p, ProductAddon $a) => $this->actingAs($owner)->post(route('club.pos.checkout'), ['items' => [['product_id' => $p->id, 'quantity' => 1, 'addons' => [['addon_id' => $a->id, 'quantity' => 1]]]], 'payment_method' => 'cash']);
        $sell($bar, $scoop)->assertSessionHasErrors('items');
        $sell($bar, $ice)->assertSessionHasErrors('items');
        $sell($shake, $online)->assertSessionHasErrors('items');
        $this->asTenant($club, fn () => $this->assertSame(0, Sale::count()));

        $this->get(route('site.shop.product', ['club' => $club->slug, 'product' => $shake->id]))->assertOk()
            ->assertInertia(fn ($page) => $page->where('product.addons', fn ($addons) => collect($addons)->pluck('name')->sort()->values()->all() === ['Gift bag', 'Ice', 'Online only', 'Scoop']));
        $this->get(route('site.shop.product', ['club' => $club->slug, 'product' => $bar->id]))->assertOk()
            ->assertInertia(fn ($page) => $page->where('product.addons', fn ($addons) => collect($addons)->pluck('name')->all() === ['Gift bag']));
    }

    public function test_the_point_of_sale_prices_addons_on_the_server_and_keeps_them_on_the_sale_invoice_and_receipt(): void
    {
        $club = $this->club();
        [$shake, $banana, $scoop, $fruit] = $this->shakeWithAddons($club);
        $owner = $this->owner($club);

        $this->actingAs($owner)->post(route('club.pos.checkout'), ['items' => [['product_id' => $shake->id, 'quantity' => 1, 'addons' => [['addon_id' => $scoop->id, 'quantity' => 3]]]], 'payment_method' => 'cash'])
            ->assertSessionHasErrors('items');

        // Prices sent by the browser are ignored: 2 × (2.000 + 2 × 0.500 + 0.300) = 6.600.
        $this->actingAs($owner)->post(route('club.pos.checkout'), [
            'items' => [['product_id' => $shake->id, 'quantity' => 2, 'price' => 0.001, 'addons' => [['addon_id' => $scoop->id, 'quantity' => 2, 'price' => 0], ['addon_id' => $fruit->id, 'quantity' => 1]]]],
            'payment_method' => 'cash', 'total' => 0.001,
        ])->assertSessionHasNoErrors();

        $sale = $this->asTenant($club, fn () => Sale::with(['items.addons', 'invoice.items'])->firstOrFail());
        $this->asTenant($club, function () use ($sale, $shake, $banana) {
            $item = $sale->items->first();
            $this->assertSame(['6.600', '6.600', '2.600', '6.600'], [$sale->subtotal, $sale->total, $item->addons_total, $item->line_total]);
            $this->assertSame([['Extra scoop', '2.000', '0.500', '2.000'], ['Add banana', '1.000', '0.300', '0.600']], $item->addons->map(fn ($a) => [$a->name, $a->quantity, $a->unit_price, $a->line_total])->all());
            $this->assertSame([['Protein shake', '2.000', '2.000'], ['+ Extra scoop — Protein shake', '4.000', '0.500'], ['+ Add banana — Protein shake', '2.000', '0.300']],
                $sale->invoice->items->map(fn ($i) => [$i->description, $i->quantity, $i->unit_price])->all());
            $this->assertSame(['6.600', Invoice::PAID], [$sale->invoice->total, $sale->invoice->status]);
            $this->assertSame(1, Payment::count());
            $this->assertSame('8.000', $shake->fresh()->stock_quantity);
            $this->assertSame('8.000', $banana->fresh()->stock_quantity, 'two bananas, one per shake');
            $this->assertSame('1.600', $item->addons_cost);
        });
        $this->assertSame('6.600', $this->balance($club, 'revenue_pos'));
        $this->assertSame('6.600', $this->balance($club, 'cash'));

        $this->actingAs($owner)->get(route('club.sales.receipt', $sale))->assertOk()
            ->assertInertia(fn ($page) => $page->where('sale.items.0.addons.0.name', 'Extra scoop')->where('sale.items.0.addons.1.name', 'Add banana')->where('sale.items.0.line_total', '6.600'));

        // A price changed later does not change what was sold.
        $this->asTenant($club, fn () => $scoop->update(['price' => 0.9]));
        $this->asTenant($club, fn () => $this->assertSame('0.500', SaleItemAddon::where('name', 'Extra scoop')->value('unit_price')));
    }

    public function test_voiding_puts_back_the_product_and_addon_stock_and_refunds_once(): void
    {
        $club = $this->club();
        [$shake, $banana, $scoop, $fruit] = $this->shakeWithAddons($club);
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.pos.checkout'), ['items' => [['product_id' => $shake->id, 'quantity' => 3, 'addons' => [['addon_id' => $fruit->id, 'quantity' => 1]]]], 'payment_method' => 'card', 'card_reference' => 'T-1']);
        $sale = $this->asTenant($club, fn () => Sale::firstOrFail());
        // The add-on's stock rule changes after the sale; the void puts back what the sale took.
        $this->asTenant($club, fn () => $fruit->update(['stock_usage' => 5]));

        $this->actingAs($owner)->post(route('club.pos.void', $sale), ['reason' => 'Wrong order'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.pos.void', $sale), ['reason' => 'Again'])->assertSessionHasErrors();

        $this->asTenant($club, function () use ($sale, $shake, $banana) {
            $this->assertSame(Sale::VOIDED, $sale->fresh()->status);
            $this->assertSame(['10.000', '10.000'], [$shake->fresh()->stock_quantity, $banana->fresh()->stock_quantity]);
            $this->assertSame(2, StockMovement::where('type', 'void')->count());
            $this->assertSame(1, Payment::where('kind', Payment::KIND_REFUND)->count());
        });
        $this->assertSame('6.900', $this->balance($club, 'sales_returns'));
    }

    public function test_a_website_order_with_addons_is_priced_on_the_server_paid_once_and_cancelling_releases_stock(): void
    {
        Http::fake(['*/v1/intention/' => Http::response(['client_secret' => 'cs_abc', 'intention_order_id' => 4411], 201)]);
        $club = $this->club();
        $this->asTenant($club, fn () => TenantIntegration::create([
            'provider' => 'paymob', 'is_enabled' => true, 'mode' => 'test',
            'credentials' => ['secret_key' => 'sk_test', 'public_key' => 'pk_test', 'hmac_secret' => 'hmac-test', 'integration_ids' => '111'],
        ]));
        [$shake, $banana, $scoop, $fruit] = $this->shakeWithAddons($club);

        // Two lines of the same shake with the same add-ons are one line; with other add-ons, another.
        $this->post(route('site.shop.order.store', ['club' => $club->slug]), [
            'items' => [
                ['product_id' => $shake->id, 'quantity' => 1, 'addons' => [['addon_id' => $scoop->id, 'quantity' => 1], ['addon_id' => $fruit->id, 'quantity' => 1]]],
                ['product_id' => $shake->id, 'quantity' => 1, 'addons' => [['addon_id' => $fruit->id, 'quantity' => 1], ['addon_id' => $scoop->id, 'quantity' => 1]]],
                ['product_id' => $shake->id, 'quantity' => 1],
            ],
            'name' => 'Online', 'phone' => '91234567', 'payment_method' => 'paymob', 'total' => 0.01,
        ])->assertRedirectContains('clientSecret=cs_abc');

        [$sale, $intent] = $this->asTenant($club, fn () => [Sale::with('items.addons')->firstOrFail(), PaymentIntent::firstOrFail()]);
        // 2 × (2.000 + 0.500 + 0.300) + 2.000 = 7.600, and the gateway is asked for exactly that.
        $this->assertSame(['7.600', '7.600'], [$sale->total, $intent->amount]);
        $this->assertSame([2, 1], $sale->items->map(fn ($i) => (int) $i->quantity)->all());
        $this->asTenant($club, fn () => $this->assertSame(['7.000', '8.000'], [$shake->fresh()->stock_quantity, $banana->fresh()->stock_quantity], 'held for the order'));

        $this->get(route('site.shop.order', ['club' => $club->slug, 'token' => $sale->public_token]))->assertOk()
            ->assertInertia(fn ($page) => $page->where('order.items.0.addons.0.name', 'Extra scoop')->where('order.items.1.addons', []));

        $obj = ['id' => 777001, 'pending' => false, 'amount_cents' => 7600, 'success' => true, 'is_voided' => false, 'is_refunded' => false, 'error_occured' => false,
            'currency' => 'OMR', 'order' => ['id' => 4411, 'merchant_order_id' => $intent->reference], 'created_at' => '2026-10-30T10:00:00',
            'is_auth' => false, 'is_capture' => false, 'is_standalone_payment' => true, 'is_3d_secure' => true, 'integration_id' => 111,
            'has_parent_transaction' => false, 'owner' => 1, 'source_data' => ['pan' => '1234', 'sub_type' => 'Visa', 'type' => 'card']];
        $hmac = PaymobSignature::compute($obj, 'hmac-test');
        $this->postJson(route('webhooks.paymob').'?hmac='.$hmac, ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
        $this->postJson(route('webhooks.paymob').'?hmac='.$hmac, ['type' => 'TRANSACTION', 'obj' => $obj])->assertOk();
        $this->asTenant($club, function () use ($sale) {
            $this->assertNotNull($sale->fresh()->paid_at);
            $this->assertSame(1, Payment::count(), 'the repeated callback records nothing');
        });

        $this->actingAs($this->owner($club))->post(route('club.sales.orders.cancel', $sale), ['reason' => 'Out of oats', 'refund_method' => 'other'])->assertSessionHasNoErrors();
        $this->asTenant($club, function () use ($sale, $shake, $banana) {
            $this->assertSame(Sale::CANCELLED, $sale->fresh()->status);
            $this->assertSame(['10.000', '10.000'], [$shake->fresh()->stock_quantity, $banana->fresh()->stock_quantity]);
            $this->assertSame(1, Payment::where('kind', Payment::KIND_REFUND)->count());
        });
        $this->assertSame('7.600', $this->balance($club, 'revenue_pos'));
        $this->assertSame('7.600', $this->balance($club, 'sales_returns'));
    }

    public function test_an_addon_not_offered_on_the_website_cannot_be_ordered_there(): void
    {
        $club = $this->club();
        $shake = $this->product($club);
        $deskOnly = $this->addon($club, ['name' => 'Desk only', 'show_on_website' => false], [$shake]);

        $this->post(route('site.shop.order.store', ['club' => $club->slug]), [
            'items' => [['product_id' => $shake->id, 'quantity' => 1, 'addons' => [['addon_id' => $deskOnly->id, 'quantity' => 1]]]],
            'name' => 'Salim', 'phone' => '91234567', 'payment_method' => 'pickup',
        ])->assertSessionHasErrors('items');
        $this->asTenant($club, fn () => $this->assertSame([0, '10.000'], [Sale::count(), $shake->fresh()->stock_quantity]));
    }

    public function test_addons_categories_and_products_of_another_club_stay_out(): void
    {
        $a = $this->club('A');
        $b = $this->club('B');
        $shakeA = $this->product($a);
        $shakeB = $this->product($b, ['name' => 'B shake']);
        $addonB = $this->addon($b, ['name' => 'B scoop'], [$shakeB]);
        $categoryB = $this->asTenant($b, fn () => ProductCategory::create(['name' => 'B drinks']));
        $ownerA = $this->owner($a);

        $this->actingAs($ownerA)->post(route('club.pos.checkout'), ['items' => [['product_id' => $shakeA->id, 'quantity' => 1, 'addons' => [['addon_id' => $addonB->id, 'quantity' => 1]]]], 'payment_method' => 'cash'])
            ->assertSessionHasErrors('items.0.addons.0.addon_id');
        $this->actingAs($ownerA)->post(route('club.product-addons.store'), ['name' => 'Sneaky', 'price' => 0.1, 'applies_to' => 'products', 'product_ids' => [$shakeB->id]])->assertSessionHasErrors('product_ids.0');
        $this->actingAs($ownerA)->post(route('club.product-addons.store'), ['name' => 'Sneaky', 'price' => 0.1, 'applies_to' => 'categories', 'category_ids' => [$categoryB->id]])->assertSessionHasErrors('category_ids.0');
        $this->actingAs($ownerA)->put(route('club.product-addons.update', $addonB), ['name' => 'Mine', 'price' => 0, 'applies_to' => 'all'])->assertNotFound();
        $this->actingAs($ownerA)->delete(route('club.product-categories.destroy', $categoryB))->assertNotFound();
        $this->actingAs($ownerA)->get(route('club.products.edit', $shakeB))->assertNotFound();
        $this->actingAs($ownerA)->get(route('club.product-addons.index'))->assertInertia(fn ($page) => $page->has('addons', 0)->has('products', 1));
        $this->actingAs($ownerA)->get(route('club.product-categories.index'))->assertInertia(fn ($page) => $page->has('categories', 0));
    }

    public function test_a_category_in_use_is_not_deleted_and_categories_keep_their_order(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        [$drinks, $snacks, $empty] = $this->asTenant($club, fn () => [ProductCategory::create(['name' => 'Drinks', 'sort_order' => 1]), ProductCategory::create(['name' => 'Snacks', 'sort_order' => 2]), ProductCategory::create(['name' => 'Empty', 'sort_order' => 3])]);
        $shake = $this->product($club, ['product_category_id' => $drinks->id]);
        $this->addon($club, ['name' => 'Salt'], [], [$snacks]);

        $this->actingAs($owner)->delete(route('club.product-categories.destroy', $drinks))->assertSessionHasErrors();
        $this->actingAs($owner)->delete(route('club.product-categories.destroy', $snacks))->assertSessionHasErrors();
        $this->actingAs($owner)->delete(route('club.product-categories.destroy', $empty))->assertSessionHasNoErrors();
        $this->asTenant($club, function () use ($shake, $drinks) {
            $this->assertSame(2, ProductCategory::count());
            $this->assertSame($drinks->id, $shake->fresh()->product_category_id);
        });

        $this->actingAs($owner)->put(route('club.product-categories.update', $drinks), ['name' => 'Shakes'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->put(route('club.product-categories.update', $snacks), ['name' => 'Shakes'])->assertSessionHasErrors('name');
        $this->actingAs($owner)->post(route('club.product-categories.reorder'), ['ids' => [$snacks->id, $drinks->id]])->assertSessionHasNoErrors();
        $this->actingAs($owner)->get(route('club.product-categories.index'))->assertInertia(fn ($page) => $page
            ->where('categories.0.name', 'Snacks')->where('categories.0.addons', 1)
            ->where('categories.1.name', 'Shakes')->where('categories.1.products', 1));
    }

    public function test_older_product_addresses_still_lead_to_the_products_section(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $shake = $this->product($club);

        $this->actingAs($owner)->get(route('club.products.index', ['low' => 1]))->assertOk()->assertInertia(fn ($page) => $page->where('filters.stock', 'low'));
        $this->actingAs($owner)->get(route('club.products.index', ['website' => 1]))->assertOk()->assertInertia(fn ($page) => $page->where('filters.website', '1')->has('products.data', 1));
        $this->actingAs($owner)->get('/app/sales/products')->assertRedirect(route('club.products.index'));
        $this->actingAs($owner)->get('/app/products/'.$shake->id)->assertRedirect(route('club.products.edit', $shake));
        $this->actingAs($this->staff($club, 'reception'))->get('/app/sales/products')->assertForbidden();
    }

    public function test_the_sales_report_shows_addons_inside_the_product_lines(): void
    {
        $club = $this->club();
        [$shake, , $scoop] = $this->shakeWithAddons($club);
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.pos.checkout'), ['items' => [['product_id' => $shake->id, 'quantity' => 2, 'addons' => [['addon_id' => $scoop->id, 'quantity' => 1]]]], 'payment_method' => 'cash']);

        $this->actingAs($owner)->get(route('club.reports.show', 'sales'))->assertOk()->assertInertia(fn ($page) => $page
            ->where('data.products.0.name', 'Protein shake')->where('data.products.0.total', '5.000')
            ->where('data.addons.0.name', 'Extra scoop')->where('data.addons.0.quantity', fn ($q) => (float) $q === 2.0)->where('data.addons.0.total', '1.000'));
    }

    public function test_photos_are_added_ordered_and_removed(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $shake = $this->product($club);
        Storage::fake('public');

        $this->actingAs($owner)->post(route('club.products.update', $shake), [
            '_method' => 'put', 'name' => 'Protein shake', 'price' => 2,
            'photos' => [UploadedFile::fake()->image('a.jpg', 50, 50), UploadedFile::fake()->image('b.png', 50, 50)],
        ])->assertSessionHasNoErrors();
        $product = $this->asTenant($club, fn () => $shake->fresh());
        [$first, $second] = [$product->image_path, $product->images[0] ?? null];
        $this->assertNotNull($first);
        $this->assertCount(1, $product->images);

        // The second photo becomes the main one, then is removed: the first is the main photo again.
        $this->actingAs($owner)->post(route('club.products.photo', ['product' => $shake->id, 'index' => 1]), ['action' => 'main'])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame([$second, [$first]], [$shake->fresh()->image_path, $shake->fresh()->images]));
        $this->actingAs($owner)->post(route('club.products.photo', ['product' => $shake->id, 'index' => 0]), ['action' => 'remove'])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame([$first, null], [$shake->fresh()->image_path, $shake->fresh()->images]));
        Storage::disk('public')->assertMissing($second);
        Storage::disk('public')->assertExists($first);

        $this->actingAs($owner)->post(route('club.products.update', $shake), ['_method' => 'put', 'name' => 'Protein shake', 'price' => 2, 'photos' => [UploadedFile::fake()->create('x.svg', 1, 'image/svg+xml')]])
            ->assertSessionHasErrors('photos.0');
    }
}
