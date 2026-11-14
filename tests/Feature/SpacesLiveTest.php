<?php

namespace Tests\Feature;

use App\Core\Support\Images\Bucket;
use App\Core\Support\Images\OriginalsInventory;
use App\Core\Support\Images\SpacesOriginals;
use App\Core\Support\ImageStore;
use App\Models\Tenant;
use App\Modules\Gym\Models\Member;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Events\RequestSending;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Group;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The originals against REAL buckets, nothing simulated: the requests go over the network to an S3 server that checks
 * the signatures and the hashes itself. Not part of the normal run (phpunit.xml leaves the group out); it runs only when
 * asked for and given test buckets:
 *
 *   scripts/spaces-live-test.sh <keys file outside the repository> <name>_testing
 *
 * (the script reads the keys from that file, sets SPACES_LIVE_TEST=1, and hides the keys in everything it prints).
 * Both bucket names must contain "test", and neither may be the database copies' bucket (SPACES_BUCKET): it never runs
 * against real originals or other data. It writes under tenants/{test club}/, incoming/ and selftest/ and removes what
 * it wrote, except what versioning keeps. Nothing it prints carries a key or a signed address.
 */
#[Group('spaces-live')]
class SpacesLiveTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** @var list<array{method: string, url: string, range: ?string}> requests the app itself sent to a bucket */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('SPACES_LIVE_TEST') !== '1') {
            $this->markTestSkipped('Real buckets only when asked: SPACES_LIVE_TEST=1 with MEDIA_SPACES_* and MEDIA_COPY_*.');
        }
        foreach (['spaces', 'copy'] as $which) {
            $settings = (array) config('media.'.$which);
            $this->assertTrue((new Bucket($settings))->configured(), "media.$which is not set");
            $this->assertStringContainsString('test', (string) $settings['bucket'], 'a test bucket only, never the real originals');
            $this->assertNotSame((string) env('SPACES_BUCKET'), (string) $settings['bucket'], 'never the database copies\' bucket');
        }
        config(['media.originals' => 'spaces', 'gym.max_image_kb' => 25_600, 'media.max_pixels' => 50_000_000]);
        Storage::fake('public');
        Storage::fake('local');
        Event::listen(RequestSending::class, function (RequestSending $e) {
            $this->sent[] = ['method' => $e->request->method(), 'url' => $e->request->url(), 'range' => $e->request->header('Range')[0] ?? null];
        });
    }

    protected function tearDown(): void
    {
        if (getenv('SPACES_LIVE_TEST') === '1') {
            foreach ([Bucket::main(), Bucket::copy()] as $bucket) {
                foreach (array_keys(iterator_to_array(OriginalsInventory::remote())) as $key) {
                    $bucket->delete($key);
                }
            }
        }
        parent::tearDown();
    }

    // ---------------------------------------------------------------- what the browser does, for real

    /** @return array{0: string, 1: array{url: string, headers: array<string, string>, ticket: string}} the ticket, after a real PUT */
    private function browserPuts(string $bytes, string $name, string $type, string $route = 'club.members.uploads'): array
    {
        $ticket = $this->postJson(route($route), ['name' => $name, 'type' => $type, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes), 'md5' => md5($bytes)])
            ->assertOk()->json();
        $put = Http::timeout(600)->withHeaders($ticket['headers'])->withBody($bytes, $type)->put($ticket['url']);
        // Only the status and the S3 error code: an error body can name the access key.
        $this->assertTrue($put->successful(), 'the real bucket took the signed put: HTTP '.$put->status().' '.(preg_match('#<Code>([A-Za-z]+)</Code>#', $put->body(), $m) ? $m[1] : ''));

        return [$ticket['ticket'], $ticket];
    }

    private function jpeg(int $w, int $h, int $padTo = 0): string
    {
        $image = imagecreatetruecolor($w, $h);
        for ($x = 0; $x < $w; $x += 16) {
            imagefilledrectangle($image, $x, 0, $x + 15, $h - 1, imagecolorallocate($image, $x % 256, ($x * 3) % 256, 160));
        }
        ob_start();
        imagejpeg($image, null, 85);
        $jpeg = (string) ob_get_clean();

        // A camera file this large carries more data than a decoder needs; the padding stands for it.
        return $padTo > strlen($jpeg) ? $jpeg.random_bytes($padTo - strlen($jpeg)) : $jpeg;
    }

    private function preview(int $w, int $h): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('view.jpg', $this->jpeg($w, $h));
    }

    private function heic(): string
    {
        return (string) file_get_contents(base_path('tests/Fixtures/images/gradient-96x64.heic'));
    }

    /** @return array{0: string, 1: string} the record's display path and the original's key */
    private function latestPhoto(Tenant $club): array
    {
        $path = $this->asTenant($club, fn () => Member::query()->latest('id')->firstOrFail()->photo_path);

        return [$path, ImageStore::parse($path)['original']];
    }

    private function downloaded(Member $member): string
    {
        $location = (string) $this->get(route('club.members.photo', $member))->assertRedirect()->headers->get('Location');
        $response = Http::timeout(600)->get($location);
        $this->assertTrue($response->successful(), 'the signed download works: HTTP '.$response->status());
        $this->assertStringContainsString('attachment', (string) $response->header('Content-Disposition'));

        return $response->body();
    }

    // ---------------------------------------------------------------- the tests

    public function test_the_bucket_itself_passes_the_self_test(): void
    {
        $this->assertSame(0, Artisan::call('media:spaces-check', ['--origin' => getenv('SPACES_LIVE_ORIGIN') ?: 'http://127.0.0.1:8791']), Artisan::output());
        $this->assertSame(0, Artisan::call('media:spaces-check', ['--bucket' => 'copy']), Artisan::output());
    }

    public function test_jpg_heic_and_25_mb_reach_the_bucket_byte_for_byte_and_come_back_unchanged(): void
    {
        $club = $this->club();
        $this->actingAs($this->owner($club));
        $files = [
            ['IMG_0001.JPG', 'image/jpeg', $this->jpeg(3000, 2000), [2400, 1600]],
            ['IMG_0002.HEIC', 'image/heic', $this->heic(), [96, 64]],
            ['IMG_25MB.JPG', 'image/jpeg', $this->jpeg(6000, 4000, 25 * 1_048_576 - 4096), [2400, 1600]],
        ];

        foreach ($files as [$name, $type, $bytes, [$w, $h]]) {
            [$ticket, $signed] = $this->browserPuts($bytes, $name, $type);
            $incoming = ltrim((string) parse_url($signed['url'], PHP_URL_PATH), '/');
            $this->sent = [];
            $started = microtime(true);
            $this->post(route('club.members.store'), ['full_name' => 'Noor '.$name, 'phone' => '9689'.random_int(1000000, 9999999), 'photo_direct' => $ticket, 'photo_preview' => $this->preview($w, $h)])
                ->assertSessionHasNoErrors();
            $seconds = microtime(true) - $started;
            $bySave = $this->sent;

            [$path, $key] = $this->latestPhoto($club);
            $this->assertTrue(Bucket::matches(Bucket::main()->head($key), strlen($bytes), hash('sha256', $bytes), md5($bytes)), "$name kept in the bucket byte for byte");
            $this->assertNull(Bucket::main()->head(explode('/', $incoming, 2)[1]), "$name: the upload left incoming/");
            $whole = array_filter($bySave, fn ($r) => $r['method'] === 'GET' && $r['range'] === null);
            $this->assertSame([], array_values($whole), "$name: the app never fetched it whole");
            $this->assertSame([], Storage::disk('local')->allFiles(), "$name: nothing on the droplet's private disk");
            Storage::disk('public')->assertExists([$path, ImageStore::parse($path)['thumb']]);
            $this->assertLessThan(15, $seconds, "$name saved in a reasonable time");

            $member = $this->asTenant($club, fn () => Member::query()->latest('id')->firstOrFail());
            $this->assertSame(hash('sha256', $bytes), hash('sha256', $this->downloaded($member)), "$name downloads back unchanged");
            $requests = implode(', ', array_map(fn ($r) => $r['method'].($r['range'] ? ' '.$r['range'] : '').' '.basename((string) parse_url($r['url'], PHP_URL_PATH)), $bySave));
            fwrite(STDERR, sprintf("  %s: %.1f MB, saved in %.2f s; the app sent: %s\n", $name, strlen($bytes) / 1_048_576, $seconds, $requests));
        }
    }

    public function test_permissions_and_clubs_never_mix(): void
    {
        [$a, $b] = [$this->club('A'), $this->club('B')];
        $ownerA = $this->owner($a);
        $this->actingAs($ownerA);
        [$ticket, $signed] = $this->browserPuts($this->jpeg(800, 600), 'a.jpg', 'image/jpeg');
        $this->assertStringContainsString('/incoming/'.$a->id.'/', $signed['url']);
        $form = fn (string $t) => ['full_name' => 'Noor', 'phone' => '96891112233', 'photo_direct' => $t, 'photo_preview' => $this->preview(800, 600)];

        // B cannot use A's upload, nor download A's original, nor get a signed address for it.
        $this->actingAs($this->owner($b))->post(route('club.members.store'), $form($ticket))->assertSessionHasErrors('photo');
        $this->assertSame(0, $this->asTenant($b, fn () => Member::count()));
        $this->actingAs($ownerA)->post(route('club.members.store'), $form($ticket))->assertSessionHasNoErrors();
        [, $key] = $this->latestPhoto($a);
        $this->assertStringStartsWith('tenants/'.$a->id.'/originals/members/v2r-', $key);
        $memberA = $this->asTenant($a, fn () => Member::firstOrFail());
        $this->actingAs($this->owner($b))->get(route('club.members.photo', $memberA))->assertNotFound();

        // Without the permission: no ticket, no download.
        $trainer = $this->staff($a, 'trainer');
        $this->actingAs($trainer)->postJson(route('club.members.uploads'), ['name' => 'a.jpg', 'type' => 'image/jpeg', 'size' => 10, 'sha256' => str_repeat('a', 64), 'md5' => str_repeat('a', 32)])->assertForbidden();
        $this->actingAs($trainer)->get(route('club.members.photo', $memberA))->assertForbidden();

        // A signed address is for one object and one method: it cannot read another club's key or be reused for a put.
        $this->actingAs($ownerA);
        $location = (string) $this->get(route('club.members.photo', $memberA))->headers->get('Location');
        $this->assertFalse(Http::get(str_replace('/tenants/'.$a->id.'/', '/tenants/'.$b->id.'/', $location))->successful());
        $this->assertFalse(Http::withBody('x', 'image/jpeg')->put($location)->successful());
    }

    public function test_replacing_keeps_the_old_original_until_the_save_and_versioning_still_holds_it(): void
    {
        $club = $this->club();
        $this->actingAs($this->owner($club));
        $first = $this->jpeg(900, 600);
        $this->post(route('club.members.store'), ['full_name' => 'Noor', 'phone' => '96891112233', 'photo_direct' => $this->browserPuts($first, 'a.jpg', 'image/jpeg')[0], 'photo_preview' => $this->preview(900, 600)])
            ->assertSessionHasNoErrors();
        [$oldPath, $oldKey] = $this->latestPhoto($club);
        $version = Http::head(Bucket::main()->url('HEAD', $oldKey))->header('x-amz-version-id');
        $member = $this->asTenant($club, fn () => Member::firstOrFail());

        $this->put(route('club.members.update', $member), ['full_name' => 'Noor', 'phone' => '96891112233', 'photo_direct' => $this->browserPuts($this->jpeg(600, 600), 'b.jpg', 'image/jpeg')[0], 'photo_preview' => $this->preview(600, 600)])
            ->assertSessionHasNoErrors();

        [$newPath, $newKey] = $this->latestPhoto($club);
        $this->assertNotSame($oldPath, $newPath);
        $this->assertNotNull(Bucket::main()->head($newKey));
        $this->assertNull(Bucket::main()->head($oldKey), 'the old original is removed after the save');
        Storage::disk('public')->assertMissing($oldPath);

        // Versioning (layer 1 of the restore plan): the removed original is still there by its version.
        if ($version) {
            $old = Http::get(Bucket::main()->url('GET', $oldKey, 60, query: ['versionId' => $version]));
            $this->assertSame(hash('sha256', $first), hash('sha256', $old->body()), 'the previous version brings it back');
        } else {
            $this->markTestIncomplete('the bucket did not give a version id: versioning is off');
        }
    }

    public function test_a_lost_connection_keeps_everything_and_the_same_upload_saves_once_back(): void
    {
        $club = $this->club();
        $this->actingAs($this->owner($club));
        $this->post(route('club.members.store'), ['full_name' => 'Noor', 'phone' => '96891112233', 'photo_direct' => $this->browserPuts($this->jpeg(800, 600), 'a.jpg', 'image/jpeg')[0], 'photo_preview' => $this->preview(800, 600)])
            ->assertSessionHasNoErrors();
        [$oldPath, $oldKey] = $this->latestPhoto($club);
        $member = $this->asTenant($club, fn () => Member::firstOrFail());
        $bytes = $this->jpeg(1200, 800);
        [$ticket] = $this->browserPuts($bytes, 'b.jpg', 'image/jpeg');
        $form = ['full_name' => 'Noor', 'phone' => '96891112233', 'photo_direct' => $ticket, 'photo_preview' => $this->preview(1200, 800)];

        $endpoint = config('media.spaces.endpoint');
        config(['media.spaces.endpoint' => 'http://localhost:9']); // nothing listens there: the connection is refused
        $this->put(route('club.members.update', $member), $form)->assertSessionHasErrors('photo');
        $this->assertSame(SpacesOriginals::UNREACHABLE, session('errors')->first('photo'));
        config(['media.spaces.endpoint' => $endpoint]);

        $this->assertSame([$oldPath, $oldKey], $this->latestPhoto($club), 'the record is as it was');
        $this->assertNotNull(Bucket::main()->head($oldKey), 'the old original is still there');
        Storage::disk('public')->assertExists($oldPath);

        $this->put(route('club.members.update', $member), $form)->assertSessionHasNoErrors();
        [, $newKey] = $this->latestPhoto($club);
        $this->assertTrue(Bucket::matches(Bucket::main()->head($newKey), strlen($bytes), hash('sha256', $bytes), md5($bytes)), 'saved with the same ticket once back');
    }

    public function test_older_local_images_keep_working_once_originals_go_to_the_bucket(): void
    {
        $club = $this->club();
        $this->actingAs($this->owner($club));
        // Kept before the switch: a v2- photo with its original on the droplet, and an older photo with no original.
        config(['media.originals' => 'local']);
        $local = UploadedFile::fake()->image('local.jpg', 640, 480);
        $localBytes = (string) file_get_contents($local->getRealPath());
        $this->post(route('club.members.store'), ['full_name' => 'Local', 'phone' => '96891112233', 'photo' => $local])->assertSessionHasNoErrors();
        [$localPath, $localKey] = $this->latestPhoto($club);
        $this->assertStringContainsString('/v2-', $localPath);
        $legacyPath = 'tenants/'.$club->id.'/members/old-photo.jpg';
        Storage::disk('public')->put($legacyPath, $this->jpeg(300, 300));
        $older = $this->member($club, ['full_name' => 'Older', 'photo_path' => $legacyPath]);

        config(['media.originals' => 'spaces']);
        $localMember = $this->asTenant($club, fn () => Member::query()->where('full_name', 'Local')->firstOrFail());

        // Both still show, print and download as before.
        $this->assertSame($localBytes, $this->get(route('club.members.photo', $localMember))->assertOk()->streamedContent());
        $this->get(route('club.members.photo', $older))->assertOk();
        $this->assertNotNull(ImageStore::thumbUrl($localPath));
        $this->assertSame(Storage::disk('public')->url($legacyPath), ImageStore::thumbUrl($legacyPath));
        $this->get(route('club.members.show', $localMember))->assertOk();

        // Replacing the local one: the new original goes to the bucket, the old one leaves the droplet after the save.
        $bytes = $this->jpeg(800, 800);
        $this->put(route('club.members.update', $localMember), ['full_name' => 'Local', 'phone' => '96891112233', 'photo_direct' => $this->browserPuts($bytes, 'n.jpg', 'image/jpeg')[0], 'photo_preview' => $this->preview(800, 800)])
            ->assertSessionHasNoErrors();
        $newPath = $this->asTenant($club, fn () => $localMember->fresh()->photo_path);
        $this->assertStringContainsString('/v2r-', $newPath);
        $this->assertTrue(Bucket::matches(Bucket::main()->head(ImageStore::parse($newPath)['original']), strlen($bytes), hash('sha256', $bytes), md5($bytes)));
        Storage::disk('local')->assertMissing($localKey);
        Storage::disk('public')->assertExists($legacyPath);
        $this->assertSame($legacyPath, $this->asTenant($club, fn () => $older->fresh()->photo_path));
    }

    public function test_the_originals_come_back_from_the_independent_copy_after_a_loss(): void
    {
        [$a, $b] = [$this->club('A'), $this->club('B')];
        $kept = [];
        foreach ([[$a, 'IMG_A.JPG', 'image/jpeg', $this->jpeg(1600, 1200), [1600, 1200]], [$a, 'IMG_A.HEIC', 'image/heic', $this->heic(), [96, 64]], [$b, 'IMG_B.JPG', 'image/jpeg', $this->jpeg(1000, 1000), [1000, 1000]]] as [$club, $name, $type, $bytes, [$w, $h]]) {
            $this->actingAs($this->owner($club));
            $this->post(route('club.members.store'), ['full_name' => $name, 'phone' => '9689'.random_int(1000000, 9999999), 'photo_direct' => $this->browserPuts($bytes, $name, $type)[0], 'photo_preview' => $this->preview($w, $h)])
                ->assertSessionHasNoErrors();
            $kept[$this->latestPhoto($club)[1]] = $bytes;
        }
        $records = fn () => [$this->asTenant($a, fn () => Member::pluck('photo_path')->all()), $this->asTenant($b, fn () => Member::pluck('photo_path')->all())];
        $before = $records();

        // The daily copy, one way, checked.
        $this->assertSame(0, Artisan::call('media:originals-copy', ['--from' => 'main', '--to' => 'copy']), Artisan::output());
        $this->assertSame(0, Artisan::call('media:originals-check', ['--bucket' => 'copy', '--deep' => count($kept)]), Artisan::output());
        // Can the copy's own key delete? Tried on a test object, never on an original. A key that cannot (MinIO, B2) is
        // what the plan wants; Spaces' limited keys are read or read/write/delete, so on Spaces it can, and that is said.
        $probe = 'selftest/'.Str::uuid().'.bin';
        $tmp = (string) tempnam(sys_get_temp_dir(), 'probe');
        file_put_contents($tmp, 'probe');
        $this->assertTrue(Bucket::copy()->put($probe, $tmp, hash('sha256', 'probe'), md5('probe'), 'application/octet-stream'));
        @unlink($tmp);
        fwrite(STDERR, Bucket::copy()->delete($probe) ? "  note: the copy's key CAN delete (on Spaces a limited key is read or read/write/delete)\n" : "  the copy's key cannot delete\n");
        $this->assertNotNull(Bucket::copy()->head(array_key_first($kept)), 'the originals are still in the copy');

        // The main bucket loses them all.
        foreach (array_keys($kept) as $key) {
            $this->assertTrue(Bucket::main()->delete($key));
        }
        $this->assertSame(1, Artisan::call('media:originals-check'), 'the check names what is missing');
        $this->assertStringContainsString('missing', Artisan::output());

        // Restore, then check every byte.
        $this->assertSame(0, Artisan::call('media:originals-copy', ['--from' => 'copy', '--to' => 'main']), Artisan::output());
        $this->assertSame(0, Artisan::call('media:originals-check', ['--deep' => count($kept)]), Artisan::output());
        foreach ($kept as $key => $bytes) {
            $this->assertTrue(Bucket::matches(Bucket::main()->head($key), strlen($bytes), hash('sha256', $bytes), md5($bytes)), "$key restored byte for byte");
        }
        $this->assertSame($before, $records(), 'no record changed');
        $member = $this->asTenant($a, fn () => Member::query()->where('full_name', 'IMG_A.HEIC')->firstOrFail());
        $this->actingAs($this->owner($a));
        $this->assertSame(hash('sha256', $this->heic()), hash('sha256', $this->downloaded($member)), 'and downloads as before');

        // An original damaged in the main bucket is not spread to the copy, and is repaired from it on request.
        $damaged = array_key_first($kept);
        // Bit rot: other bytes under the key, the recorded SHA-256 left as it was.
        $other = $this->jpeg(10, 10);
        $headers = ['Content-Type' => 'image/jpeg', 'Content-MD5' => base64_encode(md5($other, true)), 'x-amz-meta-sha256' => hash('sha256', $kept[$damaged])];
        $this->assertTrue(Http::withHeaders($headers)->withBody($other, 'image/jpeg')->put(Bucket::main()->url('PUT', $damaged, 60, $headers))->successful());
        $this->assertSame(1, Artisan::call('media:originals-check', ['--deep' => count($kept)]), 'the drill finds the damaged bytes');
        $this->assertSame(1, Artisan::call('media:originals-copy', ['--from' => 'main', '--to' => 'copy', '--repair' => true]));
        $this->assertStringContainsString('damaged in the source: '.$damaged, Artisan::output());
        $this->assertSame(0, Artisan::call('media:originals-copy', ['--from' => 'copy', '--to' => 'main', '--repair' => true]), Artisan::output());
        $this->assertTrue(Bucket::matches(Bucket::main()->head($damaged), strlen($kept[$damaged]), hash('sha256', $kept[$damaged]), md5($kept[$damaged])), 'repaired from the copy');
    }
}
