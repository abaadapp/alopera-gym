<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Modules\Accounting\Models\AccountingPeriod;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\AccountingPeriods;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Accounting\Services\Ledger;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Closed periods: a month closes once it is over, in order and after its checks; nothing is posted, edited or deleted
 * with a date in it afterwards, a correction goes in an open period; reopening takes its own permission and a reason,
 * latest month first; a year closes after its months, and its months reopen only after it does.
 */
class AccountingPeriodsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    /** An invoice dated on that day (the clock set there while it is issued), paid in cash unless said otherwise. */
    private function sale($club, string $day, string $price = '50', bool $pay = true): Invoice
    {
        $this->travelTo(CarbonImmutable::parse($day.' 10:00'));
        $invoice = $this->asTenant($club, function () use ($price, $pay) {
            $invoice = app(InvoiceService::class)->issue(null, [['description' => 'خدمة', 'unit_price' => $price]], ['type' => 'other'], 'عميل');
            if ($pay) {
                app(PaymentService::class)->record($invoice, $invoice->total, 'cash');
            }

            return $invoice->fresh();
        });
        $this->travelBack();

        return $invoice;
    }

    private function close($user, string $month)
    {
        return $this->actingAs($user)->post(route('club.finance.periods.close'), ['month' => $month, 'client_key' => (string) Str::uuid()]);
    }

    private function lockedThrough($club): ?string
    {
        return $this->asTenant($club, fn () => app(AccountingPeriods::class)->lockedThrough()?->toDateString());
    }

    public function test_a_month_closes_with_the_months_before_it_and_nothing_enters_it_afterwards(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00'));
        $club = $this->club();
        $owner = $this->owner($club);
        $this->sale($club, '2026-09-10');
        $october = $this->sale($club, '2026-10-12', '30', pay: false);
        $expense = $this->asTenant($club, fn () => Expense::create(['number' => 'EX-1', 'description' => 'كهرباء', 'amount' => 20, 'expense_date' => '2026-10-20', 'payment_method' => 'cash']));
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00'));

        // The current month is not over: it does not close.
        $this->close($owner, '2026-11')->assertSessionHasErrors('month');

        $this->close($owner, '2026-10')->assertSessionHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, 'سبتمبر') && str_contains($m, 'أكتوبر'));
        $this->assertSame('2026-10-31', $this->lockedThrough($club));
        $this->asTenant($club, function () {
            $this->assertSame(2, AccountingPeriod::where('status', AccountingPeriod::CLOSED)->count());
            $this->assertSame(2, DB::table('audit_logs')->where('event', 'accounting.period_closed')->count());
            $this->assertSame(1, AccountingPeriod::where('starts_on', '2026-10-01')->value('times_closed'));
        });

        // Nothing dated in October: a manual entry, an expense, its edit or deletion…
        $chart = fn () => [app(ChartOfAccounts::class)->account('cash')->id, app(ChartOfAccounts::class)->account('owner_equity')->id];
        [$cash, $equity] = $this->asTenant($club, $chart);
        $entry = fn (string $date) => ['date' => $date, 'description' => 'إيداع', 'client_key' => (string) Str::uuid(), 'lines' => [['account_id' => $cash, 'debit' => '10'], ['account_id' => $equity, 'credit' => '10']]];
        $this->actingAs($owner)->post(route('club.finance.journal.store'), $entry('2026-10-15'))->assertSessionHasErrors('date');
        $this->actingAs($owner)->post(route('club.finance.journal.store'), $entry('2026-11-01'))->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.expenses.store'), ['description' => 'ماء', 'amount' => 5, 'expense_date' => '2026-10-25', 'payment_method' => 'cash'])->assertSessionHasErrors('expense_date');
        $this->actingAs($owner)->put(route('club.expenses.update', $expense), ['description' => 'كهرباء', 'amount' => 25, 'expense_date' => '2026-10-20', 'payment_method' => 'cash'])->assertSessionHasErrors();
        $this->actingAs($owner)->delete(route('club.expenses.destroy', $expense))->assertSessionHasErrors();
        $this->asTenant($club, fn () => $this->assertSame('20.000', Expense::findOrFail($expense->id)->amount));

        // …nor through the ledger itself, whoever posts.
        $this->asTenant($club, function () use ($cash, $equity) {
            try {
                app(Ledger::class)->post(['date' => '2026-10-31', 'description' => 'x'], [['account' => $cash, 'debit' => 1], ['account' => $equity, 'credit' => 1]]);
                $this->fail('posted into a closed month');
            } catch (BusinessRuleException $e) {
                $this->assertSame('period.closed', $e->rule);
            }
        });

        // A correction of October is posted in an open period: voiding its unpaid invoice reverses it today, and
        // October's own entry stays as it was.
        $this->asTenant($club, function () use ($october) {
            app(InvoiceService::class)->void($october, 'أُصدرت بالخطأ');
            $issue = JournalEntry::where(['source_type' => 'invoice', 'source_id' => $october->id, 'source_event' => 'issue'])->sole();
            $reversal = JournalEntry::where(['source_type' => 'invoice', 'source_id' => $october->id, 'source_event' => 'void'])->sole();
            $this->assertSame(['2026-10-12', '2026-11-05'], [$issue->entry_date->toDateString(), $reversal->entry_date->toDateString()]);
            $this->assertSame((string) JournalLine::sum('debit'), (string) JournalLine::sum('credit'));
        });
    }

    public function test_closing_is_refused_with_its_reasons_and_missing_postings_are_made_first(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00'));
        $club = $this->club();
        $owner = $this->owner($club);
        $invoice = $this->sale($club, '2026-10-12');
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00'));

        // The invoice's posting went missing (a failed listener): the checks list it, the closing posts it.
        $this->asTenant($club, function () use ($invoice) {
            JournalEntry::where(['source_type' => 'invoice', 'source_id' => $invoice->id])->each(fn ($e) => [$e->lines()->delete(), $e->delete()]);
            $checks = app(AccountingPeriods::class)->checks('2026-10');
            $this->assertStringContainsString($invoice->number, implode(' ', $checks['pending']));
            $this->assertSame([], $checks['errors']);
        });

        // An entry that does not balance stops it, with its number, and nothing is closed.
        $broken = $this->asTenant($club, function () {
            $cash = app(ChartOfAccounts::class)->account('cash');
            $entry = JournalEntry::create(['number' => 'JE-BROKEN', 'entry_date' => '2026-10-20', 'description' => 'تالف', 'total' => 5]);
            JournalLine::create(['journal_entry_id' => $entry->id, 'account_id' => $cash->id, 'debit' => 5, 'credit' => 0]);

            return $entry;
        });
        $this->close($owner, '2026-10')->assertSessionHasErrors('month');
        $this->assertStringContainsString('JE-BROKEN', session('errors')->first('month'));
        $this->assertNull($this->lockedThrough($club));

        $this->asTenant($club, fn () => [$broken->lines()->delete(), $broken->delete()]);
        $this->close($owner, '2026-10')->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame(1, JournalEntry::where(['source_type' => 'invoice', 'source_id' => $invoice->id, 'source_event' => 'issue'])->count()));
        $this->close($owner, '2026-10')->assertSessionHasErrors('month');
    }

    public function test_reopening_takes_its_own_permission_and_a_reason_latest_month_first(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00'));
        $club = $this->club();
        $owner = $this->owner($club);
        $finance = $this->staff($club, 'finance');
        $this->sale($club, '2026-09-10');
        $this->sale($club, '2026-10-10');
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00'));

        // Managing the books is not closing them: the finance role has neither right unless given.
        $this->close($finance, '2026-10')->assertForbidden();
        $this->actingAs($finance)->get(route('club.finance.periods'))->assertOk();
        $this->close($owner, '2026-10')->assertSessionHasNoErrors();
        $this->actingAs($finance)->post(route('club.finance.periods.reopen'), ['month' => '2026-10', 'reason' => 'تصحيح فاتورة مورد'])->assertForbidden();

        $reopen = fn (string $month, string $reason) => $this->actingAs($owner)->post(route('club.finance.periods.reopen'), ['month' => $month, 'reason' => $reason]);
        $reopen('2026-09', 'تصحيح تاريخ مصروف قديم')->assertSessionHasErrors('month');
        $this->assertStringContainsString('أكتوبر', session('errors')->first('month'));
        $reopen('2026-10', 'قصير')->assertSessionHasErrors('reason');
        $reopen('2026-10', 'تصحيح تاريخ فاتورة مورد')->assertSessionHasNoErrors();

        $this->assertSame('2026-09-30', $this->lockedThrough($club));
        $this->asTenant($club, function () {
            $row = AccountingPeriod::where('starts_on', '2026-10-01')->sole();
            $this->assertSame([AccountingPeriod::REOPENED, 'تصحيح تاريخ فاتورة مورد'], [$row->status, $row->reopen_reason]);
            $log = DB::table('audit_logs')->where('event', 'accounting.period_reopened')->sole();
            $this->assertStringContainsString('تصحيح تاريخ فاتورة مورد', $log->properties);
        });
        $this->actingAs($owner)->post(route('club.expenses.store'), ['description' => 'ماء', 'amount' => 5, 'expense_date' => '2026-10-25', 'payment_method' => 'cash'])->assertSessionHasNoErrors();

        // Closed again: counted twice, with the new expense in its figures.
        $this->close($owner, '2026-10')->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame([2, 1], [AccountingPeriod::where('starts_on', '2026-10-01')->value('times_closed'), AccountingPeriod::where('starts_on', '2026-10-01')->sole()->summary['expenses'] === '5.000' ? 1 : 0]));
    }

    public function test_a_year_closes_after_its_months_and_its_months_reopen_only_after_it(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-02-10 09:00'));
        $club = $this->club();
        $owner = $this->owner($club);
        $this->sale($club, '2025-12-15', '80');
        $this->sale($club, '2026-01-15');
        $this->travelTo(CarbonImmutable::parse('2026-02-10 09:00'));

        $this->actingAs($owner)->post(route('club.finance.periods.years.close'), ['year' => 2025])->assertSessionHasErrors('year');
        $this->close($owner, '2026-01')->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.finance.periods.years.close'), ['year' => 2025])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame('80.000', AccountingPeriod::where('kind', 'year')->sole()->summary['revenue']));

        $reopen = fn (string $month) => $this->actingAs($owner)->post(route('club.finance.periods.reopen'), ['month' => $month, 'reason' => 'مراجعة نهاية السنة']);
        $reopen('2026-01')->assertSessionHasNoErrors();
        $reopen('2025-12')->assertSessionHasErrors('month');
        $this->assertStringContainsString('2025', session('errors')->first('month'));
        $this->actingAs($owner)->post(route('club.finance.periods.years.reopen'), ['year' => 2025, 'reason' => 'مراجعة نهاية السنة'])->assertSessionHasNoErrors();
        $reopen('2025-12')->assertSessionHasNoErrors();
        $this->assertNull($this->lockedThrough($club));
    }

    public function test_salaries_and_supplier_bills_of_a_closed_month_are_not_approved_into_it(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00'));
        $club = $this->club();
        $owner = $this->owner($club);
        $this->sale($club, '2026-10-10');
        $this->asTenant($club, fn () => Employee::create(['name' => 'سارة', 'basic_salary' => '400.000', 'status' => 'active']));
        $this->travelTo(CarbonImmutable::parse('2026-11-05 09:00'));
        $this->actingAs($owner)->post(route('club.payroll.store'), ['month' => '2026-10'])->assertSessionHasNoErrors();
        $run = $this->asTenant($club, fn () => PayrollRun::where('period', '2026-10-01')->sole());
        $this->close($owner, '2026-10')->assertSessionHasNoErrors();

        $this->actingAs($owner)->post(route('club.payroll.approve', $run))->assertSessionHasErrors();
        $this->asTenant($club, fn () => $this->assertTrue($run->fresh()->isDraft()));
    }
}
