<?php

namespace Tests\Feature;

use App\Core\Auth\Grants;
use App\Core\Support\BusinessRuleException;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Access\AccessControlService;
use App\Modules\Gym\Access\AccessDecision;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Services\BookingService;
use App\Modules\Gym\Services\MembershipService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * «صلاحيات الباقة»: the owner sets, on each plan, whether it opens the door and whether it books classes. The two
 * switches are stored as the plan's type (gym, classes, combo), which every sale freezes into plan_snapshot (#70), so
 * the door and the bookings follow what was sold, not what the plan says today. Every plan here is made through the
 * panel, as the owner makes it.
 */
class PlanPermissionsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Morning, so this evening's class has not started whenever the tests run.
        $this->travelTo(now()->setTime(9, 0));
    }

    /** @param array<string, mixed> $extra */
    private function planFromPanel(Tenant $club, User $by, bool $gym, bool $classes, array $extra = []): MembershipPlan
    {
        $name = $extra['name'] ?? 'Plan '.Str::random(5);
        $this->actingAs($by)->post(route('club.plans.store'), $extra + [
            'name' => $name, 'allows_gym' => $gym, 'allows_classes' => $classes,
            'duration_value' => 1, 'duration_unit' => 'month', 'price' => 20, 'is_active' => true, 'is_public' => true,
        ])->assertSessionHasNoErrors();

        return $this->asTenant($club, fn () => MembershipPlan::where('name', $name)->firstOrFail());
    }

    /** @param array<string, mixed> $changes */
    private function editInPanel(User $by, MembershipPlan $plan, array $changes): void
    {
        $this->actingAs($by)->put(route('club.plans.update', $plan), $changes + [
            'name' => $plan->name, 'allows_gym' => $plan->allowsGym(), 'allows_classes' => $plan->allowsClasses(),
            'duration_value' => $plan->duration_value, 'duration_unit' => $plan->duration_unit, 'price' => $plan->price,
            'sessions_limit' => $plan->sessions_limit, 'is_active' => true, 'is_public' => true,
        ])->assertSessionHasNoErrors();
    }

    private function door(Tenant $club, Member $member): AccessDecision
    {
        return $this->asTenant($club, fn () => app(AccessControlService::class)->evaluate($member->fresh()));
    }

    private function book(Tenant $club, Member $member, ClassSession $session, string $source = 'portal'): Booking
    {
        return $this->asTenant($club, fn () => app(BookingService::class)->book($member->fresh(), $session, $source));
    }

    private function refused(Tenant $club, Member $member, ClassSession $session, string $source = 'portal'): string
    {
        try {
            $this->book($club, $member, $session, $source);
        } catch (BusinessRuleException $e) {
            return $e->rule;
        }
        $this->fail("a $source booking went through");
    }

    private function tonight(Tenant $club, array $attributes = []): ClassSession
    {
        return $this->classSession($club, null, $attributes + ['starts_at' => today()->setTime(18, 0)]);
    }

    // 1–3. The three plans the owner can make, and what is stored.
    public function test_the_owner_makes_a_door_only_a_classes_only_and_a_full_plan(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $yoga = $this->program($club, ['name' => 'Yoga']);

        $door = $this->planFromPanel($club, $owner, true, false, ['sessions_limit' => 8, 'program_ids' => [$yoga->id]]);
        $classes = $this->planFromPanel($club, $owner, false, true, ['sessions_limit' => 8, 'program_ids' => [$yoga->id]]);
        $both = $this->planFromPanel($club, $owner, true, true);

        $this->assertSame(['gym', true, false], [$door->type, $door->allowsGym(), $door->allowsClasses()]);
        $this->assertNull($door->sessions_limit, 'a plan without classes keeps no sessions count');
        $this->assertSame([], $this->asTenant($club, fn () => $door->programs()->pluck('gym_programs.id')->all()), 'nor a programs list');
        $this->assertSame(['classes', false, true, 8], [$classes->type, $classes->allowsGym(), $classes->allowsClasses(), $classes->sessions_limit]);
        $this->assertSame([$yoga->id], $this->asTenant($club, fn () => $classes->programs()->pluck('gym_programs.id')->all()));
        $this->assertSame(['combo', true, true], [$both->type, $both->allowsGym(), $both->allowsClasses()]);

        // The panel shows each plan's switches, and how many sold periods are still running on it.
        $this->actingAs($owner)->get(route('club.plans.index'))->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->where('plans.0.allows_gym', true)->where('plans.0.allows_classes', false)->where('plans.0.live_count', 0)
            ->where('plans.1.allows_gym', false)->where('plans.1.allows_classes', true)
            ->where('plans.2.allows_gym', true)->where('plans.2.allows_classes', true)
            ->missing('types'));
    }

    // 4. Neither, or not chosen at all: refused, and nothing is made.
    public function test_a_plan_with_no_permission_or_without_choosing_is_refused(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $base = ['name' => 'Empty', 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 10];

        $this->actingAs($owner)->post(route('club.plans.store'), $base + ['allows_gym' => false, 'allows_classes' => false])
            ->assertSessionHasErrors(['access' => 'اختر صلاحية واحدة على الأقل: دخول النادي أو حجز الحصص.']);
        $this->actingAs($owner)->post(route('club.plans.store'), $base)->assertSessionHasErrors(['allows_gym', 'allows_classes']);
        // The old field alone chooses nothing.
        $this->actingAs($owner)->post(route('club.plans.store'), $base + ['type' => 'combo'])->assertSessionHasErrors(['allows_gym', 'allows_classes']);

        $plan = $this->planFromPanel($club, $owner, true, true);
        $this->actingAs($owner)->put(route('club.plans.update', $plan), $base + ['allows_gym' => false, 'allows_classes' => false])
            ->assertSessionHasErrors('access');

        $this->assertSame(1, $this->asTenant($club, fn () => MembershipPlan::count()));
        $this->assertSame('combo', $this->asTenant($club, fn () => $plan->fresh()->type), 'a refused edit changes nothing');
    }

    // 5–6. Door only: the door any day, no class from the portal, the website or the desk.
    public function test_a_door_only_plan_opens_the_door_and_books_no_class_anywhere(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club, ['password' => 'secret123']);
        $this->activeMembership($club, $member, $this->planFromPanel($club, $owner, true, false));
        $session = $this->tonight($club);

        $this->assertTrue($this->door($club, $member)->allowed);
        foreach (['portal', 'website', 'reception'] as $source) {
            $this->assertSame('membership.gym_only', $this->refused($club, $member, $session, $source));
        }
        $this->actingAs($member, 'member')->post(route('portal.classes.book', ['club' => $club->slug, 'session' => $session->id]))
            ->assertSessionHasErrors(['booking' => 'باقة العضو لدخول النادي فقط، ولا تشمل الحصص.']);
        $this->actingAs($owner)->post(route('club.bookings.store', $session), ['member_id' => $member->id])->assertSessionHasErrors('booking');
        $this->assertSame(0, $this->asTenant($club, fn () => Booking::count()));

        // Her portal says so before she tries.
        $this->actingAs($member, 'member')->get(route('portal.classes', ['club' => $club->slug]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('membership.books_classes', false)->where('membership.opens_door_any_day', true));
    }

    // 7–9. Classes only books; its door opens on the day of a class booked here, not on another day.
    public function test_classes_only_and_full_plans_book_and_classes_only_enters_on_a_booked_day_only(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $classesOnly = $this->member($club);
        $full = $this->member($club);
        $this->activeMembership($club, $classesOnly, $this->planFromPanel($club, $owner, false, true));
        $this->activeMembership($club, $full, $this->planFromPanel($club, $owner, true, true));

        $this->assertSame('classes_without_booking', $this->door($club, $classesOnly)->reason, 'no class booked today');
        $this->assertTrue($this->door($club, $full)->allowed, 'a full plan, any day');

        $this->book($club, $classesOnly, $this->classSession($club, null, ['starts_at' => today()->addDay()->setTime(18, 0)]));
        $this->assertFalse($this->door($club, $classesOnly)->allowed, "tomorrow's class does not open today's door");
        $booking = $this->book($club, $classesOnly, $this->tonight($club));
        $this->assertSame(Booking::BOOKED, $booking->status);
        $this->assertTrue($this->door($club, $classesOnly)->allowed, "tonight's class opens today's door");
        $this->assertSame(Booking::BOOKED, $this->book($club, $full, $this->tonight($club))->status);

        $this->asTenant($club, fn () => app(BookingService::class)->cancel($booking, false));
        $this->assertFalse($this->door($club, $classesOnly)->allowed, 'a cancelled booking opens nothing');
    }

    // 10. Expired or frozen: the door stays shut, whatever the plan allows.
    public function test_an_expired_or_frozen_period_opens_nothing(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $plan = $this->planFromPanel($club, $owner, true, true, ['freeze_allowed' => true, 'max_freeze_days' => 30, 'max_freezes' => 1]);
        $frozen = $this->member($club);
        $expired = $this->member($club);
        $f = $this->activeMembership($club, $frozen, $plan);
        $e = $this->activeMembership($club, $expired, $plan);
        $this->asTenant($club, fn () => app(MembershipService::class)->freeze($f->fresh(), null, 'سفر'));

        $this->assertSame('membership_frozen', $this->door($club, $frozen)->reason);
        $this->assertSame('membership.frozen', $this->refused($club, $frozen, $this->tonight($club)));
        $this->travelTo($e->ends_on->addDay()->setTime(9, 0));
        $this->assertFalse($this->door($club, $expired)->allowed);
        $this->assertSame('membership.none', $this->refused($club, $expired, $this->tonight($club)));
    }

    // 11, 12, 16. An edit changes new sales and renewals only: what was sold, its dates, credit, bookings and money stay.
    public function test_an_edit_leaves_sold_periods_as_sold_and_applies_to_new_sales_and_renewals(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $plan = $this->planFromPanel($club, $owner, true, true, ['sessions_limit' => 10]);
        $old = $this->member($club);
        $sold = $this->activeMembership($club, $old, $plan);
        $this->book($club, $old, $this->tonight($club));
        $before = $this->asTenant($club, fn () => [
            'period' => Membership::find($sold->id)->only('status', 'starts_on', 'ends_on', 'plan_snapshot', 'sessions_total', 'sessions_used', 'total'),
            'invoices' => Invoice::orderBy('id')->get(['id', 'total', 'paid_total', 'status'])->toArray(),
            'bookings' => Booking::orderBy('id')->get(['id', 'status', 'membership_id', 'consumed_session'])->toArray(),
        ]);

        // The owner turns the classes off: the plan is door only from now on.
        $this->editInPanel($owner, $plan, ['allows_classes' => false]);
        $this->assertSame('gym', $this->asTenant($club, fn () => $plan->fresh()->type));

        // What was sold: unchanged, and still books and opens the door.
        $this->assertEquals($before, $this->asTenant($club, fn () => [
            'period' => Membership::find($sold->id)->only('status', 'starts_on', 'ends_on', 'plan_snapshot', 'sessions_total', 'sessions_used', 'total'),
            'invoices' => Invoice::orderBy('id')->get(['id', 'total', 'paid_total', 'status'])->toArray(),
            'bookings' => Booking::orderBy('id')->get(['id', 'status', 'membership_id', 'consumed_session'])->toArray(),
        ]));
        $this->assertSame(Booking::BOOKED, $this->book($club, $old, $this->tonight($club, ['starts_at' => today()->setTime(19, 0)]))->status);
        $this->assertTrue($this->door($club, $old)->allowed);

        // A new sale: door only.
        $new = $this->member($club);
        $this->activeMembership($club, $new, $plan->fresh());
        $this->assertSame('membership.gym_only', $this->refused($club, $new, $this->tonight($club)));

        // Her renewal is sold with the plan as it is now: door only from its first day.
        $renewal = $this->asTenant($club, function () use ($old) {
            $renewal = app(MembershipService::class)->renew($old->fresh());
            app(PaymentService::class)->record($renewal->invoice()->firstOrFail(), $renewal->total, 'cash');

            return $renewal->fresh();
        });
        $this->assertSame('gym', $renewal->plan_snapshot['type']);
        $this->assertFalse($renewal->booksClasses());
        $this->assertTrue($renewal->opensDoorAnyDay());
    }

    // 13. Only the owner, or whoever the owner grants plans.manage to by name, edits plans; nothing sent with the form adds a permission.
    public function test_permissions_are_set_on_the_server_by_the_owner_or_whoever_the_owner_grants_it_to(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $reception = $this->staff($club, 'reception');
        $manager = $this->staff($club, 'manager');
        $plan = $this->planFromPanel($club, $owner, true, false);
        $form = ['name' => 'X', 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 5, 'allows_gym' => true, 'allows_classes' => true];

        // Reception, and a manager the owner has not granted the plans to: refused, nothing changed.
        foreach (['reception' => $reception, 'manager' => $manager] as $who => $staff) {
            $this->actingAs($staff)->get(route('club.plans.index'))->assertForbidden();
            $this->actingAs($staff)->post(route('club.plans.store'), $form)->assertForbidden();
            $this->actingAs($staff)->put(route('club.plans.update', $plan), $form)->assertForbidden();
            $this->assertFalse($staff->hasPermission('plans.manage'), $who);
        }
        $this->assertSame(['gym'], $this->asTenant($club, fn () => MembershipPlan::pluck('type')->all()));

        // A type sent alongside is not read: the switches decide.
        $this->actingAs($owner)->put(route('club.plans.update', $plan), ['type' => 'combo', 'allows_classes' => false, 'name' => $plan->name] + $form)
            ->assertSessionHasNoErrors();
        $this->assertSame('gym', $this->asTenant($club, fn () => $plan->fresh()->type));

        // The owner grants it to the manager role by name (the roles screen): from then on the manager may.
        $role = $this->asTenant($club, fn () => Role::where('key', 'manager')->sole());
        $this->assertNotContains('plans.manage', $role->permissions);
        $this->actingAs($owner)->put(route('club.roles.update', $role), ['name' => $role->name, 'permissions' => [...$role->permissions, 'plans.manage']])
            ->assertSessionHasNoErrors();
        $this->planFromPanel($club, $manager->fresh(), false, true);
        $this->assertSame(['gym', 'classes'], $this->asTenant($club, fn () => MembershipPlan::orderBy('id')->pluck('type')->all()));

        // Holding it, the manager still cannot hand it on: what the roles and accounts screens would store is without it.
        $holder = $manager->fresh();
        $this->assertTrue($holder->hasPermission('users.manage'));
        $this->assertFalse(Grants::mayGive($holder, ['plans.manage']));
        $this->assertSame([], Grants::merge($holder, [], ['plans.manage']));
        $this->assertSame(['plans.manage'], Grants::merge($owner, [], ['plans.manage']));
    }

    // 13. A club set up before: its manager role was stored with plans.manage. It stays until the owner takes it away,
    // the manager cannot give it to anyone (themselves included), and the owner taking it away holds from the next request.
    public function test_a_manager_who_held_the_plans_before_keeps_them_until_the_owner_takes_them_away(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $manager = $this->staff($club, 'manager');
        $reception = $this->staff($club, 'reception');
        $plan = $this->planFromPanel($club, $owner, true, true);
        $role = $this->asTenant($club, fn () => Role::where('key', 'manager')->sole());
        $this->asTenant($club, fn () => $role->update(['permissions' => [...$role->permissions, 'plans.manage']]));
        $roleBefore = $role->fresh()->permissions;
        $ownerBefore = $owner->fresh()->permissions();

        // Nothing rewrites what is stored: the manager still edits plans.
        $this->editInPanel($manager->fresh(), $plan, ['allows_gym' => false]);
        $this->assertSame('classes', $this->asTenant($club, fn () => $plan->fresh()->type));

        // Holding it, the manager gives it to nobody: not to a role, not to their own list or role, not to a colleague.
        $account = fn (User $u) => ['name' => $u->name, 'email' => $u->email, 'role_id' => $u->role_id, 'is_active' => true];
        $this->actingAs($manager->fresh())->put(route('club.roles.update', $role), ['name' => $role->name, 'permissions' => $roleBefore])->assertForbidden();
        $this->actingAs($manager->fresh())->put(route('club.users.update', $manager), $account($manager) + ['custom' => true, 'permissions' => $roleBefore])->assertForbidden();
        $this->actingAs($manager->fresh())->put(route('club.users.update', $manager), ['role_id' => $this->asTenant($club, fn () => Role::where('key', 'owner')->value('id'))] + $account($manager))
            ->assertSessionHasErrors('role_id');
        $this->actingAs($manager->fresh())->put(route('club.users.update', $reception), $account($reception) + ['custom' => true, 'permissions' => [...$reception->permissions(), 'plans.manage']])
            ->assertSessionHasNoErrors();
        $this->assertFalse($reception->fresh()->hasPermission('plans.manage'));
        $this->actingAs($reception->fresh())->get(route('club.plans.index'))->assertForbidden();

        // The owner takes it off the manager role in the roles screen: only that permission goes, the owner keeps everything.
        $this->actingAs($owner)->put(route('club.roles.update', $role), ['name' => $role->name, 'permissions' => array_values(array_diff($roleBefore, ['plans.manage']))])
            ->assertSessionHasNoErrors();
        $this->assertEqualsCanonicalizing(array_values(array_diff($roleBefore, ['plans.manage'])), $role->fresh()->permissions);
        $this->assertSame($ownerBefore, $owner->fresh()->permissions());

        // The manager's next request (the account read again, as every request does) is refused, and nothing changes.
        $form = ['name' => $plan->name, 'allows_gym' => true, 'allows_classes' => true, 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 20];
        $this->actingAs($manager->fresh())->get(route('club.plans.index'))->assertForbidden();
        $this->actingAs($manager->fresh())->put(route('club.plans.update', $plan), $form)->assertForbidden();
        $this->actingAs($manager->fresh())->post(route('club.plans.store'), $form)->assertForbidden();
        $this->assertSame('classes', $this->asTenant($club, fn () => $plan->fresh()->type));
        $this->assertSame(1, $this->asTenant($club, fn () => MembershipPlan::count()));

        // The owner still edits plans, and can give it back the same way.
        $this->editInPanel($owner->fresh(), $plan, ['allows_gym' => true]);
        $this->actingAs($owner)->put(route('club.roles.update', $role), ['name' => $role->name, 'permissions' => $roleBefore])->assertSessionHasNoErrors();
        $this->actingAs($manager->fresh())->get(route('club.plans.index'))->assertOk();
    }

    // 13. A person's own list (it replaces the role's): the owner takes plans.manage off it, and it holds from the next request.
    public function test_the_owner_takes_the_plans_off_a_persons_own_list(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $manager = $this->staff($club, 'manager');
        $mine = [...$manager->permissions(), 'plans.manage'];
        $manager->forceFill(['custom_permissions' => $mine])->save();
        $this->actingAs($manager->fresh())->get(route('club.plans.index'))->assertOk();

        $this->actingAs($owner)->put(route('club.users.update', $manager), ['name' => $manager->name, 'email' => $manager->email, 'role_id' => $manager->role_id,
            'is_active' => true, 'custom' => true, 'permissions' => array_values(array_diff($mine, ['plans.manage']))])->assertSessionHasNoErrors();

        $this->assertNotContains('plans.manage', $manager->fresh()->custom_permissions);
        $this->actingAs($manager->fresh())->get(route('club.plans.index'))->assertForbidden();
        $this->assertTrue($owner->fresh()->hasPermission('plans.manage'));
    }

    // 13. A list of a person's own replaces the role's. Only the owner starts one for an account whose role carries what
    // only the owner decides: otherwise a manager could copy plans.manage into a colleague's list, past the owner taking
    // it off the role.
    public function test_a_manager_cannot_copy_the_plans_into_a_colleagues_own_list(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $manager = $this->staff($club, 'manager');
        $colleague = $this->staff($club, 'manager');
        $role = $this->asTenant($club, fn () => Role::where('key', 'manager')->sole());
        $this->asTenant($club, fn () => $role->update(['permissions' => [...$role->permissions, 'plans.manage']]));
        $list = $role->fresh()->permissions;
        $form = fn (User $u, array $permissions) => ['name' => $u->name, 'email' => $u->email, 'role_id' => $u->role_id, 'is_active' => true, 'custom' => true, 'permissions' => $permissions];

        // With the plans or without them, the manager starts no list for the colleague.
        foreach ([$list, array_values(array_diff($list, ['plans.manage']))] as $permissions) {
            $this->actingAs($manager->fresh())->put(route('club.users.update', $colleague), $form($colleague, $permissions))->assertSessionHasErrors('custom');
            $this->assertNull($colleague->fresh()->custom_permissions);
        }

        // So the owner taking the plans off the role reaches the colleague at once.
        $this->actingAs($owner)->put(route('club.roles.update', $role), ['name' => $role->name, 'permissions' => array_values(array_diff($list, ['plans.manage']))])
            ->assertSessionHasNoErrors();
        $this->actingAs($colleague->fresh())->get(route('club.plans.index'))->assertForbidden();
        $this->actingAs($manager->fresh())->get(route('club.plans.index'))->assertForbidden();

        // The owner starts one, and decides what is on it.
        $this->actingAs($owner)->put(route('club.users.update', $colleague), $form($colleague, $list))->assertSessionHasNoErrors();
        $this->actingAs($colleague->fresh())->get(route('club.plans.index'))->assertOk();
    }

    // 4 (owner's choice). The owner gives plans.manage to whoever the owner chooses: a role, or one person's own list.
    public function test_the_owner_gives_the_plans_to_any_member_of_staff(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $reception = $this->staff($club, 'reception');
        $cashier = $this->staff($club, 'cashier');
        $plan = $this->planFromPanel($club, $owner, true, false);

        $this->actingAs($owner)->put(route('club.users.update', $reception), ['name' => $reception->name, 'email' => $reception->email, 'role_id' => $reception->role_id,
            'is_active' => true, 'custom' => true, 'permissions' => [...$reception->permissions(), 'plans.manage']])->assertSessionHasNoErrors();
        $this->editInPanel($reception->fresh(), $plan, ['allows_classes' => true]);
        $this->assertSame('combo', $this->asTenant($club, fn () => $plan->fresh()->type));

        $cashiers = $this->asTenant($club, fn () => Role::where('key', 'cashier')->sole());
        $this->actingAs($owner)->put(route('club.roles.update', $cashiers), ['name' => $cashiers->name, 'permissions' => [...$cashiers->permissions, 'plans.manage']])
            ->assertSessionHasNoErrors();
        $this->actingAs($cashier->fresh())->get(route('club.plans.index'))->assertOk();
    }

    // 14. One club's owner or plans holder reaches nothing of another club's: its roles, accounts or plans.
    public function test_plans_permissions_stay_inside_their_club(): void
    {
        $club = $this->club('A');
        $other = $this->club('B');
        $owner = $this->owner($club);
        $theirs = $this->staff($other, 'manager');
        $theirRole = $this->asTenant($other, fn () => Role::where('key', 'manager')->sole());
        $theirPlan = $this->planFromPanel($other, $this->owner($other), true, false);
        $before = $theirRole->permissions;

        $this->actingAs($owner)->put(route('club.roles.update', $theirRole), ['name' => 'X', 'permissions' => [...$before, 'plans.manage']])->assertNotFound();
        $this->actingAs($owner)->put(route('club.users.update', $theirs), ['name' => $theirs->name, 'email' => $theirs->email, 'role_id' => $theirs->role_id,
            'is_active' => true, 'custom' => true, 'permissions' => ['plans.manage']])->assertNotFound();
        $this->assertSame($before, $this->asTenant($other, fn () => $theirRole->fresh()->permissions));
        $this->assertNull($theirs->fresh()->custom_permissions);
        $this->assertFalse($theirs->fresh()->hasPermission('plans.manage'));

        $this->actingAs($owner)->put(route('club.plans.update', $theirPlan), ['name' => 'X', 'allows_gym' => true, 'allows_classes' => true,
            'duration_value' => 1, 'duration_unit' => 'month', 'price' => 1])->assertNotFound();
        $this->assertSame('gym', $this->asTenant($other, fn () => $theirPlan->fresh()->type));
    }

    // 14. A plan belongs to its club: not edited, sold or used in another one.
    public function test_a_plan_of_another_club_is_neither_edited_nor_sold_here(): void
    {
        [$here, $there] = [$this->club(), $this->club('Other')];
        $owner = $this->owner($here);
        $theirs = $this->planFromPanel($there, $this->owner($there), true, true);
        $member = $this->member($here);

        $this->actingAs($owner)->put(route('club.plans.update', $theirs), ['name' => 'X', 'allows_gym' => true, 'allows_classes' => false, 'duration_value' => 1, 'duration_unit' => 'month', 'price' => 1])
            ->assertNotFound();
        $this->actingAs($owner)->post(route('club.memberships.store', $member), ['membership_plan_id' => $theirs->id, 'mode' => 'new'])
            ->assertSessionHasErrors('membership_plan_id');
        $this->assertSame('combo', $this->asTenant($there, fn () => $theirs->fresh()->type));
        $this->assertSame(0, $this->asTenant($here, fn () => Membership::count()));
    }

    // An upgrade to a plan without classes does not carry the old period's class bookings.
    public function test_an_upgrade_to_a_plan_without_classes_releases_the_future_bookings(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $member = $this->member($club);
        $current = $this->activeMembership($club, $member, $this->planFromPanel($club, $owner, true, true, ['sessions_limit' => 10]));
        $booking = $this->book($club, $member, $this->classSession($club, null, ['starts_at' => today()->addDays(3)->setTime(18, 0)]));
        $doorOnly = $this->planFromPanel($club, $owner, true, false, ['price' => 60]);

        $this->asTenant($club, function () use ($current, $doorOnly) {
            $upgrade = app(MembershipService::class)->upgrade($current->fresh(), $doorOnly);
            app(PaymentService::class)->record($upgrade->invoice()->firstOrFail(), $upgrade->total, 'cash');
        });

        $this->asTenant($club, function () use ($booking, $current) {
            $this->assertSame(Booking::CANCELLED, $booking->fresh()->status, 'a door-only plan keeps no class booking');
            $this->assertSame(0, $current->fresh()->sessions_used, 'the session went back to the old period');
        });
    }

    // The day of a class is the club's day, in the club's timezone.
    public function test_a_classes_only_door_reads_the_day_in_the_clubs_timezone(): void
    {
        $club = $this->club();
        $club->forceFill(['timezone' => 'UTC'])->save(); // the app is in Asia/Muscat, four hours ahead
        $owner = $this->owner($club);
        $plan = $this->planFromPanel($club, $owner, false, true);
        [$evening, $early] = [$this->member($club), $this->member($club)];
        $this->activeMembership($club, $evening, $plan);
        $this->activeMembership($club, $early, $plan);
        $day = CarbonImmutable::today()->addDays(5);
        // 20:00 Muscat is 16:00 UTC: the club's same day. 05:00 Muscat the next morning is 01:00 UTC: the club's next day.
        $this->book($club, $evening, $this->classSession($club, null, ['starts_at' => $day->setTime(20, 0)]));
        $this->book($club, $early, $this->classSession($club, null, ['starts_at' => $day->addDay()->setTime(5, 0)]));

        // 02:00 Muscat on the next day is still 22:00 of $day in the club.
        $this->travelTo(CarbonImmutable::parse($day->addDay()->toDateString().' 02:00', 'Asia/Muscat'));
        $this->assertTrue($this->door($club, $evening)->allowed, "the club's day still has her class");
        $this->assertSame('classes_without_booking', $this->door($club, $early)->reason, "her class is on the club's next day");
    }

    // 17. The website and the portal say what a plan gives before it is bought, in Arabic and in English.
    public function test_the_website_shows_each_plans_permissions_in_both_languages(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        // Names and prices that say the opposite: what the site shows follows the switches only.
        $door = $this->planFromPanel($club, $owner, true, false, ['name' => 'حصص غير محدودة', 'name_en' => 'Unlimited classes', 'price' => 99, 'sessions_limit' => 30, 'sort' => 1]);
        $this->planFromPanel($club, $owner, false, true, ['name' => 'دخول كامل للنادي', 'name_en' => 'Full gym access', 'price' => 1, 'sessions_limit' => 8, 'sort' => 2]);
        // A program left linked to the door-only plan from before (the panel now clears it on save) is not listed.
        $yoga = $this->program($club);
        $this->asTenant($club, fn () => $door->programs()->attach($yoga));

        $this->get('/c/'.$club->slug.'/plans')->assertInertia(fn (AssertableInertia $p) => $p->where('plans.0.programs', [])->etc());

        foreach (['/c/'.$club->slug.'/plans', '/c/'.$club->slug.'/en/plans', '/c/'.$club->slug.'/join', '/c/'.$club->slug.'/en/join'] as $url) {
            $english = str_contains($url, '/en/');
            $this->get($url)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
                ->where('locale', $english ? 'en' : 'ar')
                ->where('plans.0.name', $english ? 'Unlimited classes' : 'حصص غير محدودة')
                ->where('plans.0.allows_gym', true)->where('plans.0.allows_classes', false)->where('plans.0.sessions_limit', null)
                ->where('plans.0.type_label', $english ? 'Gym access' : 'دخول النادي')
                ->where('plans.1.allows_gym', false)->where('plans.1.allows_classes', true)->where('plans.1.sessions_limit', 8)
                ->where('plans.1.type_label', $english ? 'Group classes' : 'حصص جماعية'));
        }
    }

    // 6 and 17. The portal says why a booking is refused, in the page's language, and what the membership gives.
    public function test_the_portal_says_why_a_booking_is_refused_in_arabic_and_in_english(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $door = $this->member($club);
        $classes = $this->member($club);
        $this->activeMembership($club, $door, $this->planFromPanel($club, $owner, true, false));
        $this->activeMembership($club, $classes, $this->planFromPanel($club, $owner, false, true, ['sessions_limit' => 1]));
        $first = $this->tonight($club);
        $second = $this->tonight($club, ['starts_at' => today()->setTime(20, 0)]);
        $base = '/c/'.$club->slug;

        foreach (['' => ['باقة العضو لدخول النادي فقط، ولا تشمل الحصص.', 'تم استهلاك جميع حصص الباقة.'],
            '/en' => ['This membership is for the gym only: it does not include classes.', 'All the classes of your plan are used.']] as $lang => [$gymOnly, $used]) {
            $this->actingAs($door, 'member')->post("$base$lang/portal/classes/{$first->id}/book")->assertSessionHasErrors(['booking' => $gymOnly]);
            foreach (['/portal', '/portal/classes'] as $page) {
                $this->actingAs($door, 'member')->get("$base$lang$page")->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
                    ->where('locale', $lang === '' ? 'ar' : 'en')
                    ->where($page === '/portal' ? 'current.books_classes' : 'membership.books_classes', false)
                    ->where($page === '/portal' ? 'current.opens_door_any_day' : 'membership.opens_door_any_day', true));
            }
            $this->actingAs($classes, 'member')->get("$base$lang/portal")->assertInertia(fn (AssertableInertia $p) => $p
                ->where('current.books_classes', true)->where('current.opens_door_any_day', false));
        }

        // The classes-only member books her one class, then the second is refused in both languages.
        $this->actingAs($classes, 'member')->post("$base/portal/classes/{$first->id}/book")->assertSessionHasNoErrors();
        $this->actingAs($classes, 'member')->post("$base/portal/classes/{$second->id}/book")->assertSessionHasErrors(['booking' => 'تم استهلاك جميع حصص الباقة.']);
        $this->actingAs($classes, 'member')->post("$base/en/portal/classes/{$second->id}/book")->assertSessionHasErrors(['booking' => 'All the classes of your plan are used.']);
        $this->assertSame(1, $this->asTenant($club, fn () => Booking::where('status', Booking::BOOKED)->count()));
    }
}
