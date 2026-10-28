<?php

namespace App\Modules\Messaging\Jobs;

use App\Core\Tenancy\TenantContext;
use App\Models\Tenant;
use App\Modules\Messaging\Models\Campaign;
use App\Modules\Messaging\Services\CampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class DispatchCampaign implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public function __construct(public readonly int $campaignId, public readonly int $tenantId)
    {
        $this->onQueue('notifications');
    }

    public function handle(TenantContext $tenants, CampaignService $campaigns): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        $tenants->run($tenant, function () use ($campaigns) {
            $campaign = Campaign::with('template')->find($this->campaignId);
            if ($campaign && $campaign->status === Campaign::SENDING) {
                $campaigns->fanOut($campaign);
            }
        });
    }
}
