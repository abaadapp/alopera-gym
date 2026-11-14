<?php

namespace Tests\Feature;

use App\Core\Backup\TenantBackupService;
use App\Core\Support\Images\ImageInspector;
use App\Core\Support\Images\ImageRejected;
use App\Core\Support\Images\SpacesOriginals;
use App\Core\Support\ImageStore;
use App\Models\Tenant;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Services\MembershipService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * media.storage 'optimized-only': a new upload keeps only its light copy and thumbnail, never the heavy original. A
 * copy the browser already made light and clean is kept as it came (not compressed twice); anything else is made light
 * by the server and loses its camera data. Images kept before (with their originals) keep working, nothing is cropped
 * or enlarged, and a failure leaves nothing behind.
 */
class OptimizedOnlyStorageTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private Tenant $club;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        config(['media.storage' => 'optimized-only']);
        $this->club = $this->club();
    }

    private function upload(string $name, string $bytes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    /** A photo-like picture: smooth bands of colour, so it compresses like a photo. */
    private function gd(int $w, int $h, bool $transparent = false): \GdImage
    {
        $image = imagecreatetruecolor($w, $h);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        for ($x = 0; $x < $w; $x += 4) {
            imagefilledrectangle($image, $x, 0, $x + 3, $h - 1, imagecolorallocatealpha($image, ($x * 7) % 256, ($x * 3) % 256, 90, 0));
        }
        if ($transparent) {
            imagefilledrectangle($image, 0, 0, intdiv($w, 2), intdiv($h, 2), imagecolorallocatealpha($image, 0, 0, 0, 127));
        }

        return $image;
    }

    private function encoded(\GdImage $image, string $type, int $quality = 82): string
    {
        ob_start();
        match ($type) {
            'jpeg' => imagejpeg($image, null, $quality),
            'png' => imagepng($image, null, 9),
            'webp' => imagewebp($image, null, $quality),
            'gif' => imagegif($image),
        };

        return (string) ob_get_clean();
    }

    /** GD's JPEG without the comment GD writes into it ("CREATOR: gd-jpeg"), as a browser's canvas writes one. */
    private function plainJpeg(\GdImage $image, int $quality = 82): string
    {
        $jpeg = $this->encoded($image, 'jpeg', $quality);
        $at = strpos($jpeg, "\xFF\xFE");

        return $at === false ? $jpeg : substr($jpeg, 0, $at).substr($jpeg, $at + 2 + unpack('n', substr($jpeg, $at + 2, 2))[1]);
    }

    /** A JPEG as a phone writes it: an EXIF block (where the location sits) right after the start. */
    private function withExif(string $jpeg): string
    {
        $tiff = "MM\x00\x2A\x00\x00\x00\x08\x00\x01\x01\x12\x00\x03\x00\x00\x00\x01\x00\x01\x00\x00\x00\x00\x00\x00";
        $app1 = "Exif\x00\x00".$tiff;

        return "\xFF\xD8\xFF\xE1".pack('n', strlen($app1) + 2).$app1.substr($jpeg, 2);
    }

    private function animatedGif(int $w, int $h): string
    {
        $frame = $this->encoded($this->gd($w, $h), 'gif');
        $flags = ord($frame[10]);
        $table = ($flags & 0x80) ? 3 * (2 ** (($flags & 0x07) + 1)) : 0;
        $head = substr($frame, 0, 13 + $table);
        $image = substr($frame, 13 + $table);
        $image = substr($image, strpos($image, ','), -1);
        $loop = "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";
        $control = "\x21\xF9\x04\x00\x0A\x00\x00\x00";

        return str_replace('GIF87a', 'GIF89a', $head).$loop.$control.$image.$control.$image.';';
    }

    private function store(UploadedFile $file, string $folder = 'members'): string
    {
        return $this->asTenant($this->club, fn () => ImageStore::store($file, $folder));
    }

    private function pixels(string $path): array
    {
        return array_slice((array) getimagesizefromstring((string) Storage::disk('public')->get($path)), 0, 2);
    }

    public function test_a_light_clean_copy_from_the_browser_is_kept_as_it_came_with_no_original(): void
    {
        $bytes = $this->encoded($this->gd(800, 600), 'webp');
        $path = $this->store($this->upload('member.webp', $bytes));

        $this->assertMatchesRegularExpression('#^tenants/'.$this->club->id.'/members/v2o-[0-9a-z]{26}-webp\.webp$#', $path);
        $this->assertSame($bytes, Storage::disk('public')->get($path), 'not compressed a second time');
        $this->assertTrue(Storage::disk('public')->exists(ImageStore::parse($path)['thumb']));
        $this->assertSame([360, 270], $this->pixels(ImageStore::parse($path)['thumb']));
        $this->assertSame([], Storage::disk('local')->allFiles(), 'no original anywhere');
        $this->assertNull(ImageStore::parse($path)['original']);
        $this->assertFalse(ImageStore::hasOriginal($path));
    }

    public function test_a_phone_jpeg_with_camera_data_is_made_light_here_and_the_data_is_left_behind(): void
    {
        $jpeg = $this->withExif($this->encoded($this->gd(640, 480), 'jpeg', 95));
        $upload = $this->upload('phone.jpg', $jpeg);
        $this->assertTrue(ImageInspector::carriesPrivateData((string) $upload->getRealPath(), 'jpeg'));

        $path = $this->store($upload);

        $this->assertStringEndsWith('-jpg.webp', $path);
        $this->assertSame([640, 480], $this->pixels($path), 'same shape, never enlarged');
        $this->assertFalse(ImageInspector::carriesPrivateData(Storage::disk('public')->path($path), 'webp'), 'no EXIF in the public copy');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_large_picture_the_browser_could_not_make_light_fits_its_use_whole(): void
    {
        $wide = $this->store($this->upload('wide.jpg', $this->encoded($this->gd(3000, 1500), 'jpeg')));
        $tall = $this->store($this->upload('tall.png', $this->encoded($this->gd(900, 2700), 'png')), 'products');

        $this->assertSame([800, 400], $this->pixels($wide), 'members: longest side 800, proportions kept');
        $this->assertSame([400, 1200], $this->pixels($tall), 'products: longest side 1200, nothing cropped');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_heavy_png_photo_is_not_kept_as_it_came_even_when_it_fits(): void
    {
        $noise = imagecreatetruecolor(500, 500);
        for ($i = 0; $i < 500 * 500; $i++) {
            imagesetpixel($noise, $i % 500, intdiv($i, 500), mt_rand(0, 0xFFFFFF));
        }
        $png = $this->encoded($noise, 'png');
        $this->assertGreaterThan(500 * 500 / 2, strlen($png), 'over 4 bits a pixel');

        $path = $this->store($this->upload('noise.png', $png));

        $this->assertStringEndsWith('-png.webp', $path);
        $this->assertLessThan(strlen($png), Storage::disk('public')->size($path));
    }

    public function test_a_moving_gif_keeps_moving_and_a_transparent_logo_keeps_its_transparency(): void
    {
        $gif = $this->animatedGif(120, 80);
        $path = $this->store($this->upload('move.gif', $gif), 'posts');
        $this->assertStringEndsWith('-gif.gif', $path);
        $this->assertSame($gif, Storage::disk('public')->get($path));
        $this->assertTrue(Storage::disk('public')->exists(ImageStore::parse($path)['thumb']));

        $png = $this->encoded($this->gd(400, 200, transparent: true), 'png');
        $logo = $this->asTenant($this->club, fn () => ImageStore::storeLogo($this->upload('logo.png', $png), 'brand', 1600));
        $this->assertStringEndsWith('-png.png', $logo);
        $this->assertSame($png, Storage::disk('public')->get($logo), 'a clean PNG logo is kept as it came, lossless');
        $shown = imagecreatefromstring((string) Storage::disk('public')->get($logo));
        $this->assertSame(127, (imagecolorat($shown, 10, 10) >> 24) & 0x7F, 'still see-through');

        $jpegLogo = $this->asTenant($this->club, fn () => ImageStore::storeLogo($this->upload('logo.jpg', $this->encoded($this->gd(400, 200), 'jpeg')), 'brand', 1600));
        $this->assertStringEndsWith('-jpg.png', $jpegLogo, 'a logo is shown from a PNG the prints read');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_file_that_cannot_be_read_leaves_nothing_behind(): void
    {
        $png = $this->encoded($this->gd(200, 100), 'png');
        $at = strpos($png, 'IDAT');
        $broken = substr($png, 0, $at + 8).str_repeat("\x00", 64).substr($png, $at + 72); // its picture data spoiled

        try {
            $this->store($this->upload('broken.png', $broken));
            $this->fail('a picture that cannot be read was stored');
        } catch (ImageRejected) {
            $this->assertSame([], Storage::disk('public')->allFiles());
            $this->assertSame([], Storage::disk('local')->allFiles());
        }
    }

    public function test_extra_data_is_found_in_each_kind_and_a_colour_profile_is_not(): void
    {
        $file = function (string $bytes): string {
            $path = (string) tempnam(sys_get_temp_dir(), 'meta');
            file_put_contents($path, $bytes);
            $this->beforeApplicationDestroyed(fn () => @unlink($path));

            return $path;
        };
        $jpeg = $this->plainJpeg($this->gd(40, 30));
        $png = $this->encoded($this->gd(40, 30), 'png');
        $webp = $this->encoded($this->gd(40, 30), 'webp');
        $icc = "ICC_PROFILE\x00\x01\x01".str_repeat("\x00", 128);

        $this->assertFalse(ImageInspector::carriesPrivateData($file($jpeg), 'jpeg'));
        $this->assertFalse(ImageInspector::carriesPrivateData($file("\xFF\xD8\xFF\xE2".pack('n', strlen($icc) + 2).$icc.substr($jpeg, 2)), 'jpeg'), 'an ICC profile only keeps colours right');
        $this->assertTrue(ImageInspector::carriesPrivateData($file($this->withExif($jpeg)), 'jpeg'));
        $this->assertTrue(ImageInspector::carriesPrivateData($file("\xFF\xD8\xFF\xFE\x00\x07hello".substr($jpeg, 2)), 'jpeg'), 'a comment');
        $this->assertTrue(ImageInspector::carriesPrivateData($file($jpeg.'video'), 'jpeg'), 'bytes after the picture (a motion photo)');

        $text = 'Comment'."\x00".'where';
        $chunk = pack('N', strlen($text)).'tEXt'.$text.pack('N', crc32('tEXt'.$text));
        $this->assertFalse(ImageInspector::carriesPrivateData($file($png), 'png'));
        $this->assertTrue(ImageInspector::carriesPrivateData($file(substr($png, 0, 33).$chunk.substr($png, 33)), 'png'));
        $this->assertTrue(ImageInspector::carriesPrivateData($file($png.'zip'), 'png'));

        $exif = 'EXIF'.pack('V', 4).'MM*'."\x00";
        $withExif = 'RIFF'.pack('V', strlen($webp) - 8 + strlen($exif)).substr($webp, 8).$exif;
        $this->assertFalse(ImageInspector::carriesPrivateData($file($webp), 'webp'));
        $this->assertTrue(ImageInspector::carriesPrivateData($file($withExif), 'webp'));
        $this->assertTrue(ImageInspector::carriesPrivateData($file($webp.'tail'), 'webp'));
    }

    public function test_images_kept_with_their_originals_before_keep_working(): void
    {
        config(['media.storage' => 'originals']);
        $jpeg = $this->encoded($this->gd(300, 200), 'jpeg', 92);
        $before = $this->store($this->upload('old.jpg', $jpeg));
        config(['media.storage' => 'optimized-only']);

        $this->assertTrue(ImageStore::hasOriginal($before));
        $this->assertSame($jpeg, Storage::disk('local')->get(ImageStore::parse($before)['original']));

        $now = $this->store($this->upload('new.webp', $this->encoded($this->gd(300, 200), 'webp')));
        $this->asTenant($this->club, fn () => ImageStore::delete($now));
        $this->assertTrue(Storage::disk('local')->exists(ImageStore::parse($before)['original']), 'removing a new image leaves the older ones');
        $this->asTenant($this->club, fn () => ImageStore::delete($before));
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_the_browser_is_told_how_to_make_the_copy_and_spaces_stays_out_of_it(): void
    {
        config(['media.originals' => 'spaces', 'media.spaces' => ['key' => 'k', 'secret' => 's', 'region' => 'fra1', 'bucket' => 'b', 'endpoint' => 'https://fra1.example.test', 'prefix' => 'incoming', 'ttl' => 600]]);
        $this->assertFalse(SpacesOriginals::enabled(), 'no original goes to the bucket either');

        $plan = ImageStore::browserPlan();
        $this->assertSame(800, $plan['sides']['members']);
        $this->assertSame(1200, $plan['sides']['products']);
        $this->assertSame(1920, $plan['sides']['site']);
        $this->assertSame(1600, $plan['sides']['brand']);
        $this->assertEqualsWithDelta(0.82, $plan['quality'], 0.001);

        $owner = $this->owner($this->club);
        $this->actingAs($owner)->get(route('club.members.create'))->assertOk()
            ->assertInertia(fn ($page) => $page->where('media.optimize.sides.members', 800)->where('media.direct', false));

        config(['media.storage' => 'originals']);
        $this->assertNull(ImageStore::browserPlan());
    }

    public function test_a_member_photo_is_saved_replaced_and_downloaded_as_the_light_copy_not_as_an_original(): void
    {
        $owner = $this->owner($this->club);
        $first = $this->encoded($this->gd(600, 800), 'webp');
        $this->actingAs($owner)->post(route('club.members.store'), ['full_name' => 'Huda', 'phone' => '96891112233', 'photo' => $this->upload('huda.webp', $first)])
            ->assertSessionHasNoErrors();
        $member = $this->asTenant($this->club, fn () => Member::query()->latest('id')->firstOrFail());
        $old = $member->photo_path;
        $this->assertSame($first, Storage::disk('public')->get($old));

        $this->actingAs($owner)->get(route('club.members.show', $member))->assertOk()
            ->assertInertia(fn ($page) => $page->where('member.photo_has_original', false));
        $download = $this->actingAs($owner)->get(route('club.members.photo', $member))->assertOk();
        $this->assertStringContainsString('.webp', (string) $download->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('original', strtolower((string) $download->headers->get('Content-Disposition')));

        $second = $this->encoded($this->gd(800, 600), 'webp');
        $this->put(route('club.members.update', $member), ['full_name' => 'Huda', 'phone' => '96891112233', 'photo' => $this->upload('new.webp', $second)])
            ->assertSessionHasNoErrors();
        $member = $this->asTenant($this->club, fn () => $member->fresh());
        $this->assertNotSame($old, $member->photo_path, 'a new name, so no cache shows the old picture');
        $this->assertSame($second, Storage::disk('public')->get($member->photo_path));
        $this->assertFalse(Storage::disk('public')->exists($old), 'the old copy goes once the new one is saved');
        $this->assertFalse(Storage::disk('public')->exists(ImageStore::parse($old)['thumb']));
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_club_archive_restore_puts_every_new_image_back_in_place(): void
    {
        // One image kept with its original (the default) and one optimized-only, then the club's archive.
        config(['media.storage' => 'originals']);
        $withOriginal = $this->store($this->upload('kept.jpg', $this->encoded($this->gd(300, 200), 'jpeg', 92)));
        config(['media.storage' => 'optimized-only']);
        $light = $this->store($this->upload('light.webp', $this->encoded($this->gd(300, 200), 'webp')), 'products');
        $pieces = [
            ['public', $withOriginal], ['public', ImageStore::parse($withOriginal)['thumb']], ['local', ImageStore::parse($withOriginal)['original']],
            ['public', $light], ['public', ImageStore::parse($light)['thumb']],
        ];
        $bytes = array_map(fn ($p) => Storage::disk($p[0])->get($p[1]), $pieces);

        $service = app(TenantBackupService::class);
        $name = $this->asTenant($this->club, fn () => $service->create($this->club));
        $archive = Storage::disk((string) config('gym.backup_disk', 'local'))->path($service->directory($this->club).'/'.$name);
        Storage::disk('public')->deleteDirectory('tenants');
        Storage::disk('local')->deleteDirectory('tenants');

        $this->asTenant($this->club, fn () => $service->restore($this->club, $archive));

        foreach ($pieces as $i => [$disk, $path]) {
            $this->assertSame($bytes[$i], Storage::disk($disk)->get($path), "{$path} is back on the {$disk} disk, byte for byte");
        }
        $this->assertSame([], Storage::disk('local')->allFiles('tenants/'.$this->club->id.'/'.TenantBackupService::ISOLATED), 'nothing set aside');
    }

    public function test_the_website_library_keeps_light_copies_measured_and_whole(): void
    {
        $owner = $this->owner($this->club);
        $wide = $this->encoded($this->gd(1920, 1080), 'webp');
        $media = $this->actingAs($owner)->postJson(route('club.website.media.store'), ['images' => [$this->upload('hero.webp', $wide), $this->upload('big.jpg', $this->encoded($this->gd(3000, 1000), 'jpeg'))]])
            ->assertOk()->json('media');

        $this->assertSame([1920, 1080, false], [$media[0]['width'], $media[0]['height'], $media[0]['has_original']]);
        $this->assertSame([1920, 640], [$media[1]['width'], $media[1]['height']], 'longest side 1920, nothing cropped');
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_optimized_only_is_the_default_and_the_release_gate_says_which_mode_runs(): void
    {
        // CI copies .env.example to .env, so the environment may carry MEDIA_STORAGE: check the default in both places.
        $this->assertStringContainsString("env('MEDIA_STORAGE', 'optimized-only')", (string) file_get_contents(config_path('media.php')), 'a server without MEDIA_STORAGE runs optimized-only');
        $this->assertMatchesRegularExpression('/^MEDIA_STORAGE=optimized-only$/m', (string) file_get_contents(base_path('.env.example')));
        $this->assertTrue(ImageStore::optimizedOnly());

        $this->artisan('platform:preflight')->expectsOutputToContain('New images keep only their light copy');
    }

    public function test_light_copies_keep_working_after_switching_back_to_originals(): void
    {
        $light = $this->store($this->upload('light.webp', $this->encoded($this->gd(300, 200), 'webp')));
        config(['media.storage' => 'originals']);

        $this->assertFalse(ImageStore::hasOriginal($light));
        $this->assertNotNull(ImageStore::url($light));
        $this->assertStringEndsWith('-thumb.webp', (string) ImageStore::thumbUrl($light));
        $after = $this->store($this->upload('after.jpg', $this->encoded($this->gd(300, 200), 'jpeg', 92)));
        $this->assertTrue(ImageStore::hasOriginal($after), 'a new upload keeps its original again');

        $this->asTenant($this->club, fn () => ImageStore::delete($light));
        $this->assertFalse(Storage::disk('public')->exists($light));
        $this->assertFalse(Storage::disk('public')->exists(ImageStore::parse($light)['thumb']));
        $this->assertTrue(Storage::disk('public')->exists($after), 'the others stay');
    }

    public function test_a_logo_kept_as_it_came_prints_on_the_page_and_in_the_pdf(): void
    {
        // An interlaced, palette PNG with a see-through corner: the kinds a PDF engine is pickiest about.
        $image = imagecreate(600, 200);
        imagecolorallocate($image, 255, 255, 255);
        $ink = imagecolorallocate($image, 124, 58, 237);
        imagecolortransparent($image, imagecolorallocate($image, 0, 255, 0));
        imagefilledrectangle($image, 20, 20, 180, 180, $ink);
        imagestring($image, 5, 220, 90, 'ALOPERA GYM', $ink);
        imageinterlace($image, true);
        $png = $this->encoded($image, 'png');

        $owner = $this->owner($this->club);
        $this->actingAs($owner)->post(route('club.settings.update'), ['_method' => 'put', 'name' => $this->club->name, 'primary_color' => '#9d174d', 'secondary_color' => '#f9a8d4', 'logo' => $this->upload('logo.png', $png)])
            ->assertSessionHasNoErrors();
        $logo = (string) $this->club->fresh()->setting('logo_file');
        $this->assertStringEndsWith('-png.png', $logo);
        $this->assertSame($png, Storage::disk('public')->get($logo), 'kept as it came: clean, light, lossless');

        $member = $this->member($this->club, ['full_name' => 'ليلى أحمد']);
        $plan = $this->plan($this->club, ['name' => 'باقة شهر', 'price' => 25]);
        $invoice = $this->asTenant($this->club, fn () => app(MembershipService::class)->sell($member, $plan)->invoice()->firstOrFail());

        $this->actingAs($owner)->get(route('club.papers.show', ['type' => 'invoice', 'id' => $invoice->id]))->assertOk()->assertSee(basename($logo), false);
        $pdf = $this->actingAs($owner)->get(route('club.papers.pdf', ['type' => 'invoice', 'id' => $invoice->id]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF', (string) $pdf->getContent());
        $this->assertStringContainsString('/Subtype /Image', (string) $pdf->getContent(), 'the logo is drawn in the PDF');
    }
}
