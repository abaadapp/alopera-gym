<?php

namespace App\Core\Backup;

use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * The file steps of a restore (TenantBackupService::restore), each checked and each throwing when it did not happen:
 * the disks are set not to throw (config/filesystems.php), so nothing here trusts a write's or a move's own answer.
 * One class, so a test can make any step fail as a full, read-only or failing disk would.
 */
class RestoreFiles
{
    /** Copies a stream to $disk/$path and checks the copy against $sha256. */
    public function putStream(string $disk, string $path, $stream, string $sha256): void
    {
        $written = Storage::disk($disk)->writeStream($path, $stream);
        if (! $written || $this->sha256($disk, $path) !== $sha256) {
            Storage::disk($disk)->delete($path);

            throw new RuntimeException("restore: could not write {$disk}:{$path}");
        }
    }

    public function putString(string $disk, string $path, string $contents): void
    {
        if (! Storage::disk($disk)->put($path, $contents) || Storage::disk($disk)->get($path) !== $contents) {
            throw new RuntimeException("restore: could not write {$disk}:{$path}");
        }
    }

    /**
     * Moves a file between two local disks of this server (the private and the public one share a filesystem, so it is
     * a rename: no copy, no extra space), replacing what is at the destination. Checked by existence after.
     */
    public function move(string $fromDisk, string $from, string $toDisk, string $to): void
    {
        $source = Storage::disk($fromDisk)->path($from);
        $target = Storage::disk($toDisk)->path($to);
        if (! is_dir(dirname($target)) && ! @mkdir(dirname($target), 0755, true) && ! is_dir(dirname($target))) {
            throw new RuntimeException("restore: could not make the folder of {$toDisk}:{$to}");
        }
        if (! @rename($source, $target) || ! is_file($target) || is_file($source)) {
            throw new RuntimeException("restore: could not move {$fromDisk}:{$from} to {$toDisk}:{$to}");
        }
    }

    public function delete(string $disk, string $path): void
    {
        Storage::disk($disk)->delete($path);
        if (Storage::disk($disk)->exists($path)) {
            throw new RuntimeException("restore: could not remove {$disk}:{$path}");
        }
    }

    public function deleteDirectory(string $disk, string $path): void
    {
        Storage::disk($disk)->deleteDirectory($path);
        if (Storage::disk($disk)->exists($path)) {
            throw new RuntimeException("restore: could not remove {$disk}:{$path}");
        }
    }

    public function exists(string $disk, string $path): bool
    {
        return Storage::disk($disk)->exists($path);
    }

    public function sha256(string $disk, string $path): ?string
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
}
