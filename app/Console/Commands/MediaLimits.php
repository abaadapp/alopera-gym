<?php

namespace App\Console\Commands;

use App\Core\Support\Images\SpacesOriginals;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;

/**
 * Read-only: whether the server takes the image size the app is set to (GYM_MAX_IMAGE_KB, MEDIA_MAX_REQUEST_KB).
 * It checks this PHP's limits and the nginx file in deploy/nginx; it changes nothing. PHP-FPM may read another
 * php.ini than the command line: check it too (php-fpm8.4 -i | grep -E "upload_max|post_max").
 */
class MediaLimits extends Command
{
    protected $signature = 'media:limits';

    protected $description = 'Check that PHP and nginx accept the configured image upload sizes (read-only)';

    public function handle(): int
    {
        $image = (int) config('gym.max_image_kb') * 1024;
        $request = (int) config('media.max_request_kb') * 1024;
        $upload = UploadedFile::getMaxFilesize();
        $post = $this->bytes((string) ini_get('post_max_size'));
        $nginx = $this->nginx();
        $direct = SpacesOriginals::enabled();
        $rows = [
            ['image limit (GYM_MAX_IMAGE_KB)', $this->mb($image), ''],
            ['request limit (MEDIA_MAX_REQUEST_KB)', $this->mb($request), $request >= $image ? 'OK' : 'WARN: below one image'],
            // With originals in the bucket, they go there from the browser; only display sources (≤ 4 MB) reach PHP.
            ['PHP upload_max_filesize', $this->mb($upload), $direct || $upload >= $image ? 'OK' : 'FAIL: below the image limit'],
            ['PHP post_max_size', $post < 0 ? 'none' : $this->mb($post), $direct || $post < 0 || $post >= $request ? 'OK' : 'FAIL: below the request limit'],
            ['nginx client_max_body_size (deploy/nginx)', $nginx === null ? 'not found' : $this->mb($nginx), $direct || ($nginx !== null && $nginx >= $request) ? 'OK' : 'FAIL: below the request limit'],
            ['originals', $direct ? 'Spaces (direct from the browser)' : 'this server', ''],
        ];
        $this->table(['check', 'value', 'result'], $rows);
        $this->line('PHP-FPM may use another php.ini: php-fpm8.4 -i | grep -E "upload_max|post_max"');

        return collect($rows)->contains(fn ($r) => str_starts_with($r[2], 'FAIL')) ? self::FAILURE : self::SUCCESS;
    }

    private function nginx(): ?int
    {
        foreach (glob(base_path('deploy/nginx/{,snippets/}*.conf'), GLOB_BRACE) ?: [] as $file) {
            if (preg_match('/client_max_body_size\s+(\d+[kKmMgG]?)\s*;/', (string) file_get_contents($file), $m)) {
                return $this->bytes($m[1]);
            }
        }

        return null;
    }

    private function bytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1' || $value === '0') {
            return -1;
        }
        $n = (int) $value;

        return match (strtolower(substr($value, -1))) {
            'g' => $n * 1_073_741_824,
            'm' => $n * 1_048_576,
            'k' => $n * 1024,
            default => $n,
        };
    }

    private function mb(int $bytes): string
    {
        return rtrim(rtrim(number_format($bytes / 1_048_576, 1), '0'), '.').' MB';
    }
}
