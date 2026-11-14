<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Services\BookingService;
use Tests\Concerns\BuildsClubs;
use Tests\Concerns\RunsConcurrently;
use Tests\TestCase;

/**
 * A classes-only plan (#70, «صلاحيات الباقة») at the same moment, in separate processes on PostgreSQL. Its credit is
 * spent once: two bookings at once on its last class leave one booking and the credit used once, and the same class
 * booked twice at once is booked once. The membership row and the class row are read under their locks.
 *
 * plans.manage is the owner's to give and take: an edit of a role or of an account made at the same moment as the
 * owner's never puts back what the owner took off (each reads the row again under its lock before saving).
 */
class PlanPermissionsConcurrencyTest extends TestCase
{
    use BuildsClubs, RunsConcurrently;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpConcurrency();
    }

    /** @return array{Tenant, Member, Membership} */
    private function classesOnly(int $sessions): array
    {
        $club = $this->club();
        $member = $this->member($club);
        $membership = $this->activeMembership($club, $member, $this->plan($club, ['type' => 'classes', 'sessions_limit' => $sessions]));

        return [$club, $member, $membership];
    }

    private function booking(Tenant $club, Member $member, ClassSession $session): \Closure
    {
        return fn () => $this->asTenant($club, fn () => app(BookingService::class)->book($member->fresh(), $session, 'portal')->id);
    }

    public function test_two_bookings_at_once_on_the_last_class_of_the_credit_book_one(): void
    {
        [$club, $member, $membership] = $this->classesOnly(1);
        $first = $this->classSession($club, null, ['starts_at' => now()->addDay()->setTime(18, 0)]);
        $second = $this->classSession($club, null, ['starts_at' => now()->addDays(2)->setTime(18, 0)]);

        $results = $this->concurrently([
            function (string $dir) use ($club, $member, $first) {
                self::pauseAfter('update "gym_memberships" set "sessions_used"', $dir, 1.0); // credit spent, not committed

                return ($this->booking($club, $member, $first))();
            },
            function (string $dir) use ($club, $member, $second) {
                self::waitFor("$dir/inside");

                return ($this->booking($club, $member, $second))();
            },
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertFalse($results[1]['ok'], 'a second booking spent the same credit: '.json_encode($results, JSON_UNESCAPED_UNICODE));
        // Refused by the rule once the first was saved, not by a timeout: it waited on the membership's lock.
        $this->assertStringContainsString('تم استهلاك جميع حصص الباقة.', (string) $results[1]['error']);
        $this->asTenant($club, function () use ($membership) {
            $this->assertSame(1, Booking::where('status', Booking::BOOKED)->count());
            $this->assertSame(1, $membership->fresh()->sessions_used);
        });
    }

    public function test_the_same_class_booked_twice_at_once_is_booked_once(): void
    {
        [$club, $member, $membership] = $this->classesOnly(5);
        $session = $this->classSession($club, null, ['starts_at' => now()->addDay()->setTime(18, 0)]);

        $results = $this->concurrently([
            function (string $dir) use ($club, $member, $session) {
                self::pauseAfter('insert into "gym_bookings"', $dir, 1.0); // booked, not committed

                return ($this->booking($club, $member, $session))();
            },
            function (string $dir) use ($club, $member, $session) {
                self::waitFor("$dir/inside");

                return ($this->booking($club, $member, $session))();
            },
        ]);

        $this->assertTrue($results[0]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertFalse($results[1]['ok'], 'the class was booked twice: '.json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertStringContainsString('العضو محجوز في هذه الحصة بالفعل.', (string) $results[1]['error']);
        $this->asTenant($club, function () use ($membership) {
            $this->assertSame(1, Booking::count());
            $this->assertSame(1, $membership->fresh()->sessions_used);
        });
    }

    public function test_an_edit_of_an_account_at_the_same_moment_never_puts_back_plans_the_owner_took_off_it(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $manager = $this->staff($club, 'manager');
        $colleague = $this->staff($club, 'manager');
        // The owner gave the plans to the manager role, and to the colleague's own list.
        $role = $this->asTenant($club, fn () => Role::where('key', 'manager')->sole());
        $this->asTenant($club, fn () => $role->update(['permissions' => [...$role->permissions, 'plans.manage']]));
        $list = $role->fresh()->permissions;
        $colleague->forceFill(['custom_permissions' => $list])->save();
        $form = fn (User $u, array $permissions) => ['name' => $u->name, 'email' => $u->email, 'role_id' => $u->role_id, 'is_active' => true, 'custom' => true, 'permissions' => $permissions];

        $results = $this->concurrently([
            // The manager saves the colleague's list as the screen showed it, slowed down once the account was read.
            function (string $dir) use ($manager, $colleague, $list, $form) {
                self::pauseAfter('select count(*) as "aggregate" from "users" where "email"', $dir, 1.0);

                return $this->actingAs($manager->fresh())->put(route('club.users.update', $colleague), $form($colleague, $list))->getStatusCode();
            },
            // Meanwhile the owner takes the plans off the colleague's list.
            function (string $dir) use ($owner, $colleague, $list, $form) {
                self::waitFor("$dir/inside");

                return $this->actingAs($owner->fresh())->put(route('club.users.update', $colleague), $form($colleague, array_values(array_diff($list, ['plans.manage']))))->getStatusCode();
            },
        ]);

        $this->assertSame([302, 302], array_column($results, 'value'), json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertNotContains('plans.manage', $colleague->fresh()->custom_permissions, 'the manager\'s save put back what the owner took off');
        $this->assertFalse($colleague->fresh()->hasPermission('plans.manage'));
    }

    public function test_an_edit_of_a_role_at_the_same_moment_never_puts_back_plans_the_owner_took_off_it(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $manager = $this->staff($club, 'manager');
        // The owner lets the manager edit roles, and made a role that edits plans.
        $role = $this->asTenant($club, fn () => Role::where('key', 'manager')->sole());
        $this->asTenant($club, fn () => $role->update(['permissions' => [...$role->permissions, 'roles.manage']]));
        $planners = $this->asTenant($club, fn () => Role::create(['key' => 'custom_planners', 'name' => 'Planners', 'permissions' => ['plans.manage', 'members.view'], 'is_system' => false]));

        $results = $this->concurrently([
            // The manager adds a permission of their own to the role, the rest as the screen showed it, slowed down once
            // the role was read.
            function (string $dir) use ($manager, $planners) {
                self::pauseAfter('select * from "roles" where "id" = ?', $dir, 1.0);

                return $this->actingAs($manager->fresh())->put(route('club.roles.update', $planners), ['name' => 'Planners', 'permissions' => ['plans.manage', 'members.view', 'members.manage']])->getStatusCode();
            },
            // Meanwhile the owner takes the plans off the role.
            function (string $dir) use ($owner, $planners) {
                self::waitFor("$dir/inside");

                return $this->actingAs($owner->fresh())->put(route('club.roles.update', $planners), ['name' => 'Planners', 'permissions' => ['members.view']])->getStatusCode();
            },
        ]);

        $this->assertSame([302, 302], array_column($results, 'value'), json_encode($results, JSON_UNESCAPED_UNICODE));
        // The manager's own addition stands; the plans stay off.
        $this->assertEqualsCanonicalizing(['members.view', 'members.manage'], $this->asTenant($club, fn () => $planners->fresh()->permissions), 'the manager\'s save put back what the owner took off');
    }
}
