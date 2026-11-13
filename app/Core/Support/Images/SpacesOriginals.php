<?php

namespace App\Core\Support\Images;

use App\Core\Tenancy\TenantContext;
use Illuminate\Support\Facades\Crypt;
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

    public const UNREACHABLE = Bucket::UNREACHABLE;

    public static function enabled(): bool
    {
        return config('media.originals') === 'spaces' && Bucket::main()->configured();
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
        $headers = Bucket::putHeaders($type !== '' ? $type : 'application/octet-stream', $sha256, $md5);
        $ttl = (int) config('media.spaces.ttl', 600);
        $ticket = Crypt::encryptString((string) json_encode([
            'k' => $key, 's' => $sha256, 'm' => $md5, 'n' => $size, 'f' => trim($folder, '/'), 't' => $tenant,
            'u' => auth()->id(), 'x' => time() + 3600, 'o' => mb_substr($name, 0, 120),
        ]));

        return ['url' => Bucket::main()->url('PUT', $key, $ttl, $headers, payload: $sha256), 'headers' => $headers, 'ticket' => $ticket];
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
     * What the bucket says of an object, or null when it is not there (Bucket::head).
     *
     * @return array{size: int, sha256: string, md5: string, checksum: ?string}|null
     *
     * @throws ImageRejected
     */
    public static function head(string $key): ?array
    {
        return Bucket::main()->head($key);
    }

    /** @param  array{size: int, sha256: string, md5: string, checksum: ?string}|null  $object */
    public static function matches(?array $object, int $size, string $sha256, string $md5): bool
    {
        return Bucket::matches($object, $size, $sha256, $md5);
    }

    /** @return array{0: string, 1: string} the SHA-256 and MD5 of a file the app has */
    public static function fileHashes(string $path): array
    {
        return Bucket::fileHashes($path);
    }

    /** The first bytes of an object (its header), read with a Range request: never the whole file. */
    public static function firstBytes(string $key, int $bytes = self::HEAD_BYTES): string
    {
        return Bucket::main()->firstBytes($key, $bytes);
    }

    /** Copies an object inside the bucket (no bytes pass through the app), keeping its recorded hash. */
    public static function copy(string $from, string $to): bool
    {
        return Bucket::main()->copyWithin($from, $to);
    }

    /** Puts a file the app already has (an upload that came through it), as a stream, the bucket checking its hashes. */
    public static function put(string $key, string $path, string $sha256, string $md5, string $type): bool
    {
        return Bucket::main()->put($key, $path, $sha256, $md5, $type);
    }

    public static function delete(string $key): bool
    {
        return Bucket::main()->delete($key);
    }

    /** A 60-second signed address that downloads the original under a readable name. */
    public static function downloadUrl(string $key, string $filename, string $mime, int $ttl = 60): string
    {
        $query = [
            'response-content-disposition' => 'attachment; filename="'.addcslashes($filename, '"\\').'"; filename*=UTF-8\'\''.rawurlencode($filename),
            'response-content-type' => $mime,
            'response-cache-control' => 'private, no-store',
        ];

        return Bucket::main()->url('GET', $key, $ttl, query: $query);
    }

    private static function prefix(): string
    {
        return trim((string) config('media.spaces.prefix', 'incoming'), '/');
    }
}
