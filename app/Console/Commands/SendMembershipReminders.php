<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Modules\Gym\Models\Membership;
use App\Modules\Messaging\Services\MemberNotifier;
use Carbon\CarbonImmutable;

/**
 * Expiry reminders (e.g. 7, 3 and 1 day before) and the follow-up after a
 * membership has ended without renewal. Each reminder is sent once (dedupe key).
 */
class SendMembershipReminders extends ForEachTenant
{
    protected $signature = 'gym:send-reminders {--tenant=}';

    protected $description = 'Queue WhatsApp expiry reminders and post-expiry follow-ups';

    protected function handleTenant(Tenant $tenant): string
    {
        $notifier = app(MemberNotifier::class);
        $today = CarbonImmutable::today();
        $sent = 0;

        foreach ((array) $tenant->setting('expiry_reminder_days', []) as $days) {
            $days = (int) $days;
            Membership::with('member')->where('status', Membership::ACTIVE)
                ->whereDate('ends_on', $today->addDays($days))
                // no reminder if she has already renewed
                ->whereNotExists(fn ($q) => $q->from('gym_memberships as n')->whereColumn('n.member_id', 'gym_memberships.member_id')
                    ->whereIn('n.status', [Membership::SCHEDULED])->whereColumn('n.starts_on', '>', 'gym_memberships.ends_on'))
                ->each(function (Membership $m) use ($notifier, $days, &$sent) {
                    $notifier->membershipExpiring($m, $days);
                    $sent++;
                });
        }

        foreach ((array) $tenant->setting('expired_followup_days', []) as $days) {
            $days = (int) $days;
            Membership::with('member')->where('status', Membership::EXPIRED)
                ->whereDate('ends_on', $today->subDays($days))
                ->whereNotExists(fn ($q) => $q->from('gym_memberships as n')->whereColumn('n.member_id', 'gym_memberships.member_id')
                    ->whereIn('n.status', [Membership::ACTIVE, Membership::SCHEDULED, Membership::FROZEN, Membership::PENDING]))
                ->each(function (Membership $m) use ($notifier, $days, &$sent) {
                    $notifier->membershipFollowup($m, $days);
                    $sent++;
                });
        }

        return "reminders_queued={$sent}";
    }
}
