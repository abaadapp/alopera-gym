<?php

/*
 * Run by ImageServerSafetyTest in a PHP process of its own, with the memory limit it is given (128M, as PHP-FPM):
 * stores one picture for one use the way the server does when the browser sent the picked file itself (no light copy
 * of its own), and prints one JSON line: stored (with its size, and whether its thumbnail is there) or refused (with
 * the message), and the peak memory. A PHP fatal error prints {"fatal": …} instead.
 *
 *   php -d memory_limit=128M tests/Support/image-memory-child.php <base path> <picture> <use>
 */

use App\Core\Support\Images\ImageRejected;
use App\Core\Support\ImageStore;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

[, $base, $file, $use] = $argv;
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

register_shutdown_function(function () {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo json_encode(['fatal' => $error['message'], 'peak_mb' => round(memory_get_peak_usage() / 1_048_576, 1)]), PHP_EOL;
    }
});

Storage::fake('public');
Storage::fake('local');
$tenant = new Tenant;
$tenant->id = 990_001;
app(TenantContext::class)->set($tenant);

memory_reset_peak_usage();
$upload = new UploadedFile($file, 'photo.jpg', 'image/jpeg', null, true);
try {
    $path = $use === 'brand' ? ImageStore::storeLogo($upload, 'brand', 1600) : ImageStore::store($upload, $use);
    [$width, $height] = getimagesizefromstring((string) Storage::disk('public')->get($path));
    $result = ['stored' => true, 'width' => $width, 'height' => $height, 'thumb' => Storage::disk('public')->exists(preg_replace('/\.\w+$/', '-thumb.webp', $path))];
} catch (ImageRejected $e) {
    $result = ['stored' => false, 'refused' => $e->getMessage(), 'files' => count(Storage::disk('public')->allFiles())];
}
echo json_encode($result + ['peak_mb' => round(memory_get_peak_usage() / 1_048_576, 1)], JSON_UNESCAPED_UNICODE), PHP_EOL;
