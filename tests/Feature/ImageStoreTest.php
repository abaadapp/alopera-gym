<?php

namespace Tests\Feature;

use App\Core\Support\Images\ImageInspector;
use App\Core\Support\Images\ImageRejected;
use App\Core\Support\Images\ImageUpload;
use App\Core\Support\ImageStore;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The image layer: the original is kept byte for byte (SHA-256 before and after) on the private disk, a display copy
 * and a thumbnail are made once, every accepted format is read from its own bytes, a disguised or oversized file is
 * refused before it is decoded, a failure leaves nothing behind, and images stored before this keep working.
 */
class ImageStoreTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private Tenant $club;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->club = $this->club();
    }

    private function upload(string $name, string $bytes): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    /** @return array{path: string, width: int, height: int, mime: string, size: int} */
    private function measured(UploadedFile|ImageUpload $file, string $folder = 'site', int $maxSide = 2400): array
    {
        return $this->asTenant($this->club, fn () => ImageStore::storeMeasured($file, $folder, $maxSide));
    }

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

    private function encoded(\GdImage $image, string $type): string
    {
        ob_start();
        match ($type) {
            'jpeg' => imagejpeg($image, null, 92),
            'png' => imagepng($image),
            'webp' => imagewebp($image, null, 90),
            'avif' => imageavif($image, null, 60),
            'gif' => imagegif($image),
        };

        return (string) ob_get_clean();
    }

    /** Two frames of the same GIF: a real animated GIF89a with a loop block. */
    private function animatedGif(int $w, int $h): string
    {
        $frame = $this->encoded($this->gd($w, $h), 'gif');
        $flags = ord($frame[10]);
        $table = ($flags & 0x80) ? 3 * (2 ** (($flags & 0x07) + 1)) : 0;
        $head = substr($frame, 0, 13 + $table);
        $image = substr($frame, 13 + $table);
        $image = substr($image, strpos($image, ','), -1); // the image descriptor and its data, without the trailer
        $loop = "\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00";
        $control = "\x21\xF9\x04\x00\x0A\x00\x00\x00";

        return str_replace('GIF87a', 'GIF89a', $head).$loop.$control.$image.$control.$image.';';
    }

    private function heic(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/images/gradient-96x64.heic'));
    }

    public function test_each_format_keeps_its_original_byte_for_byte_with_a_display_copy_and_a_thumbnail(): void
    {
        $formats = ['jpeg' => 'photo.jpg', 'png' => 'shot.png', 'webp' => 'pic.webp', 'gif' => 'still.gif'];
        if (function_exists('imageavif')) {
            $formats['avif'] = 'pic.avif';
        }
        foreach ($formats as $type => $name) {
            $bytes = $this->encoded($this->gd(3000, 1500), $type);
            $stored = $this->measured($this->upload($name, $bytes), 'site', 1200);

            $parts = ImageStore::parse($stored['path']);
            $this->assertNotNull($parts, "$type is stored under the new names");
            Storage::disk('local')->assertExists($parts['original']);
            $this->assertSame(hash('sha256', $bytes), ImageStore::sha256('local', $parts['original']), "$type original is byte for byte");
            $this->assertStringContainsString('/originals/site/', $parts['original']);
            Storage::disk('public')->assertMissing($parts['original']);

            Storage::disk('public')->assertExists($stored['path']);
            $this->assertStringEndsWith('.webp', $stored['path']);
            $this->assertSame([1200, 600], [$stored['width'], $stored['height']], "$type display keeps the proportions");
            $display = getimagesizefromstring(Storage::disk('public')->get($stored['path']));
            $this->assertSame([1200, 600], [$display[0], $display[1]]);

            Storage::disk('public')->assertExists($parts['thumb']);
            $thumb = getimagesizefromstring(Storage::disk('public')->get($parts['thumb']));
            $this->assertSame([360, 180], [$thumb[0], $thumb[1]], "$type thumbnail fits 360 and is not cropped");
            $this->assertStringEndsWith('.thumb.webp', ImageStore::thumbUrl($stored['path']));
        }
    }

    public function test_transparency_survives_in_the_display_copy(): void
    {
        $stored = $this->measured($this->upload('logo.png', $this->encoded($this->gd(400, 200, transparent: true), 'png')));

        $display = imagecreatefromstring(Storage::disk('public')->get($stored['path']));
        $this->assertSame(127, (imagecolorat($display, 10, 10) >> 24) & 0x7F, 'the transparent corner stays transparent');
        $this->assertSame(0, (imagecolorat($display, 390, 190) >> 24) & 0x7F, 'the opaque part stays opaque');
    }

    public function test_a_moving_gif_is_shown_moving_and_its_thumbnail_is_its_first_frame(): void
    {
        $bytes = $this->animatedGif(120, 80);
        $file = $this->upload('a.gif', $bytes);
        $this->assertTrue(ImageInspector::inspect($file->getRealPath())->animated);

        $stored = $this->measured($this->upload('moving.gif', $bytes));

        $this->assertStringEndsWith('.gif.gif', $stored['path']);
        $this->assertSame($bytes, Storage::disk('public')->get($stored['path']), 'shown as uploaded, every frame');
        Storage::disk('public')->assertExists(ImageStore::parse($stored['path'])['thumb']);
    }

    public function test_a_moving_gif_over_the_limit_is_shown_by_its_first_frame(): void
    {
        config(['media.animated_max_kb' => 0]);

        $stored = $this->measured($this->upload('moving.gif', $this->animatedGif(120, 80)));

        $this->assertStringEndsWith('.gif.webp', $stored['path']);
    }

    public function test_a_heic_from_an_iphone_is_kept_as_it_is_and_shown_from_the_browsers_display_source(): void
    {
        $heic = $this->heic();
        $file = $this->upload('IMG_0001.HEIC', $heic);
        $facts = ImageInspector::inspect($file->getRealPath());
        $this->assertSame(['heic', 96, 64], [$facts->type, $facts->width, $facts->height]);

        $preview = $this->upload('IMG_0001.jpg', $this->encoded($this->gd(96, 64), 'jpeg'));
        $stored = $this->measured(new ImageUpload($this->upload('IMG_0001.HEIC', $heic), $preview));

        $parts = ImageStore::parse($stored['path']);
        $this->assertStringEndsWith('.heic', $parts['original']);
        $this->assertSame(hash('sha256', $heic), ImageStore::sha256('local', $parts['original']));
        $this->assertStringEndsWith('.heic.webp', $stored['path']);
        $this->assertSame([96, 64], [$stored['width'], $stored['height']]);
    }

    public function test_a_heic_without_a_display_source_is_refused_and_leaves_nothing(): void
    {
        try {
            $this->measured($this->upload('IMG_0001.HEIC', $this->heic()));
            $this->fail('a HEIC the server cannot read needs the browser\'s display source');
        } catch (ImageRejected $e) {
            $this->assertStringContainsString('HEIC', $e->getMessage());
        }

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_display_source_of_another_shape_is_refused(): void
    {
        $this->expectException(ImageRejected::class);

        $this->measured(new ImageUpload($this->upload('IMG.HEIC', $this->heic()), $this->upload('other.jpg', $this->encoded($this->gd(50, 50), 'jpeg'))));
    }

    public function test_a_phone_photo_turned_by_its_exif_matches_a_display_source_either_way_up(): void
    {
        $preview = $this->upload('p.jpg', $this->encoded($this->gd(64, 96), 'jpeg'));

        $stored = $this->measured(new ImageUpload($this->upload('IMG.HEIC', $this->heic()), $preview));

        $this->assertSame([64, 96], [$stored['width'], $stored['height']]);
    }

    public function test_files_that_only_claim_to_be_images_are_refused(): void
    {
        $fakes = [
            'shell.jpg' => '<?php system($_GET["c"]); ?>',
            'page.png' => '<!doctype html><html><script>alert(1)</script></html>',
            'archive.webp' => "PK\x03\x04".str_repeat("\0", 40),
            'clip.heic' => "\x00\x00\x00\x18ftypisom\x00\x00\x02\x00isomiso2".str_repeat("\0", 32),
            'empty.gif' => '',
            'text.avif' => 'just some words',
        ];
        foreach ($fakes as $name => $bytes) {
            try {
                $this->measured($this->upload($name, $bytes));
                $this->fail("$name was accepted");
            } catch (ImageRejected) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_a_gif_header_in_front_of_a_script_is_refused_when_it_cannot_be_decoded(): void
    {
        $this->expectException(ImageRejected::class);

        $this->measured($this->upload('poly.gif', "GIF89a\x10\x00\x10\x00\x00\x00\x00<?php echo 1; ?>"));
    }

    public function test_a_pixel_bomb_is_refused_from_its_header_before_decoding(): void
    {
        $ihdr = pack('NNCCCCC', 30000, 30000, 8, 6, 0, 0, 0);
        $png = "\x89PNG\r\n\x1A\n".pack('N', 13).'IHDR'.$ihdr.pack('N', crc32('IHDR'.$ihdr)).pack('N', 0).'IEND'.pack('N', crc32('IEND'));

        try {
            $this->measured($this->upload('bomb.png', $png));
            $this->fail('a 900-megapixel image was accepted');
        } catch (ImageRejected $e) {
            $this->assertStringContainsString('30000×30000', $e->getMessage());
        }
    }

    public function test_a_file_over_the_size_limit_is_refused(): void
    {
        config(['gym.max_image_kb' => 1]);

        $this->expectException(ImageRejected::class);

        $this->measured($this->upload('big.jpg', $this->encoded($this->gd(800, 800), 'jpeg')));
    }

    public function test_a_photo_too_large_for_the_servers_memory_is_shown_from_the_browsers_display_source_instead(): void
    {
        $bytes = $this->encoded($this->gd(4000, 3000), 'jpeg');
        $limit = ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage(true) + 48 * 1_048_576));
        try {
            try {
                $this->measured($this->upload('huge.jpg', $bytes));
                $this->fail('a photo needing more memory than there is was decoded');
            } catch (ImageRejected $e) {
                $this->assertStringContainsString('كبيرة جدًا', $e->getMessage());
            }

            $stored = $this->measured(new ImageUpload($this->upload('huge.jpg', $bytes), $this->upload('huge-view.jpg', $this->encoded($this->gd(2000, 1500), 'jpeg'))));
        } finally {
            ini_set('memory_limit', $limit);
        }
        $this->assertSame(hash('sha256', $bytes), ImageStore::sha256('local', ImageStore::parse($stored['path'])['original']));
        $this->assertSame([2000, 1500], [$stored['width'], $stored['height']]);
    }

    public function test_a_failed_write_of_the_display_copy_removes_the_original_too(): void
    {
        $dir = Storage::disk('public')->path('tenants/'.$this->club->id.'/site');
        @mkdir($dir, 0755, true);
        chmod($dir, 0555);
        try {
            $this->measured($this->upload('p.jpg', $this->encoded($this->gd(300, 200), 'jpeg')));
            $this->fail('a write that failed was reported as stored');
        } catch (ImageRejected) {
            $this->assertSame([], Storage::disk('local')->allFiles(), 'no original left without its record');
        } finally {
            chmod($dir, 0755);
        }
    }

    public function test_delete_removes_the_display_copy_the_thumbnail_and_the_original(): void
    {
        $stored = $this->measured($this->upload('p.jpg', $this->encoded($this->gd(300, 200), 'jpeg')));
        $parts = ImageStore::parse($stored['path']);

        ImageStore::delete($stored['path']);

        Storage::disk('public')->assertMissing([$stored['path'], $parts['thumb']]);
        Storage::disk('local')->assertMissing($parts['original']);
    }

    public function test_images_stored_before_keep_working(): void
    {
        $old = 'tenants/'.$this->club->id.'/products/Ab3dEf6hIj9kLm2nOp5qRs8t.webp';
        Storage::disk('public')->put($old, 'an older display copy');

        $this->assertNull(ImageStore::parse($old));
        $this->assertFalse(ImageStore::hasOriginal($old));
        $this->assertSame(Storage::disk('public')->url($old), ImageStore::url($old));
        $this->assertSame(ImageStore::url($old), ImageStore::thumbUrl($old), 'no thumbnail: the image itself');

        $download = $this->asTenant($this->club, fn () => ImageStore::download($old, 'Protein'));
        $this->assertStringContainsString('Protein.webp', (string) $download->headers->get('content-disposition'));

        ImageStore::delete($old);
        Storage::disk('public')->assertMissing($old);
    }

    public function test_the_original_downloads_as_it_was_and_only_inside_its_club(): void
    {
        $bytes = $this->encoded($this->gd(300, 200), 'png');
        $stored = $this->measured($this->upload('p.png', $bytes));

        $response = $this->asTenant($this->club, fn () => ImageStore::download($stored['path'], 'Logo'));
        ob_start();
        $response->sendContent();
        $this->assertSame($bytes, ob_get_clean());
        $this->assertSame('image/png', $response->headers->get('content-type'));
        $this->assertSame('nosniff', $response->headers->get('x-content-type-options'));

        $other = $this->club('Other');
        $this->expectException(NotFoundHttpException::class);
        $this->asTenant($other, fn () => ImageStore::download($stored['path'], 'Logo'));
    }

    public function test_a_cdn_serves_the_public_copies_when_one_is_set(): void
    {
        config(['media.cdn_url' => 'https://cdn.example.test/']);

        $this->assertSame('https://cdn.example.test/storage/tenants/1/site/x.webp', ImageStore::url('tenants/1/site/x.webp'));
    }

    public function test_each_club_keeps_its_images_in_its_own_folder(): void
    {
        $other = $this->club('Other');
        $a = $this->measured($this->upload('a.jpg', $this->encoded($this->gd(100, 100), 'jpeg')));
        $b = $this->asTenant($other, fn () => ImageStore::storeMeasured($this->upload('b.jpg', $this->encoded($this->gd(100, 100), 'jpeg')), 'site'));

        $this->assertStringStartsWith('tenants/'.$this->club->id.'/', $a['path']);
        $this->assertStringStartsWith('tenants/'.$other->id.'/', $b['path']);
        $this->assertStringStartsWith('tenants/'.$other->id.'/originals/', ImageStore::parse($b['path'])['original']);
    }
}
