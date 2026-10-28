<?php

namespace App\Console\Commands;

use App\Modules\Platform\Services\SubscriptionService;
use Illuminate\Console\Command;

class RunPlatformBilling extends Command
{
    protected $signature = 'platform:billing';

    protected $description = 'Renew club subscriptions to the platform and suspend clubs unpaid beyond the grace period';

    public function handle(SubscriptionService $subscriptions): int
    {
        $r = $subscriptions->runDaily();
        $this->info("renewed={$r['renewed']} suspended={$r['suspended']}");

        return self::SUCCESS;
    }
}
