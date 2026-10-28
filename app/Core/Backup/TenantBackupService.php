<?php

namespace App\Core\Backup;

use App\Core\Audit\AuditLogger;
use App\Core\Support\BusinessRuleException;
use App\Models\Tenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
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

    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function disk(): \Illuminate\Contracts\Filesystem\Filesystem
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
     * Replace the club's data with the archive's.
     *
     * @return array<string, int> rows restored per table
     */
    public function restore(Tenant $tenant, string $archivePath): array
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

        $counts = DB::transaction(function () use ($tenant, $data) {
            foreach (array_reverse(TenantTables::ORDER) as $table) {
                $this->deleteTenantRows($tenant, $table);
            }

            $counts = [];
            foreach (TenantTables::ORDER as $table) {
                $rows = $data[$table] ?? [];
                if (in_array($table, TenantTables::SELF_REFERENCING, true)) {
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
        });

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $entry = (string) $zip->getNameIndex($i);
            if (preg_match('#^files/(private|public)/(tenants/'.$tenant->id.'/[^\0]+)$#', $entry, $m) && ! str_contains($m[2], '..')) {
                Storage::disk($m[1] === 'private' ? 'local' : 'public')->put($m[2], (string) $zip->getFromIndex($i));
            }
        }
        $zip->close();

        $this->audit->log('backup.restored', $tenant, ['counts' => $counts, 'from' => $data ? 'archive' : null], tenantId: $tenant->id);

        return $counts;
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

        if (in_array($table, TenantTables::SELF_REFERENCING, true)) {
            $column = $table === 'payments' ? 'refund_of_id' : 'previous_membership_id';
            DB::table($table)->where('tenant_id', $tenant->id)->update([$column => null]);
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
