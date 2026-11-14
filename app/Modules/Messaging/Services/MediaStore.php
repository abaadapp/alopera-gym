<?php

namespace App\Modules\Messaging\Services;

use App\Core\Support\Files\SafeFiles;
use App\Modules\Messaging\Models\Attachment;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Chat files on the private disk, in the club's own folder (whatsapp/<club>/<year>/<month>/<random name>): never a
 * public address. Names are random; the original file name is kept only in the attachment row. A path is given only for
 * a file that landed whole (SafeFiles): a refused or cut write throws FileNotStored instead.
 */
final class MediaStore
{
    private const EXTENSIONS = [
        'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'video/mp4' => 'mp4', 'video/3gpp' => '3gp',
        'audio/aac' => 'aac', 'audio/mp4' => 'm4a', 'audio/mpeg' => 'mp3', 'audio/amr' => 'amr', 'audio/ogg' => 'ogg',
        'application/pdf' => 'pdf', 'text/plain' => 'txt',
    ];

    public function disk(): Filesystem
    {
        return Storage::disk($this->diskName());
    }

    private function diskName(): string
    {
        return (string) config('services.whatsapp.media_disk', 'local');
    }

    public function storeUpload(UploadedFile $file, int $tenantId): string
    {
        $extension = strtolower($file->getClientOriginalExtension() ?: (self::EXTENSIONS[(string) $file->getMimeType()] ?? 'bin'));

        return SafeFiles::putUpload($file, $this->folder($tenantId), 'file', $this->diskName(), Str::uuid().'.'.preg_replace('/[^a-z0-9]/', '', $extension));
    }

    public function storeBytes(string $bytes, ?string $mime, int $tenantId): string
    {
        $mime = strtolower(trim(explode(';', (string) $mime)[0]));

        return SafeFiles::putBytes($bytes, $this->folder($tenantId).'/'.Str::uuid().'.'.(self::EXTENSIONS[$mime] ?? 'bin'), 'file', $this->diskName());
    }

    public function absolutePath(Attachment $attachment): ?string
    {
        return $attachment->path && $this->disk()->exists($attachment->path) ? $this->disk()->path($attachment->path) : null;
    }

    private function folder(int $tenantId): string
    {
        return 'whatsapp/'.$tenantId.'/'.now()->format('Y/m');
    }
}
