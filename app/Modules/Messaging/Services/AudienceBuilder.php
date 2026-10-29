<?php

namespace App\Modules\Messaging\Services;

use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use Illuminate\Database\Eloquent\Builder;

/**
 * Campaign segments. Every segment is restricted to members who gave
 * marketing consent and have not opted out.
 */
final class AudienceBuilder
{
    public const SEGMENTS = [
        'all' => 'كل الأعضاء الموافقين على التسويق',
        'active' => 'العضويات النشطة',
        'expiring' => 'تنتهي عضويتهم خلال (أيام)',
        'expired' => 'انتهت عضويتهم خلال آخر (أيام)',
        'inactive' => 'لم يزوروا النادي منذ (أيام)',
        'new' => 'انضموا خلال آخر (أيام)',
    ];

    /** @param array{segment?: string, days?: int|null, plan_ids?: list<int>|null, program_ids?: list<int>|null} $audience */
    public function query(array $audience): Builder
    {
        $days = max(1, (int) ($audience['days'] ?? 30));
        $today = today();

        $query = Member::query()
            ->where('status', Member::ACTIVE)
            ->where('whatsapp_opt_in', true)
            ->where('marketing_opt_in', true)
            ->whereNull('marketing_opt_out_at');

        match ($audience['segment'] ?? 'all') {
            'active' => $query->whereHas('memberships', fn ($q) => $q->where('status', Membership::ACTIVE)),
            'expiring' => $query->whereHas('memberships', fn ($q) => $q->where('status', Membership::ACTIVE)
                ->whereBetween('ends_on', [$today, $today->copy()->addDays($days)])),
            'expired' => $query->whereHas('memberships', fn ($q) => $q->where('status', Membership::EXPIRED)
                ->where('ends_on', '>=', $today->copy()->subDays($days)))
                ->whereDoesntHave('memberships', fn ($q) => $q->whereIn('status', [Membership::ACTIVE, Membership::SCHEDULED, Membership::FROZEN])),
            'inactive' => $query->where(fn ($q) => $q->whereNull('last_check_in_at')->orWhere('last_check_in_at', '<', now()->subDays($days))),
            'new' => $query->where('joined_on', '>=', $today->copy()->subDays($days)),
            default => null,
        };

        if (! empty($audience['plan_ids'])) {
            $query->whereHas('memberships', fn ($q) => $q->whereIn('membership_plan_id', $audience['plan_ids'])
                ->whereIn('status', [Membership::ACTIVE, Membership::EXPIRED, Membership::FROZEN]));
        }

        if (! empty($audience['program_ids'])) {
            $query->whereIn('id', Booking::query()
                ->select('member_id')
                ->whereHas('session', fn ($q) => $q->whereIn('program_id', $audience['program_ids'])->where('starts_at', '>=', now()->subDays(90))));
        }

        return $query;
    }
}
