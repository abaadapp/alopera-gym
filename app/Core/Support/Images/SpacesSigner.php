<?php

namespace App\Core\Support\Images;

/**
 * Short-lived signed addresses for one object in a DigitalOcean Spaces bucket (S3's Signature Version 4, query
 * string form), so a browser can put a file there and the server can read or remove it, without an SDK and without
 * the secret ever leaving the server. Checked against AWS's published example in DirectUploadTest.
 */
final class SpacesSigner
{
    public function __construct(
        private readonly string $key,
        private readonly string $secret,
        private readonly string $region,
        private readonly string $bucket,
        private readonly string $endpoint,
        private readonly string $service = 's3',
    ) {}

    /**
     * @param  array<string, string>  $headers  headers the request must carry exactly (e.g. content-type)
     * @param  array<string, string>  $query  more query parameters, signed too (e.g. response-content-disposition)
     * @param  string  $payload  the body's SHA-256 in hex when the store must check it (x-amz-content-sha256)
     */
    public function presign(string $method, string $objectKey, int $ttl, array $headers = [], ?int $now = null, array $query = [], string $payload = 'UNSIGNED-PAYLOAD'): string
    {
        $now ??= time();
        $date = gmdate('Ymd', $now);
        $time = gmdate('Ymd\THis\Z', $now);
        $host = $this->bucket.'.'.(parse_url($this->endpoint, PHP_URL_HOST) ?: $this->endpoint);
        $scheme = parse_url($this->endpoint, PHP_URL_SCHEME) ?: 'https';
        $path = '/'.implode('/', array_map(rawurlencode(...), explode('/', ltrim($objectKey, '/'))));
        $scope = $date.'/'.$this->region.'/'.$this->service.'/aws4_request';

        $signed = ['host' => $host];
        foreach ($headers as $name => $value) {
            $signed[strtolower($name)] = trim($value);
        }
        ksort($signed);
        $signedNames = implode(';', array_keys($signed));

        $query = [
            'X-Amz-Algorithm' => 'AWS4-HMAC-SHA256',
            'X-Amz-Credential' => $this->key.'/'.$scope,
            'X-Amz-Date' => $time,
            'X-Amz-Expires' => (string) $ttl,
            'X-Amz-SignedHeaders' => $signedNames,
        ] + $query;
        ksort($query);
        $canonicalQuery = implode('&', array_map(fn ($k, $v) => rawurlencode($k).'='.rawurlencode($v), array_keys($query), $query));
        $canonicalHeaders = implode('', array_map(fn ($k, $v) => $k.':'.$v."\n", array_keys($signed), $signed));

        $canonical = implode("\n", [strtoupper($method), $path, $canonicalQuery, $canonicalHeaders, $signedNames, $payload]);
        $toSign = implode("\n", ['AWS4-HMAC-SHA256', $time, $scope, hash('sha256', $canonical)]);

        $k = hash_hmac('sha256', $date, 'AWS4'.$this->secret, true);
        $k = hash_hmac('sha256', $this->region, $k, true);
        $k = hash_hmac('sha256', $this->service, $k, true);
        $k = hash_hmac('sha256', 'aws4_request', $k, true);
        $signature = hash_hmac('sha256', $toSign, $k);

        return $scheme.'://'.$host.$path.'?'.$canonicalQuery.'&X-Amz-Signature='.$signature;
    }
}
