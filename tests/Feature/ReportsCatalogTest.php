<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Documents\Models\BusinessDocument;
use App\Modules\Documents\Models\DocumentRenewal;
use App\Modules\Documents\Models\DocumentType;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\CheckIn;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Models\PtSession;
use App\Modules\Gym\Models\PtSubscription;
use App\Modules\Gym\Models\StaffAttendance;
use App\Modules\Gym\Models\Trainer;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Reports\Services\ReportCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The reports page is the one place for the club's reports: every card opens a real report page and exports a real
 * Excel and CSV file of the same figures, each only for those allowed, within the club and the user's branch. The
 * sections keep their working screens and lose their report tabs.
 */
class ReportsCatalogTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private const XLSX = 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';

    /** @return list<array<string, mixed>> */
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

    private function roleWith(Tenant $club, array $permissions, array $attributes = []): User
    {
        $role = $this->asTenant($club, fn () => Role::create(['key' => 'custom_'.uniqid(), 'name' => 'Custom', 'permissions' => $permissions]));
        $user = $this->staff($club, 'reception', $attributes);
        $user->forceFill(['role_id' => $role->id])->save();

        return $user->fresh();
    }

    /** A club with something in every report: money, members, classes, entries, attendance, payroll and documents. */
    private function busyClub(): array
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $khoud = $this->asTenant($club, fn () => Branch::create(['name' => 'فرع الخوض', 'is_active' => true]));
        $member = $this->member($club, ['full_name' => 'سالم الكندي']);
        $this->activeMembership($club, $member);

        $this->asTenant($club, function () use ($member, $owner) {
            $invoice = app(InvoiceService::class)->issue($member, [['description' => 'اشتراك شهري', 'quantity' => 1, 'unit_price' => '150']], ['type' => 'membership']);
            app(PaymentService::class)->record($invoice, 100, 'cash');
            Expense::create(['number' => 'EXP-1', 'description' => 'كهرباء', 'amount' => '12.500', 'expense_date' => today(), 'payment_method' => 'cash']);
            CheckIn::create(['member_id' => $member->id, 'method' => 'qr', 'direction' => 'in', 'result' => 'allowed', 'checked_in_at' => now()->startOfDay()->addHours(8)]);
            CheckIn::create(['member_id' => $member->id, 'method' => 'qr', 'direction' => 'in', 'result' => 'denied', 'reason' => 'member_blocked', 'checked_in_at' => now()->startOfDay()->addHours(9)]);
            StaffAttendance::create(['user_id' => $owner->id, 'method' => 'manual', 'clock_in_at' => now()->startOfDay()->addHours(6), 'clock_out_at' => now()->startOfDay()->addHours(9)]);
            DocumentType::ensureDefaults();
            $document = BusinessDocument::create(['name' => 'السجل التجاري', 'document_type_id' => DocumentType::value('id'), 'expiry_date' => today()->addDays(10), 'status' => 'active', 'renewal_months' => 12]);
            DocumentRenewal::create(['document_id' => $document->id, 'previous_expiry_date' => today()->subDay(), 'new_expiry_date' => today()->addDays(10), 'renewed_by' => $owner->id]);
            Employee::create(['name' => 'سارة', 'basic_salary' => '400.000', 'allowances' => [], 'status' => 'active', 'payment_method' => 'cash']);
        });
        $session = $this->classSession($club, null, ['starts_at' => now()->startOfDay()->addHours(7)]);
        $this->asTenant($club, fn () => Booking::create(['class_session_id' => $session->id, 'member_id' => $member->id, 'status' => Booking::ATTENDED, 'source' => 'staff']));

        $this->actingAs($owner)->post(route('club.payroll.store'), ['month' => now()->format('Y-m')])->assertSessionHasNoErrors();
        $run = $this->asTenant($club, fn () => PayrollRun::firstOrFail());
        $this->actingAs($owner)->post(route('club.payroll.approve', $run))->assertSessionHasNoErrors();
        $cash = $this->asTenant($club, fn () => app(ChartOfAccounts::class)->account('cash'));
        $this->actingAs($owner)->post(route('club.payroll.pay', $run), ['items' => $this->asTenant($club, fn () => $run->items()->pluck('id')->all()), 'account_id' => $cash->id, 'paid_on' => today()->toDateString()])->assertSessionHasNoErrors();

        return [$club, $owner, $khoud, $member];
    }

    public function test_every_card_opens_its_report_and_exports_a_real_excel_and_csv_file(): void
    {
        [$club, $owner] = $this->busyClub();
        $reports = $this->catalog($owner);
        // 32 files in a row: past the ten-a-minute limit, which ExportsAuditSecurityTest covers.
        $this->withoutMiddleware(ThrottleRequests::class);

        $this->assertEqualsCanonicalizing(ReportCatalog::keys(), array_column($reports, 'key'), 'the owner of a club with two branches sees every report');
        $this->assertSame(array_keys(ReportCatalog::CATEGORIES), array_values(array_unique(array_column($reports, 'category'))), 'every gym category, in order');

        foreach ($reports as $card) {
            $key = $card['key'];
            $this->assertNotEmpty($card['desc'], $key);
            $this->assertSame(route('club.reports.show', $key), $card['href']);
            $this->assertSame(route('club.reports.export', $key), $card['export'], "{$key}: the card exports");

            $this->actingAs($owner)->get($card['href'])->assertOk()
                ->assertInertia(fn ($page) => $page->component('Reports/Show')->where('kind', $key)->where('title', $card['title'])->where('canExport', true)->has('data.empty')->missing('key'));

            $xlsx = $this->actingAs($owner)->get($card['export'].'?format=xlsx')->assertOk()->assertHeader('content-type', self::XLSX);
            $this->assertStringStartsWith('PK', $xlsx->streamedContent(), "{$key}: a real xlsx (zip) file");
            $this->assertMatchesRegularExpression('/filename="?'.preg_quote($key, '/').'-[0-9_-]+\.xlsx/', $xlsx->headers->get('content-disposition'), "{$key}: named by key and dates only");

            $csv = $this->actingAs($owner)->get($card['export'].'?format=csv')->assertOk();
            $body = $csv->streamedContent();
            $this->assertStringStartsWith("\xEF\xBB\xBF", $body, "{$key}: Excel-friendly CSV");
            $this->assertStringContainsString($card['title'], $body, "{$key}: the file names its report");
            $this->assertStringContainsString('تاريخ الإصدار', $body, "{$key}: and when it was made");
            $this->assertMatchesRegularExpression('/الفترة|الوضع في/', $body, "{$key}: and what it covers");
            $this->assertContains($csv->headers->get('x-report-empty'), ['0', '1']);
        }

        $this->assertSame(count($reports) * 2, $this->asTenant($club, fn () => AuditLog::where('event', 'report.exported')->count()), 'every export is audited');
    }

    public function test_exports_carry_the_reports_figures_in_arabic_with_three_decimal_amounts(): void
    {
        [$club, $owner] = $this->busyClub();

        $finance = $this->actingAs($owner)->get(route('club.reports.export', ['report' => 'finance', 'preset' => 'month', 'format' => 'csv']))->streamedContent();
        $this->assertStringContainsString('صافي التحصيل', $finance);
        $this->assertStringContainsString('المبلغ (ر.ع)', $finance, 'amounts say their currency');
        $this->assertStringContainsString('100.000', $finance, 'the payment, to three decimals');
        $this->assertStringContainsString('12.500', $finance, 'the expense');
        $this->assertStringContainsString('كل الفروع', $finance, 'the file names the branch it covers');

        $dues = $this->actingAs($owner)->get(route('club.reports.export', ['report' => 'dues', 'format' => 'csv']))->streamedContent();
        $this->assertStringContainsString('سالم الكندي', $dues);
        $this->assertStringContainsString('50.000', $dues, 'what is still owed');

        $payroll = $this->actingAs($owner)->get(route('club.reports.export', ['report' => 'payroll', 'format' => 'csv']))->streamedContent();
        $this->assertStringContainsString('400.000', $payroll);
        $this->assertStringNotContainsString('IBAN', $payroll, 'no bank numbers in a payroll file');

        // A report with nothing in its period: a valid file of headings, said in the file and in a header.
        $empty = $this->actingAs($owner)->get(route('club.reports.export', ['report' => 'sales', 'from' => '2020-01-01', 'to' => '2020-01-31', 'format' => 'csv']));
        $empty->assertHeader('x-report-empty', '1');
        $this->assertStringContainsString('لا توجد بيانات', $empty->streamedContent());
        $this->assertStringContainsString('الرقم,التاريخ,المصدر', $empty->streamedContent(), 'the headings are there');

        $this->actingAs($owner)->get(route('club.reports.show', ['report' => 'sales', 'from' => '2020-01-01', 'to' => '2020-01-31']))
            ->assertInertia(fn ($page) => $page->where('data.empty', true));

        // A formula typed into a name is written as text.
        $this->activeMembership($club, $this->member($club, ['full_name' => '=HYPERLINK("http://evil")']));
        $members = $this->actingAs($owner)->get(route('club.reports.export', ['report' => 'members', 'format' => 'csv']))->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $members);
    }

    public function test_opening_a_report_and_exporting_it_are_separate_permissions(): void
    {
        $club = $this->club();

        // reports.view alone: the general reports, without money, and no files.
        $viewer = $this->roleWith($club, ['reports.view']);
        $cards = $this->catalog($viewer);
        $this->assertEqualsCanonicalizing(['members', 'classes', 'trainers'], array_column($cards, 'key'));
        $this->assertNull($cards[0]['export'], 'no export without reports.export');
        $this->actingAs($viewer)->get(route('club.reports.show', 'finance'))->assertForbidden();
        $this->actingAs($viewer)->get(route('club.reports.show', 'payroll'))->assertForbidden();
        $this->actingAs($viewer)->get(route('club.reports.show', 'members'))->assertOk()->assertInertia(fn ($page) => $page->where('canExport', false));
        $this->actingAs($viewer)->get(route('club.reports.export', ['report' => 'members', 'format' => 'csv']))->assertForbidden();
        $this->actingAs($viewer)->get(route('club.reports.show', 'branches'))->assertNotFound();

        // Exporting is not opening: reports.export without the report's own permission is refused.
        $exporter = $this->roleWith($club, ['reports.view', 'reports.export']);
        $this->actingAs($exporter)->get(route('club.reports.export', ['report' => 'members', 'format' => 'csv']))->assertOk();
        $this->actingAs($exporter)->get(route('club.reports.export', ['report' => 'payroll', 'format' => 'csv']))->assertForbidden();
        $this->actingAs($exporter)->get(route('club.reports.export', ['report' => 'statements', 'format' => 'csv']))->assertForbidden();

        // Salaries: payroll.reports opens them, and the file also needs reports.export.
        $payroll = $this->roleWith($club, ['reports.view', 'payroll.reports']);
        $this->actingAs($payroll)->get(route('club.reports.show', 'payroll'))->assertOk();
        $this->actingAs($payroll)->get(route('club.reports.export', ['report' => 'payroll', 'format' => 'csv']))->assertForbidden();

        // A section's permission without reports.view opens no report; reception and trainers have none.
        $accountant = $this->roleWith($club, ['accounting.view']);
        $this->actingAs($accountant)->get(route('club.reports.show', 'statements'))->assertForbidden();
        $this->actingAs($this->staff($club, 'reception'))->get(route('club.reports.index'))->assertForbidden();
        $this->actingAs($this->staff($club, 'trainer'))->get(route('club.reports.index'))->assertForbidden();

        // The finance role: money, books, sales and payroll, not documents or attendance.
        $finance = array_column($this->catalog($this->staff($club, 'finance')), 'key');
        foreach (['finance', 'statements', 'movement', 'dues', 'sales', 'payroll', 'payouts'] as $key) {
            $this->assertContains($key, $finance);
        }
        foreach (['documents', 'renewals', 'checkins', 'staff-attendance'] as $key) {
            $this->assertNotContains($key, $finance);
        }
    }

    public function test_reports_and_their_files_stay_within_the_users_branch(): void
    {
        [$club, $owner, $khoud] = $this->busyClub();
        $this->member($club, ['full_name' => 'عضو الخوض', 'branch_id' => $khoud->id]);
        $this->member($club, ['full_name' => 'عضو الخوض الثاني', 'branch_id' => $khoud->id]);
        $newMembers = fn (User $user, array $query = []) => $this->actingAs($user)->get(route('club.reports.show', ['report' => 'members'] + $query))->assertOk()
            ->inertiaProps('data.report.comparison.new_members.current');

        // The owner chooses: the whole club, or one branch (records without a branch are the main branch's).
        $this->assertSame(3, $newMembers($owner, ['branch' => 'all']));
        $this->assertSame(2, $newMembers($owner, ['branch' => $khoud->id]));
        $this->assertSame(1, $newMembers($owner, ['branch' => $this->asTenant($club, fn () => Branch::where('is_main', true)->value('id'))]));

        // A manager tied to a branch gets theirs, whatever the address says, and no comparison of branches.
        $tied = $this->staff($club, 'manager', ['branch_id' => $khoud->id]);
        $this->assertSame(2, $newMembers($tied));
        $this->assertSame(2, $newMembers($tied, ['branch' => 'all']));
        $this->actingAs($tied)->get(route('club.reports.show', 'members'))->assertInertia(fn ($page) => $page->where('branchFilter.choices', [])->where('branchFilter.name', 'فرع الخوض'));
        $this->assertNotContains('branches', array_column($this->catalog($tied), 'key'));
        $this->actingAs($tied)->get(route('club.reports.show', 'branches'))->assertForbidden();

        $csv = $this->actingAs($tied)->get(route('club.reports.export', ['report' => 'checkins', 'branch' => 'all', 'format' => 'csv']))->streamedContent();
        $this->assertStringContainsString('فرع الخوض', $csv, 'the file names the branch');
        $this->assertStringNotContainsString('سالم الكندي', $csv, "the main branch's entries are not in a Khoud file");
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
                ->where('data.trainers.rows.0.trainer', 'ريم')->where('data.trainers.rows.0.attended', 1)->where('data.trainers.rows.0.attendance_rate', 100)
                ->where('data.trainers.rows.1.trainer', 'دانة')->where('data.trainers.rows.1.pt_completed', 1)
                ->where('data.trainers.comparison.trainers.current', 2)->where('data.trainers.comparison.pt_completed.current', 1));

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

        $rows = collect($this->actingAs($owner)->get(route('club.reports.show', 'branches'))->assertOk()->inertiaProps('data.branches'))->keyBy('name');
        $this->assertSame(1, $rows['الفرع الرئيسي']['comparison']['new_members']['current']);
        $this->assertSame(2, $rows['فرع الخوض']['comparison']['new_members']['current']);
        $this->assertArrayHasKey('revenue', $rows['فرع الخوض']['comparison'], 'the owner sees money');

        $viewer = $this->roleWith($club, ['reports.view', 'reports.export']);
        $this->actingAs($viewer)->get(route('club.reports.show', 'branches'))->assertInertia(fn ($page) => $page->missing('data.branches.0.comparison.revenue'));
        $this->assertStringNotContainsString('صافي التحصيل', $this->actingAs($viewer)->get(route('club.reports.export', ['report' => 'branches', 'format' => 'csv']))->streamedContent(), 'no money in their file either');
    }

    public function test_the_sections_lose_their_report_tabs_and_old_addresses_lead_to_the_reports(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);

        $this->actingAs($owner)->get(route('club.sales.reports', ['from' => '2026-10-01', 'to' => '2026-10-15']))->assertRedirect(route('club.reports.show', ['report' => 'sales', 'from' => '2026-10-01', 'to' => '2026-10-15']));
        $this->actingAs($owner)->get(route('club.sales.reports.export', ['format' => 'xlsx']))->assertRedirect(route('club.reports.export', ['report' => 'sales', 'format' => 'xlsx']));
        $this->actingAs($owner)->get(route('club.finance.statements', ['view' => 'trial']))->assertRedirect(route('club.reports.show', ['report' => 'statements', 'view' => 'trial']));
        $this->actingAs($owner)->get(route('club.finance.statements.export', ['format' => 'csv']))->assertRedirect(route('club.reports.export', ['report' => 'statements', 'format' => 'csv']));
        $this->actingAs($owner)->get(route('club.finance.movement', ['direction' => 'in']))->assertRedirect(route('club.reports.show', ['report' => 'movement', 'direction' => 'in']));
        $this->actingAs($owner)->get(route('club.reports.index', ['section' => 'classes', 'preset' => 'week']))->assertRedirect(route('club.reports.show', ['report' => 'classes', 'preset' => 'week']));

        // The working screens stay where they were.
        foreach (['club.dues', 'club.payroll.index', 'club.payroll.payouts', 'club.checkins.index', 'club.staff-attendance.index', 'club.business-documents.renewals', 'club.sales.summary', 'club.finance.summary'] as $name) {
            $this->actingAs($owner)->get(route($name))->assertOk();
        }

        // No section of the menu keeps a report tab of its own.
        $nav = file_get_contents(resource_path('js/lib/nav.ts'));
        foreach (["'club.sales.reports'", "'club.finance.statements'", "'club.finance.movement'"] as $route) {
            $this->assertStringNotContainsString($route, $nav);
        }
    }
}
