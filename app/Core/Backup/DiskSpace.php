<?php

namespace App\Core\Backup;

use App\Core\Support\BusinessRuleException;

/**
 * Free space where an archive is built or a restore stages its files, checked before either starts: a full disk
 * refuses the work up front instead of failing half-way. One class, so a test can stand for a full disk.
 */
class DiskSpace
{
    /** Bytes free on the filesystem holding $path; null when the system cannot say (then nothing is refused). */
    public function free(string $path): ?int
    {
        while (! file_exists($path) && dirname($path) !== $path) {
            $path = dirname($path);
        }
        $free = @disk_free_space($path);

        return $free === false ? null : (int) $free;
    }

    /** Refuses when $needed bytes (with a fifth more and 64 MB kept for the site) do not fit on $path's filesystem. */
    public function ensure(string $path, int $needed, string $rule, string $what): void
    {
        $free = $this->free($path);
        $want = (int) ($needed * 1.2) + 64 * 1024 * 1024;
        if ($free !== null && $free < $want) {
            throw new BusinessRuleException($rule, sprintf('المساحة الحرة على القرص (%s) لا تكفي %s (تحتاج نحو %s)، ولم يتغير شيء.', self::mb($free), $what, self::mb($want)));
        }
    }

    private static function mb(int $bytes): string
    {
        return number_format($bytes / 1048576, 0).' MB';
    }
}
