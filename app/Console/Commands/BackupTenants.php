<?php

namespace App\Console\Commands;

use App\Core\Backup\TenantBackupService;
use App\Models\Tenant;

class BackupTenants extends ForEachTenant
{
    protected $signature = 'backup:tenants {--tenant=}';

    protected $description = 'Create a backup archive for every club (keeps the last 14)';

    protected function handleTenant(Tenant $tenant): string
    {
        return 'file='.app(TenantBackupService::class)->create($tenant);
    }
}
