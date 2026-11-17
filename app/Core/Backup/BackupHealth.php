<?php

namespace App\Core\Backup;

use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Whether the club's backups can be relied on, read from what is on the backup disk (not from the scheduler, which can
 * stop without a word): the newest sound archive (backup-….zip, one of the fourteen) and the newest incomplete one
 * (incomplete/). Shown to whoever manages the club's backups, on every panel page, until a sound archive is made again:
 *
 * - incomplete: the newest backup is incomplete (a chat attachment's file was missing), newer than the last sound one;
 * - stale: no sound archive in more than STALE_HOURS (the nightly failed, or did not run);
 * - none: no sound archive at all, for a club older than STALE_HOURS.
 *
 * It says when the last sound archive was made and that an incomplete one does not bring every file back. It never
 * says which files, nor anything of their contents.
 */
final class BackupHealth
{
    /** The nightly runs at 03:00: a day and two hours without a sound archive means it failed or did not run. */
    public const STALE_HOURS = 26;

    private const NAME = '/^backup-(\d{8}-\d{6})\.zip$/';

    public function __construct(private readonly TenantBackupService $backups) {}

    /** @return array{kind: string, last_sound_at: ?string, hours: ?int, missing_attachments: ?int, message: string}|null */
    public function alert(Tenant $tenant): ?array
    {
        // The folder there but closed to this process (the panel's user): reading it would fail or look empty, and an
        // empty look is not «no backup» — the nightly may be making them all along.
        if ($this->backups->folderUnreadable($tenant)) {
            return ['kind' => 'unreadable', 'last_sound_at' => null, 'hours' => null, 'missing_attachments' => null,
                'message' => 'لا تستطيع لوحة التحكم قراءة مجلد النسخ الاحتياطية على الخادم (صلاحيات المجلد): لا يمكن عرض النسخ ولا إنشاء نسخة يدوية من اللوحة حتى يُصلَح.'];
        }
        $dir = $this->backups->directory($tenant);
        $sound = $this->newest($this->files($dir));
        $incomplete = $this->newest($this->files($dir.'/'.TenantBackupService::INCOMPLETE));
        $now = CarbonImmutable::now();

        if ($incomplete && (! $sound || $incomplete > $sound)) {
            $kind = 'incomplete';
        } elseif ($sound && $sound->diffInHours($now) > self::STALE_HOURS) {
            $kind = 'stale';
        } elseif (! $sound && $tenant->created_at && $tenant->created_at->diffInHours($now) > self::STALE_HOURS) {
            $kind = 'none';
        } else {
            return null;
        }

        $last = $sound?->format('Y-m-d H:i');
        $hours = $sound ? (int) floor($sound->diffInHours($now)) : null;
        $missing = $kind === 'incomplete' ? $this->missingAttachments($tenant) : null;
        $since = $sound ? "آخر نسخة سليمة: {$last} (قبل {$hours} ساعة)." : 'لا توجد أي نسخة سليمة للنادي.';
        $message = match ($kind) {
            'incomplete' => 'آخر نسخة احتياطية ناقصة: '.($missing ? "{$missing} من مرفقات واتساب ملفاتها مفقودة. " : '')
                .'النسخة الناقصة لا يُعتمد عليها لاستعادة جميع الملفات. '.$since,
            'stale' => 'لم تُنشأ نسخة احتياطية سليمة منذ أكثر من '.self::STALE_HOURS.' ساعة: النسخة الليلية فشلت أو لم تعمل. '.$since,
            'none' => 'لم تُنشأ أي نسخة احتياطية سليمة للنادي بعد: النسخة الليلية فشلت أو لم تعمل.',
        };

        return ['kind' => $kind, 'last_sound_at' => $last, 'hours' => $hours, 'missing_attachments' => $missing, 'message' => $message];
    }

    /**
     * A folder's files, or none when it cannot be read: this runs on every panel page, which a folder closed to the web
     * (one the nightly made in a group the web is not in) must never break.
     *
     * @return list<string>
     */
    private function files(string $dir): array
    {
        try {
            return $this->backups->disk()->files($dir);
        } catch (\Throwable $e) {
            report($e);

            return [];
        }
    }

    /** @param list<string> $files */
    private function newest(array $files): ?CarbonImmutable
    {
        $names = array_filter(array_map('basename', $files), fn ($n) => preg_match(self::NAME, $n) === 1);
        if ($names === []) {
            return null;
        }
        preg_match(self::NAME, max($names), $m);

        // Named when made, in the application's time zone (TenantBackupService::freeName).
        return CarbonImmutable::createFromFormat('Ymd-His', $m[1]) ?: null;
    }

    /** How many attachments the newest incomplete backup recorded as missing (its audit entry: a count, never a path). */
    private function missingAttachments(Tenant $tenant): ?int
    {
        $properties = DB::table('audit_logs')->where('tenant_id', $tenant->id)->where('event', 'backup.incomplete')->orderByDesc('id')->value('properties');
        $count = json_decode((string) $properties, true)['missing_attachments'] ?? null;

        return is_int($count) ? $count : null;
    }
}
