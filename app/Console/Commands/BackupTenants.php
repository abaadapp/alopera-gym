<?php

namespace App\Console\Commands;

use App\Core\Backup\TenantBackupService;
use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;

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
        return 'file='.app(TenantBackupService::class)->create($tenant);
    }
}
