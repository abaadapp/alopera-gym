<?php

namespace App\Console\Commands;

use App\Core\Backup\BackupDailyReport;
use App\Core\Backup\TenantBackupService;
use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use Throwable;

class BackupTenants extends ForEachTenant
{
    protected $signature = 'backup:tenants {--tenant=}';

    protected $description = 'Create a backup archive for every club (keeps the last 14)';

    public function handle(TenantContext $tenants): int
    {
        // Hashing and writing every club's files is CPU work: the nightly run gives way to the site (this process only).
        if (function_exists('proc_nice')) {
            @proc_nice(10);
        }

        return parent::handle($tenants);
    }

    protected function handleTenant(Tenant $tenant): string
    {
        // One automatic archive a day: a run again the same day (retried, or started twice) makes none and tells nothing.
        $done = app(BackupDailyReport::class)->succeededToday($tenant);
        if ($done && collect(app(TenantBackupService::class)->list($tenant))->contains('name', $done)) {
            return 'already made today: '.$done;
        }
        try {
            // create() returns only once the archive is built, read back and proven whole: then, and only then, success.
            $file = app(TenantBackupService::class)->create($tenant);
        } catch (Throwable $e) {
            $this->tell(fn (BackupDailyReport $report) => $report->failed($tenant, self::reason($e)));

            throw $e;
        }
        $this->tell(fn (BackupDailyReport $report) => $report->succeeded($tenant, $file));

        return 'file='.$file;
    }

    /** The owner is told; a notice that cannot be written never turns a made archive into a failure (nor hides one). */
    private function tell(callable $report): void
    {
        try {
            $report(app(BackupDailyReport::class));
        } catch (Throwable $e) {
            report($e);
        }
    }

    /** Why, in plain words for the bell: never a path, a file name or a technical message. */
    private static function reason(Throwable $e): string
    {
        $rule = $e instanceof BusinessRuleException ? $e->rule : null;

        return match ($rule) {
            'backup.folder' => 'لا يستطيع النظام الوصول إلى مجلد النسخ الاحتياطية على الخادم (صلاحيات المجلد).',
            'backup.no_space' => 'لا توجد مساحة كافية على الخادم لإنشاء النسخة.',
            'backup.incomplete' => 'النسخة ناقصة: ملفات بعض مرفقات واتساب غير موجودة، فحُفظت نسخة ناقصة منفصلة.',
            'backup.busy' => 'كانت نسخة احتياطية أو استعادة أخرى جارية للنادي.',
            'backup.restore_interrupted' => 'توجد استعادة سابقة توقفت في منتصفها ولم تُكمَل.',
            default => 'تعذر إنشاء النسخة أو التحقق من سلامتها، ولم يُحذف أي أرشيف سابق.',
        };
    }
}
