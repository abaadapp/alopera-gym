<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Models\Branch;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Services\InventoryService;
use App\Modules\Inventory\Services\StockService;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollItem;
use App\Modules\Payroll\Models\PayrollPayout;
use App\Modules\Payroll\Models\PayrollRun;
use App\Modules\Payroll\Papers\PayslipPaper;
use App\Modules\Payroll\Services\PayrollService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** The owner's decisions after the launch audit (2026-11-05), each held by a test. */
class OwnerDecisionsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_an_approved_count_posts_the_difference_found_when_counting_and_keeps_later_sales(): void
    {
        $club = $this->club();

        $this->asTenant($club, function () {
            $branch = Branch::orderByDesc('is_main')->orderBy('id')->firstOrFail();
            $water = Product::create(['name' => 'Water', 'price' => 0.3]);
            app(StockService::class)->move($water, 10, 'adjustment', null, 'shelf', 0.1, branchId: $branch->id);
            $count = app(InventoryService::class)->startCount($branch->id, [$water->id]);
            app(InventoryService::class)->saveCounts($count, [$count->lines()->value('id') => ['counted' => 8]]);
            // Sold after the count, before it was approved.
            app(StockService::class)->move($water, -3, 'sale', null, 'sold', branchId: $branch->id);

            app(InventoryService::class)->approveCount($count->fresh());

            $this->assertSame(5.0, (float) app(StockService::class)->quantity($water, $branch->id), 'ten, two missing at the count, three sold since');
            $this->assertSame('-2.000', $count->lines()->value('difference'));
            $this->assertThrows(fn () => app(InventoryService::class)->approveCount($count->fresh()), BusinessRuleException::class);
            $this->assertSame(5.0, (float) app(StockService::class)->quantity($water, $branch->id), 'approved once');
        });
    }

    public function test_a_draft_payroll_run_is_listed_but_left_out_of_the_totals(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->asTenant($club, function () {
            Employee::create(['name' => 'موظف', 'basic_salary' => 100, 'status' => 'active']);
            app(PayrollService::class)->open(now()->format('Y-m'));
        });

        $this->actingAs($owner)->get(route('club.reports.show', ['report' => 'payroll']))->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('data.runs.0.status', 'draft')
                ->where('data.runs.0.net', '100.000')
                ->where('data.comparison.net.current', '0.000')
                ->where('data.comparison.runs.current', 0));
    }

    public function test_a_salary_payout_is_voided_once_by_its_own_reversing_entry(): void
    {
        $club = $this->club();
        $this->fundAccounts($club);
        $owner = $this->owner($club);
        [$run, $cash] = $this->asTenant($club, function () {
            Employee::create(['name' => 'موظف', 'basic_salary' => 100, 'status' => 'active']);
            $run = app(PayrollService::class)->open(now()->format('Y-m'));
            app(PayrollService::class)->approve($run);

            return [$run->fresh(), app(ChartOfAccounts::class)->account('cash')];
        });
        $this->actingAs($owner)->post(route('club.payroll.pay', $run), ['items' => [$this->asTenant($club, fn () => PayrollItem::value('id'))], 'account_id' => $cash->id, 'paid_on' => today()->toDateString()])->assertSessionHasNoErrors();
        $payout = $this->asTenant($club, fn () => PayrollPayout::sole());
        $drawer = fn () => $this->asTenant($club, fn () => JournalEntry::join('gym_journal_lines as l', 'l.journal_entry_id', '=', 'gym_journal_entries.id')->where('l.account_id', $cash->id)->selectRaw('coalesce(sum(l.debit - l.credit), 0) as b')->value('b'));
        $this->assertSame('9900.000', (string) $drawer());

        $this->actingAs($owner)->post(route('club.payroll.payouts.void', $payout), ['reason' => 'صُرف للحساب الخطأ'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.payroll.payouts.void', $payout), ['reason' => 'مرة ثانية'])->assertSessionHasErrors();

        $this->asTenant($club, function () use ($payout, $run, $drawer) {
            $pay = JournalEntry::where(['source_type' => 'payroll_payout', 'source_id' => $payout->id, 'source_event' => 'pay'])->sole();
            $void = JournalEntry::where(['source_type' => 'payroll_payout', 'source_id' => $payout->id, 'source_event' => 'void'])->sole();
            $this->assertNotNull($pay->reversed_at, 'the original entry stays, marked reversed');
            $this->assertSame([$pay->id, $pay->total], [$void->reverses_id, $void->total]);
            $this->assertSame('10000.000', number_format((float) $drawer(), 3, '.', ''), 'the drawer is back where it was');
            $this->assertNotNull($payout->fresh()->voided_at);
            $this->assertSame([null, null], [PayrollItem::value('paid_at'), PayrollItem::value('payroll_payout_id')]);
            $this->assertSame(PayrollRun::APPROVED, $run->fresh()->status, 'the run is to be paid again');
        });
    }

    public function test_a_payout_in_a_closed_month_is_not_voided(): void
    {
        $club = $this->club();
        $this->fundAccounts($club);
        $owner = $this->owner($club);
        $payout = $this->asTenant($club, function () {
            Employee::create(['name' => 'موظف', 'basic_salary' => 100, 'status' => 'active']);
            $month = today()->subMonthNoOverflow();
            $run = app(PayrollService::class)->open($month->format('Y-m'));
            app(PayrollService::class)->approve($run);
            app(PayrollService::class)->pay($run->fresh(), PayrollItem::pluck('id')->all(), app(ChartOfAccounts::class)->account('cash'), $month->endOfMonth()->toDateString());
            // That month is then closed.
            AccountingPeriod::create(['kind' => 'month', 'starts_on' => $month->startOfMonth(), 'ends_on' => $month->endOfMonth(), 'status' => 'closed', 'closed_at' => now()]);

            return PayrollPayout::sole();
        });

        $this->actingAs($owner)->post(route('club.payroll.payouts.void', $payout), ['reason' => 'متأخر'])->assertSessionHasErrors();
        $this->asTenant($club, fn () => $this->assertNull($payout->fresh()->voided_at));
    }

    public function test_a_payout_from_the_bank_voided_puts_the_bank_back_and_the_page_shows_it_void(): void
    {
        $club = $this->club();
        $this->fundAccounts($club);
        $owner = $this->owner($club);
        $bank = $this->asTenant($club, function () {
            Employee::create(['name' => 'موظف', 'basic_salary' => 250, 'status' => 'active']);
            $run = app(PayrollService::class)->open(now()->format('Y-m'));
            app(PayrollService::class)->approve($run);
            $bank = app(ChartOfAccounts::class)->account('bank');
            app(PayrollService::class)->pay($run->fresh(), PayrollItem::pluck('id')->all(), $bank, today()->toDateString());

            return $bank;
        });
        $payout = $this->asTenant($club, fn () => PayrollPayout::sole());
        $balance = fn () => $this->asTenant($club, fn () => number_format((float) JournalEntry::join('gym_journal_lines as l', 'l.journal_entry_id', '=', 'gym_journal_entries.id')->where('l.account_id', $bank->id)->sum(\DB::raw('l.debit - l.credit')), 3, '.', ''));
        $this->assertSame('9750.000', $balance());
        $this->actingAs($owner)->get(route('club.payroll.payouts'))->assertInertia(fn (Assert $page) => $page->where('payouts.0.voidable', true)->where('payouts.0.voided', false));

        $this->actingAs($owner)->post(route('club.payroll.payouts.void', $payout), ['reason' => 'تحويل لم يتم'])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame('10000.000', $balance(), 'the bank in the books is back where it was');
        $this->actingAs($owner)->get(route('club.payroll.payouts'))->assertInertia(fn (Assert $page) => $page
            ->where('payouts.0.voided', true)->where('payouts.0.voidable', false)->where('payouts.0.void_reason', 'تحويل لم يتم'));
        // Who may not pay may not void, whatever they send.
        $reports = $this->staff($club, 'finance');
        $reports->role->update(['permissions' => ['payroll.reports', 'payroll.salaries']]);
        $this->actingAs($reports->fresh())->post(route('club.payroll.payouts.void', $payout), ['reason' => 'x'])->assertForbidden();
    }

    public function test_saving_payroll_settings_without_the_hidden_hours_keeps_them_stored_and_they_change_no_pay(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->asTenant($club, fn () => $club->forceFill(['settings' => array_merge($club->settings ?? [], ['payroll' => ['work_hours' => 4, 'overtime_rate' => 9, 'payout_account_id' => null, 'punch_window_seconds' => 60]])])->save());

        // The settings page no longer sends work hours or the overtime rate.
        $this->actingAs($owner)->put(route('club.settings.payroll.update'), ['payout_account_id' => '', 'punch_window_seconds' => 30])->assertSessionHasNoErrors();
        $stored = $club->fresh()->settings['payroll'];
        $this->assertSame([4, 9, 30], [$stored['work_hours'], $stored['overtime_rate'], $stored['punch_window_seconds']], 'kept as they were, not erased');

        // A run with those settings pays what one without them pays: basic, no overtime of its own; manual lines still count.
        $this->asTenant($club, function () {
            Employee::create(['name' => 'موظف', 'basic_salary' => 100, 'status' => 'active']);
            $run = app(PayrollService::class)->open(now()->format('Y-m'));
            $item = PayrollItem::sole();
            $this->assertSame(['100.000', '0.000', '100.000'], [$item->basic, $item->overtime, $item->net]);
            app(PayrollService::class)->updateItem($item, ['overtime_hours' => '2', 'overtime' => '6', 'deductions' => '1']);
            $this->assertSame('105.000', $item->fresh()->net, 'manual overtime and deductions work as before');
            $this->assertSame('105.000', $run->fresh()->net);
        });
    }

    public function test_payroll_settings_without_the_payout_account_in_the_request_keep_it_instead_of_a_server_error(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->asTenant($club, fn () => $club->forceFill(['settings' => array_merge($club->settings ?? [], ['payroll' => ['payout_account_id' => 7, 'punch_window_seconds' => 60]])])->save());

        $this->actingAs($owner)->put(route('club.settings.payroll.update'), ['punch_window_seconds' => 30])
            ->assertRedirect()->assertSessionHasNoErrors();
        $stored = $club->fresh()->settings['payroll'];
        $this->assertSame([7, 30], [$stored['payout_account_id'], $stored['punch_window_seconds']]);
    }

    /** @return array{0: Tenant, 1: User, 2: PayrollItem, 3: Branch} a bank-paid line of an employee of the main branch, and a second branch */
    private function paidByBank(): array
    {
        $club = $this->club();
        $this->fundAccounts($club);
        $owner = $this->owner($club);
        [$item, $khoud] = $this->asTenant($club, function () {
            $main = Branch::orderByDesc('is_main')->orderBy('id')->firstOrFail();
            Employee::create(['name' => 'موظف', 'basic_salary' => 100, 'status' => 'active', 'branch_id' => $main->id, 'payment_method' => 'bank', 'bank_name' => 'بنك مسقط', 'iban' => 'OM810180000001299123451234']);
            $run = app(PayrollService::class)->open(now()->format('Y-m'));
            app(PayrollService::class)->approve($run);
            app(PayrollService::class)->pay($run->fresh(), PayrollItem::pluck('id')->all(), app(ChartOfAccounts::class)->account('bank'), today()->toDateString());

            return [PayrollItem::sole(), Branch::create(['name' => 'فرع الخوض', 'is_active' => true])];
        });

        return [$club, $owner, $item, $khoud];
    }

    private function payslip($club, $user, PayrollItem $item): string
    {
        $this->actingAs($user);

        return $this->asTenant($club, fn () => json_encode(PayslipPaper::document($item->fresh(), [], $club->fresh()), JSON_UNESCAPED_UNICODE));
    }

    public function test_a_payslip_reprinted_after_the_employee_moved_branch_and_bank_shows_the_branch_and_bank_at_payment(): void
    {
        [$club, $owner, $item, $khoud] = $this->paidByBank();
        $before = $this->payslip($club, $owner, $item);
        $this->assertStringContainsString('بنك مسقط', $before);
        $this->assertStringContainsString('1234', $before);

        // Moved to another branch, paid into another bank since.
        $this->asTenant($club, fn () => $item->employee->update(['branch_id' => $khoud->id, 'bank_name' => 'بنك ظفار', 'iban' => 'OM020270000009999999999999']));
        $after = $this->payslip($club, $owner, $item);

        $this->assertSame($before, $after, 'the reprint is the payslip as paid');
        $this->assertStringNotContainsString('ظفار', $after);
        $this->assertStringNotContainsString('9999', $after);
        $this->assertStringNotContainsString('فرع الخوض', $after);
        $this->asTenant($club, fn () => $this->assertSame(1, JournalEntry::where(['source_type' => 'payroll_payout', 'source_event' => 'pay'])->count(), 'paid and posted once'));
    }

    public function test_a_payslip_paid_before_snapshots_never_shows_todays_bank_as_the_old_one_and_bank_lines_need_permission(): void
    {
        [$club, $owner, $item] = $this->paidByBank();
        // Paid before the snapshot existed.
        $this->asTenant($club, fn () => \DB::table('gym_payroll_items')->where('id', $item->id)->update(['paid_snapshot' => null]));
        $legacy = $this->payslip($club, $owner, $item);
        $this->assertStringNotContainsString('بنك مسقط', $legacy);
        $this->assertStringContainsString('لم تُحفظ وقت الصرف', $legacy);

        // Someone who may print payslips but not read salaries and bank details sees no bank line at all.
        [$club2, $owner2, $item2] = $this->paidByBank();
        $clerk = $this->staff($club2, 'reception');
        $clerk->role->update(['permissions' => ['payroll.payslips']]);
        $this->assertStringNotContainsString('بنك مسقط', $this->payslip($club2, $clerk->fresh(), $item2));
    }

    public function test_a_payout_sent_again_says_it_was_already_paid_and_in_which_payout_without_paying_twice(): void
    {
        $club = $this->club();
        $this->fundAccounts($club);
        $owner = $this->owner($club);
        [$run, $item, $cash] = $this->asTenant($club, function () {
            Employee::create(['name' => 'موظف', 'basic_salary' => 100, 'status' => 'active']);
            $run = app(PayrollService::class)->open(now()->format('Y-m'));
            app(PayrollService::class)->approve($run);

            return [$run->fresh(), PayrollItem::sole(), app(ChartOfAccounts::class)->account('cash')];
        });
        $send = ['items' => [$item->id], 'account_id' => $cash->id, 'paid_on' => today()->toDateString()];
        $this->actingAs($owner)->post(route('club.payroll.pay', $run), $send)->assertSessionHasNoErrors();
        $number = $this->asTenant($club, fn () => PayrollPayout::sole()->number);

        // A double click or a second tab: the lines were paid by the first request a moment ago.
        $again = $this->actingAs($owner)->post(route('club.payroll.pay', $run), $send);
        $said = json_encode(session()->all(), JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString($number, (string) $said, 'it names the payout that paid them');
        $this->assertStringContainsString('لم يُصرف شيء مرة ثانية', (string) $said);
        $this->asTenant($club, fn () => $this->assertSame([1, 1], [PayrollPayout::count(), JournalEntry::where(['source_type' => 'payroll_payout', 'source_event' => 'pay'])->count()]));
    }
}
