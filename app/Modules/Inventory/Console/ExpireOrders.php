<?php

namespace App\Modules\Inventory\Console;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Modules\Inventory\Services\OrderService;
use Illuminate\Console\Command;

/** Releases website orders whose online payment window passed unpaid (stock back, invoice voided). */
class ExpireOrders extends Command
{
    protected $signature = 'sales:expire-orders';

    protected $description = 'Cancel unpaid online-payment website orders past their payment window';

    public function handle(TenantContext $tenants, OrderService $orders): int
    {
        foreach (Tenant::query()->orderBy('id')->get() as $club) {
            $count = $tenants->run($club, fn () => $orders->expireOverdue());
            if ($count > 0) {
                $this->line("{$club->slug}: {$count} expired");
            }
        }

        return self::SUCCESS;
    }
}
