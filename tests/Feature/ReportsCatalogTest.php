<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Models\PtSession;
use App\Modules\Gym\Models\PtSubscription;
use App\Modules\Gym\Models\Trainer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The reports page: every report the club has, in gym categories, only those the user may open; each card opens a
 * real page. The trainer and branch reports are drawn from the club's own records and export like the others.
 */
class ReportsCatalogTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** @return list<array<string, string>> */
    private function catalog(User $user): array
    {
        $reports = [];
        $this->actingAs($user)->get(route('club.reports.index'))->assertOk()
            ->assertInertia(function ($page) use (&$reports) {
                $page->component('Reports/Index');
                $reports = $page->toArray()['props']['reports'];
            });

        return $reports;
    }

    private function roleWith(Tenant $club, array $permissions): User
    {
        $role = $this->asTenant($club, fn () => Role::create(['key' => 'custom_'.uniqid(), 'name' => 'Custom', 'permissions' => $permissions]));
        $user = $this->staff($club, 'reception');
        $user->forceFill(['role_id' => $role->id])->save();

        return $user;
    }

    public function test_the_owner_sees_every_report_in_gym_categories_and_each_one_opens(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $reports = $this->catalog($owner);

        $this->assertEqualsCanonicalizing(
            ['finance', 'statements', 'movement', 'dues', 'members', 'expiring', 'classes', 'trainers', 'sales', 'payroll', 'payouts', 'checkins', 'staff-attendance', 'documents', 'renewals'],
            array_column($reports, 'key'),
            'one branch: no branch comparison',
        );
        $this->assertEqualsCanonicalizing(['finance', 'members', 'classes', 'training', 'sales', 'payroll', 'attendance', 'documents'], array_values(array_unique(array_column($reports, 'category'))));
        foreach ($reports as $report) {
            $this->assertNotEmpty($report['desc']);
            $this->actingAs($owner)->get($report['href'])->assertOk();
        }

        // A second branch brings the comparison of branches.
        $this->asTenant($club, fn () => Branch::create(['name' => 'فرع الخوض', 'is_active' => true]));
        $this->assertContains('branches', array_column($this->catalog($owner), 'key'));
    }

    public function test_a_user_sees_only_the_reports_they_may_open(): void
    {
        $club = $this->club();
        $keys = array_column($this->catalog($this->roleWith($club, ['reports.view'])), 'key');
        $this->assertEqualsCanonicalizing(['members', 'classes', 'trainers'], $keys);

        // Opening a report beyond them by its address is refused.
        $viewer = $this->roleWith($club, ['reports.view']);
        $this->actingAs($viewer)->get(route('club.reports.show', 'finance'))->assertForbidden();
        $this->actingAs($viewer)->get(route('club.reports.show', 'branches'))->assertNotFound();
        $this->actingAs($this->staff($club, 'reception'))->get(route('club.reports.index'))->assertForbidden();

        $finance = array_column($this->catalog($this->staff($club, 'finance')), 'key');
        $this->assertContains('finance', $finance);
        $this->assertContains('payouts', $finance);
        $this->assertNotContains('documents', $finance, 'finance does not see business documents');
    }

    public function test_an_old_tab_link_opens_the_report_itself(): void
    {
        $club = $this->club();
        $this->actingAs($this->owner($club))->get(route('club.reports.index', ['section' => 'classes', 'preset' => 'week']))
            ->assertRedirect(route('club.reports.show', ['report' => 'classes', 'preset' => 'week']));
    }

    public function test_the_trainer_report_counts_classes_bookings_and_personal_training(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        [$reem, $dana] = $this->asTenant($club, fn () => [Trainer::create(['name' => 'ريم']), Trainer::create(['name' => 'دانة'])]);
        $session = $this->classSession($club, null, ['trainer_id' => $reem->id, 'starts_at' => now()->startOfDay()->addHours(7)]);
        $member = $this->member($club);
        $this->asTenant($club, function () use ($session, $member, $dana) {
            Booking::create(['class_session_id' => $session->id, 'member_id' => $member->id, 'status' => Booking::ATTENDED, 'source' => 'staff']);
            $package = PtPackage::create(['name' => '10 جلسات', 'sessions_count' => 10, 'session_minutes' => 60, 'price' => 100, 'validity_days' => 60]);
            $sub = PtSubscription::create(['member_id' => $member->id, 'pt_package_id' => $package->id, 'trainer_id' => $dana->id, 'status' => 'active', 'sessions_total' => 10, 'sessions_used' => 1, 'price' => 100]);
            PtSession::create(['pt_subscription_id' => $sub->id, 'trainer_id' => $dana->id, 'member_id' => $member->id, 'starts_at' => now()->startOfDay()->addHours(8), 'ends_at' => now()->startOfDay()->addHours(9), 'status' => PtSession::COMPLETED]);
        });

        $this->actingAs($owner)->get(route('club.reports.show', ['report' => 'trainers', 'preset' => 'today']))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Reports/Show')->where('kind', 'trainers')->missing('key')
                ->where('trainers.rows.0.trainer', 'ريم')->where('trainers.rows.0.attended', 1)->where('trainers.rows.0.attendance_rate', 100)
                ->where('trainers.rows.1.trainer', 'دانة')->where('trainers.rows.1.pt_completed', 1)
                ->where('trainers.comparison.trainers.current', 2)->where('trainers.comparison.pt_completed.current', 1));

        $csv = $this->actingAs($owner)->get(route('club.reports.export', ['report' => 'trainers', 'preset' => 'today', 'format' => 'csv']))->assertOk()->streamedContent();
        $this->assertStringContainsString('ريم', $csv);
    }

    public function test_the_branch_report_adds_up_to_the_club_with_records_without_a_branch_in_the_main_one(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $khoud = $this->asTenant($club, fn () => Branch::create(['name' => 'فرع الخوض', 'is_active' => true]));
        $this->member($club, ['branch_id' => $khoud->id]);
        $this->member($club, ['branch_id' => $khoud->id]);
        $this->member($club);

        $rows = [];
        $this->actingAs($owner)->get(route('club.reports.show', 'branches'))->assertOk()
            ->assertInertia(function ($page) use (&$rows) {
                $rows = collect($page->toArray()['props']['branches'])->keyBy('name');
            });
        $this->assertSame(1, $rows['الفرع الرئيسي']['comparison']['new_members']['current']);
        $this->assertSame(2, $rows['فرع الخوض']['comparison']['new_members']['current']);
        $this->assertArrayHasKey('revenue', $rows['فرع الخوض']['comparison'], 'the owner sees money');

        $viewer = $this->roleWith($club, ['reports.view']);
        $this->actingAs($viewer)->get(route('club.reports.show', 'branches'))
            ->assertInertia(fn ($page) => $page->missing('branches.0.comparison.revenue'));

        $this->actingAs($owner)->get(route('club.reports.export', ['report' => 'branches', 'format' => 'csv']))->assertOk();
    }
}
