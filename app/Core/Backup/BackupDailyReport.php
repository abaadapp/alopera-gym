<?php

namespace App\Core\Backup;

use App\Core\Audit\AuditLogger;
use App\Core\Notifications\StaffNotifier;
use App\Models\AuditLog;
use App\Models\StaffNotification;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * The nightly backup's result, told to the club's owner: one bell notification per club per day (key backup.daily.{date}),
 * and the result recorded (backup.daily) for the backups page.
 *
 * - Succeeded: only once the archive is built and proven whole (TenantBackupService::create returned its name).
 * - Failed: the reason in plain words, never a path or a secret.
 * - Missing: the nightly did not run at all (backup:check-daily, after it should have).
 *
 * One notification a day: a run again (the command retried) adds nothing; a success replaces that day's failure or
 * missing notice (unread again), never the other way round, so the bell never says both.
 */
final class BackupDailyReport
{
    public const KEY = 'backup.daily.';

    private const RANK = ['missing' => 0, 'failed' => 1, 'succeeded' => 2];

    public function __construct(private readonly StaffNotifier $notifier, private readonly AuditLogger $audit) {}

    public function succeeded(Tenant $tenant, string $file): void
    {
        $at = self::archiveTime($file) ?? CarbonImmutable::now();
        $this->record($tenant, 'succeeded', ['file' => $file, 'at' => $at->format('Y-m-d H:i')],
            'تم إنشاء النسخة الاحتياطية اليومية بنجاح — التاريخ والوقت: '.$at->format('Y-m-d H:i'));
    }

    public function failed(Tenant $tenant, string $reason): void
    {
        $this->record($tenant, 'failed', ['reason' => mb_substr($reason, 0, 200)],
            'فشلت النسخة الاحتياطية اليومية — يرجى مراجعة إعدادات النسخ الاحتياطي. السبب: '.mb_substr($reason, 0, 160));
    }

    public function missing(Tenant $tenant): void
    {
        $this->record($tenant, 'missing', [],
            'لم تعمل النسخة الاحتياطية اليومية اليوم (الساعة 3 فجرًا) — يرجى مراجعة إعدادات النسخ الاحتياطي.');
    }

    /** Whether today's nightly result is known already (a notice of any kind), so the check adds nothing. */
    public function reportedToday(Tenant $tenant): bool
    {
        return StaffNotification::where('tenant_id', $tenant->id)->where('key', self::key())->exists()
            || AuditLog::where('tenant_id', $tenant->id)->where('event', 'backup.daily')->where('created_at', '>=', CarbonImmutable::today())->exists();
    }

    /** Today's nightly archive, when today's nightly already succeeded (one automatic archive a day). */
    public function succeededToday(Tenant $tenant): ?string
    {
        $entry = AuditLog::where('tenant_id', $tenant->id)->where('event', 'backup.daily')->where('created_at', '>=', CarbonImmutable::today())
            ->latest('id')->get()->first(fn (AuditLog $e) => ($e->properties['result'] ?? null) === 'succeeded');

        return $entry ? ($entry->properties['file'] ?? null) : null;
    }

    /** @return array{result: string, at: string, file: ?string, reason: ?string}|null the latest daily result */
    public function last(Tenant $tenant): ?array
    {
        $entry = AuditLog::where('tenant_id', $tenant->id)->where('event', 'backup.daily')->latest('id')->first();
        if (! $entry) {
            return null;
        }
        $p = (array) $entry->properties;

        return ['result' => (string) ($p['result'] ?? ''), 'at' => $entry->created_at->format('Y-m-d H:i'), 'file' => $p['file'] ?? null, 'reason' => $p['reason'] ?? null];
    }

    public static function key(?CarbonImmutable $day = null): string
    {
        return self::KEY.($day ?? CarbonImmutable::today())->format('Y-m-d');
    }

    /** @param array<string, mixed> $properties */
    private function record(Tenant $tenant, string $result, array $properties, string $text): void
    {
        $this->audit->as('system', null, 'backup', fn () => $this->audit->log('backup.daily', $tenant, ['result' => $result] + $properties, tenantId: $tenant->id));

        $key = self::key();
        $url = route('club.backups.index', absolute: false);
        $owner = fn (User $u) => $u->isOwner();
        $this->notifier->notify('backups.manage', $key, 'backup', $text, $url, $owner);

        // Today's notice already there with a weaker result (a failure, or «did not run»): this one replaces it.
        StaffNotification::where('tenant_id', $tenant->id)->where('key', $key)->where('text', '!=', mb_substr($text, 0, 300))->get()
            ->filter(fn (StaffNotification $n) => self::RANK[self::resultOf($n->text)] < self::RANK[$result])
            ->each(fn (StaffNotification $n) => $n->forceFill(['text' => mb_substr($text, 0, 300), 'read_at' => null, 'created_at' => now()])->save());
    }

    private static function resultOf(string $text): string
    {
        return match (true) {
            str_starts_with($text, 'تم إنشاء') => 'succeeded',
            str_starts_with($text, 'فشلت') => 'failed',
            default => 'missing',
        };
    }

    /** The archive's own time, from its name (backup-YYYYMMDD-HHMMSS.zip, made in the application's time zone). */
    private static function archiveTime(string $file): ?CarbonImmutable
    {
        return preg_match('/backup-(\d{8}-\d{6})\.zip$/', $file, $m) ? (CarbonImmutable::createFromFormat('Ymd-His', $m[1]) ?: null) : null;
    }
}
