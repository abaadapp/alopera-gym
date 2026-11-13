<?php

namespace Tests\Feature;

use App\Core\Support\Images\DirectUploads;
use App\Core\Support\Images\SpacesSigner;
use App\Core\Support\ImageStore;
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
 * Direct upload to a DigitalOcean Spaces bucket: off by default (nothing changes until it is configured); when on,
 * the browser gets a short-lived signed address for one file in its own club's folder, the server fetches that file,
 * stores it like any upload and removes it from the bucket; a key of another club or of any other shape is refused.
 * No real bucket is used here: its answers are faked.
 */
class DirectUploadTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Storage::fake('local');
    }

    private function enable(): void
    {
        config(['media.direct_upload' => [
            'enabled' => true, 'key' => 'DO00EXAMPLEKEY', 'secret' => 'example-secret', 'region' => 'fra1',
            'bucket' => 'alopera-media', 'endpoint' => 'https://fra1.digitaloceanspaces.com', 'prefix' => 'incoming', 'ttl' => 600,
        ]]);
    }

    public function test_the_signature_matches_the_published_aws_example(): void
    {
        $signer = new SpacesSigner('AKIAIOSFODNN7EXAMPLE', 'wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY', 'us-east-1', 'examplebucket', 'https://s3.amazonaws.com');

        $url = $signer->presign('GET', 'test.txt', 86400, [], gmmktime(0, 0, 0, 5, 24, 2013));

        $this->assertStringStartsWith('https://examplebucket.s3.amazonaws.com/test.txt?X-Amz-Algorithm=AWS4-HMAC-SHA256', $url);
        $this->assertStringEndsWith('&X-Amz-Signature=aeeed9bbccd4d02ee5c0109b86d86835f995330da4c265957d157751f604d404', $url);
    }

    public function test_it_is_off_by_default_and_the_library_uploads_through_the_server(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);

        $this->assertFalse(DirectUploads::enabled());
        $this->actingAs($owner)->postJson(route('club.website.media.direct'), ['name' => 'a.jpg', 'type' => 'image/jpeg', 'size' => 100])->assertNotFound();
        $this->get(route('club.website.edit'))->assertInertia(fn (AssertableInertia $p) => $p->where('limits.direct', false));
    }

    public function test_switched_on_without_every_setting_it_stays_off(): void
    {
        $this->enable();
        config(['media.direct_upload.secret' => null]);

        $this->assertFalse(DirectUploads::enabled());
    }

    public function test_the_browser_gets_a_signed_address_in_its_own_clubs_folder(): void
    {
        $this->enable();
        $club = $this->club();

        $ticket = $this->actingAs($this->owner($club))
            ->postJson(route('club.website.media.direct'), ['name' => 'IMG_0001.HEIC', 'type' => 'image/heic', 'size' => 2_000_000])
            ->assertOk()->json();

        $this->assertMatchesRegularExpression('#^incoming/'.$club->id.'/[0-9a-f-]{36}\.heic$#', $ticket['key']);
        $this->assertStringStartsWith('https://alopera-media.fra1.digitaloceanspaces.com/'.$ticket['key'].'?', $ticket['url']);
        $this->assertStringContainsString('X-Amz-SignedHeaders=content-type%3Bhost', $ticket['url']);
        $this->assertStringContainsString('X-Amz-Expires=600', $ticket['url']);
        $this->assertStringNotContainsString('example-secret', $ticket['url']);
        $this->assertSame(['Content-Type' => 'image/heic'], $ticket['headers']);

        $this->postJson(route('club.website.media.direct'), ['name' => 'run.php', 'type' => 'text/plain', 'size' => 10])->assertStatus(422);
        $this->actingAs($this->staff($club, 'reception'))->postJson(route('club.website.media.direct'), ['name' => 'a.jpg', 'size' => 10])->assertForbidden();
    }

    public function test_a_direct_upload_is_fetched_stored_byte_for_byte_and_removed_from_the_bucket(): void
    {
        $this->enable();
        $club = $this->club();
        $owner = $this->owner($club);
        $photo = UploadedFile::fake()->image('big.jpg', 1600, 1200);
        $bytes = (string) file_get_contents($photo->getRealPath());
        Http::fake(fn (ClientRequest $r) => $r->method() === 'GET' ? Http::response($bytes) : Http::response('', 204));

        $key = $this->actingAs($owner)->postJson(route('club.website.media.direct'), ['name' => 'big.jpg', 'type' => 'image/jpeg', 'size' => strlen($bytes)])->json('key');
        $this->post(route('club.website.media.store'), ['images_direct' => [$key]])->assertSessionHasNoErrors();

        $media = $this->asTenant($club, fn () => SiteMedia::firstOrFail());
        $this->assertSame(hash('sha256', $bytes), ImageStore::sha256('local', ImageStore::parse($media->path)['original']));
        Http::assertSent(fn (ClientRequest $r) => $r->method() === 'GET' && str_contains($r->url(), $key));
        Http::assertSent(fn (ClientRequest $r) => $r->method() === 'DELETE' && str_contains($r->url(), $key));
    }

    public function test_a_key_of_another_club_or_any_other_shape_is_refused_without_a_request(): void
    {
        $this->enable();
        $club = $this->club();
        $other = $this->club('Other');
        Http::fake();

        foreach (['incoming/'.$other->id.'/'.'0b7c8f1e-6a51-4c49-9d0f-3a1f2b4c5d6e.jpg', 'incoming/'.$club->id.'/../secret.jpg', 'tenants/'.$club->id.'/x.jpg', 'incoming/'.$club->id.'/0b7c8f1e-6a51-4c49-9d0f-3a1f2b4c5d6e.php'] as $key) {
            $this->actingAs($this->owner($club))->post(route('club.website.media.store'), ['images_direct' => [$key]])->assertSessionHasErrors();
        }

        Http::assertNothingSent();
        $this->assertSame(0, $this->asTenant($club, fn () => SiteMedia::count()));
    }

    public function test_a_direct_upload_over_the_size_limit_is_refused_and_nothing_is_kept(): void
    {
        $this->enable();
        config(['gym.max_image_kb' => 1]);
        $club = $this->club();
        Http::fake(fn (ClientRequest $r) => $r->method() === 'GET' ? Http::response(str_repeat('x', 4096)) : Http::response('', 204));

        $key = 'incoming/'.$club->id.'/0b7c8f1e-6a51-4c49-9d0f-3a1f2b4c5d6e.jpg';
        $this->actingAs($this->owner($club))->post(route('club.website.media.store'), ['images_direct' => [$key]])->assertSessionHasErrors();

        $this->assertSame([], [...Storage::disk('public')->allFiles(), ...Storage::disk('local')->allFiles()]);
    }
}
