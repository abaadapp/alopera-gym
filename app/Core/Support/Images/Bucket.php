<?php

namespace App\Core\Support\Images;

use GuzzleHttp\Exception\TransferException;
use GuzzleHttp\Psr7\Utils;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * One S3-compatible bucket (DigitalOcean Spaces, or another provider for the independent copy), reached through
 * short-lived signed addresses (SpacesSigner): the originals' bucket (config media.spaces) or their independent copy
 * (config media.copy). Every request that cannot reach the bucket becomes an ImageRejected with a plain message.
 */
final class Bucket
{
    /** What a user sees when the bucket cannot be reached: nothing was kept and nothing was lost. */
    public const UNREACHABLE = 'تعذر الاتصال بمخزن الصور، فلم يُحفظ شيء ولم يُفقد شيء. حاول مرة أخرى بعد قليل.';

    private const SETTINGS = ['key', 'secret', 'region', 'bucket', 'endpoint'];

    /** @param  array<string, mixed>  $settings */
    public function __construct(private readonly array $settings) {}

    /** The originals' bucket (MEDIA_SPACES_*). */
    public static function main(): self
    {
        return new self((array) config('media.spaces'));
    }

    /** The independent copy (MEDIA_COPY_*). */
    public static function copy(): self
    {
        return new self((array) config('media.copy'));
    }

    public static function named(string $name): self
    {
        return match ($name) {
            'main' => self::main(),
            'copy' => self::copy(),
            default => throw new \InvalidArgumentException('main or copy'),
        };
    }

    public function configured(): bool
    {
        return collect(self::SETTINGS)->every(fn ($k) => filled($this->settings[$k] ?? null));
    }

    /** bucket@host, for messages (never the key or the secret). */
    public function label(): string
    {
        return ($this->settings['bucket'] ?? '?').'@'.(parse_url((string) ($this->settings['endpoint'] ?? ''), PHP_URL_HOST) ?: '?');
    }

    public function endpoint(): string
    {
        return (string) ($this->settings['endpoint'] ?? '');
    }

    public function bucket(): string
    {
        return (string) ($this->settings['bucket'] ?? '');
    }

    /**
     * @param  array<string, string>  $headers
     * @param  array<string, string>  $query
     */
    public function url(string $method, string $key, int $ttl = 120, array $headers = [], array $query = [], string $payload = 'UNSIGNED-PAYLOAD'): string
    {
        return $this->signer()->presign($method, $key, $ttl, $headers, query: $query, payload: $payload);
    }

    /**
     * What the bucket says of an object, or null when it is not there: its size, the SHA-256 recorded when it was put,
     * the MD5 the bucket computed itself from the stored bytes (the ETag of an object put in one piece), and the
     * SHA-256 the bucket checked itself when it keeps checksums (null when it does not report one).
     *
     * @return array{size: int, sha256: string, md5: string, checksum: ?string}|null
     *
     * @throws ImageRejected when the bucket cannot be reached or does not answer properly
     */
    public function head(string $key): ?array
    {
        $response = $this->send('HEAD', $key, ['x-amz-checksum-mode' => 'ENABLED']);
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
     * Whether an object is exactly the expected file: its size, its recorded hash, the MD5 the bucket computed from the
     * bytes it stored, and the SHA-256 the bucket checked when it reports one.
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

    /**
     * The SHA-256 and MD5 of a file, read once in pieces.
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

    /** The first bytes of an object (its header), read with a Range request: never the whole file. */
    public function firstBytes(string $key, int $bytes): string
    {
        $response = $this->reach(fn () => Http::timeout(30)->withHeaders(['Range' => 'bytes=0-'.($bytes - 1)])->get($this->url('GET', $key)));
        if (! in_array($response->status(), [200, 206], true)) {
            throw new ImageRejected('تعذر قراءة الصورة المرفوعة. حاول مرة أخرى.');
        }

        return substr($response->body(), 0, $bytes);
    }

    /**
     * Fetches a whole object into a file, as a stream (for the independent copy and the restore drill only; uploads
     * never do this). False when it is not there.
     */
    public function download(string $key, string $path): bool
    {
        $response = $this->reach(fn () => Http::timeout(600)->withOptions(['sink' => $path])->get($this->url('GET', $key, 900)));

        return $response->successful();
    }

    /** Puts a file as a stream with its hashes signed, the bucket checking them. */
    public function put(string $key, string $path, string $sha256, string $md5, string $type): bool
    {
        $headers = self::putHeaders($type, $sha256, $md5);
        $stream = fopen($path, 'rb');
        try {
            return $this->reach(fn () => Http::timeout(600)->withHeaders($headers)
                ->withBody(Utils::streamFor($stream), $type)
                ->put($this->url('PUT', $key, 900, $headers, payload: $sha256)))
                ->successful();
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }

    /** Copies an object inside this bucket (no bytes pass through the app), keeping its recorded hash. */
    public function copyWithin(string $from, string $to): bool
    {
        $headers = ['x-amz-copy-source' => '/'.$this->bucket().'/'.$from, 'x-amz-metadata-directive' => 'COPY'];

        return $this->send('PUT', $to, $headers)->successful();
    }

    public function delete(string $key): bool
    {
        return rescue(fn () => $this->send('DELETE', $key)->successful(), false, report: false);
    }

    /** @param  array<string, string>  $headers */
    public function send(string $method, string $key, array $headers = []): Response
    {
        return $this->reach(fn () => Http::timeout(30)->withHeaders($headers)->send($method, $this->url($method, $key, 120, $headers)));
    }

    /**
     * A request to the bucket; a lost connection, a timeout or a reset becomes a refusal with a plain message.
     *
     * @param  callable(): Response  $request
     *
     * @throws ImageRejected
     */
    public function reach(callable $request): Response
    {
        try {
            return $request();
        } catch (ConnectionException|TransferException $e) {
            throw new ImageRejected(self::UNREACHABLE, 0, $e);
        }
    }

    private function signer(): SpacesSigner
    {
        $c = $this->settings;

        return new SpacesSigner((string) $c['key'], (string) $c['secret'], (string) $c['region'], (string) $c['bucket'], (string) $c['endpoint']);
    }
}
