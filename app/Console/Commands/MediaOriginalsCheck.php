<?php

namespace App\Console\Commands;

use App\Core\Support\Images\Bucket;
use App\Core\Support\Images\OriginalsInventory;
use Illuminate\Console\Command;

/**
 * Read-only: whether every original the display copies on this server point at (v2r-…) is in a bucket — the main one
 * or the independent copy — with its recorded SHA-256 and the bucket's MD5. With --deep=N it also fetches N of them at
 * random and checks their bytes against both (the monthly restore drill, on --bucket=copy). It changes nothing.
 */
class MediaOriginalsCheck extends Command
{
    protected $signature = 'media:originals-check
        {--bucket=main : main (MEDIA_SPACES_*) or copy (MEDIA_COPY_*)}
        {--club= : one club only (its id)}
        {--deep=0 : fetch this many originals at random and check their bytes}';

    protected $description = 'Check that every original in Spaces is in a bucket, and optionally its bytes (read-only)';

    public function handle(): int
    {
        $bucket = Bucket::named((string) $this->option('bucket'));
        if (! $bucket->configured()) {
            $this->error('The '.$this->option('bucket').' bucket is not set.');

            return self::FAILURE;
        }
        $this->line('Bucket: '.$bucket->label());

        $present = [];
        $missing = [];
        $incomplete = [];
        foreach (OriginalsInventory::remote($this->option('club') ?: null) as $key => $display) {
            $head = $bucket->head($key);
            if ($head === null) {
                $missing[] = $key;
            } elseif ($head['size'] < 1 || ! preg_match('/^[0-9a-f]{64}$/D', $head['sha256']) || $head['md5'] === '') {
                $incomplete[] = $key;
            } else {
                $present[$key] = $head;
            }
        }

        $damaged = [];
        $deep = min((int) $this->option('deep'), count($present));
        $checked = 0;
        if ($deep > 0) {
            foreach ((array) array_rand($present, $deep) as $key) {
                $file = (string) tempnam(sys_get_temp_dir(), 'drill-');
                try {
                    [$sha, $md5] = $bucket->download($key, $file) ? Bucket::fileHashes($file) : ['', ''];
                } finally {
                    @unlink($file);
                }
                $checked++;
                if ($sha !== $present[$key]['sha256'] || $md5 !== $present[$key]['md5']) {
                    $damaged[] = $key;
                }
            }
        }

        $this->table(['', 'originals'], [
            ['present', count($present)],
            ['missing', count($missing)],
            ['without their hash or MD5', count($incomplete)],
            ['bytes checked (--deep)', $checked],
            ['bytes not matching their hash', count($damaged)],
        ]);
        foreach ([['missing', $missing], ['without hash', $incomplete], ['not matching', $damaged]] as [$label, $keys]) {
            foreach (array_slice($keys, 0, 200) as $key) {
                $this->line($label.': '.$key);
            }
            if (count($keys) > 200) {
                $this->line('… and '.(count($keys) - 200).' more '.$label);
            }
        }

        return $missing || $incomplete || $damaged ? self::FAILURE : self::SUCCESS;
    }
}
