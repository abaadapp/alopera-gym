<?php

namespace App\Core\Support\Images;

use App\Core\Tenancy\TenantContext;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Originals kept in a private DigitalOcean Spaces bucket instead of the droplet's disk (MEDIA_ORIGINALS=spaces, off by
 * default). The browser puts each original straight into the bucket through a signed address that also carries the
 * file's SHA-256: the bucket refuses a body that does not match it, so the original is checked byte for byte without
 * the app ever reading it whole. The app then reads only its size, its recorded hash and its first 256 KB (type and
 * dimensions), copies it inside the bucket to its place in the club's folder, and keeps the display copy made from the
 * browser's light display source. Downloads are a 60-second signed address given after the permission check.
 *
 * Keys: incoming/{club}/{uuid}.{ext} for an upload not yet used (a lifecycle rule removes these after a day), and
 * tenants/{club}/originals/{folder}/v2r-{ulid}.{ext} for a kept original. A ticket names its club, user, place and key,
 * is encrypted and expires: one of another club, user or place is refused before any request.
 */
final class SpacesOriginals
{
    public const HEAD_BYTES = 262_144;

    public static function enabled(): bool
    {
        $c = (array) config('media.spaces');

        return config('media.originals') === 'spaces'
            && collect(['key', 'secret', 'region', 'bucket', 'endpoint'])->every(fn ($k) => filled($c[$k] ?? null));
    }

    /**
     * A signed address for the browser to put one original, and the ticket the form sends back in its place.
     *
     * @return array{url: string, headers: array<string, string>, ticket: string}
     */
    public static function ticket(string $folder, string $name, string $type, int $size, string $sha256): array
    {
        abort_unless(self::enabled(), 404);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        abort_unless(in_array($ext, ImageInspector::EXTENSIONS, true), 422, 'هذا الملف ليس صورة مقبولة.');
        abort_unless($size >= 1 && $size <= (int) config('gym.max_image_kb') * 1024, 422, 'حجم الصورة أكبر من المسموح.');
        abort_unless((bool) preg_match('/^[0-9a-f]{64}$/D', $sha256), 422);

        $tenant = app(TenantContext::class)->require()->id;
        $key = self::prefix().'/'.$tenant.'/'.Str::uuid().'.'.$ext;
        $headers = [
            'Content-Type' => $type !== '' ? $type : 'application/octet-stream',
            'x-amz-content-sha256' => $sha256,
            'x-amz-meta-sha256' => $sha256,
        ];
        $ttl = (int) config('media.spaces.ttl', 600);
        $ticket = Crypt::encryptString((string) json_encode([
            'k' => $key, 's' => $sha256, 'n' => $size, 'f' => trim($folder, '/'), 't' => $tenant,
            'u' => auth()->id(), 'x' => time() + 3600, 'o' => mb_substr($name, 0, 120),
        ]));

        return ['url' => self::signer()->presign('PUT', $key, $ttl, $headers, payload: $sha256), 'headers' => $headers, 'ticket' => $ticket];
    }

    /**
     * What a ticket the form sent back says, checked: this club, this user, this place, not expired.
     *
     * @return array{k: string, s: string, n: int, f: string, o: string}
     *
     * @throws ImageRejected
     */
    public static function open(string $ticket, string $folder, ?string $field = null): array
    {
        $refused = fn () => (new ImageRejected('تعذر العثور على الصورة المرفوعة. ارفعها مرة أخرى.'))->on($field);
        if (! self::enabled()) {
            throw $refused();
        }
        try {
            $data = json_decode(Crypt::decryptString($ticket), true, 4, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw $refused();
        }
        $tenant = app(TenantContext::class)->require()->id;
        if (! is_array($data) || ($data['t'] ?? null) !== $tenant || ($data['u'] ?? null) !== auth()->id()
            || ($data['f'] ?? null) !== trim($folder, '/') || (int) ($data['x'] ?? 0) < time()
            || ! preg_match('#^'.preg_quote(self::prefix(), '#').'/'.$tenant.'/[0-9a-f-]{36}\.[a-z]+$#D', (string) ($data['k'] ?? ''))) {
            throw $refused();
        }

        return $data;
    }

    /**
     * The object's size and the SHA-256 recorded when it was put, or null when it is not there.
     *
     * @return array{size: int, sha256: string}|null
     */
    public static function head(string $key): ?array
    {
        $response = self::send('HEAD', $key);
        if (! $response->successful()) {
            return null;
        }

        return ['size' => (int) $response->header('Content-Length'), 'sha256' => strtolower((string) $response->header('x-amz-meta-sha256'))];
    }

    /** The first bytes of an object (its header), read with a Range request: never the whole file. */
    public static function firstBytes(string $key, int $bytes = self::HEAD_BYTES): string
    {
        $response = Http::timeout(30)->withHeaders(['Range' => 'bytes=0-'.($bytes - 1)])->get(self::signer()->presign('GET', $key, 120));
        if (! in_array($response->status(), [200, 206], true)) {
            throw new ImageRejected('تعذر قراءة الصورة المرفوعة. حاول مرة أخرى.');
        }

        return substr($response->body(), 0, $bytes);
    }

    /** Copies an object inside the bucket (no bytes pass through the app), keeping its recorded hash. */
    public static function copy(string $from, string $to): bool
    {
        $c = (array) config('media.spaces');
        $headers = ['x-amz-copy-source' => '/'.$c['bucket'].'/'.$from, 'x-amz-metadata-directive' => 'COPY'];

        return self::send('PUT', $to, $headers)->successful();
    }

    /** Puts a file the app already has (an upload that came through it), as a stream, the bucket checking its hash. */
    public static function put(string $key, string $path, string $sha256, string $type): bool
    {
        $headers = ['Content-Type' => $type, 'x-amz-content-sha256' => $sha256, 'x-amz-meta-sha256' => $sha256];
        $stream = fopen($path, 'rb');
        try {
            return Http::timeout(120)->withHeaders($headers)
                ->withBody(Utils::streamFor($stream), $type)
                ->put(self::signer()->presign('PUT', $key, 300, $headers, payload: $sha256))
                ->successful();
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    public static function delete(string $key): bool
    {
        return rescue(fn () => self::send('DELETE', $key)->successful(), false, report: false);
    }

    /** A 60-second signed address that downloads the original under a readable name. */
    public static function downloadUrl(string $key, string $filename, string $mime, int $ttl = 60): string
    {
        $query = [
            'response-content-disposition' => 'attachment; filename="'.addcslashes($filename, '"\\').'"; filename*=UTF-8\'\''.rawurlencode($filename),
            'response-content-type' => $mime,
            'response-cache-control' => 'private, no-store',
        ];

        return self::signer()->presign('GET', $key, $ttl, query: $query);
    }

    /** @param  array<string, string>  $headers */
    private static function send(string $method, string $key, array $headers = []): Response
    {
        return Http::timeout(30)->withHeaders($headers)->send($method, self::signer()->presign($method, $key, 120, $headers));
    }

    private static function prefix(): string
    {
        return trim((string) config('media.spaces.prefix', 'incoming'), '/');
    }

    private static function signer(): SpacesSigner
    {
        $c = (array) config('media.spaces');

        return new SpacesSigner((string) $c['key'], (string) $c['secret'], (string) $c['region'], (string) $c['bucket'], (string) $c['endpoint']);
    }
}
