<?php

namespace App\Core\Support\Images;

use App\Core\Tenancy\TenantContext;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Direct upload: the browser puts the original straight into a DigitalOcean Spaces bucket through a signed address
 * that lasts a few minutes, so a large photo never crosses nginx and PHP on its way in; the form then sends only its
 * key (and the light display source). The server fetches it once, checks and stores it exactly like a normal upload
 * (ImageStore), then removes it from the bucket.
 *
 * Off unless MEDIA_DIRECT_UPLOAD=true and the bucket's five settings are set (config/media.php). Keys are
 * incoming/{club id}/{uuid}.{ext}: a key of another club, or any other shape, is refused. The bucket needs a CORS rule
 * allowing PUT from the panel's address, and a lifecycle rule removing incoming/ after a day for uploads never used.
 */
final class DirectUploads
{
    public static function enabled(): bool
    {
        $c = config('media.direct_upload');

        return (bool) ($c['enabled'] ?? false)
            && collect(['key', 'secret', 'region', 'bucket', 'endpoint'])->every(fn ($k) => filled($c[$k] ?? null));
    }

    /**
     * A signed address the browser puts one file to, and the key the form sends back.
     *
     * @return array{url: string, key: string, headers: array<string, string>, expires_in: int}
     */
    public static function begin(string $name, string $type): array
    {
        abort_unless(self::enabled(), 404);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        abort_unless(in_array($ext, ImageInspector::EXTENSIONS, true), 422, 'هذا الملف ليس صورة مقبولة.');
        $key = self::prefix().'/'.Str::uuid().'.'.$ext;
        $ttl = (int) config('media.direct_upload.ttl', 600);
        $headers = ['Content-Type' => $type !== '' ? $type : 'application/octet-stream'];

        return ['url' => self::signer()->presign('PUT', $key, $ttl, $headers), 'key' => $key, 'headers' => $headers, 'expires_in' => $ttl];
    }

    /**
     * Fetches an object the browser put, as an uploaded file the image layer checks like any other. The object is
     * read in pieces into a temporary file, never held whole in memory, and refused past the size limit.
     *
     * @throws ImageRejected
     */
    public static function claim(string $key, ?string $field = null): UploadedFile
    {
        if (! self::enabled() || ! preg_match('#^'.preg_quote(self::prefix(), '#').'/[0-9a-f-]{36}\.(jpg|png|gif|webp|avif|heic|heif)$#D', $key)) {
            throw (new ImageRejected('تعذر العثور على الصورة المرفوعة. ارفعها مرة أخرى.'))->on($field);
        }
        $max = (int) config('gym.max_image_kb') * 1024;
        $tmp = tempnam(sys_get_temp_dir(), 'direct-');
        $out = fopen($tmp, 'wb');
        try {
            $response = Http::timeout(60)->withOptions(['stream' => true])->get(self::signer()->presign('GET', $key, 120));
            if (! $response->successful()) {
                throw (new ImageRejected('تعذر العثور على الصورة المرفوعة. ارفعها مرة أخرى.'))->on($field);
            }
            $body = $response->toPsrResponse()->getBody();
            $written = 0;
            while (! $body->eof()) {
                $chunk = $body->read(1_048_576);
                $written += strlen($chunk);
                if ($written > $max) {
                    throw (new ImageRejected('حجم الصورة أكبر من المسموح.'))->on($field);
                }
                fwrite($out, $chunk);
            }
        } catch (\Throwable $e) {
            fclose($out);
            @unlink($tmp);

            throw $e instanceof ImageRejected ? $e : (new ImageRejected('تعذر جلب الصورة المرفوعة. حاول مرة أخرى.', 0, $e))->on($field);
        }
        fclose($out);

        return new UploadedFile($tmp, basename($key), null, null, true);
    }

    /** Removes an object once it is stored (or refused); a failure only leaves it to the bucket's lifecycle rule. */
    public static function forget(string $key): void
    {
        if (self::enabled() && str_starts_with($key, self::prefix().'/')) {
            rescue(fn () => Http::timeout(15)->delete(self::signer()->presign('DELETE', $key, 120)), report: false);
        }
    }

    private static function prefix(): string
    {
        return trim((string) config('media.direct_upload.prefix', 'incoming'), '/').'/'.app(TenantContext::class)->require()->id;
    }

    private static function signer(): SpacesSigner
    {
        $c = config('media.direct_upload');

        return new SpacesSigner((string) $c['key'], (string) $c['secret'], (string) $c['region'], (string) $c['bucket'], (string) $c['endpoint']);
    }
}
