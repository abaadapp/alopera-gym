<?php

namespace App\Console\Commands;

use App\Core\Notifications\StaffAlerts;
use App\Models\Tenant;

/**
 * Makes sure what is still open — document alerts, website orders, payments to confirm — is in the staff's bells,
 * adds the day's «memberships ending this week», and clears read notifications older than 90 days. Repeats are
 * no-ops, so it is safe hourly; it also fills the bells the first time after an upgrade.
 */
class SyncStaffNotifications extends ForEachTenant
{
    protected $signature = 'notifications:sync {--tenant=}';

    protected $description = 'Fill the staff notification bells from what is still open';

    protected function handleTenant(Tenant $tenant): string
    {
        return 'new='.app(StaffAlerts::class)->sync();
    }
}
