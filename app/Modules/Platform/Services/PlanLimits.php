<?php

namespace App\Modules\Platform\Services;

use App\Core\Support\BusinessRuleException;
use App\Core\Tenancy\TenantContext;
use App\Models\Branch;
use App\Models\PlatformPlan;
use App\Models\User;
use App\Modules\Gym\Models\Member;

/** Enforces the limits of the club's platform plan (members, staff, branches). */
final class PlanLimits
{
    public function __construct(private readonly TenantContext $tenants)
    {
    }

    public function plan(): ?PlatformPlan
    {
        return $this->tenants->require()->currentSubscription?->plan;
    }

    public function assertCanAddMember(): void
    {
        $max = $this->plan()?->max_members;
        if ($max !== null && Member::count() >= $max) {
            throw new BusinessRuleException('plan.members', "وصلتِ إلى الحد الأقصى للمشتركات في باقتك على المنصة ({$max}). قومي بترقية الباقة.");
        }
    }

    public function assertCanAddStaff(): void
    {
        $tenant = $this->tenants->require();
        $max = $this->plan()?->max_staff;
        if ($max !== null && User::ofTenant($tenant->id)->where('is_active', true)->count() >= $max) {
            throw new BusinessRuleException('plan.staff', "وصلتِ إلى الحد الأقصى للمستخدمين في باقتك ({$max}).");
        }
    }

    public function assertCanAddBranch(): void
    {
        $max = $this->plan()?->max_branches;
        if ($max !== null && Branch::count() >= $max) {
            throw new BusinessRuleException('plan.branches', "وصلتِ إلى الحد الأقصى للفروع في باقتك ({$max}).");
        }
    }

    /** @return array<string, array{used: int, max: int|null}> */
    public function usage(): array
    {
        $tenant = $this->tenants->require();
        $plan = $this->plan();

        return [
            'members' => ['used' => Member::count(), 'max' => $plan?->max_members],
            'staff' => ['used' => User::ofTenant($tenant->id)->where('is_active', true)->count(), 'max' => $plan?->max_staff],
            'branches' => ['used' => Branch::count(), 'max' => $plan?->max_branches],
        ];
    }
}
