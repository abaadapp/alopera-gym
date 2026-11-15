<?php

namespace App\Console\Commands;

use App\Core\Backup\TenantBackupService;
use App\Models\Tenant;

/**
 * Finishes any club restore that stopped half-way (the server or PHP stopped mid-restore): forward when its rows had
 * committed, back when they had not. The nightly backup and every restore do it first by themselves; this is for an
 * operator who wants it now. Not scheduled.
 */
class BackupRecover extends ForEachTenant
{
    protected $signature = 'backup:recover {--tenant=}';

    protected $description = 'Finish any club restore that stopped half-way (forward or back, by its journal)';

    protected function handleTenant(Tenant $tenant): string
    {
        return 'finished='.app(TenantBackupService::class)->recover($tenant);
    }
}
