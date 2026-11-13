<?php

namespace App\Core\Support\Images;

use App\Core\Tenancy\TenantContext;
use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Originals kept in a private DigitalOcean Spaces bucket instead of the droplet's disk (MEDIA_ORIGINALS=spaces, off by
 * default). The browser puts each original straight into the bucket through a signed address; the app then reads only
 * its size, its fingerprints and its first 256 KB (type and dimensions), copies it inside the bucket to its place in the
 * club's folder, and keeps the display copy made from the browser's light display source. Downloads are a 60-second
 * signed address given after the permission check.
 *
 * The bytes are checked by the bucket, not taken on the browser's word: the signed address carries the file's MD5
 * (Content-MD5) and SHA-256 (x-amz-checksum-sha256, x-amz-content-sha256), so the bucket refuses other bytes, and the
 * app then compares the MD5 the bucket computed itself from what it stored (the ETag), and the SHA-256 it checked when
 * it reports one, before keeping anything. A bucket that ignored every signed hash would still be caught by its ETag.
 *
 * Keys: incoming/{club}/{uuid}.{ext} for an upload not yet used (a lifecycle rule removes these after a day), and
 * tenants/{club}/originals/{folder}/v2r-{ulid}.{ext} for a kept original. A ticket names its club, user, place and key,
 * is encrypted and expires: one of another club, user or place is refused before any request.
 */
final class SpacesOriginals
{
    public const HEAD_BYTES = 262_144;

    /** What a user sees when the bucket cannot be reached: nothing was kept and nothing was lost. */
    public const UNREACHABLE = 'تعذر الاتصال بمخزن الصور، فلم يُحفظ شيء ولم يُفقد شيء. حاول مرة أخرى بعد قليل.';

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
    public static function ticket(string $folder, string $name, string $type, int $size, string $sha256, string $md5): array
    {
        abort_unless(self::enabled(), 404);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        abort_unless(in_array($ext, ImageInspector::EXTENSIONS, true), 422, 'هذا الملف ليس صورة مقبولة.');
        abort_unless($size >= 1 && $size <= (int) config('gym.max_image_kb') * 1024, 422, 'حجم الصورة أكبر من المسموح.');
        abort_unless((bool) preg_match('/^[0-9a-f]{64}$/D', $sha256) && (bool) preg_match('/^[0-9a-f]{32}$/D', $md5), 422);

        $tenant = app(TenantContext::class)->require()->id;
        $key = self::prefix().'/'.$tenant.'/'.Str::uuid().'.'.$ext;
        $headers = self::putHeaders($type !== '' ? $type : 'application/octet-stream', $sha256, $md5);
        $ttl = (int) config('media.spaces.ttl', 600);
        $ticket = Crypt::encryptString((string) json_encode([
            'k' => $key, 's' => $sha256, 'm' => $md5, 'n' => $size, 'f' => trim($folder, '/'), 't' => $tenant,
            'u' => auth()->id(), 'x' => time() + 3600, 'o' => mb_substr($name, 0, 120),
        ]));

        return ['url' => self::signer()->presign('PUT', $key, $ttl, $headers, payload: $sha256), 'headers' => $headers, 'ticket' => $ticket];
    }

    /**
     * What a ticket the form sent back says, checked: this club, this user, this place, not expired.
     *
     * @return array{k: string, s: string, m: string, n: int, f: string, o: string}
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
            || ($data['f'] ?? null) !== trim($folder, '/') || (int) ($data['x'] ?? 0) < time() || ! is_string($data['m'] ?? null)
            || ! preg_match('#^'.preg_quote(self::prefix(), '#').'/'.$tenant.'/[0-9a-f-]{36}\.[a-z]+$#D', (string) ($data['k'] ?? ''))) {
            throw $refused();
        }

        return $data;
    }

    /**
     * What the bucket says of an object, or null when it is not there: its size, the SHA-256 recorded when it was put,
     * the MD5 the bucket computed itself from the stored bytes (the ETag of an object put in one piece), and the SHA-256
     * the bucket checked itself when it keeps checksums (null when it does not report one).
     *
     * @return array{size: int, sha256: string, md5: string, checksum: ?string}|null
     *
     * @throws ImageRejected when the bucket cannot be reached or does not answer properly
     */
    public static function head(string $key): ?array
    {
        $response = self::send('HEAD', $key, ['x-amz-checksum-mode' => 'ENABLED']);
        if ($response->status() === 404) {
            return null;
        }
        if (! $response->successful()) {
            throw new ImageRejected(self::UNREACHABLE);
        }
        $etag = strtolower(trim((string) $response->header('ETag'), '" '));
        $checksum = base64_decode((string) $response->header('x-amz-checksum-sha256'), true);

        return [
            'size' => (int) $response->header('Content-Length'),
            'sha256' => strtolower((string) $response->header('x-amz-meta-sha256')),
            'md5' => preg_match('/^[0-9a-f]{32}$/D', $etag) ? $etag : '',
            'checksum' => is_string($checksum) && strlen($checksum) === 32 ? bin2hex($checksum) : null,
        ];
    }

    /**
     * Whether the bucket holds exactly the expected file: its size, its recorded hash, the MD5 the bucket computed from
     * the bytes it stored, and the SHA-256 the bucket checked when it reports one.
     *
     * @param  array{size: int, sha256: string, md5: string, checksum: ?string}|null  $object
     */
    public static function matches(?array $object, int $size, string $sha256, string $md5): bool
    {
        return $object !== null && $object['size'] === $size && hash_equals($sha256, $object['sha256'])
            && $object['md5'] !== '' && hash_equals($md5, $object['md5'])
            && ($object['checksum'] === null || hash_equals($sha256, $object['checksum']));
    }

    /**
     * The SHA-256 and MD5 of a file the app has, read once in pieces.
     *
     * @return array{0: string, 1: string}
     */
    public static function fileHashes(string $path): array
    {
        $handle = fopen($path, 'rb');
        $sha = hash_init('sha256');
        $md5 = hash_init('md5');
        while ($handle && ! feof($handle)) {
            $chunk = (string) fread($handle, 1_048_576);
            hash_update($sha, $chunk);
            hash_update($md5, $chunk);
        }
        if ($handle) {
            fclose($handle);
        }

        return [hash_final($sha), hash_final($md5)];
    }

    /**
     * The headers a put carries, all of them signed: the bucket refuses a body that does not match its MD5 (and its
     * SHA-256 when it checks checksums), and keeps the SHA-256 beside the object.
     *
     * @return array<string, string>
     */
    public static function putHeaders(string $type, string $sha256, string $md5): array
    {
        return [
            'Content-Type' => $type,
            'Content-MD5' => base64_encode((string) hex2bin($md5)),
            'x-amz-checksum-sha256' => base64_encode((string) hex2bin($sha256)),
            'x-amz-content-sha256' => $sha256,
            'x-amz-meta-sha256' => $sha256,
        ];
    }

    /** The first bytes of an object (its header), read with a Range request: never the whole file. */
    public static function firstBytes(string $key, int $bytes = self::HEAD_BYTES): string
    {
        $response = self::reach(fn () => Http::timeout(30)->withHeaders(['Range' => 'bytes=0-'.($bytes - 1)])->get(self::signer()->presign('GET', $key, 120)));
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

    /** Puts a file the app already has (an upload that came through it), as a stream, the bucket checking its hashes. */
    public static function put(string $key, string $path, string $sha256, string $md5, string $type): bool
    {
        $headers = self::putHeaders($type, $sha256, $md5);
        $stream = fopen($path, 'rb');
        try {
            return self::reach(fn () => Http::timeout(120)->withHeaders($headers)
                ->withBody(Utils::streamFor($stream), $type)
                ->put(self::signer()->presign('PUT', $key, 300, $headers, payload: $sha256)))
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
        return self::reach(fn () => Http::timeout(30)->withHeaders($headers)->send($method, self::signer()->presign($method, $key, 120, $headers)));
    }

    /**
     * A request to the bucket; a lost connection, a timeout or a reset becomes a refusal the form shows on the field.
     *
     * @param  callable(): Response  $request
     *
     * @throws ImageRejected
     */
    private static function reach(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException|TransferException $e) {
            throw new ImageRejected(self::UNREACHABLE, 0, $e);
        }
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
