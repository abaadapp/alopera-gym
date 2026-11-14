<?php

namespace App\Console\Commands;

use App\Core\Support\Images\Bucket;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Tests a bucket for what the originals need, with a few small objects under selftest/ that it removes at the end:
 * a signed put, whether the bucket itself refuses bytes that do not match their MD5 or SHA-256, its ETag being the MD5
 * of what it stored, a Range read, a copy that keeps the hash, a signed download, the browser's CORS preflight, and
 * (read only) its versioning and lifecycle. Run it on a test bucket, and on the real one before switching it on.
 * It does not change the bucket's settings and never touches anything outside selftest/. On the independent copy,
 * whose key should not be able to delete, a refused delete is what is wanted (two 16 KB test objects then stay).
 */
class MediaSpacesCheck extends Command
{
    protected $signature = 'media:spaces-check
        {--bucket=main : main (MEDIA_SPACES_*) or copy (MEDIA_COPY_*)}
        {--origin= : the panel address the browser uploads from (default: APP_URL)}';

    protected $description = 'Test a Spaces bucket with small objects under selftest/ (removed after): what it checks and what the originals need';

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $rows = [];

    public function handle(): int
    {
        $this->rows = [];
        $name = (string) $this->option('bucket');
        $bucket = Bucket::named($name);
        if (! $bucket->configured()) {
            $this->error('The '.$name.' bucket is not set ('.($name === 'main' ? 'MEDIA_SPACES_*' : 'MEDIA_COPY_*').').');

            return self::FAILURE;
        }
        $this->line('Bucket: '.$bucket->label());
        $host = (string) parse_url($bucket->endpoint(), PHP_URL_HOST);
        $this->row('endpoint is not the CDN one', ! str_contains($host, '.cdn.'), 'FAIL', $host);

        $dir = 'selftest/'.Str::uuid();
        $body = "selftest\n".random_bytes(16_384);
        [$sha, $md5] = [hash('sha256', $body), md5($body)];
        $file = (string) tempnam(sys_get_temp_dir(), 'selftest-');
        file_put_contents($file, $body);
        $type = 'application/octet-stream';
        $made = [];

        try {
            $made[] = $dir.'/a.bin';
            $this->row('signed put', $bucket->put($dir.'/a.bin', $file, $sha, $md5, $type), 'FAIL');

            // Same signed headers, other bytes: does the bucket itself refuse them (Content-MD5)?
            $made[] = $dir.'/b.bin';
            $headers = Bucket::putHeaders($type, $sha, $md5);
            $other = Http::withHeaders($headers)->withBody($body.'x', $type)->put($bucket->url('PUT', $dir.'/b.bin', 120, $headers, payload: $sha));
            $this->row('refuses bytes that do not match their MD5', ! $other->successful(), 'WARN', 'HTTP '.$other->status().($other->successful() ? ' (the app still compares the ETag)' : ''));

            // Right MD5, wrong SHA-256: does it check the SHA-256 itself?
            $made[] = $dir.'/c.bin';
            $wrongSha = hash('sha256', 'not this file');
            $headers = Bucket::putHeaders($type, $wrongSha, $md5);
            $shaPut = Http::withHeaders($headers)->withBody($body, $type)->put($bucket->url('PUT', $dir.'/c.bin', 120, $headers, payload: $wrongSha));
            $this->row('refuses bytes that do not match their SHA-256', ! $shaPut->successful(), 'WARN', 'HTTP '.$shaPut->status().($shaPut->successful() ? ' (only the MD5 is checked by the bucket)' : ''));

            $head = $bucket->head($dir.'/a.bin');
            $this->row('HEAD: size and recorded SHA-256', $head !== null && $head['size'] === strlen($body) && $head['sha256'] === $sha, 'FAIL');
            $this->row('ETag is the MD5 of the stored bytes', $head !== null && $head['md5'] === $md5, 'FAIL', $head['md5'] ?? '');
            $this->row('reports its own SHA-256 checksum', ($head['checksum'] ?? null) === $sha, 'INFO', ($head['checksum'] ?? null) === null ? 'not reported' : 'reported');

            $this->row('Range read of the first bytes', $bucket->firstBytes($dir.'/a.bin', 16) === substr($body, 0, 16), 'FAIL');

            $made[] = $dir.'/copy.bin';
            $copied = $bucket->copyWithin($dir.'/a.bin', $dir.'/copy.bin');
            $this->row('copy inside the bucket keeps size, hash and MD5', $copied && Bucket::matches($bucket->head($dir.'/copy.bin'), strlen($body), $sha, $md5), 'FAIL');

            $download = Http::timeout(60)->get($bucket->url('GET', $dir.'/a.bin', 60, query: [
                'response-content-disposition' => 'attachment; filename="selftest.bin"',
                'response-cache-control' => 'private, no-store',
            ]));
            $this->row('signed download as an attachment, same bytes', $download->successful() && str_contains(strtolower((string) $download->header('Content-Disposition')), 'attachment') && hash('sha256', $download->body()) === $sha, 'FAIL', 'HTTP '.$download->status());

            if ($name === 'main') {
                $this->cors($bucket, $dir.'/a.bin');
                $this->settings($bucket, 'lifecycle', fn (string $xml) => str_contains($xml, 'incoming/') && str_contains($xml, '<Expiration>'), 'lifecycle removes incoming/ after a while');
            }
            $this->settings($bucket, 'versioning', fn (string $xml) => str_contains($xml, '<Status>Enabled</Status>'), 'versioning is on');
        } finally {
            foreach ($made as $key) {
                $bucket->delete($key);
            }
            @unlink($file);
        }
        $gone = rescue(fn () => $bucket->head($dir.'/a.bin'), ['size' => -1], false) === null;
        if ($name === 'copy') {
            // The independent copy's key should not be able to delete: then nothing can wipe the copy through it.
            $this->row('its key cannot delete (the copy cannot be wiped through it)', ! $gone, 'WARN', $gone ? 'this key can delete: give the copy a key without delete' : 'two 16 KB test objects stay under '.$dir);
        } else {
            $this->row('removes the test objects', $gone, 'FAIL');
        }

        $this->table(['check', 'result', 'detail'], $this->rows);

        return collect($this->rows)->contains(fn ($r) => $r[1] === 'FAIL') ? self::FAILURE : self::SUCCESS;
    }

    /** The preflight a browser sends before putting an original from the panel. */
    private function cors(Bucket $bucket, string $key): void
    {
        $origin = rtrim((string) ($this->option('origin') ?: config('app.url')), '/');
        $url = strtok($bucket->url('GET', $key), '?');
        $response = rescue(fn () => Http::timeout(30)->withHeaders([
            'Origin' => $origin,
            'Access-Control-Request-Method' => 'PUT',
            'Access-Control-Request-Headers' => 'content-md5,content-type,x-amz-checksum-sha256,x-amz-content-sha256,x-amz-meta-sha256',
        ])->send('OPTIONS', (string) $url), null, false);
        $allowed = (string) $response?->header('Access-Control-Allow-Origin');
        $ok = $response?->successful() && in_array($allowed, [$origin, '*'], true)
            && str_contains(strtoupper((string) $response->header('Access-Control-Allow-Methods')), 'PUT');
        $this->row('CORS lets '.$origin.' put with the signed headers', (bool) $ok, 'FAIL', 'HTTP '.($response?->status() ?? 'none').' allow-origin: '.($allowed ?: 'none'));
    }

    /** A bucket setting, read only. */
    private function settings(Bucket $bucket, string $what, callable $good, string $label): void
    {
        $response = rescue(fn () => Http::timeout(30)->get($bucket->url('GET', '', 60, query: [$what => ''])), null, false);
        $this->row($label, $response?->successful() && $good((string) $response->body()), 'WARN', 'HTTP '.($response?->status() ?? 'none'));
    }

    private function row(string $check, bool $ok, string $otherwise, string $detail = ''): void
    {
        $this->rows[] = [$check, $ok ? 'OK' : $otherwise, $detail];
    }
}
