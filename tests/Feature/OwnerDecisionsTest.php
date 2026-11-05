<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Models\Branch;
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
        $this->assertSame('-100.000', (string) $drawer());

        $this->actingAs($owner)->post(route('club.payroll.payouts.void', $payout), ['reason' => 'صُرف للحساب الخطأ'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.payroll.payouts.void', $payout), ['reason' => 'مرة ثانية'])->assertSessionHasErrors();

        $this->asTenant($club, function () use ($payout, $run, $drawer) {
            $pay = JournalEntry::where(['source_type' => 'payroll_payout', 'source_id' => $payout->id, 'source_event' => 'pay'])->sole();
            $void = JournalEntry::where(['source_type' => 'payroll_payout', 'source_id' => $payout->id, 'source_event' => 'void'])->sole();
            $this->assertNotNull($pay->reversed_at, 'the original entry stays, marked reversed');
            $this->assertSame([$pay->id, $pay->total], [$void->reverses_id, $void->total]);
            $this->assertSame('0.000', number_format((float) $drawer(), 3, '.', ''), 'the drawer is back where it was');
            $this->assertNotNull($payout->fresh()->voided_at);
            $this->assertSame([null, null], [PayrollItem::value('paid_at'), PayrollItem::value('payroll_payout_id')]);
            $this->assertSame(PayrollRun::APPROVED, $run->fresh()->status, 'the run is to be paid again');
        });
    }

    public function test_a_payout_in_a_closed_month_is_not_voided(): void
    {
        $club = $this->club();
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
        $this->assertSame('-250.000', $balance());
        $this->actingAs($owner)->get(route('club.payroll.payouts'))->assertInertia(fn (Assert $page) => $page->where('payouts.0.voidable', true)->where('payouts.0.voided', false));

        $this->actingAs($owner)->post(route('club.payroll.payouts.void', $payout), ['reason' => 'تحويل لم يتم'])->assertSessionHasNoErrors()->assertSessionHas('success');

        $this->assertSame('0.000', $balance(), 'the bank in the books is back where it was');
        $this->actingAs($owner)->get(route('club.payroll.payouts'))->assertInertia(fn (Assert $page) => $page
            ->where('payouts.0.voided', true)->where('payouts.0.voidable', false)->where('payouts.0.void_reason', 'تحويل لم يتم'));
        // Who may not pay may not void, whatever they send.
        $reports = $this->staff($club, 'finance');
        $reports->role->update(['permissions' => ['payroll.reports', 'payroll.salaries']]);
        $this->actingAs($reports->fresh())->post(route('club.payroll.payouts.void', $payout), ['reason' => 'x'])->assertForbidden();
    }
}
