<?php

namespace App\Core\Support\Files;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Files a record points at (documents, payment proofs, attachments, chat files), kept as they came — never compressed
 * or changed. The disks are set not to throw (config/filesystems.php), so a refused or short write only returns false;
 * here every write is read back and compared (size and SHA-256) before its path is given out, and a write that did
 * not land whole throws FileNotStored and leaves nothing behind.
 *
 * Written inside a database transaction, the file goes again if that transaction rolls back, so no file stays
 * without its record. A file the record no longer points at is removed with deleteAfterCommit(): only once the new
 * one is saved for good, never before.
 */
final class SafeFiles
{
    /** Copies an upload to $folder under $name (the upload's random hash name by default) without reading it into memory. */
    public static function putUpload(UploadedFile $file, string $folder, string $field = 'file', string $disk = 'local', ?string $name = null): string
    {
        $path = trim($folder, '/').'/'.($name ?? $file->hashName());
        $source = $file->getRealPath();
        $expected = is_string($source) && is_file($source) ? hash_file('sha256', $source) : false;
        $stream = $expected !== false ? fopen($source, 'rb') : false;
        $written = $stream !== false && Storage::disk($disk)->writeStream($path, $stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        return self::verified($disk, $path, $written, $expected, $field);
    }

    public static function putBytes(string $bytes, string $path, string $field = 'file', string $disk = 'local'): string
    {
        return self::verified($disk, $path, Storage::disk($disk)->put($path, $bytes), hash('sha256', $bytes), $field);
    }

    /** Removes a file no record points at any more: after the surrounding transaction commits, at once outside one. */
    public static function deleteAfterCommit(?string $path, string $disk = 'local'): void
    {
        if ($path) {
            DB::afterCommit(fn () => Storage::disk($disk)->delete($path));
        }
    }

    public static function sha256(string $disk, string $path): ?string
    {
        $stream = Storage::disk($disk)->exists($path) ? Storage::disk($disk)->readStream($path) : null;
        if (! is_resource($stream)) {
            return null;
        }
        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }

    private static function verified(string $disk, string $path, bool $written, string|false $expected, string $field): string
    {
        if (! $written || $expected === false || self::sha256($disk, $path) !== $expected) {
            Storage::disk($disk)->delete($path);
            Log::error('file not stored: the disk refused the write or kept only part of it', ['disk' => $disk, 'path' => $path, 'written' => $written]);

            throw new FileNotStored($field);
        }
        DB::afterRollBack(fn () => Storage::disk($disk)->delete($path));

        return $path;
    }
}
