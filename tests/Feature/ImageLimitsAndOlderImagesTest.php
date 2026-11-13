<?php

namespace Tests\Feature;

use App\Core\Branding\OfficialLogo;
use App\Core\Papers\Paper;
use App\Core\Papers\PaperIssuer;
use App\Core\Papers\Papers;
use App\Core\Papers\PaperSize;
use App\Core\Papers\PaperTypes;
use App\Core\Support\Images\ImageInspector;
use App\Core\Support\Images\ImageRejected;
use App\Core\Support\ImageStore;
use App\Models\Tenant;
use App\Modules\Gym\Models\Member;
use App\Modules\Inventory\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The upload limits can be raised by settings without changing what production allows today, a file too large for
 * the server says so plainly, and every image stored before this keeps showing, printing and being replaced safely.
 */
class ImageLimitsAndOlderImagesTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function png(int $w, int $h): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, imagecolorallocate($image, 157, 23, 77));
        ob_start();
        imagepng($image);

        return (string) ob_get_clean();
    }

    /** A PNG that declares its size in its header only: enough for the limits, which never decode. */
    private function pngHeader(int $w, int $h): string
    {
        $ihdr = pack('NNCCCCC', $w, $h, 8, 6, 0, 0, 0);

        return "\x89PNG\r\n\x1A\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr)).pack('N', 0).'IEND'.pack('N', crc32('IEND'));
    }

    public function test_the_limits_stay_as_they_are_unless_set(): void
    {
        $this->assertSame(4096, config('gym.max_image_kb'));
        $this->assertSame(40_000_000, config('media.max_pixels'));
        $this->assertSame(20_480, config('media.max_request_kb'));
    }

    public function test_a_48_megapixel_phone_photo_passes_once_the_pixel_limit_is_raised(): void
    {
        $file = UploadedFile::fake()->createWithContent('IMG_48MP.png', $this->pngHeader(8064, 6048));

        try {
            ImageInspector::inspect($file->getRealPath());
            $this->fail('48.8 million pixels passed the default 40 million');
        } catch (ImageRejected) {
            $this->addToAssertionCount(1);
        }

        config(['media.max_pixels' => 50_000_000]);
        $this->assertSame([8064, 6048], [ImageInspector::inspect($file->getRealPath())->width, ImageInspector::inspect($file->getRealPath())->height]);
    }

    public function test_a_raised_size_limit_takes_a_larger_file(): void
    {
        $club = $this->club();
        $bytes = $this->png(2400, 2400);
        config(['gym.max_image_kb' => (int) floor(strlen($bytes) / 1024) - 1]);
        $form = fn () => ['full_name' => 'Noor', 'phone' => '96891112233', 'photo' => UploadedFile::fake()->createWithContent('p.png', $bytes)];

        $this->actingAs($this->owner($club))->post(route('club.members.store'), $form())->assertSessionHasErrors('photo');

        config(['gym.max_image_kb' => 25_600]);
        $this->post(route('club.members.store'), $form())->assertSessionHasNoErrors();
    }

    public function test_a_file_the_server_would_not_take_is_named_as_too_large(): void
    {
        $club = $this->club();
        $tmp = tempnam(sys_get_temp_dir(), 'big');
        file_put_contents($tmp, 'x');
        $tooBig = new UploadedFile($tmp, 'IMG_0001.jpg', 'image/jpeg', UPLOAD_ERR_INI_SIZE, true);

        $this->actingAs($this->owner($club))
            ->post(route('club.members.store'), ['full_name' => 'Noor', 'phone' => '96891112233', 'photo' => $tooBig])
            ->assertSessionHasErrors('photo');
        $this->assertStringContainsString('أكبر مما يقبله الخادم', session('errors')->first('photo'));
        $this->assertSame(0, $this->asTenant($club, fn () => Member::count()));
    }

    public function test_a_request_larger_than_php_takes_comes_back_with_a_plain_message(): void
    {
        $club = $this->club();

        $this->actingAs($this->owner($club))
            ->from(route('club.members.create'))
            ->call('POST', route('club.members.store'), [], [], [], ['CONTENT_LENGTH' => (string) (2 * 1024 ** 3)])
            ->assertRedirect(route('club.members.create'))
            ->assertSessionHas('error', fn ($m) => str_contains((string) $m, 'أكبر مما يقبله الخادم'));

        $this->call('POST', route('club.website.media.store'), [], [], [], ['CONTENT_LENGTH' => (string) (2 * 1024 ** 3), 'HTTP_ACCEPT' => 'application/json'])->assertStatus(413);
    }

    public function test_an_older_member_photo_still_shows_and_is_replaced_only_after_the_save(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $old = 'tenants/'.$club->id.'/members/Ab3dEf6hIj9kLm2nOp5qRs8t.webp';
        Storage::disk('public')->put($old, $this->png(60, 60));
        $member = $this->member($club, ['photo_path' => $old]);
        $url = Storage::disk('public')->url($old);

        $this->actingAs($owner)->get(route('club.members.show', $member))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('member.photo_url', $url)->where('member.photo_has_original', false));
        $this->get(route('club.members.index'))->assertOk()->assertSee(str_replace('/', '\/', $url), false);
        $this->get(route('club.members.photo', $member))->assertOk();

        $this->put(route('club.members.update', $member), ['full_name' => $member->full_name, 'phone' => $member->phone, 'photo' => UploadedFile::fake()->createWithContent('x.jpg', 'not an image')])
            ->assertSessionHasErrors('photo');
        Storage::disk('public')->assertExists($old);
        $this->assertSame($old, $this->asTenant($club, fn () => $member->fresh()->photo_path));

        $this->put(route('club.members.update', $member), ['full_name' => $member->full_name, 'phone' => $member->phone, 'photo' => UploadedFile::fake()->image('new.jpg', 300, 300)])
            ->assertSessionHasNoErrors();
        Storage::disk('public')->assertMissing($old);
        $this->assertTrue(ImageStore::hasOriginal($this->asTenant($club, fn () => $member->fresh()->photo_path)));
    }

    public function test_older_product_photos_stay_beside_new_ones_and_go_only_when_removed(): void
    {
        $club = $this->club();
        $old = 'tenants/'.$club->id.'/products/OldPhoto0000000000000000.webp';
        Storage::disk('public')->put($old, $this->png(80, 80));
        $product = $this->asTenant($club, fn () => Product::create(['name' => 'Bar', 'price' => 1, 'image_path' => $old]));

        $this->actingAs($this->owner($club))
            ->put(route('club.products.update', $product), ['name' => 'Bar', 'price' => 1, 'photos' => [UploadedFile::fake()->image('n.jpg', 300, 300)]])
            ->assertSessionHasNoErrors();
        $product = $this->asTenant($club, fn () => $product->fresh());
        $this->assertSame($old, $product->image_path, 'the older photo stays the main one');
        $this->assertCount(1, $product->images);
        Storage::disk('public')->assertExists($old);

        $this->post(route('club.products.photo', [$product, 0]), ['action' => 'remove'])->assertRedirect();
        Storage::disk('public')->assertMissing($old);
        Storage::disk('public')->assertExists($this->asTenant($club, fn () => $product->fresh()->image_path));
    }

    public function test_an_older_uploaded_logo_still_prints_once_in_the_pdf(): void
    {
        $club = $this->club();
        $old = 'tenants/'.$club->id.'/brand/logo-AbCdEfGh12345678.png';
        Storage::disk('public')->put($old, $this->png(800, 200));
        $club->forceFill(['settings' => array_merge($club->settings ?? [], ['logo_file' => $old])])->save();

        $paper = $this->paper($club->fresh());
        $this->assertStringContainsString($old, $paper->html());
        $this->assertStringContainsString('%PDF', substr($paper->pdf()->getContent(), 0, 8));
        $this->assertSame(Storage::disk('public')->path($old), OfficialLogo::forPaper(PaperSize::A4, true, $club->fresh()));
        $this->assertEqualsWithDelta(4.0, OfficialLogo::box(PaperSize::A4, $club->fresh())['width'] / OfficialLogo::box(PaperSize::A4, $club->fresh())['height'], 0.02);
    }

    public function test_a_new_logo_prints_in_the_pdf_from_its_png_copy(): void
    {
        $club = $this->club();

        $this->actingAs($this->owner($club))->post(route('club.settings.update'), [
            'name' => $club->name, 'primary_color' => '#9d174d', 'secondary_color' => '#f9a8d4', '_method' => 'put',
            'logo' => UploadedFile::fake()->createWithContent('logo.png', $this->png(1200, 300)),
        ])->assertSessionHasNoErrors();
        $file = $club->fresh()->setting('logo_file');

        $paper = $this->paper($club->fresh());
        $this->assertStringContainsString($file, $paper->html());
        $this->assertStringContainsString('%PDF', substr($paper->pdf()->getContent(), 0, 8));
        $this->assertSame(IMAGETYPE_PNG, getimagesize(Storage::disk('public')->path($file))[2]);
    }

    private function paper(Tenant $club): Paper
    {
        return $this->asTenant($club, function () use ($club) {
            $tpl = PaperTypes::settings($club, 'invoice');

            return new Paper($club, 'invoice', Papers::builder('invoice')::sample($club, $tpl), $tpl, PaperIssuer::resolve($club), PaperSize::A4, 'sample');
        });
    }
}
