<?php

namespace App\Core\Backup;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Models\Tenant;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Club-level backup and restore.
 *
 * A backup is a zip with manifest.json, data.json (every row of every table in
 * {@see TenantTables}) and the club's uploaded files. Restore replaces the
 * club's data with the archive inside one transaction, and refuses an archive
 * that belongs to another club.
 *
 * Encrypted columns (integration secrets, health notes) stay encrypted with
 * APP_KEY; restoring requires the same APP_KEY.
 *
 * Platform-wide disaster recovery uses pg_dump (see scripts/backup-database.sh).
 */
final class TenantBackupService
{
    public const FORMAT_VERSION = 1;

    public const KEEP = 14;

    /** On the private disk, inside the club's folder: what a restore found in the public part that may not be public. */
    public const ISOLATED = 'restore-isolated';

    /** What the public disk holds (ImageStore, OfficialLogo): extension => the image type its bytes must be. */
    private const PUBLIC_IMAGES = [
        'webp' => IMAGETYPE_WEBP, 'png' => IMAGETYPE_PNG, 'jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG,
        'gif' => IMAGETYPE_GIF, 'bmp' => IMAGETYPE_BMP,
    ];

    /** On the private disk, outside the club's folder (club archives never collect it): one folder per restore run. */
    public const STAGING = 'restore-staging';

    public function __construct(private readonly AuditLogger $audit, private readonly RestoreFiles $files) {}

    public function disk(): Filesystem
    {
        return Storage::disk(config('gym.backup_disk', 'local'));
    }

    public function directory(Tenant $tenant): string
    {
        return 'backups/tenants/'.$tenant->id;
    }

    /** @return string the stored file name */
    public function create(Tenant $tenant): string
    {
        $data = [];
        foreach (TenantTables::ORDER as $table) {
            $data[$table] = $this->rows($tenant, $table);
        }

        $manifest = [
            'format' => self::FORMAT_VERSION,
            'tenant_id' => $tenant->id,
            'tenant_slug' => $tenant->slug,
            'created_at' => now()->toIso8601String(),
            'app_version' => config('app.version', 'dev'),
            'counts' => array_map('count', $data),
        ];

        $name = 'backup-'.now()->format('Ymd-His').'.zip';
        $tmp = tempnam(sys_get_temp_dir(), 'gymbk');
        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Cannot create backup archive');
        }
        $zip->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        $zip->addFromString('data.json', json_encode($data, JSON_UNESCAPED_UNICODE));

        foreach (['local' => 'private', 'public' => 'public'] as $disk => $label) {
            foreach (Storage::disk($disk)->allFiles('tenants/'.$tenant->id) as $file) {
                $zip->addFromString('files/'.$label.'/'.$file, Storage::disk($disk)->get($file));
            }
        }
        $zip->close();

        $this->disk()->put($this->directory($tenant).'/'.$name, fopen($tmp, 'r'));
        @unlink($tmp);

        $this->prune($tenant);
        $this->audit->log('backup.created', $tenant, ['file' => $name, 'counts' => $manifest['counts']], tenantId: $tenant->id);

        return $name;
    }

    /** @return list<array{name: string, size: int, created_at: string}> */
    public function list(Tenant $tenant): array
    {
        return collect($this->disk()->files($this->directory($tenant)))
            ->filter(fn ($f) => str_ends_with($f, '.zip'))
            ->map(fn ($f) => [
                'name' => basename($f),
                'size' => $this->disk()->size($f),
                'created_at' => date('Y-m-d H:i', $this->disk()->lastModified($f)),
            ])
            ->sortByDesc('name')->values()->all();
    }

    public function path(Tenant $tenant, string $name): string
    {
        if (! preg_match('/^backup-\d{8}-\d{6}\.zip$/', $name)) {
            throw new BusinessRuleException('backup.name', 'اسم ملف غير صالح.');
        }

        return $this->directory($tenant).'/'.$name;
    }

    /**
     * Replace the club's data and files with the archive's: all of it, or nothing.
     *
     *  1. Checked before anything changes: the archive, its club, every row, every file entry's path, and every file's
     *     bytes against the CRC the archive keeps for it (ZipArchive does not always check it by itself).
     *  2. Staged: each file copied to restore-staging/{club}/{run}/new and checked (SHA-256), then the run's journal
     *     written. A failure here removes the staging; nothing of the club changed.
     *  3. One database transaction: the rows replaced, each file put in place (a rename on this server's disk; the
     *     file it replaces moved aside to …/previous), then a marker (audit backup.restore_committed; a restore never
     *     rewrites audit_logs) that commits with the rows. A failure anywhere here rolls the rows back and puts every
     *     file back as it was.
     *  4. After the commit, the staging (and the files it replaced) goes.
     *
     * A run stopped half-way (the process killed, or putting the files back failed too) keeps its journal: the next
     * restore, or recoverInterrupted(), finishes it, forward when its marker committed and back when it did not.
     *
     * @return array<string, int> rows restored per table
     */
    public function restore(Tenant $tenant, string $archivePath): array
    {
        // One restore of a club at a time: a second one would take the first's half-done run for an interrupted one.
        $lock = Cache::lock('club-restore:'.$tenant->id, 3600);
        if (! $lock->get()) {
            throw new BusinessRuleException('backup.restore_running', 'استعادة أخرى لهذا النادي جارية الآن. انتظر حتى تنتهي.');
        }
        try {
            $this->recoverInterrupted($tenant);

            return $this->restoreWhole($tenant, $archivePath);
        } finally {
            $lock->release();
        }
    }

    /** @return array<string, int> */
    private function restoreWhole(Tenant $tenant, string $archivePath): array
    {
        $zip = new ZipArchive;
        if ($zip->open($archivePath) !== true) {
            throw new BusinessRuleException('backup.invalid', 'ملف النسخة الاحتياطية غير صالح.');
        }

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $data = json_decode((string) $zip->getFromName('data.json'), true);

        if (! is_array($manifest) || ! is_array($data) || ($manifest['format'] ?? null) !== self::FORMAT_VERSION) {
            $zip->close();
            throw new BusinessRuleException('backup.invalid', 'ملف النسخة الاحتياطية غير صالح أو بإصدار غير مدعوم.');
        }

        if ((int) ($manifest['tenant_id'] ?? 0) !== $tenant->id) {
            $zip->close();
            $this->audit->log('backup.restore_refused', $tenant, ['reason' => 'other_tenant', 'archive_tenant' => $manifest['tenant_id'] ?? null], tenantId: $tenant->id);
            throw new BusinessRuleException('backup.other_tenant', 'هذه النسخة تخص ناديًا آخر ولا يمكن استعادتها هنا.');
        }

        // Every row must belong to this club — a tampered archive cannot inject rows elsewhere.
        foreach ($data as $table => $rows) {
            if (! in_array($table, TenantTables::ORDER, true)) {
                throw new BusinessRuleException('backup.invalid', 'جدول غير معروف في النسخة: '.$table);
            }
            if (! isset(TenantTables::PIVOTS[$table])) {
                foreach ($rows as $row) {
                    if ((int) ($row['tenant_id'] ?? 0) !== $tenant->id) {
                        throw new BusinessRuleException('backup.other_tenant', 'النسخة تحتوي على بيانات نادٍ آخر.');
                    }
                }
            }
        }

        // Every file too, before anything is replaced: a refused archive leaves the club's rows and files as they were.
        $files = $this->archiveFiles($zip, $tenant);

        $run = (string) Str::ulid();
        $dir = self::STAGING.'/'.$tenant->id.'/'.$run;
        try {
            [$items, $report] = $this->stage($zip, $tenant, $files, $dir, $run);
        } catch (Throwable $e) {
            $zip->close();
            $this->discardQuietly($dir);
            $this->audit->log('backup.restore_refused', $tenant, ['reason' => 'files', 'error' => mb_substr($e->getMessage(), 0, 200)], tenantId: $tenant->id);
            if ($e instanceof BusinessRuleException) {
                throw $e;
            }
            report($e);

            throw new BusinessRuleException('backup.restore_failed', 'تعذر تجهيز ملفات النسخة، ولم يتغير شيء: بقيت بيانات النادي وملفاته كما كانت.');
        }
        $zip->close();

        try {
            $counts = DB::transaction(function () use ($tenant, $data, $items, $dir, $run) {
                $counts = $this->replaceRows($tenant, $data);
                foreach ($items as $item) {
                    $this->place($dir, $item);
                }
                $this->audit->log('backup.restore_committed', $tenant, ['run' => $run], tenantId: $tenant->id);

                return $counts;
            });
        } catch (Throwable $e) {
            report($e);
            // The commit may have gone through although PHP saw an error (the connection lost on the way back): the
            // marker says. Then the run is finished forward, never put back under committed rows. When the database
            // cannot say, nothing is guessed: the journal waits for the next restore or recoverInterrupted().
            try {
                $committed = $this->committed($tenant, $run);
            } catch (Throwable) {
                throw $this->interrupted($tenant, $run, $e);
            }
            try {
                $committed ? $this->putForward($dir, $items) : $this->putBack($dir, $items);
                if (! $committed) {
                    $this->files->deleteDirectory('local', $dir);
                }
            } catch (Throwable $undo) {
                report($undo);

                throw $this->interrupted($tenant, $run, $e);
            }
            if (! $committed) {
                $this->audit->log('backup.restore_failed', $tenant, ['run' => $run, 'error' => mb_substr($e->getMessage(), 0, 200)], tenantId: $tenant->id);

                throw new BusinessRuleException('backup.restore_failed', 'تعذرت الاستعادة، ولم يتغير شيء: بقيت بيانات النادي وملفاته كما كانت.');
            }
            $counts = [];
            foreach (TenantTables::ORDER as $table) {
                $counts[$table] = count($data[$table] ?? []);
            }
        }

        // Committed: the rows and the files are the archive's. If the staging cannot go now, the next restore or
        // recoverInterrupted() removes it (its marker says the run is done).
        $this->discardQuietly($dir);

        $summary = ['written' => $report['written']] + array_map(fn ($list) => array_slice($list, 0, 50), array_filter([
            'kept_private' => $report['kept_private'], 'hidden_skipped' => $report['hidden_skipped'],
        ]));
        $this->audit->log('backup.restored', $tenant, ['counts' => $counts, 'from' => $data ? 'archive' : null, 'files' => $summary, 'run' => $run], tenantId: $tenant->id);

        return $counts;
    }

    /** Whether the run's rows committed: its marker is in audit_logs, which a restore never rewrites. */
    private function committed(Tenant $tenant, string $run): bool
    {
        return DB::table('audit_logs')->where('tenant_id', $tenant->id)->where('event', 'backup.restore_committed')
            ->where('properties->run', $run)->exists();
    }

    /** The run stays with its journal for the next restore or recoverInterrupted(); the person is told, never "restored". */
    private function interrupted(Tenant $tenant, string $run, Throwable $e): BusinessRuleException
    {
        try {
            $this->audit->log('backup.restore_interrupted', $tenant, ['run' => $run, 'error' => mb_substr($e->getMessage(), 0, 200)], tenantId: $tenant->id);
        } catch (Throwable) {
            Log::error('restore interrupted; its journal waits in '.self::STAGING, ['tenant' => $tenant->id, 'run' => $run]);
        }

        return new BusinessRuleException('backup.restore_interrupted', 'لم تكتمل الاستعادة ولم تظهر نتيجتها بعد. أصلح سبب الخلل ثم أعد المحاولة: تُكمل أولًا ما بدأ (إلى الأمام إن اعتُمدت البيانات، وإلى الخلف إن لم تُعتمد) قبل أي استعادة جديدة.');
    }

    /**
     * Finishes every restore of this club that stopped half-way: forward (the files still staged put in place) when its
     * rows committed, back (each file as it was before) when they did not; then its staging goes.
     */
    public function recoverInterrupted(Tenant $tenant): void
    {
        foreach (Storage::disk('local')->directories(self::STAGING.'/'.$tenant->id) as $dir) {
            $journal = json_decode((string) Storage::disk('local')->get($dir.'/journal.json'), true);
            if (! is_array($journal) || ! isset($journal['run'], $journal['items'])) {
                // Stopped while staging: nothing of the club was replaced yet.
                $this->files->deleteDirectory('local', $dir);

                continue;
            }
            $committed = $this->committed($tenant, $journal['run']);
            $committed ? $this->putForward($dir, $journal['items']) : $this->putBack($dir, $journal['items']);
            $this->files->deleteDirectory('local', $dir);
            $this->audit->log('backup.restore_recovered', $tenant, ['run' => $journal['run'], 'direction' => $committed ? 'forward' : 'back'], tenantId: $tenant->id);
        }
    }

    /**
     * Copies every file of the archive to the staging, each checked against the archive's CRC on the way and against
     * its SHA-256 once written, one at a time (never the whole archive in memory), then writes the run's journal.
     *
     * @param  list<array{0: int, 1: string, 2: string}>  $files
     * @return array{0: list<array{n: int, disk: string, path: string, sha: string}>, 1: array{written: int, kept_private: list<string>, hidden_skipped: list<string>}}
     */
    private function stage(ZipArchive $zip, Tenant $tenant, array $files, string $dir, string $run): array
    {
        // The public disk is served as it is under /storage/ on the website's and the panel's domains: only an image
        // goes back there. Anything else from the public part is kept on the private disk, which is never served.
        $folder = 'tenants/'.$tenant->id.'/';
        $report = ['written' => 0, 'kept_private' => [], 'hidden_skipped' => []];
        $items = [];
        foreach ($files as [$index, $part, $path]) {
            if (preg_match('#(^|/)\.#', $path)) {
                $report['hidden_skipped'][] = $path;

                continue;
            }
            [$tmp, $sha] = $this->readChecked($zip, $index, $path);
            try {
                if ($part === 'public' && ! self::isPublicImage($path, (string) file_get_contents($tmp, length: 64 * 1024 * 1024))) {
                    [$disk, $final] = ['local', $folder.self::ISOLATED.'/'.$path];
                    $report['kept_private'][] = $path;
                } else {
                    [$disk, $final] = [$part === 'private' ? 'local' : 'public', $folder.$path];
                    $report['written']++;
                }
                $n = count($items);
                $stream = fopen($tmp, 'rb');
                try {
                    $this->files->putStream('local', $dir.'/new/'.$n, $stream, $sha);
                } finally {
                    fclose($stream);
                }
                $items[] = ['n' => $n, 'disk' => $disk, 'path' => $final, 'sha' => $sha];
            } finally {
                @unlink($tmp);
            }
        }
        $this->files->putString('local', $dir.'/journal.json', (string) json_encode(['run' => $run, 'tenant' => $tenant->id, 'items' => $items], JSON_UNESCAPED_UNICODE));

        return [$items, $report];
    }

    /**
     * One entry to a temporary file, read in pieces, its size and CRC compared with what the archive says.
     *
     * @return array{0: string, 1: string} the temporary file and its SHA-256
     */
    private function readChecked(ZipArchive $zip, int $index, string $path): array
    {
        $stat = $zip->statIndex($index);
        $in = $zip->getStreamIndex($index);
        $tmp = (string) tempnam(sys_get_temp_dir(), 'gymrs');
        $out = fopen($tmp, 'wb');
        $crc = hash_init('crc32b');
        $sha = hash_init('sha256');
        $size = 0;
        $read = is_resource($in) && $stat !== false;
        try {
            while ($read && ! feof($in)) {
                $chunk = fread($in, 1 << 20);
                if ($chunk === false) {
                    $read = false;
                    break;
                }
                hash_update($crc, $chunk);
                hash_update($sha, $chunk);
                $read = fwrite($out, $chunk) === strlen($chunk);
                $size += strlen($chunk);
            }
        } catch (Throwable) {
            $read = false; // a CRC error raised by libzip itself while reading
        } finally {
            is_resource($in) && fclose($in);
            fclose($out);
        }
        if (! $read || $size !== (int) $stat['size'] || hexdec(hash_final($crc)) !== ((int) $stat['crc'] & 0xFFFFFFFF)) {
            @unlink($tmp);

            throw new BusinessRuleException('backup.invalid', 'النسخة تحتوي على ملف تالف، ولم يُستبدل شيء: '.mb_scrub(mb_substr($path, 0, 120)));
        }

        return [$tmp, hash_final($sha)];
    }

    /** @param array<string, list<array<string, mixed>>> $data */
    private function replaceRows(Tenant $tenant, array $data): array
    {
        foreach (array_reverse(TenantTables::ORDER) as $table) {
            $this->deleteTenantRows($tenant, $table);
        }

        $counts = [];
        foreach (TenantTables::ORDER as $table) {
            $rows = $data[$table] ?? [];
            if (isset(TenantTables::SELF_REFERENCING[$table])) {
                usort($rows, fn ($a, $b) => $a['id'] <=> $b['id']);
            }
            foreach (array_chunk($rows, 500) as $chunk) {
                DB::table($table)->insert($chunk);
            }
            $counts[$table] = count($rows);
            if (! isset(TenantTables::PIVOTS[$table])) {
                $this->resetSequence($table);
            }
        }

        return $counts;
    }

    /** @param array{n: int, disk: string, path: string, sha: string} $item */
    private function place(string $dir, array $item): void
    {
        if ($this->files->exists($item['disk'], $item['path'])) {
            $this->files->move($item['disk'], $item['path'], 'local', $dir.'/previous/'.$item['n']);
        }
        $this->files->move('local', $dir.'/new/'.$item['n'], $item['disk'], $item['path']);
        if ($this->files->sha256($item['disk'], $item['path']) !== $item['sha']) {
            throw new RuntimeException("restore: {$item['disk']}:{$item['path']} is not the archive's file once in place");
        }
    }

    /**
     * Each file as it was before the run, last placed first: the one moved aside comes back; one the run put where
     * nothing was goes; one still staged was never placed.
     *
     * @param  list<array{n: int, disk: string, path: string, sha: string}>  $items
     */
    private function putBack(string $dir, array $items): void
    {
        foreach (array_reverse($items) as $item) {
            if ($this->files->exists('local', $dir.'/previous/'.$item['n'])) {
                $this->files->move('local', $dir.'/previous/'.$item['n'], $item['disk'], $item['path']);
            } elseif (! $this->files->exists('local', $dir.'/new/'.$item['n']) && $this->files->sha256($item['disk'], $item['path']) === $item['sha']) {
                $this->files->delete($item['disk'], $item['path']);
            }
        }
    }

    /** @param  list<array{n: int, disk: string, path: string, sha: string}>  $items */
    private function putForward(string $dir, array $items): void
    {
        foreach ($items as $item) {
            if ($this->files->exists('local', $dir.'/new/'.$item['n'])) {
                $this->place($dir, $item);
            }
        }
    }

    private function discardQuietly(string $dir): void
    {
        try {
            $this->files->deleteDirectory('local', $dir);
        } catch (Throwable $e) {
            Log::warning('restore staging not removed; the next restore removes it', ['dir' => $dir, 'error' => $e->getMessage()]);
        }
    }

    /**
     * The archive's club files, read before anything is replaced. create() writes each one as
     * files/{private|public}/tenants/{id}/…; an entry of another club or outside the club's folder, a path that
     * climbs, has an empty part, a backslash or a control character, or a symbolic link refuses the whole archive.
     *
     * @return list<array{0: int, 1: string, 2: string}> entry index, private|public, path inside the club's folder
     */
    private function archiveFiles(ZipArchive $zip, Tenant $tenant): array
    {
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (! str_starts_with($entry, 'files/') || str_ends_with($entry, '/')) {
                continue;
            }
            $opsys = $attributes = 0;
            $zip->getExternalAttributesIndex($i, $opsys, $attributes);
            $link = $opsys === ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000;
            if ($link || ! preg_match('#^files/(private|public)/tenants/'.$tenant->id.'/(.+)$#D', $entry, $m)
                || preg_match('#[\x00-\x1f\x7f\\\\]#', $m[2]) || array_intersect(explode('/', $m[2]), ['', '.', '..']) !== []) {
                $zip->close();
                $this->audit->log('backup.restore_refused', $tenant, ['reason' => 'unsafe_file', 'entry' => mb_scrub(mb_substr($entry, 0, 200))], tenantId: $tenant->id);
                throw new BusinessRuleException('backup.invalid', 'النسخة تحتوي على ملف خارج مجلد النادي أو بمسار غير صالح، ولم يُستبدل شيء: '.mb_scrub(mb_substr($entry, 0, 120)));
            }
            $files[] = [$i, $m[1], $m[2]];
        }

        return $files;
    }

    /** One extension (no "photo.php.png"), an image one, and bytes that really are that image. */
    private static function isPublicImage(string $path, string $bytes): bool
    {
        if (! preg_match('/^[^.]+\.([a-z]+)$/i', basename($path), $m) || ! isset(self::PUBLIC_IMAGES[strtolower($m[1])])) {
            return false;
        }
        $info = @getimagesizefromstring($bytes);

        return $info !== false && $info[2] === self::PUBLIC_IMAGES[strtolower($m[1])];
    }

    /** @return list<array<string, mixed>> */
    private function rows(Tenant $tenant, string $table): array
    {
        if (isset(TenantTables::PIVOTS[$table])) {
            [$fk, $parent] = TenantTables::PIVOTS[$table];

            return DB::table($table)
                ->whereIn($fk, DB::table($parent)->where('tenant_id', $tenant->id)->select('id'))
                ->get()->map(fn ($r) => (array) $r)->all();
        }

        return DB::table($table)->where('tenant_id', $tenant->id)->orderBy('id')->get()->map(fn ($r) => (array) $r)->all();
    }

    private function deleteTenantRows(Tenant $tenant, string $table): void
    {
        if (isset(TenantTables::PIVOTS[$table])) {
            [$fk, $parent] = TenantTables::PIVOTS[$table];
            DB::table($table)->whereIn($fk, DB::table($parent)->where('tenant_id', $tenant->id)->select('id'))->delete();

            return;
        }

        if (isset(TenantTables::SELF_REFERENCING[$table])) {
            DB::table($table)->where('tenant_id', $tenant->id)->update([TenantTables::SELF_REFERENCING[$table] => null]);
        }

        DB::table($table)->where('tenant_id', $tenant->id)->delete();
    }

    private function resetSequence(string $table): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), GREATEST((SELECT COALESCE(MAX(id), 0) FROM {$table}), 1))");
    }

    private function prune(Tenant $tenant): void
    {
        $files = collect($this->disk()->files($this->directory($tenant)))->filter(fn ($f) => str_ends_with($f, '.zip'))->sort()->values();
        $files->slice(0, max(0, $files->count() - self::KEEP))->each(fn ($f) => $this->disk()->delete($f));
    }
}
