<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Gym\Services\PtService;

class RunDailyMemberships extends ForEachTenant
{
    protected $signature = 'gym:daily-memberships {--tenant=}';

    protected $description = 'Start scheduled memberships, auto-resume freezes, expire finished memberships and PT packages';

    protected function handleTenant(Tenant $tenant): string
    {
        $r = app(MembershipService::class)->runDailyTransitions();
        $pt = app(PtService::class)->expireDue();

        return "started={$r['started']} resumed={$r['resumed']} expired={$r['expired']} pt_expired={$pt}";
    }
}
