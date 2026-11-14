<?php

namespace App\Core\Backup;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Models\Tenant;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Club-level backup and restore.
 *
 * A backup is a zip with manifest.json, data.json (every row of every table in {@see TenantTables}) and the club's
 * uploaded files (files/{private|public}/tenants/{id}/…). Format 1 since the start; newer archives add to the manifest
 * what lets them prove themselves whole (each file's size and SHA-256, data.json's SHA-256), which older code ignores
 * and older archives go without.
 *
 * Creating one (create): the rows read in one consistent snapshot, written to data.json piece by piece; each file
 * added from its disk, never held in memory; the archive built under a name no listing shows (.building-…), then
 * opened again and every entry read back against its CRC and its recorded SHA-256, the rows counted against the
 * manifest; only then named backup-….zip, recorded (backup.created) and the fourteen kept. Any failure removes what was
 * being built, records backup.failed and removes no archive.
 *
 * Restoring one: all or nothing, with a journal that survives the process (restoreWhole). Encrypted columns stay
 * encrypted with APP_KEY; restoring requires the same APP_KEY. Platform-wide recovery uses pg_dump.
 *
 * One backup or restore of a club at a time: a PostgreSQL session lock, released by the database itself if the
 * process dies (a cache lock would hold until it expired).
 */
final class TenantBackupService
{
    public const FORMAT_VERSION = 1;

    public const KEEP = 14;

    /** On the private disk, inside the club's folder: what a restore found in the public part that may not be public. */
    public const ISOLATED = 'restore-isolated';

    /** On the private disk, outside the club's folder (club archives never collect it): one folder per restore run. */
    public const STAGING = 'restore-staging';

    /** An archive being built, beside the kept ones: never named .zip, so neither the list nor the fourteen see it. */
    public const BUILDING = '.building-';

    /** Where these locks sit among PostgreSQL's advisory locks (AccountFunds::LOCKS is 74201): (LOCKS, club id). */
    public const LOCKS = 74202;

    /** What the public disk holds (ImageStore, OfficialLogo): extension => the image type its bytes must be. */
    private const PUBLIC_IMAGES = [
        'webp' => IMAGETYPE_WEBP, 'png' => IMAGETYPE_PNG, 'jpg' => IMAGETYPE_JPEG, 'jpeg' => IMAGETYPE_JPEG,
        'gif' => IMAGETYPE_GIF, 'bmp' => IMAGETYPE_BMP,
    ];

    /** Already compressed: stored as they are (no CPU spent deflating them again). */
    private const STORED = ['jpg', 'jpeg', 'png', 'webp', 'gif', 'heic', 'heif', 'avif', 'pdf', 'mp4', 'mov', '3gp', 'mp3', 'm4a', 'aac', 'ogg', 'amr', 'zip', 'gz', 'docx', 'xlsx', 'pptx'];

    public function __construct(private readonly AuditLogger $audit, private readonly RestoreFiles $files, private readonly DiskSpace $space) {}

    public function disk(): Filesystem
    {
        return Storage::disk(config('gym.backup_disk', 'local'));
    }

    public function directory(Tenant $tenant): string
    {
        return 'backups/tenants/'.$tenant->id;
    }

    /**
     * Creates the club's archive and proves it whole before saying so.
     *
     * @return string the stored file name
     *
     * @throws BusinessRuleException backup.failed (nothing kept, no archive removed), backup.busy, backup.no_space
     */
    public function create(Tenant $tenant): string
    {
        return $this->exclusively($tenant, fn () => $this->createVerified($tenant, false, true));
    }

    /** Finishes any restore of the club that stopped half-way (see restoreWhole). @return int runs finished */
    public function recover(Tenant $tenant): int
    {
        return $this->exclusively($tenant, fn () => $this->recoverInterrupted($tenant));
    }

    /** Restore runs of the club that stopped half-way and wait to be finished. */
    public function interruptedRuns(Tenant $tenant): int
    {
        return count(Storage::disk('local')->directories(self::STAGING.'/'.$tenant->id));
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
     * Replace the club's data and files with the archive's, with no safety copy first (the panel takes one: see
     * restoreWithSafetyCopy).
     *
     * @return array<string, int> rows restored per table
     */
    public function restore(Tenant $tenant, string $archivePath): array
    {
        return $this->exclusively($tenant, fn () => $this->restoreWhole($tenant, $archivePath, false));
    }

    /**
     * As the panel restores: the archive checked and its files staged first, then a safety copy of the club as it is
     * now (created and proven whole, or the same as the newest archive, which is then reused), then the restore.
     *
     * @return array<string, int>
     */
    public function restoreWithSafetyCopy(Tenant $tenant, string $archivePath): array
    {
        return $this->exclusively($tenant, fn () => $this->restoreWhole($tenant, $archivePath, true));
    }

    /**
     * Reads an archive back whole: every entry against its CRC and, when the manifest records them, its size and
     * SHA-256; data.json against its SHA-256 and the manifest's counts. Pieces at a time, never the files in memory.
     *
     * @return array{entries: int, files: int, rows: int}
     *
     * @throws RuntimeException naming what is wrong
     */
    public function verifyArchive(string $file): array
    {
        $zip = new ZipArchive;
        if ($zip->open($file, ZipArchive::CHECKCONS | ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('the archive does not open as a sound zip');
        }
        try {
            $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
            if (! is_array($manifest) || ($manifest['format'] ?? null) !== self::FORMAT_VERSION) {
                throw new RuntimeException('manifest.json is missing or of another format');
            }
            $listed = is_array($manifest['files'] ?? null) ? $manifest['files'] : null;
            $files = 0;
            $dataSha = null;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                if (str_ends_with($name, '/')) {
                    continue;
                }
                [$size, $sha] = $this->readEntry($zip, $i, null);
                if ($name === 'data.json') {
                    $dataSha = $sha;
                } elseif (str_starts_with($name, 'files/')) {
                    $files++;
                    if ($listed !== null && (($listed[$name]['sha256'] ?? null) !== $sha || ($listed[$name]['size'] ?? null) !== $size)) {
                        throw new RuntimeException("{$name} is not the file the manifest records");
                    }
                }
            }
            if ($dataSha === null) {
                throw new RuntimeException('data.json is missing');
            }
            if ($listed !== null && $files !== count($listed)) {
                throw new RuntimeException('the manifest records '.count($listed).' files, the archive holds '.$files);
            }
            if (isset($manifest['data_sha256']) && $manifest['data_sha256'] !== $dataSha) {
                throw new RuntimeException('data.json is not the one the manifest records');
            }
            $data = json_decode((string) $zip->getFromName('data.json'), true);
            if (! is_array($data) || array_map('count', $data) != ($manifest['counts'] ?? null)) {
                throw new RuntimeException('the rows in data.json are not the manifest counts');
            }

            return ['entries' => $zip->numFiles, 'files' => $files, 'rows' => array_sum($manifest['counts'])];
        } finally {
            $zip->close();
        }
    }

    // ── Creating ───────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  bool  $reuseIdentical  a safety copy: when the newest archive already holds exactly this, it is reused
     * @param  bool  $recover  first finish any restore stopped half-way; false only for a restore's own safety copy
     *                         (that restore recovered already, and its own staging has no journal yet)
     */
    private function createVerified(Tenant $tenant, bool $reuseIdentical, bool $recover): string
    {
        $dir = $this->directory($tenant);
        $build = null;
        $dataFile = (string) tempnam(sys_get_temp_dir(), 'gymbk');
        try {
            // An archive taken over a half-finished restore would keep the half state and push a sound archive out.
            if ($recover) {
                $this->recoverInterrupted($tenant);
            }
            $this->disk()->makeDirectory($dir);
            $this->removeLeftovers($dir);

            [$counts, $dataSha] = $this->writeRows($tenant, $dataFile);
            // A file removed or changed while it is copied fails the check below: the files are listed again, once.
            for ($attempt = 1; ; $attempt++) {
                $sources = $this->sources($tenant);
                $content = hash('sha256', $dataSha."\n".implode("\n", array_map(fn ($s) => $s['entry'].' '.$s['sha256'], $sources)));
                if ($reuseIdentical && ($same = $this->newestWithContent($tenant, $content))) {
                    $this->audit->log('backup.reused', $tenant, ['file' => $same, 'why' => 'safety copy: the newest archive holds this already'], tenantId: $tenant->id);

                    return $same;
                }
                $bytes = filesize($dataFile) + array_sum(array_column($sources, 'size'));
                $manifest = [
                    'format' => self::FORMAT_VERSION,
                    'tenant_id' => $tenant->id,
                    'tenant_slug' => $tenant->slug,
                    'created_at' => now()->toIso8601String(),
                    'app_version' => config('app.version', 'dev'),
                    'counts' => $counts,
                    'data_sha256' => $dataSha,
                    'content_sha256' => $content,
                    'files' => array_combine(array_column($sources, 'entry'), array_map(fn ($s) => ['size' => $s['size'], 'sha256' => $s['sha256']], $sources)) ?: (object) [],
                ];
                $build = $this->buildPath($dir, $bytes);
                try {
                    $this->buildZip($build['path'], $manifest, $dataFile, $sources);
                    $checked = $this->verifyArchive($build['path']);

                    break;
                } catch (Throwable $e) {
                    $this->dropBuild($build);
                    $build = null;
                    if ($attempt >= 2 || $this->sourcesUnchanged($sources)) {
                        throw $e;
                    }
                }
            }

            $name = $this->freeName($dir);
            $sha = (string) hash_file('sha256', $build['path']);
            $size = (int) filesize($build['path']);
            $this->keep($build, $dir.'/'.$name, $sha, $size);
            $build = null;

            $this->audit->log('backup.created', $tenant, ['file' => $name, 'counts' => $counts, 'size' => $size, 'sha256' => $sha, 'files' => $checked['files']], tenantId: $tenant->id);
            // Only now, with a new archive proven whole, the oldest beyond fourteen goes.
            $this->prune($tenant);

            return $name;
        } catch (Throwable $e) {
            if ($build) {
                $this->dropBuild($build);
            }
            report($e);
            try {
                $this->audit->log('backup.failed', $tenant, ['error' => mb_substr($e->getMessage(), 0, 300)], tenantId: $tenant->id);
            } catch (Throwable) {
                Log::error('backup failed and could not be recorded', ['tenant' => $tenant->id, 'error' => $e->getMessage()]);
            }
            if ($e instanceof BusinessRuleException && in_array($e->rule, ['backup.no_space', 'backup.busy', 'backup.restore_interrupted'], true)) {
                throw $e;
            }

            throw new BusinessRuleException('backup.failed', 'تعذر إنشاء النسخة الاحتياطية، ولم يُحذف أي أرشيف سابق: '.mb_substr($e->getMessage(), 0, 160));
        } finally {
            @unlink($dataFile);
        }
    }

    /**
     * Every row of the club, in one snapshot (REPEATABLE READ, read only: what was committed when the backup started,
     * whatever is saved meanwhile), written to $file a table at a time, a thousand rows at a time.
     *
     * @return array{0: array<string, int>, 1: string} rows per table, data.json's SHA-256
     */
    private function writeRows(Tenant $tenant, string $file): array
    {
        $out = fopen($file, 'wb');
        if ($out === false) {
            throw new RuntimeException('data.json could not be opened for writing');
        }
        $put = function (string $piece) use ($out): void {
            if (fwrite($out, $piece) !== strlen($piece)) {
                throw new RuntimeException('data.json could not be written (is the disk full?)');
            }
        };
        $counts = [];
        $read = function () use ($tenant, $put, &$counts): void {
            $put('{');
            foreach (TenantTables::ORDER as $i => $table) {
                $put(($i ? ',' : '').json_encode($table).':[');
                $n = 0;
                foreach ($this->rowsOf($tenant, $table) as $row) {
                    $put(($n ? ',' : '').json_encode((array) $row, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
                    $n++;
                }
                $put(']');
                $counts[$table] = $n;
            }
            $put('}');
        };
        try {
            // Inside a transaction already (a test's), the snapshot is that transaction's own.
            DB::transactionLevel() > 0 || DB::getDriverName() !== 'pgsql' ? $read() : DB::transaction(function () use ($read) {
                DB::statement('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY');
                $read();
            });
        } finally {
            if (! fclose($out)) {
                throw new RuntimeException('data.json could not be closed (is the disk full?)');
            }
        }

        return [$counts, (string) hash_file('sha256', $file)];
    }

    /** @return iterable<object> */
    private function rowsOf(Tenant $tenant, string $table): iterable
    {
        if (isset(TenantTables::PIVOTS[$table])) {
            [$fk, $parent] = TenantTables::PIVOTS[$table];

            return DB::table($table)->whereIn($fk, DB::table($parent)->where('tenant_id', $tenant->id)->select('id'))->get();
        }

        return DB::table($table)->where('tenant_id', $tenant->id)->lazyById(1000, 'id');
    }

    /**
     * The club's files on both disks, each with its size and SHA-256 as read now.
     *
     * @return list<array{entry: string, real: string, size: int, sha256: string}>
     */
    private function sources(Tenant $tenant): array
    {
        $sources = [];
        foreach (['local' => 'private', 'public' => 'public'] as $disk => $label) {
            foreach (Storage::disk($disk)->allFiles('tenants/'.$tenant->id) as $file) {
                $real = Storage::disk($disk)->path($file);
                $sha = @hash_file('sha256', $real);
                if ($sha === false) {
                    continue; // removed since it was listed
                }
                $sources[] = ['entry' => 'files/'.$label.'/'.$file, 'real' => $real, 'size' => (int) filesize($real), 'sha256' => $sha];
            }
        }

        return $sources;
    }

    /** @param list<array{entry: string, real: string, size: int, sha256: string}> $sources */
    private function sourcesUnchanged(array $sources): bool
    {
        foreach ($sources as $s) {
            if (@hash_file('sha256', $s['real']) !== $s['sha256']) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<array{entry: string, real: string, size: int, sha256: string}>  $sources
     */
    private function buildZip(string $path, array $manifest, string $dataFile, array $sources): void
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('the archive could not be started');
        }
        $ok = $zip->addFromString('manifest.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))
            && $zip->addFile($dataFile, 'data.json');
        foreach ($sources as $source) {
            // Added by path: libzip reads each file while writing, one at a time, never all of them in memory.
            $ok = $ok && $zip->addFile($source['real'], $source['entry']);
            if ($ok && in_array(strtolower(pathinfo($source['entry'], PATHINFO_EXTENSION)), self::STORED, true)) {
                $zip->setCompressionName($source['entry'], ZipArchive::CM_STORE);
            }
        }
        $status = $ok ? '' : $zip->getStatusString();
        if (! $ok || ! @$zip->close()) {
            throw new RuntimeException('the archive could not be written: '.($status ?: $zip->getStatusString()));
        }
    }

    /**
     * Where to build: beside the kept archives under a name no listing shows, when the backup disk is a local folder
     * (then keeping it is a rename); otherwise a temporary file, copied to the disk and checked after.
     *
     * @return array{path: string, local: bool, part: string}
     */
    private function buildPath(string $dir, int $bytes): array
    {
        $part = $dir.'/'.self::BUILDING.Str::ulid().'.part';
        if ($this->disk()->getAdapter() instanceof LocalFilesystemAdapter) {
            $path = $this->disk()->path($part);
            $this->space->ensure(dirname($path), $bytes, 'backup.no_space', 'لإنشاء النسخة الاحتياطية');

            return ['path' => $path, 'local' => true, 'part' => $part];
        }
        $path = (string) tempnam(sys_get_temp_dir(), 'gymbk');
        $this->space->ensure(sys_get_temp_dir(), $bytes, 'backup.no_space', 'لإنشاء النسخة الاحتياطية');

        return ['path' => $path, 'local' => false, 'part' => $part];
    }

    /** Names the proven archive: a rename beside it, or a copy to the disk read back and compared (size, SHA-256). */
    private function keep(array $build, string $final, string $sha, int $size): void
    {
        if (! $build['local']) {
            $in = fopen($build['path'], 'rb');
            $written = $in !== false && $this->disk()->writeStream($build['part'], $in);
            is_resource($in) && fclose($in);
            @unlink($build['path']);
            if (! $written || $this->disk()->size($build['part']) !== $size || $this->sha256($build['part']) !== $sha) {
                $this->disk()->delete($build['part']);

                throw new RuntimeException('the archive was not stored whole on the backup disk');
            }
        }
        if (! $this->disk()->move($build['part'], $final) || $this->disk()->size($final) !== $size || $this->sha256($final) !== $sha) {
            throw new RuntimeException('the archive could not be named '.basename($final));
        }
    }

    private function dropBuild(array $build): void
    {
        @unlink($build['path']);
        foreach (glob($build['path'].'*') ?: [] as $left) {
            @unlink($left); // libzip's own temporary beside it
        }
        if (! $build['local']) {
            $this->disk()->delete($build['part']);
        }
    }

    /** What a build stopped half-way left (the process killed while writing): this club's lock is held, so none is live. */
    private function removeLeftovers(string $dir): void
    {
        foreach ($this->disk()->files($dir) as $file) {
            if (str_starts_with(basename($file), self::BUILDING)) {
                $this->disk()->delete($file);
            }
        }
        foreach ([...(glob(sys_get_temp_dir().'/gymbk*') ?: []), ...(glob(sys_get_temp_dir().'/gymrs*') ?: [])] as $tmp) {
            if (@filemtime($tmp) < time() - 6 * 3600) {
                @unlink($tmp);
            }
        }
    }

    private function freeName(string $dir): string
    {
        $at = now();
        while ($this->disk()->exists($dir.'/'.($name = 'backup-'.$at->format('Ymd-His').'.zip'))) {
            $at = $at->addSecond();
        }

        return $name;
    }

    /** The newest kept archive when it holds exactly $content (recorded by this code) and still reads back whole. */
    private function newestWithContent(Tenant $tenant, string $content): ?string
    {
        $newest = collect($this->disk()->files($this->directory($tenant)))->filter(fn ($f) => str_ends_with($f, '.zip'))->sort()->last();
        if (! $newest || ! ($this->disk()->getAdapter() instanceof LocalFilesystemAdapter)) {
            return null;
        }
        $zip = new ZipArchive;
        if ($zip->open($this->disk()->path($newest), ZipArchive::RDONLY) !== true) {
            return null;
        }
        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $zip->close();
        if (($manifest['content_sha256'] ?? null) !== $content || (int) ($manifest['tenant_id'] ?? 0) !== $tenant->id) {
            return null;
        }
        try {
            $this->verifyArchive($this->disk()->path($newest));
        } catch (Throwable) {
            return null;
        }

        return basename($newest);
    }

    private function sha256(string $path): ?string
    {
        $stream = $this->disk()->readStream($path);
        if (! is_resource($stream)) {
            return null;
        }
        $context = hash_init('sha256');
        hash_update_stream($context, $stream);
        fclose($stream);

        return hash_final($context);
    }

    // ── Restoring ──────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Replace the club's data and files with the archive's: all of it, or nothing, and a stop at any moment (the
     * process killed, the power gone) leaves a run the next one finishes.
     *
     *  1. Checked before anything changes: the archive, its club, every row, every file entry's path, every file's
     *     bytes against the CRC the archive keeps (and its SHA-256 when the manifest records it), and the free space.
     *  2. Staged: each file copied to restore-staging/{club}/{run}/new and checked; then (from the panel) a safety copy
     *     of the club as it is, proven whole; then the run's journal. A failure here changes nothing of the club.
     *  3. One database transaction: the rows replaced, each file put in place (a rename on this server's disk; the
     *     file it replaces moved aside to …/previous), then a marker (audit backup.restore_committed; a restore never
     *     rewrites audit_logs) that commits with the rows.
     *  4. After the commit, the staging (and the files it replaced) goes.
     *
     * Durable: the journal is on disk before the first file moves, the marker commits with the rows. A run stopped
     * anywhere is finished by recoverInterrupted() — at the next restore or backup (the nightly one included), from the
     * backups page, or with `php artisan backup:recover` — forward when its marker committed (the rows are the
     * archive's: the remaining files are put in place), back when it did not (the database rolled the rows back by
     * itself: each moved file is put back). Recovering is itself safe to stop and run again.
     *
     * @return array<string, int>
     */
    private function restoreWhole(Tenant $tenant, string $archivePath, bool $safetyCopy): array
    {
        $this->recoverInterrupted($tenant);

        $zip = new ZipArchive;
        if ($zip->open($archivePath) !== true) {
            throw new BusinessRuleException('backup.invalid', 'ملف النسخة الاحتياطية غير صالح.');
        }

        $manifest = json_decode((string) $zip->getFromName('manifest.json'), true);
        $json = (string) $zip->getFromName('data.json');
        $data = json_decode($json, true);

        if (! is_array($manifest) || ! is_array($data) || ($manifest['format'] ?? null) !== self::FORMAT_VERSION
            || (isset($manifest['data_sha256']) && $manifest['data_sha256'] !== hash('sha256', $json))) {
            $zip->close();
            throw new BusinessRuleException('backup.invalid', 'ملف النسخة الاحتياطية غير صالح أو بإصدار غير مدعوم.');
        }
        unset($json);

        if ((int) ($manifest['tenant_id'] ?? 0) !== $tenant->id) {
            $zip->close();
            $this->audit->log('backup.restore_refused', $tenant, ['reason' => 'other_tenant', 'archive_tenant' => $manifest['tenant_id'] ?? null], tenantId: $tenant->id);
            throw new BusinessRuleException('backup.other_tenant', 'هذه النسخة تخص ناديًا آخر ولا يمكن استعادتها هنا.');
        }

        // Every row must belong to this club — a tampered archive cannot inject rows elsewhere.
        foreach ($data as $table => $rows) {
            if (! in_array($table, TenantTables::ORDER, true)) {
                $zip->close();
                throw new BusinessRuleException('backup.invalid', 'جدول غير معروف في النسخة: '.$table);
            }
            if (! isset(TenantTables::PIVOTS[$table])) {
                foreach ($rows as $row) {
                    if ((int) ($row['tenant_id'] ?? 0) !== $tenant->id) {
                        $zip->close();
                        throw new BusinessRuleException('backup.other_tenant', 'النسخة تحتوي على بيانات نادٍ آخر.');
                    }
                }
            }
        }

        // Every file too, before anything is replaced: a refused archive leaves the club's rows and files as they were.
        $files = $this->archiveFiles($zip, $tenant);
        $listed = is_array($manifest['files'] ?? null) ? $manifest['files'] : null;
        if ($listed !== null && count($listed) !== count($files)) {
            $zip->close();
            throw new BusinessRuleException('backup.invalid', 'ملفات النسخة لا تطابق بيانها، ولم يُستبدل شيء.');
        }
        $bytes = 0;
        foreach ($files as [$index]) {
            $bytes += (int) ($zip->statIndex($index)['size'] ?? 0);
        }
        try {
            $this->space->ensure(Storage::disk('local')->path(''), $bytes, 'backup.no_space', 'لتجهيز ملفات الاستعادة');
        } catch (BusinessRuleException $e) {
            $zip->close();

            throw $e;
        }

        $run = (string) Str::ulid();
        $dir = self::STAGING.'/'.$tenant->id.'/'.$run;
        $open = true;
        try {
            [$items, $report] = $this->stage($zip, $tenant, $files, $listed, $dir);
            $zip->close();
            $open = false;
            $safety = $safetyCopy ? $this->safetyCopy($tenant) : null;
            $this->files->putString('local', $dir.'/journal.json', (string) json_encode(['run' => $run, 'tenant' => $tenant->id, 'safety_copy' => $safety, 'items' => $items], JSON_UNESCAPED_UNICODE));
        } catch (Throwable $e) {
            if ($open) {
                $zip->close();
            }
            $this->discardQuietly($dir);
            $this->audit->log('backup.restore_refused', $tenant, ['reason' => 'before_replacing', 'error' => mb_substr($e->getMessage(), 0, 200)], tenantId: $tenant->id);
            if ($e instanceof BusinessRuleException) {
                throw $e;
            }
            report($e);

            throw new BusinessRuleException('backup.restore_failed', 'تعذر تجهيز ملفات النسخة، ولم يتغير شيء: بقيت بيانات النادي وملفاته كما كانت.');
        }

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
            // cannot say, nothing is guessed: the journal waits for recoverInterrupted().
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

        // Committed: the rows and the files are the archive's. If the staging cannot go now, the next run removes it
        // (its marker says the run is done).
        $this->discardQuietly($dir);

        $summary = ['written' => $report['written']] + array_map(fn ($list) => array_slice($list, 0, 50), array_filter([
            'kept_private' => $report['kept_private'], 'hidden_skipped' => $report['hidden_skipped'],
        ]));
        $this->audit->log('backup.restored', $tenant, ['counts' => $counts, 'from' => $data ? 'archive' : null, 'files' => $summary, 'run' => $run], tenantId: $tenant->id);

        return $counts;
    }

    /** The club as it is now, proven whole before anything is replaced; a failure refuses the restore. */
    private function safetyCopy(Tenant $tenant): string
    {
        try {
            return $this->createVerified($tenant, true, false);
        } catch (BusinessRuleException $e) {
            throw new BusinessRuleException('backup.restore_failed', 'تعذر حفظ نسخة أمان من حالة النادي الحالية قبل الاستعادة، فلم يُستبدل شيء. '.$e->getMessage());
        }
    }

    /** Whether the run's rows committed: its marker is in audit_logs, which a restore never rewrites. */
    private function committed(Tenant $tenant, string $run): bool
    {
        return DB::table('audit_logs')->where('tenant_id', $tenant->id)->where('event', 'backup.restore_committed')
            ->where('properties->run', $run)->exists();
    }

    /** The run stays with its journal for recoverInterrupted(); the person is told, never "restored". */
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
     *
     * @return int runs finished
     */
    private function recoverInterrupted(Tenant $tenant): int
    {
        $done = 0;
        foreach (Storage::disk('local')->directories(self::STAGING.'/'.$tenant->id) as $dir) {
            $journal = json_decode((string) Storage::disk('local')->get($dir.'/journal.json'), true);
            if (! is_array($journal) || ! isset($journal['run'], $journal['items'])) {
                // Stopped before its journal: nothing of the club was replaced yet.
                $this->files->deleteDirectory('local', $dir);

                continue;
            }
            try {
                $committed = $this->committed($tenant, $journal['run']);
                $committed ? $this->putForward($dir, $journal['items']) : $this->putBack($dir, $journal['items']);
                $this->files->deleteDirectory('local', $dir);
            } catch (Throwable $e) {
                report($e);

                throw new BusinessRuleException('backup.restore_interrupted', 'استعادة سابقة لهذا النادي توقفت في منتصفها ولم يمكن إكمالها بعد ('.mb_substr($e->getMessage(), 0, 120).'). لا نسخ ولا استعادة حتى تكتمل: أصلح سبب الخلل ثم أعد المحاولة.');
            }
            $this->audit->log('backup.restore_recovered', $tenant, ['run' => $journal['run'], 'direction' => $committed ? 'forward' : 'back'], tenantId: $tenant->id);
            $done++;
        }

        return $done;
    }

    /**
     * Copies every file of the archive to the staging, each checked against the archive's CRC (and the manifest's
     * SHA-256) on the way and against its SHA-256 once written, one at a time.
     *
     * @param  list<array{0: int, 1: string, 2: string}>  $files
     * @param  array<string, array{size: int, sha256: string}>|null  $listed
     * @return array{0: list<array{n: int, disk: string, path: string, sha: string}>, 1: array{written: int, kept_private: list<string>, hidden_skipped: list<string>}}
     */
    private function stage(ZipArchive $zip, Tenant $tenant, array $files, ?array $listed, string $dir): array
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
            $tmp = (string) tempnam(sys_get_temp_dir(), 'gymrs');
            try {
                [, $sha] = $this->readEntry($zip, $index, $tmp);
                $entry = 'files/'.$part.'/'.$folder.$path;
                if ($listed !== null && ($listed[$entry]['sha256'] ?? null) !== $sha) {
                    throw new BusinessRuleException('backup.invalid', 'ملف في النسخة لا يطابق بصمته المسجلة، ولم يُستبدل شيء: '.mb_scrub(mb_substr($path, 0, 120)));
                }
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

        return [$items, $report];
    }

    /**
     * Reads one entry in pieces (to $to when given), its size and CRC compared with what the archive says.
     *
     * @return array{0: int, 1: string} its size and SHA-256
     */
    private function readEntry(ZipArchive $zip, int $index, ?string $to): array
    {
        $stat = $zip->statIndex($index);
        $name = (string) ($stat['name'] ?? $index);
        $in = $zip->getStreamIndex($index);
        $out = $to !== null ? fopen($to, 'wb') : null;
        $crc = hash_init('crc32b');
        $sha = hash_init('sha256');
        $size = 0;
        $read = is_resource($in) && $stat !== false && ($to === null || is_resource($out));
        try {
            while ($read && ! feof($in)) {
                $chunk = fread($in, 1 << 20);
                if ($chunk === false) {
                    $read = false;
                    break;
                }
                hash_update($crc, $chunk);
                hash_update($sha, $chunk);
                $size += strlen($chunk);
                if ($out !== null && fwrite($out, $chunk) !== strlen($chunk)) {
                    $read = false;
                }
            }
        } catch (Throwable) {
            $read = false; // a CRC error raised by libzip itself while reading
        } finally {
            is_resource($in) && fclose($in);
            is_resource($out) && fclose($out);
        }
        if (! $read || $size !== (int) $stat['size'] || hexdec(hash_final($crc)) !== ((int) $stat['crc'] & 0xFFFFFFFF)) {
            throw new BusinessRuleException('backup.invalid', 'النسخة تحتوي على ملف تالف، ولم يُستبدل شيء: '.mb_scrub(mb_substr(preg_replace('#^files/(private|public)/tenants/\d+/#', '', $name), 0, 120)));
        }

        return [$size, hash_final($sha)];
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

    /**
     * Puts one staged file in place. A file already there is first marked as such (…/had/n, written before it moves, so
     * the mark survives any stop) and moved aside to …/previous/n.
     *
     * @param  array{n: int, disk: string, path: string, sha: string}  $item
     */
    private function place(string $dir, array $item): void
    {
        if ($this->files->exists($item['disk'], $item['path']) && ! $this->files->exists('local', $dir.'/previous/'.$item['n'])) {
            if (! $this->files->exists('local', $dir.'/had/'.$item['n'])) {
                $this->files->putString('local', $dir.'/had/'.$item['n'], '');
            }
            $this->files->move($item['disk'], $item['path'], 'local', $dir.'/previous/'.$item['n']);
        }
        $this->files->move('local', $dir.'/new/'.$item['n'], $item['disk'], $item['path']);
        if ($this->files->sha256($item['disk'], $item['path']) !== $item['sha']) {
            throw new RuntimeException("restore: {$item['disk']}:{$item['path']} is not the archive's file once in place");
        }
    }

    /**
     * Each file as it was before the run, last placed first, and safe to run again after any stop: a file that was
     * there (marked …/had/n) comes back from …/previous/n, or is back already; a file the run put where nothing was
     * goes; one still staged was never placed.
     *
     * @param  list<array{n: int, disk: string, path: string, sha: string}>  $items
     */
    private function putBack(string $dir, array $items): void
    {
        foreach (array_reverse($items) as $item) {
            if ($this->files->exists('local', $dir.'/previous/'.$item['n'])) {
                $this->files->move('local', $dir.'/previous/'.$item['n'], $item['disk'], $item['path']);
            } elseif ($this->files->exists('local', $dir.'/had/'.$item['n'])) {
                continue; // it was there before, and is back (or never left)
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
            Log::warning('restore staging not removed; the next run removes it', ['dir' => $dir, 'error' => $e->getMessage()]);
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

    /**
     * One backup or restore of this club at a time, across processes: a PostgreSQL session lock, which the database
     * releases by itself when the process holding it dies. The same process may take it again (a restore's safety copy).
     */
    private function exclusively(Tenant $tenant, callable $work): mixed
    {
        if (DB::getDriverName() !== 'pgsql') {
            return $work();
        }
        if (! DB::selectOne('select pg_try_advisory_lock(?, ?) as got', [self::LOCKS, $tenant->id])->got) {
            throw new BusinessRuleException('backup.busy', 'نسخة احتياطية أو استعادة لهذا النادي جارية الآن. حاول بعد انتهائها.');
        }
        try {
            return $work();
        } finally {
            DB::select('select pg_advisory_unlock(?, ?)', [self::LOCKS, $tenant->id]);
        }
    }
}
