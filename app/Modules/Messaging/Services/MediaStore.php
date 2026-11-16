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

    /** The disk the chat files are on (services.whatsapp.media_disk): the club backup reads and restores them there. */
    public function diskName(): string
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

    /**
     * The file of an attachment, only from its own club's folder: a path that names another club's chat files, an
     * archive, or anything else on the disk (a row restored from a tampered archive) is treated as no file at all.
     */
    public function absolutePath(Attachment $attachment): ?string
    {
        if (! $attachment->path || ! self::insideClub((string) $attachment->path, (int) $attachment->tenant_id)) {
            return null;
        }

        return $this->disk()->exists($attachment->path) ? $this->disk()->path($attachment->path) : null;
    }

    /** Whether $path is a file inside the club's chat folder (whatsapp/{tenantId}/…), with no part that climbs or hides. */
    public static function insideClub(string $path, int $tenantId): bool
    {
        return $tenantId > 0
            && preg_match('#^whatsapp/'.$tenantId.'/(.+)$#D', $path, $m) === 1
            && preg_match('#[\x00-\x1f\x7f\\\\]#', $m[1]) === 0
            && array_intersect(explode('/', $m[1]), ['', '.', '..']) === [];
    }

    private function folder(int $tenantId): string
    {
        return 'whatsapp/'.$tenantId.'/'.now()->format('Y/m');
    }
}
