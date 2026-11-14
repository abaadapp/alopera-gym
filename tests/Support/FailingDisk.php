<?php

namespace Tests\Support;

use Illuminate\Filesystem\FilesystemAdapter as LaravelDisk;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\Config;
use League\Flysystem\FileAttributes;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemAdapter;
use League\Flysystem\Local\LocalFilesystemAdapter;
use League\Flysystem\UnableToWriteFile;

/**
 * A faked disk that fails the way a real one does, set up as production sets its disks (throw => false, so Laravel
 * answers a failed write with false, not an exception):
 *  - 'refuse': every write fails (a full disk, a read-only folder, storage that cannot be reached);
 *  - 'short':  every write keeps only the first half of the bytes and reports success (a disk that filled up mid-way).
 * Reads, listings and deletes work, so what is already there stays readable.
 */
final class FailingDisk implements FilesystemAdapter
{
    public int $writes = 0;

    private function __construct(private readonly FilesystemAdapter $inner, private string $mode) {}

    /** Call after Storage::fake($disk): the same folder, now failing. Returns the adapter, to switch it back with ->mode('ok'). */
    public static function install(string $disk, string $mode = 'refuse'): self
    {
        $root = Storage::disk($disk)->path('');
        $adapter = new self(new LocalFilesystemAdapter($root), $mode);
        Storage::set($disk, new LaravelDisk(new Filesystem($adapter), $adapter, ['root' => $root, 'throw' => false, 'report' => false]));

        return $adapter;
    }

    public function mode(string $mode): self
    {
        $this->mode = $mode;

        return $this;
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $this->writes++;
        match ($this->mode) {
            'refuse' => throw UnableToWriteFile::atLocation($path, 'No space left on device'),
            'short' => $this->inner->write($path, substr($contents, 0, intdiv(strlen($contents), 2)), $config),
            default => $this->inner->write($path, $contents, $config),
        };
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $this->write($path, (string) stream_get_contents($contents), $config);
    }

    public function fileExists(string $path): bool
    {
        return $this->inner->fileExists($path);
    }

    public function directoryExists(string $path): bool
    {
        return $this->inner->directoryExists($path);
    }

    public function read(string $path): string
    {
        return $this->inner->read($path);
    }

    public function readStream(string $path)
    {
        return $this->inner->readStream($path);
    }

    public function delete(string $path): void
    {
        $this->inner->delete($path);
    }

    public function deleteDirectory(string $path): void
    {
        $this->inner->deleteDirectory($path);
    }

    public function createDirectory(string $path, Config $config): void
    {
        $this->inner->createDirectory($path, $config);
    }

    public function setVisibility(string $path, string $visibility): void
    {
        $this->inner->setVisibility($path, $visibility);
    }

    public function visibility(string $path): FileAttributes
    {
        return $this->inner->visibility($path);
    }

    public function mimeType(string $path): FileAttributes
    {
        return $this->inner->mimeType($path);
    }

    public function lastModified(string $path): FileAttributes
    {
        return $this->inner->lastModified($path);
    }

    public function fileSize(string $path): FileAttributes
    {
        return $this->inner->fileSize($path);
    }

    public function listContents(string $path, bool $deep): iterable
    {
        return $this->inner->listContents($path, $deep);
    }

    public function move(string $source, string $destination, Config $config): void
    {
        $this->inner->move($source, $destination, $config);
    }

    public function copy(string $source, string $destination, Config $config): void
    {
        $this->inner->copy($source, $destination, $config);
    }
}
