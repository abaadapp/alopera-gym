<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Gym\Services\GiftService;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Gym\Services\PtService;

class RunDailyMemberships extends ForEachTenant
{
    protected $signature = 'gym:daily-memberships {--tenant=}';

    protected $description = 'Start scheduled memberships, auto-resume freezes, expire finished memberships, PT packages and unused gifts';

    protected function handleTenant(Tenant $tenant): string
    {
        $r = app(MembershipService::class)->runDailyTransitions();
        $pt = app(PtService::class)->expireDue();
        $reconciled = app(GiftService::class)->reconcilePaid();
        $gifts = app(GiftService::class)->expireDue();

        return "started={$r['started']} resumed={$r['resumed']} expired={$r['expired']} pt_expired={$pt} gifts_paid={$reconciled} gifts_expired={$gifts}";
    }
}
