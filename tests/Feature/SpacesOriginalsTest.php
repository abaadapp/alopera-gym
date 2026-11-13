<?php

namespace Tests\Feature;

use App\Core\Support\Images\SpacesOriginals;
use App\Core\Support\Images\SpacesSigner;
use App\Core\Support\ImageStore;
use App\Models\Tenant;
use App\Modules\Gym\Models\Member;
use App\Modules\Website\Models\SiteMedia;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Originals kept in a Spaces bucket (MEDIA_ORIGINALS=spaces, off by default). The bucket is simulated here the way
 * Spaces behaves for these requests — it refuses a body whose SHA-256 is not the signed one — and every request the
 * app makes is recorded: the original is checked from its size, its recorded hash and its first 256 KB, copied inside
 * the bucket and never fetched whole; a failure at any step keeps nothing new and loses nothing old.
 */
class SpacesOriginalsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** @var array<string, array{body: string, sha256: string}> */
    private array $bucket = [];

    /** @var list<array{method: string, key: string, range: ?string}> */
    private array $requests = [];

    /** Names a request kind that the simulated bucket answers with a failure (e.g. 'PUT-copy'). */
    private ?string $failing = null;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function enable(): void
    {
        config(['media.originals' => 'spaces', 'media.spaces' => [
            'key' => 'DO00EXAMPLEKEY', 'secret' => 'example-secret', 'region' => 'fra1', 'bucket' => 'alopera-originals',
            'endpoint' => 'https://fra1.digitaloceanspaces.com', 'prefix' => 'incoming', 'ttl' => 600,
        ]]);
        Http::fake(fn (ClientRequest $r) => $this->answer($r));
    }

    private function answer(ClientRequest $r)
    {
        $key = rawurldecode(ltrim((string) parse_url($r->url(), PHP_URL_PATH), '/'));
        $copy = $r->header('x-amz-copy-source')[0] ?? null;
        $kind = $r->method().($copy ? '-copy' : '');
        $this->requests[] = ['method' => $kind, 'key' => $key, 'range' => $r->header('Range')[0] ?? null];
        if ($this->failing === $kind) {
            return Http::response('', 503);
        }

        return match ($r->method()) {
            'PUT' => $copy ? $this->copyObject(ltrim(substr($copy, strlen('/alopera-originals')), '/'), $key) : $this->putObject($r, $key),
            'HEAD' => isset($this->bucket[$key])
                ? Http::response('', 200, ['Content-Length' => (string) strlen($this->bucket[$key]['body']), 'x-amz-meta-sha256' => $this->bucket[$key]['sha256']])
                : Http::response('', 404),
            'GET' => $this->getObject($r, $key),
            'DELETE' => tap(Http::response('', 204), function () use ($key) {
                unset($this->bucket[$key]);
            }),
        };
    }

    private function putObject(ClientRequest $r, string $key)
    {
        $body = $r->body();
        if (hash('sha256', $body) !== ($r->header('x-amz-content-sha256')[0] ?? '')) {
            return Http::response('<Code>XAmzContentSHA256Mismatch</Code>', 400);
        }
        $this->bucket[$key] = ['body' => $body, 'sha256' => $r->header('x-amz-meta-sha256')[0] ?? ''];

        return Http::response('', 200);
    }

    private function copyObject(string $from, string $to)
    {
        if (! isset($this->bucket[$from])) {
            return Http::response('', 404);
        }
        $this->bucket[$to] = $this->bucket[$from];

        return Http::response('<CopyObjectResult/>', 200);
    }

    private function getObject(ClientRequest $r, string $key)
    {
        if (! isset($this->bucket[$key])) {
            return Http::response('', 404);
        }
        $range = $r->header('Range')[0] ?? null;
        if ($range && preg_match('/bytes=0-(\d+)/', $range, $m)) {
            return Http::response(substr($this->bucket[$key]['body'], 0, (int) $m[1] + 1), 206);
        }

        return Http::response($this->bucket[$key]['body'], 200);
    }

    /** What the browser does: ask for a ticket, then put the file to the signed address with the signed headers. */
    private function browserPuts(string $route, string $bytes, string $name, string $type): string
    {
        $ticket = $this->postJson(route($route), ['name' => $name, 'type' => $type, 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)])
            ->assertOk()->json();
        Http::withHeaders($ticket['headers'])->withBody($bytes, $type)->put($ticket['url'])->throw();

        return $ticket['ticket'];
    }

    private function jpeg(int $w, int $h): string
    {
        $image = imagecreatetruecolor($w, $h);
        for ($x = 0; $x < $w; $x += 8) {
            imagefilledrectangle($image, $x, 0, $x + 7, $h - 1, imagecolorallocate($image, $x % 256, 90, 160));
        }
        ob_start();
        imagejpeg($image, null, 90);

        return (string) ob_get_clean();
    }

    /** @return list<string> */
    private function fullReads(): array
    {
        return array_column(array_filter($this->requests, fn ($q) => $q['method'] === 'GET' && $q['range'] === null), 'key');
    }

    public function test_it_is_off_by_default_and_nothing_goes_to_a_bucket(): void
    {
        $club = $this->club();
        Http::fake();

        $this->assertFalse(SpacesOriginals::enabled());
        $this->actingAs($this->owner($club))->postJson(route('club.members.uploads'), ['name' => 'a.jpg', 'size' => 10, 'sha256' => str_repeat('a', 64)])->assertNotFound();
        $this->post(route('club.members.store'), ['full_name' => 'Noor', 'phone' => '96891112233', 'photo' => UploadedFile::fake()->image('p.jpg', 300, 200)])->assertSessionHasNoErrors();

        Http::assertNothingSent();
        $path = $this->asTenant($club, fn () => Member::firstOrFail()->photo_path);
        $this->assertStringContainsString('/v2-', $path);
        Storage::disk('local')->assertExists(ImageStore::parse($path)['original']);
        $this->get(route('club.members.create'))->assertInertia(fn (AssertableInertia $p) => $p->where('media.direct', false));
    }

    public function test_switched_on_without_every_setting_it_stays_off(): void
    {
        $this->enable();
        config(['media.spaces.secret' => null]);

        $this->assertFalse(SpacesOriginals::enabled());
    }

    public function test_the_signed_upload_carries_the_files_hash_so_the_bucket_refuses_other_bytes(): void
    {
        $this->enable();
        $club = $this->club();
        $bytes = $this->jpeg(400, 300);

        $ticket = $this->actingAs($this->owner($club))
            ->postJson(route('club.members.uploads'), ['name' => 'IMG_0001.JPG', 'type' => 'image/jpeg', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)])
            ->assertOk()->json();

        $this->assertMatchesRegularExpression('#^https://alopera-originals\.fra1\.digitaloceanspaces\.com/incoming/'.$club->id.'/[0-9a-f-]{36}\.jpg\?#', $ticket['url']);
        $this->assertStringContainsString('X-Amz-SignedHeaders=content-type%3Bhost%3Bx-amz-content-sha256%3Bx-amz-meta-sha256', $ticket['url']);
        $this->assertSame(hash('sha256', $bytes), $ticket['headers']['x-amz-content-sha256']);
        $this->assertStringNotContainsString('example-secret', $ticket['url'].$ticket['ticket']);
        $this->assertFalse(Http::withHeaders($ticket['headers'])->withBody($bytes.'x', 'image/jpeg')->put($ticket['url'])->successful(), 'other bytes are refused');
        $this->assertTrue(Http::withHeaders($ticket['headers'])->withBody($bytes, 'image/jpeg')->put($ticket['url'])->successful());

        $this->postJson(route('club.members.uploads'), ['name' => 'run.php', 'size' => 10, 'sha256' => str_repeat('a', 64)])->assertStatus(422);
        $this->postJson(route('club.members.uploads'), ['name' => 'a.jpg', 'size' => 99_999_999, 'sha256' => str_repeat('a', 64)])->assertStatus(422);
        $this->actingAs($this->staff($club, 'trainer'))->postJson(route('club.members.uploads'), ['name' => 'a.jpg', 'size' => 10, 'sha256' => str_repeat('a', 64)])->assertForbidden();
    }

    public function test_a_direct_upload_is_kept_without_the_app_reading_it_whole(): void
    {
        $this->enable();
        $club = $this->club();
        $owner = $this->owner($club);
        $bytes = $this->jpeg(3200, 2400);
        $this->actingAs($owner);
        $ticket = $this->browserPuts('club.members.uploads', $bytes, 'IMG_0001.JPG', 'image/jpeg');

        $this->post(route('club.members.store'), [
            'full_name' => 'Noor', 'phone' => '96891112233',
            'photo_direct' => $ticket, 'photo_preview' => UploadedFile::fake()->image('view.jpg', 2400, 1800),
        ])->assertSessionHasNoErrors();

        $path = $this->asTenant($club, fn () => Member::firstOrFail()->photo_path);
        $parts = ImageStore::parse($path);
        $this->assertTrue($parts['remote']);
        $this->assertSame($bytes, $this->bucket[$parts['original']]['body'] ?? null, 'kept in the bucket byte for byte');
        $this->assertSame(hash('sha256', $bytes), $this->bucket[$parts['original']]['sha256']);
        $this->assertSame([], Storage::disk('local')->allFiles(), 'nothing on the droplet');
        Storage::disk('public')->assertExists([$path, $parts['thumb']]);
        $this->assertSame([], $this->fullReads(), 'never fetched whole');
        $this->assertContains('bytes=0-262143', array_column($this->requests, 'range'));
        $this->assertSame([], array_values(array_filter(array_keys($this->bucket), fn ($k) => str_starts_with($k, 'incoming/'))), 'the upload is moved out of incoming/');
        $this->assertTrue(ImageStore::hasOriginal($path));
    }

    public function test_the_original_downloads_from_the_bucket_after_the_permission_check(): void
    {
        $this->enable();
        $club = $this->club();
        $this->actingAs($this->owner($club));
        $ticket = $this->browserPuts('club.members.uploads', $this->jpeg(300, 200), 'face.jpg', 'image/jpeg');
        $this->post(route('club.members.store'), ['full_name' => 'Noor', 'phone' => '96891112233', 'photo_direct' => $ticket, 'photo_preview' => UploadedFile::fake()->image('v.jpg', 300, 200)])->assertSessionHasNoErrors();
        $member = $this->asTenant($club, fn () => Member::firstOrFail());

        $location = $this->get(route('club.members.photo', $member))->assertRedirect()->headers->get('Location');
        $this->assertStringStartsWith('https://alopera-originals.fra1.digitaloceanspaces.com/'.ImageStore::parse($member->photo_path)['original'].'?', $location);
        $this->assertStringContainsString('X-Amz-Expires=60', $location);
        $this->assertStringContainsString('response-content-disposition=attachment', $location);

        $this->actingAs($this->staff($club, 'trainer'))->get(route('club.members.photo', $member))->assertForbidden();
        $this->actingAs($this->owner($this->club('Other')))->get(route('club.members.photo', $member))->assertNotFound();
    }

    public function test_a_ticket_of_another_club_user_or_place_is_refused_before_any_request(): void
    {
        $this->enable();
        $club = $this->club();
        $owner = $this->owner($club);
        $this->actingAs($owner);
        $ticket = $this->browserPuts('club.members.uploads', $this->jpeg(300, 200), 'a.jpg', 'image/jpeg');
        $before = count($this->requests);
        $form = fn (array $extra) => ['full_name' => 'Noor', 'phone' => '96891112233', 'photo_preview' => UploadedFile::fake()->image('v.jpg', 300, 200)] + $extra;

        $this->post(route('club.products.store'), ['name' => 'Bar', 'price' => 1, 'photos_direct' => [$ticket], 'photos_preview' => [UploadedFile::fake()->image('v.jpg', 300, 200)]])
            ->assertSessionHasErrors('photos.0');
        $this->actingAs($this->staff($club, 'manager'))->post(route('club.members.store'), $form(['photo_direct' => $ticket]))->assertSessionHasErrors('photo');
        $other = $this->club('Other');
        $this->actingAs($this->owner($other))->post(route('club.members.store'), $form(['photo_direct' => $ticket]))->assertSessionHasErrors('photo');
        $this->actingAs($owner)->post(route('club.members.store'), $form(['photo_direct' => 'forged']))->assertSessionHasErrors('photo');

        $this->assertSame($before, count($this->requests), 'no request to the bucket');
        $this->assertSame(0, $this->asTenant($club, fn () => Member::count()) + $this->asTenant($other, fn () => Member::count()));
    }

    public function test_an_upload_that_did_not_finish_or_changed_is_refused_and_keeps_nothing(): void
    {
        $this->enable();
        $club = $this->club();
        $this->actingAs($this->owner($club));
        $bytes = $this->jpeg(300, 200);
        $ticket = $this->postJson(route('club.members.uploads'), ['name' => 'a.jpg', 'type' => 'image/jpeg', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)])->json('ticket');

        $this->post(route('club.members.store'), ['full_name' => 'Noor', 'phone' => '96891112233', 'photo_direct' => $ticket, 'photo_preview' => UploadedFile::fake()->image('v.jpg', 300, 200)])
            ->assertSessionHasErrors('photo');

        $this->assertSame(0, $this->asTenant($club, fn () => Member::count()));
        $this->assertSame([], [...Storage::disk('public')->allFiles(), ...Storage::disk('local')->allFiles()]);
        $this->assertSame([], array_keys($this->bucket));
    }

    public function test_a_failed_copy_or_connection_keeps_the_old_photo_and_leaves_nothing_new(): void
    {
        $this->enable();
        $club = $this->club();
        $owner = $this->owner($club);
        $this->actingAs($owner);
        $first = $this->browserPuts('club.members.uploads', $this->jpeg(300, 200), 'a.jpg', 'image/jpeg');
        $this->post(route('club.members.store'), ['full_name' => 'Noor', 'phone' => '96891112233', 'photo_direct' => $first, 'photo_preview' => UploadedFile::fake()->image('v.jpg', 300, 200)])->assertSessionHasNoErrors();
        $member = $this->asTenant($club, fn () => Member::firstOrFail());
        $old = $member->photo_path;
        $oldOriginal = ImageStore::parse($old)['original'];

        foreach (['PUT-copy', 'HEAD'] as $failing) {
            $ticket = $this->browserPuts('club.members.uploads', $this->jpeg(400, 400), 'b.jpg', 'image/jpeg');
            $this->failing = $failing;
            $this->put(route('club.members.update', $member), ['full_name' => 'Noor', 'phone' => '96891112233', 'photo_direct' => $ticket, 'photo_preview' => UploadedFile::fake()->image('v.jpg', 400, 400)])
                ->assertSessionHasErrors('photo');
            $this->failing = null;

            $this->assertSame($old, $this->asTenant($club, fn () => $member->fresh()->photo_path), "after a failing $failing the photo is the one there was");
            Storage::disk('public')->assertExists($old);
            $this->assertArrayHasKey($oldOriginal, $this->bucket);
            $this->assertCount(2, array_filter(Storage::disk('public')->allFiles(), fn ($f) => str_contains($f, '/members/')), 'no new display copy left');
            $this->assertSame([$oldOriginal], array_values(array_filter(array_keys($this->bucket), fn ($k) => str_starts_with($k, 'tenants/'))), 'no new original left');
        }
    }

    public function test_replacing_removes_the_old_original_from_the_bucket_only_after_the_save(): void
    {
        $this->enable();
        $club = $this->club();
        $this->actingAs($this->owner($club));
        $form = fn (string $t, int $s) => ['full_name' => 'Noor', 'phone' => '96891112233', 'photo_direct' => $t, 'photo_preview' => UploadedFile::fake()->image('v.jpg', $s, $s)];
        $this->post(route('club.members.store'), $form($this->browserPuts('club.members.uploads', $this->jpeg(300, 300), 'a.jpg', 'image/jpeg'), 300))->assertSessionHasNoErrors();
        $member = $this->asTenant($club, fn () => Member::firstOrFail());
        $oldOriginal = ImageStore::parse($member->photo_path)['original'];

        $this->put(route('club.members.update', $member), $form($this->browserPuts('club.members.uploads', $this->jpeg(500, 500), 'b.jpg', 'image/jpeg'), 500))->assertSessionHasNoErrors();

        $new = ImageStore::parse($this->asTenant($club, fn () => $member->fresh()->photo_path))['original'];
        $this->assertArrayNotHasKey($oldOriginal, $this->bucket);
        $this->assertArrayHasKey($new, $this->bucket);
        $deleted = array_keys(array_filter($this->requests, fn ($q) => $q['method'] === 'DELETE' && $q['key'] === $oldOriginal));
        $copied = array_keys(array_filter($this->requests, fn ($q) => $q['method'] === 'PUT-copy' && $q['key'] === $new));
        $this->assertGreaterThan(max($copied), min($deleted), 'the old original goes after the new one is kept');
    }

    public function test_an_upload_through_the_app_is_put_to_the_bucket_as_a_stream_with_its_hash(): void
    {
        $this->enable();
        $club = $this->club();
        $photo = UploadedFile::fake()->image('p.jpg', 800, 600);
        $bytes = (string) file_get_contents($photo->getRealPath());

        $this->actingAs($this->owner($club))->post(route('club.members.store'), ['full_name' => 'Noor', 'phone' => '96891112233', 'photo' => $photo])->assertSessionHasNoErrors();

        $original = ImageStore::parse($this->asTenant($club, fn () => Member::firstOrFail()->photo_path))['original'];
        $this->assertSame($bytes, $this->bucket[$original]['body']);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_the_website_library_takes_direct_uploads_and_a_moving_gif_stays_moving(): void
    {
        $this->enable();
        $club = $this->club();
        $this->actingAs($this->owner($club));
        $frame = imagecreatetruecolor(60, 40);
        ob_start();
        imagegif($frame);
        $still = (string) ob_get_clean();
        $head = substr($still, 0, strpos($still, ',', 13));
        $image = substr($still, strpos($still, ',', 13), -1);
        $control = "\x21\xF9\x04\x00\x0A\x00\x00\x00";
        $gif = str_replace('GIF87a', 'GIF89a', $head)."\x21\xFF\x0BNETSCAPE2.0\x03\x01\x00\x00\x00".$control.$image.$control.$image.';';

        $ticket = $this->browserPuts('club.website.media.uploads', $gif, 'moving.gif', 'image/gif');
        $this->post(route('club.website.media.store'), ['images_direct' => [$ticket], 'images_preview' => [UploadedFile::fake()->createWithContent('moving.gif', $gif)]])
            ->assertSessionHasNoErrors();

        $media = $this->asTenant($club, fn () => SiteMedia::firstOrFail());
        $this->assertStringEndsWith('.gif.gif', $media->path);
        $this->assertSame($gif, Storage::disk('public')->get($media->path));
        $this->assertSame([], $this->fullReads());
    }

    public function test_the_signature_still_matches_the_published_aws_example(): void
    {
        $url = (new SpacesSigner('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1', 'examplebucket', 'https://s3.amazonaws.com'))
            ->presign('GET', 'test.txt', 86400, [], gmmktime(0, 0, 0, 5, 24, 2013));

        $this->assertStringEndsWith('&X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404', $url);
    }

    public function test_tenant_type_hint_is_used(): void
    {
        $this->assertInstanceOf(Tenant::class, $this->club());
    }
}
