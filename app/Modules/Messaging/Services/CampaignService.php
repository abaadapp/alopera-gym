<?php

namespace App\Modules\Messaging\Services;

use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\TenantContext;
use App\Modules\Gym\Models\Member;
use App\Modules\Messaging\Jobs\DispatchCampaign;
use App\Modules\Messaging\Models\Campaign;
use App\Modules\Messaging\Models\Message;
use Illuminate\Support\Facades\DB;

final class CampaignService
{
    public function __construct(
        private readonly AudienceBuilder $audiences,
        private readonly WhatsAppService $whatsapp,
        private readonly TenantContext $tenants,
    ) {}

    public function launch(Campaign $campaign): void
    {
        if (! in_array($campaign->status, [Campaign::DRAFT, Campaign::SCHEDULED], true)) {
            throw new BusinessRuleException('campaign.status', 'لا يمكن إطلاق هذه الحملة بحالتها الحالية.');
        }
        if (! $campaign->template?->isUsable()) {
            throw new BusinessRuleException('campaign.template', 'قالب الحملة غير معتمد بعد من واتساب.');
        }
        if (! $this->whatsapp->enabled()) {
            throw new BusinessRuleException('campaign.whatsapp', 'فعّلي ربط واتساب أولًا من الإعدادات.');
        }

        if ($campaign->scheduled_at && $campaign->scheduled_at->isFuture()) {
            $campaign->forceFill(['status' => Campaign::SCHEDULED])->save();

            return;
        }

        $campaign->forceFill(['status' => Campaign::SENDING, 'started_at' => now()])->save();
        DispatchCampaign::dispatch($campaign->id, $campaign->tenant_id)->afterCommit();
    }

    /** Called by the job: one message per consenting recipient, never twice. */
    public function fanOut(Campaign $campaign): int
    {
        $count = 0;
        $template = $campaign->template;

        $this->audiences->query($campaign->audience ?? [])
            ->with(['currentMembership'])
            ->chunkById(200, function ($members) use ($campaign, $template, &$count) {
                foreach ($members as $member) {
                    /** @var Member $member */
                    $membership = $member->currentMembership;
                    $message = $this->whatsapp->queueTemplate($member, $template->key, [
                        'member_name' => explode(' ', $member->full_name)[0],
                        'club_name' => $this->tenants->require()->name,
                        'plan_name' => (string) ($membership?->plan_snapshot['name'] ?? ''),
                        'ends_on' => $membership?->ends_on?->format('Y/m/d') ?? '',
                    ], null, 'campaign:'.$campaign->id.':'.$member->id, null, $campaign->id);
                    $count += $message ? 1 : 0;
                }
            });

        $campaign->forceFill(['recipients_count' => $count])->save();
        self::refreshCounts($campaign->id);

        return $count;
    }

    public static function refreshCounts(int $campaignId): void
    {
        $stats = Message::where('campaign_id', $campaignId)
            ->selectRaw('status, count(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $sent = $stats->only([Message::SENT, Message::DELIVERED, Message::READ])->sum();
        $pending = $stats->only([Message::QUEUED, Message::SCHEDULED])->sum();

        $campaign = Campaign::find($campaignId);
        if (! $campaign) {
            return;
        }

        $campaign->forceFill([
            'sent_count' => $sent,
            'delivered_count' => $stats->only([Message::DELIVERED, Message::READ])->sum(),
            'read_count' => (int) ($stats[Message::READ] ?? 0),
            'failed_count' => (int) ($stats[Message::FAILED] ?? 0) + (int) ($stats[Message::SKIPPED] ?? 0),
        ]);

        if ($campaign->status === Campaign::SENDING && $pending === 0 && $campaign->recipients_count > 0) {
            $campaign->status = Campaign::COMPLETED;
            $campaign->completed_at = now();
        }

        $campaign->save();
    }

    public function cancel(Campaign $campaign): void
    {
        DB::transaction(function () use ($campaign) {
            Message::where('campaign_id', $campaign->id)->whereIn('status', [Message::QUEUED, Message::SCHEDULED])
                ->update(['status' => Message::SKIPPED, 'error_code' => 'campaign_cancelled', 'updated_at' => now()]);
            $campaign->forceFill(['status' => Campaign::CANCELLED])->save();
        });
    }
}
