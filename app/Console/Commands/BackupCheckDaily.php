<?php

namespace App\Console\Commands;

use App\Core\Backup\BackupDailyReport;
use App\Core\Backup\TenantBackupService;
use App\Models\Tenant;
use Carbon\CarbonImmutable;

/**
 * After the nightly should have run (03:00): a club with no sound archive from today and no result told yet is told
 * the nightly did not run. It makes no archive and tells each club once a day (BackupDailyReport).
 */
class BackupCheckDaily extends ForEachTenant
{
    protected $signature = 'backup:check-daily {--tenant=}';

    protected $description = 'Tell the club owner when today\'s nightly backup did not run';

    protected function handleTenant(Tenant $tenant): string
    {
        $report = app(BackupDailyReport::class);
        $today = CarbonImmutable::today();
        if ($tenant->created_at && $tenant->created_at->greaterThan($today->setTime(3, 0))) {
            return 'new club: not due yet';
        }
        $prefix = 'backup-'.$today->format('Ymd').'-';
        $madeToday = collect(app(TenantBackupService::class)->list($tenant))->contains(fn ($b) => str_starts_with($b['name'], $prefix));
        if ($madeToday || $report->reportedToday($tenant)) {
            return 'ok';
        }
        $report->missing($tenant);

        return 'missing: owner told';
    }
}
