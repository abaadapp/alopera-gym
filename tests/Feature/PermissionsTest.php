<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Modules\Gym\Models\Trainer;
use App\Modules\Gym\Services\BookingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Role matrix (spec 1.10 / acceptance "test every role"): permissions are
 * enforced on the server, not by hiding buttons. See docs/PERMISSIONS.md.
 */
class PermissionsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** @return array<string, array{string, string, int}> */
    public static function matrix(): array
    {
        return [
            // role, route name, expected status
            'owner sees settings' => ['owner', 'club.settings.edit', 200],
            'owner opens the settings hub' => ['owner', 'club.settings.index', 200],
            'owner sees notification rules' => ['owner', 'club.settings.notifications', 200],
            'owner sees automation' => ['owner', 'club.automation.index', 200],
            'manager opens the settings hub' => ['manager', 'club.settings.index', 200],
            'the owner opens the point of sale' => ['owner', 'club.pos.index', 200],
            'the owner manages products' => ['owner', 'club.products.index', 200],
            'the owner opens the sales summary' => ['owner', 'club.sales.summary', 200],
            'the owner opens the business documents' => ['owner', 'club.business-documents.index', 200],
            'the manager opens the business documents' => ['manager', 'club.business-documents.index', 200],
            'reception cannot open the business documents' => ['reception', 'club.business-documents.index', 403],
            'a trainer cannot open the point of sale' => ['trainer', 'club.pos.index', 403],
            'finance follows sales' => ['finance', 'club.sales.index', 200],
            'reception handles website orders' => ['reception', 'club.sales.orders.index', 200],
            'reception does not see sales figures' => ['reception', 'club.sales.summary', 403],
            'sales settings stay with settings managers' => ['reception', 'club.settings.sales', 403],
            'the management sees purchase orders' => ['manager', 'club.purchases.index', 200],
            'reception has no inventory' => ['reception', 'club.inventory.index', 403],
            'finance sees purchases' => ['finance', 'club.purchases.bills', 200],
            'owner sees backups' => ['owner', 'club.backups.index', 200],
            'manager sees members' => ['manager', 'club.members.index', 200],
            'manager cannot edit roles' => ['manager', 'club.roles.index', 403],
            'manager cannot manage backups' => ['manager', 'club.backups.index', 403],
            'reception has its screen' => ['reception', 'club.reception', 200],
            'reception sees members' => ['reception', 'club.members.index', 200],
            'reception sells at the point of sale' => ['reception', 'club.pos.index', 200],
            'reception cannot see expenses' => ['reception', 'club.expenses.index', 403],
            'reception opens the reports of what it works with' => ['reception', 'club.reports.index', 200],
            'reception cannot open the money report' => ['reception', 'club.reports.show', 403, 'finance'],
            'reception cannot open the payroll report' => ['reception', 'club.reports.show', 403, 'payroll'],
            'trainers have no reports' => ['trainer', 'club.reports.index', 403],
            'reception cannot see dues' => ['reception', 'club.dues', 403],
            'reception cannot open settings' => ['reception', 'club.settings.edit', 403],
            'reception cannot open the settings hub' => ['reception', 'club.settings.index', 403],
            'reception cannot open automation' => ['reception', 'club.automation.index', 403],
            'reception cannot manage users' => ['reception', 'club.users.index', 403],
            'reception cannot see audit log' => ['reception', 'club.audit.index', 403],
            'finance sees invoices' => ['finance', 'club.invoices.index', 200],
            'finance sees expenses' => ['finance', 'club.expenses.index', 200],
            'finance sees reports' => ['finance', 'club.reports.index', 200],
            'finance cannot manage devices' => ['finance', 'club.devices.index', 403],
            'finance cannot edit timetable screen of programs' => ['finance', 'club.programs.index', 403],
            'finance cannot open integrations' => ['finance', 'club.integrations.index', 403],
            'finance opens the settings hub for its financial settings' => ['finance', 'club.settings.index', 200],
            'finance cannot open the club settings' => ['finance', 'club.settings.edit', 403],
            'finance opens the financial settings' => ['finance', 'club.settings.finance', 200],
            'manager cannot open the financial settings' => ['manager', 'club.settings.finance', 403],
            'reception cannot open the books' => ['reception', 'club.finance.summary', 403],
            'finance opens the books' => ['finance', 'club.finance.journal', 200],
            'manager prepares payroll' => ['manager', 'club.payroll.index', 200],
            'trainer cannot open payroll' => ['trainer', 'club.payroll.index', 403],
            'trainer has her screen' => ['trainer', 'club.trainer', 200],
            'trainer sees timetable' => ['trainer', 'club.schedule.index', 200],
            'trainer cannot list members' => ['trainer', 'club.members.index', 403],
            'trainer cannot see invoices' => ['trainer', 'club.invoices.index', 403],
            'trainer cannot open the settings hub' => ['trainer', 'club.settings.index', 403],
            'trainer is sent from the dashboard to her screen' => ['trainer', 'club.dashboard', 302],
        ];
    }

    #[DataProvider('matrix')]
    public function test_role_matrix(string $role, string $route, int $status, ?string $param = null): void
    {
        $club = $this->club();
        $user = $role === 'owner' ? $this->owner($club) : $this->staff($club, $role);

        $this->actingAs($user)->get(route($route, $param ?? []))->assertStatus($status);
    }

    public function test_signed_in_users_opening_a_login_page_go_to_their_own_home(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->actingAs($owner)->get(route('login'))->assertRedirect(route($owner->homeRoute()));

        $member = $this->member($club, ['password' => 'password1']);
        $this->actingAs($member, 'member')->get(route('portal.login', ['club' => $club->slug]))
            ->assertRedirect(route('portal.dashboard', ['club' => $club->slug]));
    }

    public function test_trainer_can_open_only_her_own_class_rosters(): void
    {
        $club = $this->club();
        $mine = $this->staff($club, 'trainer');
        $other = $this->staff($club, 'trainer');
        [$myTrainer, $otherTrainer] = $this->asTenant($club, fn () => [
            Trainer::create(['name' => 'Mine', 'user_id' => $mine->id]),
            Trainer::create(['name' => 'Other', 'user_id' => $other->id]),
        ]);
        $mySession = $this->classSession($club, null, ['trainer_id' => $myTrainer->id]);
        $otherSession = $this->classSession($club, null, ['trainer_id' => $otherTrainer->id, 'starts_at' => now()->addDays(2)]);

        $this->actingAs($mine)->get(route('club.sessions.show', $mySession))->assertOk();
        $this->actingAs($mine)->get(route('club.sessions.show', $otherSession))->assertForbidden();
        $this->actingAs($this->owner($club))->get(route('club.sessions.show', $otherSession))->assertOk();
    }

    public function test_reception_cannot_post_to_money_endpoints_it_has_no_permission_for(): void
    {
        $club = $this->club();
        $membership = $this->activeMembership($club, $this->member($club));
        $reception = $this->staff($club, 'reception');
        $this->actingAs($reception)->post(route('club.credit-notes.store', $membership->invoice_id), ['amount' => 1, 'reason' => 'x', 'refund_now' => true, 'method' => 'cash'])->assertForbidden();
        $this->actingAs($reception)->post(route('club.invoices.void', $membership->invoice_id), ['reason' => 'x'])->assertForbidden();
        $this->actingAs($reception)->post(route('club.expenses.store'), ['description' => 'x', 'amount' => 5, 'expense_date' => today()->toDateString(), 'payment_method' => 'cash'])->assertForbidden();
        $this->actingAs($reception)->post(route('club.memberships.cancel', $membership->id), ['reason' => 'x'])->assertForbidden();
    }

    public function test_manual_discount_requires_plans_permission(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club);

        $this->actingAs($this->staff($club, 'reception'))
            ->post(route('club.memberships.store', $member->id), ['membership_plan_id' => $plan->id, 'mode' => 'new', 'discount' => 10])
            ->assertSessionHasErrors('discount');
    }

    public function test_custom_role_grants_exactly_its_permissions(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);

        $this->actingAs($owner)->post(route('club.roles.store'), [
            'name' => 'Viewer', 'permissions' => ['members.view', 'not.a.permission'],
        ])->assertSessionHasErrors('permissions.1');

        $this->actingAs($owner)->post(route('club.roles.store'), ['name' => 'Viewer', 'permissions' => ['members.view']])->assertSessionHasNoErrors();
        $role = $this->asTenant($club, fn () => Role::where('name', 'Viewer')->firstOrFail());

        $user = $this->staff($club, $role->key);
        $this->actingAs($user)->get(route('club.members.index'))->assertOk();
        $this->actingAs($user)->get(route('club.members.create'))->assertForbidden();
        $this->actingAs($user)->get(route('club.invoices.index'))->assertForbidden();
    }

    public function test_owner_role_cannot_be_changed_and_last_owner_cannot_be_demoted(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $ownerRole = $owner->role;
        $receptionRole = $this->asTenant($club, fn () => Role::where('key', 'reception')->first());

        $this->actingAs($owner)->put(route('club.roles.update', $ownerRole->id), ['name' => 'x', 'permissions' => []]);
        $this->assertSame(['*'], $ownerRole->fresh()->permissions);

        $this->actingAs($owner)->put(route('club.users.update', $owner->id), ['name' => $owner->name, 'email' => $owner->email, 'role_id' => $receptionRole->id]);
        $this->assertSame($ownerRole->id, $owner->fresh()->role_id);
    }

    public function test_trainer_marks_attendance_only_for_her_own_classes(): void
    {
        $club = $this->club();
        $mine = $this->staff($club, 'trainer');
        $other = $this->staff($club, 'trainer');
        [$myTrainer, $otherTrainer] = $this->asTenant($club, fn () => [
            Trainer::create(['name' => 'Mine', 'user_id' => $mine->id]),
            Trainer::create(['name' => 'Other', 'user_id' => $other->id]),
        ]);

        $member = $this->member($club);
        $this->activeMembership($club, $member);
        $mySession = $this->classSession($club, null, ['trainer_id' => $myTrainer->id, 'starts_at' => now()->addMinutes(10)]);
        $otherSession = $this->classSession($club, null, ['trainer_id' => $otherTrainer->id, 'starts_at' => now()->addMinutes(10)->addHours(2)]);

        [$myBooking, $otherBooking] = $this->asTenant($club, fn () => [
            app(BookingService::class)->book($member, $mySession),
            app(BookingService::class)->book($member, $otherSession),
        ]);

        $this->actingAs($mine)->post(route('club.bookings.attendance', $myBooking->id), ['status' => 'attended'])->assertSessionHasNoErrors();
        $this->actingAs($mine)->post(route('club.bookings.attendance', $otherBooking->id), ['status' => 'attended'])->assertForbidden();
        $this->actingAs($mine)->get(route('club.trainer.session', $otherSession->id))->assertForbidden();
    }

    public function test_an_account_that_belongs_to_no_gym_opens_nothing(): void
    {
        $this->club();
        // An old platform account (no gym): signed out of the panel, never shown a gym's records.
        $admin = User::factory()->platformAdmin()->create();

        $this->actingAs($admin)->get(route('club.members.index'))->assertRedirect(route('login'));
        $this->assertGuest();
        $this->get('/platform')->assertNotFound();
    }

    public function test_deactivated_staff_lose_access_immediately(): void
    {
        $club = $this->club();
        $reception = $this->staff($club, 'reception');
        $reception->forceFill(['is_active' => false])->save();

        $this->actingAs($reception)->get(route('club.reception'))->assertRedirect(route('login'));
    }
}
