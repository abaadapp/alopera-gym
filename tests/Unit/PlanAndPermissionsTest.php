<?php

namespace Tests\Unit;

use App\Core\Auth\PermissionRegistry;
use App\Core\Auth\SystemRoles;
use App\Modules\Gym\Models\MembershipPlan;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class PlanAndPermissionsTest extends TestCase
{
    public function test_plan_end_dates_are_inclusive_and_do_not_overflow_months(): void
    {
        $monthly = new MembershipPlan(['duration_value' => 1, 'duration_unit' => 'month']);
        $this->assertSame('2026-02-27', $monthly->endDateFrom(CarbonImmutable::parse('2026-01-31'))->toDateString());
        $this->assertSame('2026-02-14', $monthly->endDateFrom(CarbonImmutable::parse('2026-01-15'))->toDateString());

        $year = new MembershipPlan(['duration_value' => 1, 'duration_unit' => 'year']);
        $this->assertSame('2027-10-27', $year->endDateFrom(CarbonImmutable::parse('2026-10-28'))->toDateString());

        $days = new MembershipPlan(['duration_value' => 10, 'duration_unit' => 'day']);
        $this->assertSame('2026-10-10', $days->endDateFrom(CarbonImmutable::parse('2026-10-01'))->toDateString());
    }

    public function test_unknown_permissions_are_dropped(): void
    {
        $this->assertSame(['members.view'], PermissionRegistry::sanitize(['members.view', 'platform.godmode', '*']));
    }

    public function test_system_roles_only_reference_known_permissions(): void
    {
        foreach (SystemRoles::definitions() as $key => $role) {
            if ($role['permissions'] === ['*']) {
                $this->assertSame(SystemRoles::OWNER, $key);

                continue;
            }
            // Permissions of a switched-off module are defined but dormant (kept for when it is switched on).
            $known = [...PermissionRegistry::all(), ...PermissionRegistry::dormant($role['permissions'])];
            $this->assertSame([], array_values(array_diff($role['permissions'], $known)), "Role {$key} has unknown permissions");
        }
    }

    public function test_reception_and_trainer_cannot_touch_money_or_settings(): void
    {
        $roles = SystemRoles::definitions();
        foreach (['expenses.manage', 'payments.refund', 'roles.manage', 'settings.manage', 'backups.manage', 'reports.financial'] as $perm) {
            $this->assertNotContains($perm, $roles[SystemRoles::RECEPTION]['permissions']);
            $this->assertNotContains($perm, $roles[SystemRoles::TRAINER]['permissions']);
        }
    }
}
