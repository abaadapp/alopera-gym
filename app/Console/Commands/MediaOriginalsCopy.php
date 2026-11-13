<?php

namespace App\Console\Commands;

use App\Core\Support\Images\Bucket;
use App\Core\Support\Images\ImageRejected;
use App\Core\Support\Images\OriginalsInventory;
use Illuminate\Console\Command;

/**
 * Copies originals from one bucket to the other, one way, and never deletes anything:
 *   --from=main --to=copy   keeps the independent copy up to date (by hand, or scheduled once approved);
 *   --from=copy --to=main   puts back what the main bucket lost (the restore).
 * Every original is fetched as a stream, its bytes checked against its recorded SHA-256 and the source's MD5 before it is
 * put, put with both hashes signed (the target bucket checks them), and checked again there. One already in the target
 * with the same size and MD5 is left alone; one that differs is reported and replaced only with --repair. No record is
 * changed: the keys are the same in both buckets.
 */
class MediaOriginalsCopy extends Command
{
    protected $signature = 'media:originals-copy
        {--from=main : main or copy}
        {--to=copy : main or copy}
        {--club= : one club only (its id)}
        {--repair : replace an original that differs in the target (the source checked first)}
        {--dry-run : say what would be copied, copy nothing}';

    protected $description = 'Copy originals between the main bucket and the independent copy, checked, one way, never deleting';

    public function handle(): int
    {
        [$fromName, $toName] = [(string) $this->option('from'), (string) $this->option('to')];
        if ($fromName === $toName) {
            $this->error('--from and --to must differ.');

            return self::FAILURE;
        }
        [$from, $to] = [Bucket::named($fromName), Bucket::named($toName)];
        foreach ([$fromName => $from, $toName => $to] as $name => $bucket) {
            if (! $bucket->configured()) {
                $this->error('The '.$name.' bucket is not set.');

                return self::FAILURE;
            }
        }
        $this->line($from->label().' → '.$to->label().($this->option('dry-run') ? ' (dry run)' : ''));

        $count = ['copied' => 0, 'already there' => 0, 'would copy' => 0, 'differs (not replaced)' => 0, 'damaged in the source' => 0, 'missing in both' => 0, 'failed' => 0];
        $problems = [];
        foreach (OriginalsInventory::remote($this->option('club') ?: null) as $key => $display) {
            try {
                $source = $from->head($key);
                $target = $to->head($key);
                if ($source === null) {
                    $outcome = $target === null ? 'missing in both' : 'already there';
                } elseif ($target !== null && $target['size'] === $source['size'] && $target['md5'] !== '' && $target['md5'] === $source['md5']) {
                    $outcome = 'already there';
                } elseif ($target !== null && ! $this->option('repair')) {
                    $outcome = 'differs (not replaced)';
                } elseif ($this->option('dry-run')) {
                    $outcome = 'would copy';
                } else {
                    $outcome = $this->copy($from, $to, $key, $source);
                }
            } catch (ImageRejected $e) {
                $outcome = 'failed';
            }
            $count[$outcome]++;
            if (! in_array($outcome, ['copied', 'already there', 'would copy'], true)) {
                $problems[] = $outcome.': '.$key;
            }
        }

        $this->table(['', 'originals'], collect($count)->map(fn ($n, $label) => [$label, $n])->values()->all());
        foreach (array_slice($problems, 0, 200) as $line) {
            $this->line($line);
        }
        if (count($problems) > 200) {
            $this->line('… and '.(count($problems) - 200).' more');
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }

    /** @param  array{size: int, sha256: string, md5: string, checksum: ?string}  $source */
    private function copy(Bucket $from, Bucket $to, string $key, array $source): string
    {
        $file = (string) tempnam(sys_get_temp_dir(), 'original-');
        try {
            if (! $from->download($key, $file)) {
                return 'failed';
            }
            [$sha, $md5] = Bucket::fileHashes($file);
            // The bytes must be what was recorded when the original was kept: its SHA-256, and the source's own MD5.
            if (filesize($file) !== $source['size'] || $sha !== $source['sha256'] || ($source['md5'] !== '' && $md5 !== $source['md5'])) {
                return 'damaged in the source';
            }
            if (! $to->put($key, $file, $sha, $md5, OriginalsInventory::mime($key))) {
                return 'failed';
            }

            return Bucket::matches($to->head($key), $source['size'], $sha, $md5) ? 'copied' : 'failed';
        } finally {
            @unlink($file);
        }
    }
}
