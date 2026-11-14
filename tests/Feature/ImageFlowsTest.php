<?php

namespace Tests\Feature;

use App\Core\Branding\OfficialLogo;
use App\Core\Papers\PaperSize;
use App\Core\Support\ImageStore;
use App\Modules\Gym\Models\Member;
use App\Modules\Inventory\Models\Product;
use App\Modules\Website\Models\SiteMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The places images are uploaded save all of it or none of it: a refused image saves no record and leaves no file,
 * a replaced image goes only after its replacement is saved, and what was saved comes back when the page is opened
 * again — members, products, the logo (screens and PDF) and the website library, HEIC included.
 */
class ImageFlowsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // These describe MEDIA_STORAGE=originals (the way back); the default, optimized-only, is OptimizedOnlyStorageTest.
        config(['media.storage' => 'originals']);
        Storage::fake('public');
        Storage::fake('local');
    }

    private function jpeg(int $w = 300, int $h = 200): UploadedFile
    {
        return UploadedFile::fake()->image('photo.jpg', $w, $h);
    }

    private function disguised(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('photo.jpg', '<?php echo "not an image"; ?>');
    }

    private function heic(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('IMG_0001.HEIC', (string) file_get_contents(base_path('tests/Fixtures/images/gradient-96x64.heic')));
    }

    /** @return list<string> */
    private function files(): array
    {
        return [...Storage::disk('public')->allFiles(), ...Storage::disk('local')->allFiles()];
    }

    public function test_a_member_with_a_disguised_photo_is_not_registered_and_leaves_no_file(): void
    {
        $club = $this->club();

        $this->actingAs($this->owner($club))
            ->post(route('club.members.store'), ['full_name' => 'Noor', 'phone' => '96891112233', 'photo' => $this->disguised()])
            ->assertSessionHasErrors('photo');

        $this->assertSame(0, $this->asTenant($club, fn () => Member::count()));
        $this->assertSame([], $this->files());
    }

    public function test_a_member_photo_is_kept_with_its_original_and_replaced_only_after_the_save(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);

        $this->actingAs($owner)->post(route('club.members.store'), ['full_name' => 'Noor', 'phone' => '96891112233', 'photo' => $this->jpeg()])
            ->assertSessionHasNoErrors();
        $member = $this->asTenant($club, fn () => Member::firstOrFail());
        $first = $member->photo_path;
        $this->assertTrue(ImageStore::hasOriginal($first));
        Storage::disk('local')->assertExists(ImageStore::parse($first)['original']);

        $this->put(route('club.members.update', $member), ['full_name' => 'Noor', 'phone' => '96891112233', 'photo' => $this->jpeg(400, 400)])
            ->assertSessionHasNoErrors();
        $second = $this->asTenant($club, fn () => $member->fresh()->photo_path);
        $this->assertNotSame($first, $second);
        Storage::disk('public')->assertMissing([$first, ImageStore::parse($first)['thumb']]);
        Storage::disk('local')->assertMissing(ImageStore::parse($first)['original']);

        $this->put(route('club.members.update', $member), ['full_name' => 'Noor', 'phone' => '96891112233', 'photo' => $this->disguised()])
            ->assertSessionHasErrors('photo');
        $this->assertSame($second, $this->asTenant($club, fn () => $member->fresh()->photo_path), 'a refused photo keeps the one there');
        Storage::disk('public')->assertExists($second);
    }

    public function test_product_photos_are_all_saved_or_none(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);

        $this->actingAs($owner)->post(route('club.products.store'), ['name' => 'Bar', 'price' => 1, 'photos' => [$this->jpeg(), $this->disguised()]])
            ->assertSessionHasErrors('photos.1');
        $this->assertSame(0, $this->asTenant($club, fn () => Product::count()));
        $this->assertSame([], $this->files(), 'the first photo is removed with the refusal');

        $this->post(route('club.products.store'), ['name' => 'Bar', 'price' => 1, 'photos' => [$this->jpeg(), $this->jpeg(200, 300)]])
            ->assertSessionHasNoErrors();
        $product = $this->asTenant($club, fn () => Product::firstOrFail());
        $paths = [$product->image_path, ...$product->images];
        $this->assertCount(2, $paths);

        $this->get(route('club.products.edit', $product))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->has('product.photos', 2));

        $this->post(route('club.products.photo', [$product, 0]), ['action' => 'remove'])->assertRedirect()->assertSessionHasNoErrors();
        $product = $this->asTenant($club, fn () => $product->fresh());
        $this->assertSame($paths[1], $product->image_path);
        Storage::disk('public')->assertMissing($paths[0]);
        Storage::disk('local')->assertMissing(ImageStore::parse($paths[0])['original']);
        Storage::disk('public')->assertExists($paths[1]);
    }

    public function test_a_new_logo_keeps_its_original_prints_as_png_and_the_old_one_goes_after_the_save(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $form = fn (array $extra) => ['name' => $club->name, 'primary_color' => '#9d174d', 'secondary_color' => '#f9a8d4', '_method' => 'put'] + $extra;

        $this->actingAs($owner)->post(route('club.settings.update'), $form(['logo' => UploadedFile::fake()->image('logo.png', 800, 200)]))->assertSessionHasNoErrors();
        $first = $club->fresh()->setting('logo_file');
        $this->assertStringEndsWith('-png.png', $first);
        Storage::disk('local')->assertExists(ImageStore::parse($first)['original']);
        $this->assertSame(IMAGETYPE_PNG, getimagesize(OfficialLogo::forPaper(PaperSize::A4, true, $club->fresh()))[2], 'the PDF engine reads a PNG');

        $this->post(route('club.settings.update'), $form(['logo' => $this->disguised()]))->assertSessionHasErrors('logo');
        $this->assertSame($first, $club->fresh()->setting('logo_file'));
        Storage::disk('public')->assertExists($first);

        $this->post(route('club.settings.update'), $form(['logo' => UploadedFile::fake()->image('logo2.png', 400, 400)]))->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($first);
        Storage::disk('local')->assertMissing(ImageStore::parse($first)['original']);

        $second = $club->fresh()->setting('logo_file');
        $this->post(route('club.settings.update'), $form(['remove_logo' => true]))->assertSessionHasNoErrors();
        $this->assertNull($club->fresh()->setting('logo_file'));
        Storage::disk('public')->assertMissing($second);
    }

    public function test_the_website_library_takes_a_heic_with_the_browsers_display_source(): void
    {
        $club = $this->club();

        $this->actingAs($this->owner($club))->post(route('club.website.media.store'), [
            'images' => [$this->heic()],
            'images_preview' => [UploadedFile::fake()->image('IMG_0001.jpg', 96, 64)],
        ])->assertSessionHasNoErrors();

        $media = $this->asTenant($club, fn () => SiteMedia::firstOrFail());
        $this->assertStringEndsWith('-heic.webp', $media->path);
        $this->assertSame([96, 64], [$media->width, $media->height]);
        $this->assertSame(hash_file('sha256', base_path('tests/Fixtures/images/gradient-96x64.heic')), ImageStore::sha256('local', ImageStore::parse($media->path)['original']));
    }

    public function test_the_website_library_refuses_a_heic_without_one_with_the_reason(): void
    {
        $club = $this->club();

        $this->actingAs($this->owner($club))->post(route('club.website.media.store'), ['images' => [$this->heic()]])
            ->assertSessionHasErrors('images');

        $this->assertSame(0, $this->asTenant($club, fn () => SiteMedia::count()));
        $this->assertSame([], $this->files());
    }

    public function test_the_original_downloads_byte_for_byte_with_the_right_and_only_in_its_club(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $photo = UploadedFile::fake()->image('face.png', 320, 240);
        $bytes = (string) file_get_contents($photo->getRealPath());
        $this->actingAs($owner)->post(route('club.members.store'), ['full_name' => 'Noor', 'phone' => '96891112233', 'photo' => $photo])->assertSessionHasNoErrors();
        $member = $this->asTenant($club, fn () => Member::firstOrFail());

        $response = $this->get(route('club.members.photo', $member))->assertOk();
        $this->assertSame($bytes, $response->streamedContent());
        $this->assertStringContainsString('attachment', (string) $response->headers->get('content-disposition'));
        $this->assertStringContainsString('Noor.png', (string) $response->headers->get('content-disposition'));
        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));

        $this->actingAs($this->staff($club, 'trainer'))->get(route('club.members.photo', $member))->assertForbidden();

        $other = $this->club('Other');
        $this->actingAs($this->owner($other))->get(route('club.members.photo', $member))->assertNotFound();
    }

    public function test_the_edit_pages_link_the_original_and_the_lists_show_thumbnails(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->actingAs($owner)->post(route('club.products.store'), ['name' => 'Bar', 'price' => 1, 'photos' => [$this->jpeg(1200, 900)]])->assertSessionHasNoErrors();
        $product = $this->asTenant($club, fn () => Product::firstOrFail());

        $this->get(route('club.products.edit', $product))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('product.photo_downloads.0', route('club.products.photo.original', [$product, 0])));
        $this->get(route('club.products.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('products.data.0.image_url', fn ($url) => str_ends_with((string) $url, '-thumb.webp')));
        $this->get(route('club.products.photo.original', [$product, 0]))->assertOk();
        $this->get(route('club.products.photo.original', [$product, 3]))->assertNotFound();
    }
}
