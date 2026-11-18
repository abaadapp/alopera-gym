<?php

namespace Tests\Feature;

use App\Core\Support\Images\ImageInspector;
use App\Core\Support\Images\ImageRejected;
use App\Core\Support\ImageStore;
use App\Modules\Gym\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The server's own processing of a picture (when the browser sent the picked file itself, with no light copy): a
 * 12 MP phone photo is stored for every use within PHP-FPM's 128 MB, or refused with a message, never a fatal error;
 * and a JPEG cut short is refused before anything is written, while a whole one with camera data is kept.
 */
class ImageServerSafetyTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const DAMAGED = 'ملف الصورة تالف أو غير مكتمل';

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
        $this->dir = sys_get_temp_dir().'/image-safety-'.getmypid();
        @mkdir($this->dir);
    }

    /** A photo-like JPEG (bands of colour with fine detail), as GD writes it. */
    private function jpeg(int $width, int $height, int $quality = 90): string
    {
        $image = imagecreatetruecolor($width, $height);
        for ($y = 0; $y < $height; $y += 8) {
            $colour = imagecolorallocate($image, ($y * 7) % 256, ($y * 3) % 256, 255 - ($y % 256));
            imagefilledrectangle($image, 0, $y, $width - 1, $y + 7, $colour);
        }
        for ($i = 0; $i < 600; $i++) {
            imageline($image, ($i * 37) % $width, ($i * 91) % $height, ($i * 53) % $width, ($i * 17) % $height, imagecolorallocate($image, $i % 256, ($i * 5) % 256, ($i * 11) % 256));
        }
        ob_start();
        imagejpeg($image, null, $quality);

        return (string) ob_get_clean();
    }

    /**
     * $jpeg with what a phone camera writes before the picture: EXIF (its orientation, and its own small JPEG preview,
     * which ends with the same end marker as the picture) and an ICC colour profile.
     */
    private function withCameraData(string $jpeg, int $orientation = 1): string
    {
        $tiff = "MM\x00\x2A\x00\x00\x00\x08"."\x00\x01"."\x01\x12\x00\x03\x00\x00\x00\x01".pack('n', $orientation)."\x00\x00"."\x00\x00\x00\x00";
        $exif = "Exif\x00\x00".$tiff.$this->jpeg(160, 120, 60);
        $icc = "ICC_PROFILE\x00\x01\x01".str_repeat("\x00\xFF\xD9", 200);

        return "\xFF\xD8"
            ."\xFF\xE1".pack('n', strlen($exif) + 2).$exif
            ."\xFF\xE2".pack('n', strlen($icc) + 2).$icc
            .substr($jpeg, 2);
    }

    private function file(string $name, string $bytes): string
    {
        file_put_contents($path = $this->dir.'/'.$name, $bytes);

        return $path;
    }

    /** One use, in a PHP process of its own with PHP-FPM's 128 MB. */
    private function storeIn128M(string $path, string $use): array
    {
        $run = Process::timeout(120)->run([PHP_BINARY, '-d', 'memory_limit=128M', base_path('tests/Support/image-memory-child.php'), base_path(), $path, $use]);
        $result = json_decode(trim((string) strrchr("\n".trim($run->output()), "\n")), true);
        $this->assertIsArray($result, "$use: no answer: ".$run->output().$run->errorOutput());

        return $result + ['exit' => $run->exitCode()];
    }

    public function test_a_12mp_phone_photo_is_stored_for_every_use_within_128mb_or_refused_never_a_fatal_error(): void
    {
        $landscape = $this->file('landscape-12mp.jpg', $this->jpeg(4032, 3024, 85));
        // Under the 4 MB upload limit, as a phone's 12 MP JPEG is (2–4 MB).
        $this->assertLessThan(4 * 1_048_576, filesize($landscape));
        // Stored on its side as a phone does (3024×4032), EXIF orientation 6: turned upright when stored.
        $sideways = $this->file('sideways-12mp.jpg', $this->withCameraData($this->jpeg(3024, 4032, 85), 6));

        $sides = config('media.sides');
        foreach (['members', 'products', 'trainers', 'programs', 'posts', 'site'] as $use) {
            foreach (['landscape' => $landscape, 'sideways' => $sideways] as $shape => $path) {
                $result = $this->storeIn128M($path, $use);
                $said = "$use, $shape: ".json_encode($result, JSON_UNESCAPED_UNICODE);
                $this->assertArrayNotHasKey('fatal', $result, $said);
                $this->assertSame(0, $result['exit'], $said);
                $this->assertTrue($result['stored'], $said);
                $this->assertSame([$sides[$use], (int) round($sides[$use] * 3 / 4)], [$result['width'], $result['height']], 'upright, at its use\'s size: '.$said);
                $this->assertTrue($result['thumb'], $said);
                $this->assertLessThan(128, $result['peak_mb'], $said);
            }
        }
    }

    public function test_a_whole_jpeg_with_exif_icc_and_its_camera_preview_is_kept(): void
    {
        $whole = $this->withCameraData($this->jpeg(1200, 900), 1);
        $facts = ImageInspector::inspect($this->file('whole.jpg', $whole));
        $this->assertSame(['jpeg', 1200, 900], [$facts->type, $facts->width, $facts->height]);

        $path = $this->asTenant($this->club(), fn () => ImageStore::store(UploadedFile::fake()->createWithContent('whole.jpg', $whole), 'members'));
        $this->assertSame([800, 600], array_slice(getimagesizefromstring((string) Storage::disk('public')->get($path)), 0, 2));
        // Progressive JPEGs (several scans) too.
        $image = imagecreatefromstring($this->jpeg(1200, 900));
        imageinterlace($image, true);
        ob_start();
        imagejpeg($image, null, 85);
        $this->assertSame('jpeg', ImageInspector::inspect($this->file('progressive.jpg', (string) ob_get_clean()))->type);
    }

    public function test_a_jpeg_cut_short_anywhere_is_refused_even_when_gd_can_read_part_of_it(): void
    {
        $whole = $this->withCameraData($this->jpeg(1200, 900), 6);
        $scan = strpos($whole, "\xFF\xDA");
        $cuts = [
            'in its camera data' => 600,
            'just after the picture starts' => $scan + 40,
            'halfway' => intdiv(strlen($whole), 2),
            'without its end marker' => strlen($whole) - 2,
        ];
        // GD reads what is there of a picture cut halfway and fills the rest with grey: that is what was kept before.
        $this->assertInstanceOf(\GdImage::class, @imagecreatefromstring(substr($whole, 0, $cuts['halfway'])));

        foreach ($cuts as $where => $length) {
            try {
                ImageInspector::inspect($this->file("cut-$length.jpg", substr($whole, 0, $length)));
                $this->fail("a JPEG cut $where was accepted");
            } catch (ImageRejected $e) {
                $this->assertStringContainsString(self::DAMAGED, $e->getMessage(), $where);
            }
        }
    }

    public function test_a_damaged_member_photo_is_refused_and_leaves_no_member_and_no_file(): void
    {
        $club = $this->club();
        $whole = $this->jpeg(1200, 900);
        $cut = UploadedFile::fake()->createWithContent('photo.jpg', substr($whole, 0, intdiv(strlen($whole), 2)));

        $this->actingAs($this->owner($club))->post(route('club.members.store'), ['full_name' => 'Noor', 'phone' => '96891112233', 'photo' => $cut])
            ->assertSessionHasErrors('photo');
        $this->assertStringContainsString(self::DAMAGED, (string) session('errors')->first('photo'));

        $this->assertSame(0, $this->asTenant($club, fn () => Member::count()));
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }
}
