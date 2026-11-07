<?php

namespace App\Modules\Messaging\Services;

use App\Core\Tenancy\TenantContext;
use App\Modules\Gym\Models\Member;

/** The values a template sent by hand to one member can use (from the member's page or the chat). */
final class TemplateValues
{
    /** @return array<string, string> */
    public static function forMember(Member $member): array
    {
        $tenant = app(TenantContext::class)->require();
        $membership = $member->currentMembership()->first();

        return [
            'member_name' => explode(' ', (string) $member->full_name)[0],
            'club_name' => (string) $tenant->name,
            'member_no' => (string) $member->member_no,
            'plan_name' => (string) ($membership?->plan_snapshot['name'] ?? ''),
            'ends_on' => $membership?->ends_on?->format('Y/m/d') ?? '',
            'starts_on' => $membership?->starts_on?->format('Y/m/d') ?? '',
            'renew_link' => route('portal.renew', ['club' => $tenant->slug]),
        ];
    }
}
