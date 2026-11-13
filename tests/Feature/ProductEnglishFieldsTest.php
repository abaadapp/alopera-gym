<?php

namespace Tests\Feature;

use App\Modules\Inventory\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The product form's English name and description (#68) are saved on add and on edit, come back when the product is
 * opened again, and are what the English website shop shows. They were validated but never saved.
 */
class ProductEnglishFieldsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_the_english_words_are_saved_reopened_and_shown_in_the_english_shop(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);

        $this->actingAs($owner)->post(route('club.products.store'), [
            'name' => 'بروتين', 'name_en' => 'Whey protein',
            'description' => 'وصف عربي', 'description_en' => 'Vanilla, 2 kg',
            'price' => 12.5, 'is_active' => true, 'show_on_website' => true,
        ])->assertSessionHasNoErrors();

        $product = $this->asTenant($club, fn () => Product::where('name', 'بروتين')->firstOrFail());
        $this->assertSame('Whey protein', $product->name_en);
        $this->assertSame('Vanilla, 2 kg', $product->description_en);

        $this->get(route('club.products.edit', $product))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('product.name_en', 'Whey protein')->where('product.description_en', 'Vanilla, 2 kg'));

        $this->put(route('club.products.update', $product), [
            'name' => 'بروتين', 'name_en' => 'Whey protein isolate', 'description' => 'وصف عربي', 'description_en' => null,
            'price' => 12.5, 'is_active' => true, 'show_on_website' => true,
        ])->assertSessionHasNoErrors();

        $product = $this->asTenant($club, fn () => $product->fresh());
        $this->assertSame('Whey protein isolate', $product->name_en);
        $this->assertNull($product->description_en, 'an emptied English description is cleared, not kept');
        $this->assertSame('بروتين', $product->name, 'the Arabic name is untouched');

        auth()->logout();
        $this->get('/c/'.$club->slug.'/en/shop')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('products.0.name', 'Whey protein isolate'));
        $this->get('/c/'.$club->slug.'/shop')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('products.0.name', 'بروتين'));
    }

    public function test_an_edit_without_the_english_fields_keeps_them(): void
    {
        $club = $this->club();
        $product = $this->asTenant($club, fn () => Product::create(['name' => 'Bar', 'name_en' => 'Protein bar', 'price' => 1]));

        $this->actingAs($this->owner($club))->put(route('club.products.update', $product), ['name' => 'Bar', 'price' => 2])
            ->assertSessionHasNoErrors();

        $this->assertSame('Protein bar', $this->asTenant($club, fn () => $product->fresh()->name_en));
    }
}
