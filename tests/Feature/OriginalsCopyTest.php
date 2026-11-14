<?php

namespace Tests\Feature;

use App\Core\Support\ImageStore;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * media:originals-copy and media:originals-check with two simulated buckets (the main one and the independent copy):
 * copied one way, checked byte for byte, never deleted, a damaged original never spread and restored from the copy.
 * The same commands against real buckets: SpacesLiveTest.
 */
class OriginalsCopyTest extends TestCase
{
    /** @var array<string, array<string, array{body: string, sha256: string}>> bucket => key => object */
    private array $buckets = ['originals' => [], 'originals-copy' => []];

    /** @var list<string> */
    private array $deletes = [];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        config([
            'media.spaces' => ['key' => 'k1', 'secret' => 's1', 'region' => 'fra1', 'bucket' => 'originals', 'endpoint' => 'https://fra1.digitaloceanspaces.com', 'prefix' => 'incoming', 'ttl' => 600],
            'media.copy' => ['key' => 'k2', 'secret' => 's2', 'region' => 'ams3', 'bucket' => 'originals-copy', 'endpoint' => 'https://ams3.digitaloceanspaces.com'],
        ]);
        Http::fake(fn (ClientRequest $r) => $this->answer($r));
    }

    private function answer(ClientRequest $r)
    {
        $bucket = explode('.', (string) parse_url($r->url(), PHP_URL_HOST))[0];
        $key = rawurldecode(ltrim((string) parse_url($r->url(), PHP_URL_PATH), '/'));
        $object = $this->buckets[$bucket][$key] ?? null;

        return match ($r->method()) {
            'HEAD' => $object ? Http::response('', 200, ['Content-Length' => (string) strlen($object['body']), 'ETag' => '"'.md5($object['body']).'"', 'x-amz-meta-sha256' => $object['sha256']]) : Http::response('', 404),
            'GET' => $object ? Http::response($object['body']) : Http::response('', 404),
            'PUT' => $this->putObject($r, $bucket, $key),
            'DELETE' => tap(Http::response('', 204), fn () => $this->deletes[] = $bucket.'/'.$key),
        };
    }

    private function putObject(ClientRequest $r, string $bucket, string $key)
    {
        if (($r->header('Content-MD5')[0] ?? '') !== base64_encode(md5($r->body(), true))) {
            return Http::response('<Code>BadDigest</Code>', 400);
        }
        $this->buckets[$bucket][$key] = ['body' => $r->body(), 'sha256' => $r->header('x-amz-meta-sha256')[0] ?? ''];

        return Http::response('', 200);
    }

    /** An image kept with its original in the bucket: its display copy on this server, its original in the main bucket. */
    private function kept(int $club, string $bytes): string
    {
        $display = 'tenants/'.$club.'/members/v2r-'.strtolower((string) Str::ulid()).'-jpg.webp';
        Storage::disk('public')->put($display, 'webp');
        $key = ImageStore::parse($display)['original'];
        $this->buckets['originals'][$key] = ['body' => $bytes, 'sha256' => hash('sha256', $bytes)];

        return $key;
    }

    public function test_the_copy_is_made_checked_and_brings_the_originals_back_without_deleting_anything(): void
    {
        $a = $this->kept(1, random_bytes(5000));
        $b = $this->kept(2, random_bytes(7000));
        Storage::disk('public')->put('tenants/1/members/old-photo.jpg', 'an older image, no original'); // ignored

        $this->assertSame(0, Artisan::call('media:originals-copy', ['--from' => 'main', '--to' => 'copy', '--dry-run' => true]));
        $this->assertSame([], $this->buckets['originals-copy'], 'a dry run copies nothing');
        $this->assertSame(0, Artisan::call('media:originals-copy', ['--from' => 'main', '--to' => 'copy']), Artisan::output());
        $this->assertSame($this->buckets['originals'], $this->buckets['originals-copy']);
        $this->assertSame(0, Artisan::call('media:originals-check', ['--bucket' => 'copy', '--deep' => 2]), Artisan::output());
        $this->assertSame(0, Artisan::call('media:originals-copy', ['--from' => 'main', '--to' => 'copy']));
        $this->assertStringContainsString('already there', Artisan::output());

        // The main bucket loses one: the check names it, the restore brings it back from the copy.
        $lost = $this->buckets['originals'][$a];
        unset($this->buckets['originals'][$a]);
        $this->assertSame(1, Artisan::call('media:originals-check'));
        $this->assertStringContainsString('missing: '.$a, Artisan::output());
        $this->assertSame(0, Artisan::call('media:originals-copy', ['--from' => 'copy', '--to' => 'main']), Artisan::output());
        $this->assertSame($lost, $this->buckets['originals'][$a]);
        $this->assertSame([], $this->deletes, 'nothing was ever deleted');

        // Bit rot in the main bucket: never spread to the copy, found by the drill, repaired from the copy on request.
        $this->buckets['originals'][$b]['body'] = random_bytes(7000);
        $this->assertSame(1, Artisan::call('media:originals-copy', ['--from' => 'main', '--to' => 'copy', '--repair' => true]));
        $this->assertStringContainsString('damaged in the source: '.$b, Artisan::output());
        $this->assertSame(1, Artisan::call('media:originals-check', ['--deep' => 2]));
        $this->assertStringContainsString('not matching: '.$b, Artisan::output());
        $this->assertSame(1, Artisan::call('media:originals-copy', ['--from' => 'copy', '--to' => 'main']), 'without --repair a differing one is only reported');
        $this->assertSame(0, Artisan::call('media:originals-copy', ['--from' => 'copy', '--to' => 'main', '--repair' => true]), Artisan::output());
        $this->assertSame($this->buckets['originals-copy'][$b], $this->buckets['originals'][$b]);
    }

    public function test_it_refuses_to_run_without_both_buckets_or_on_one_bucket(): void
    {
        config(['media.copy.secret' => null]);
        $this->assertSame(1, Artisan::call('media:originals-copy', ['--from' => 'main', '--to' => 'copy']));
        $this->assertSame(1, Artisan::call('media:originals-copy', ['--from' => 'main', '--to' => 'main']));
        $this->assertSame(1, Artisan::call('media:originals-check', ['--bucket' => 'copy']));
        Http::assertNothingSent();
    }
}
