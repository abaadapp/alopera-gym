<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Inventory\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Showing a product in the website shop from the products list tells the owner what visitors will really see:
 * «يظهر الآن» only when the shop is open and the product is for sale, otherwise what is still missing.
 */
class ProductShopToggleMessageTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function product(Tenant $club, array $attributes = []): Product
    {
        return $this->asTenant($club, fn () => Product::create($attributes + ['name' => 'Protein bar', 'price' => 1.5, 'show_on_website' => false]));
    }

    private function show(Tenant $club, Product $product): string
    {
        $this->actingAs($this->owner($club))
            ->patch(route('club.products.toggle', $product), ['field' => 'show_on_website', 'value' => true])
            ->assertSessionHasNoErrors();

        return (string) session('success');
    }

    public function test_with_the_shop_open_the_product_shows_now(): void
    {
        $club = $this->club();
        $bar = $this->product($club);

        $this->assertSame('يظهر «Protein bar» الآن في متجر الموقع.', $this->show($club, $bar));
        $this->get(route('site.shop', ['club' => $club->slug]))->assertOk();
    }

    public function test_with_the_shop_switched_off_the_owner_is_told_where_to_open_it(): void
    {
        $club = $this->club();
        $club->forceFill(['settings' => array_merge($club->settings ?? [], ['sales' => ['shop_enabled' => false]])])->save();
        $bar = $this->product($club);

        $message = $this->show($club, $bar);

        $this->assertStringNotContainsString('الآن في متجر الموقع', $message);
        $this->assertStringContainsString('إظهار المتجر في موقع النادي', $message);
        $this->assertTrue($this->asTenant($club, fn () => $bar->fresh()->show_on_website), 'the choice is still saved');
        $this->get(route('site.shop', ['club' => $club->slug]))->assertNotFound();
    }

    public function test_a_product_not_for_sale_is_chosen_but_not_said_to_show(): void
    {
        $club = $this->club();
        $bar = $this->product($club, ['is_active' => false]);

        $message = $this->show($club, $bar);

        $this->assertStringNotContainsString('الآن في متجر الموقع', $message);
        $this->assertStringContainsString('متاح للبيع', $message);
    }
}
