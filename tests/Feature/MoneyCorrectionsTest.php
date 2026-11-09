<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Models\Tenant;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\AccountingPeriods;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Finance\Models\CashShift;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollPayout;
use App\Modules\Payroll\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Corrections that must not count money twice or lose it: an invoice already credited is not voided over its credit
 * note; money is not dated into a closed month; a payment is not cancelled under a refund still to be made, nor after
 * its drawer's shift was counted; an edited expense moves in its own month; an expense category keeps its account.
 */
class MoneyCorrectionsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function key(): string
    {
        return (string) Str::uuid();
    }

    private function invoice(Tenant $club, string $price): Invoice
    {
        return $this->asTenant($club, fn () => app(InvoiceService::class)->issue(null, [['description' => 'خدمة', 'unit_price' => $price]], ['type' => 'other'], 'عميل'));
    }

    private function balance(Tenant $club, string $key): string
    {
        return $this->asTenant($club, fn () => app(FinanceReports::class)->balanceOf($key));
    }

    private function assertBooksBalance(Tenant $club): void
    {
        $this->asTenant($club, fn () => $this->assertSame((string) JournalLine::sum('debit'), (string) JournalLine::sum('credit')));
    }

    public function test_an_invoice_with_a_credit_note_is_not_voided_over_it(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $invoice = $this->invoice($club, '100');

        $this->actingAs($owner)->post(route('club.credit-notes.store', $invoice), ['amount' => '30', 'reason' => 'خصم', 'client_key' => $this->key()])->assertSessionHasNoErrors();
        $this->assertSame('70.000', $this->balance($club, 'receivable'));

        // Voiding would take the 30 back a second time: refused, the books as they were.
        $this->actingAs($owner)->post(route('club.invoices.void', $invoice), ['reason' => 'خطأ في الإصدار'])->assertSessionHasErrors();
        $this->asTenant($club, fn () => $this->assertNotSame(Invoice::VOID, $invoice->fresh()->status));
        $this->assertSame('70.000', $this->balance($club, 'receivable'));

        // What is left is credited instead, and the invoice owes nothing.
        $this->actingAs($owner)->post(route('club.credit-notes.store', $invoice), ['amount' => '70', 'reason' => 'إلغاء الباقي', 'client_key' => $this->key()])->assertSessionHasNoErrors();
        $this->assertSame('0.000', $this->balance($club, 'receivable'));
        $this->assertBooksBalance($club);
    }

    public function test_money_is_not_dated_into_a_closed_month(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $lastMonth = CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth();
        $this->asTenant($club, fn () => app(AccountingPeriods::class)->closeThrough($lastMonth->format('Y-m'), $owner));
        $invoice = $this->invoice($club, '40');

        try {
            $this->asTenant($club, fn () => app(PaymentService::class)->record($invoice, '40', 'cash', ['paid_at' => $lastMonth->addDays(14)->toDateString()]));
            $this->fail('a payment dated in a closed month was taken');
        } catch (BusinessRuleException $e) {
            $this->assertSame('period.closed', $e->rule);
        }
        $this->asTenant($club, fn () => $this->assertSame(0, Payment::count()));

        // Today it is taken, and it is in the books.
        $this->asTenant($club, fn () => app(PaymentService::class)->record($invoice, '40', 'cash'));
        $this->assertSame('40.000', $this->balance($club, 'cash'));
    }

    public function test_a_payment_is_not_cancelled_under_a_refund_still_to_be_made(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $invoice = $this->invoice($club, '100');
        $this->actingAs($owner)->post(route('club.payments.store', $invoice), ['amount' => '100', 'method' => 'cash', 'client_key' => $this->key()])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.credit-notes.store', $invoice), ['amount' => '30', 'reason' => 'خصم', 'refund_now' => true, 'method' => 'bank_transfer', 'client_key' => $this->key()])->assertSessionHasNoErrors();
        [$payment, $refund] = $this->asTenant($club, fn () => [Payment::where('kind', Payment::KIND_PAYMENT)->sole(), Payment::where('kind', Payment::KIND_REFUND)->sole()]);
        $this->assertSame(Payment::PENDING, $refund->status);

        // Cancelling now would leave a refund of money that was never received.
        $this->actingAs($owner)->post(route('club.payments.cancel', $payment), ['reason' => 'سُجّلت بالخطأ'])->assertSessionHasErrors();
        $this->asTenant($club, fn () => $this->assertSame(Payment::COMPLETED, $payment->fresh()->status));

        // The refund withdrawn, the payment can be cancelled; the invoice owes its net again and the books balance.
        $this->actingAs($owner)->post(route('club.payments.reject', $refund), ['reason' => 'لم يُنفَّذ'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.payments.cancel', $payment), ['reason' => 'سُجّلت بالخطأ'])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame(['0.000', '70.000'], [$invoice->fresh()->paid_total, $invoice->fresh()->balance()]));
        $this->assertSame('70.000', $this->balance($club, 'receivable'));
        $this->assertBooksBalance($club);
    }

    public function test_money_counted_in_a_closed_shift_is_not_changed_afterwards(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $drawer = $this->asTenant($club, fn () => app(ChartOfAccounts::class)->account('cash'));
        $this->actingAs($owner)->post(route('club.cash-shifts.store'), ['account_id' => $drawer->id, 'opening_float' => '0', 'client_key' => $this->key()])->assertSessionHasNoErrors();
        $shift = $this->asTenant($club, fn () => CashShift::sole());

        $invoice = $this->invoice($club, '50');
        $this->actingAs($owner)->post(route('club.payments.store', $invoice), ['amount' => '50', 'method' => 'cash', 'client_key' => $this->key()])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.expenses.store'), ['description' => 'ماء', 'amount' => '5', 'expense_date' => today()->toDateString(), 'payment_method' => 'cash', 'client_key' => $this->key()])->assertSessionHasNoErrors();
        [$payment, $expense] = $this->asTenant($club, fn () => [Payment::sole(), Expense::sole()]);
        $this->assertSame([$shift->id, $shift->id], [$payment->cash_shift_id, $expense->cash_shift_id]);

        $this->actingAs($owner)->post(route('club.cash-shifts.close', $shift), ['counted_cash' => '45', 'client_key' => $this->key()])->assertSessionHasNoErrors();
        $this->assertSame('45.000', $this->balance($club, 'cash'));

        // The closing counted them: cancelling the payment or deleting the expense would move the drawer a second time.
        $this->actingAs($owner)->post(route('club.payments.cancel', $payment), ['reason' => 'سُجّلت بالخطأ'])->assertSessionHasErrors();
        $this->actingAs($owner)->delete(route('club.expenses.destroy', $expense))->assertSessionHasErrors();
        $edit = ['description' => 'مياه للصالة', 'amount' => '5', 'expense_date' => today()->toDateString(), 'payment_method' => 'cash'];
        $this->actingAs($owner)->put(route('club.expenses.update', $expense), ['amount' => '7'] + $edit)->assertSessionHasErrors();
        // Its wording may still be put right.
        $this->actingAs($owner)->put(route('club.expenses.update', $expense), $edit)->assertSessionHasNoErrors();

        $this->assertSame('45.000', $this->balance($club, 'cash'));
        $this->asTenant($club, fn () => $this->assertSame([Payment::COMPLETED, 'مياه للصالة'], [$payment->fresh()->status, $expense->fresh()->description]));
        $this->assertBooksBalance($club);
    }

    public function test_a_salary_payout_counted_in_a_closed_shift_is_not_voided_afterwards(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $drawer = $this->asTenant($club, fn () => app(ChartOfAccounts::class)->account('cash'));
        $this->asTenant($club, fn () => Employee::create(['name' => 'سارة', 'basic_salary' => '300.000', 'status' => 'active', 'payment_method' => 'cash']));
        $invoice = $this->invoice($club, '500');
        $this->actingAs($owner)->post(route('club.payments.store', $invoice), ['amount' => '500', 'method' => 'cash', 'client_key' => $this->key()])->assertSessionHasNoErrors();

        $this->actingAs($owner)->post(route('club.cash-shifts.store'), ['account_id' => $drawer->id, 'opening_float' => '500', 'client_key' => $this->key()])->assertSessionHasNoErrors();
        $shift = $this->asTenant($club, fn () => CashShift::sole());
        $this->actingAs($owner)->post(route('club.payroll.store'), ['month' => now()->format('Y-m')])->assertSessionHasNoErrors();
        $run = $this->asTenant($club, fn () => PayrollRun::sole());
        $this->actingAs($owner)->post(route('club.payroll.approve', $run))->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.payroll.pay', $run), ['items' => $run->items()->pluck('id')->all(), 'account_id' => $drawer->id, 'paid_on' => today()->toDateString(), 'client_key' => $this->key()])->assertSessionHasNoErrors();
        $payout = $this->asTenant($club, fn () => PayrollPayout::sole());

        // The drawer was counted with the salary gone: 200 left, no difference.
        $this->travel(1)->minutes();
        $this->actingAs($owner)->post(route('club.cash-shifts.close', $shift), ['counted_cash' => '200', 'client_key' => $this->key()])->assertSessionHasNoErrors();
        $this->assertSame('200.000', $this->balance($club, 'cash'));

        // Voiding it now would put 300 back in a drawer that was counted without it.
        $this->travel(1)->minutes();
        $this->actingAs($owner)->post(route('club.payroll.payouts.void', $payout), ['reason' => 'بالخطأ'])->assertSessionHasErrors();
        $this->assertSame('200.000', $this->balance($club, 'cash'));
        $this->asTenant($club, fn () => $this->assertNull($payout->fresh()->voided_at));
        $this->assertBooksBalance($club);
    }

    public function test_a_salary_payout_made_with_no_shift_open_can_still_be_voided(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $drawer = $this->asTenant($club, fn () => app(ChartOfAccounts::class)->account('cash'));
        $this->asTenant($club, fn () => Employee::create(['name' => 'سارة', 'basic_salary' => '300.000', 'status' => 'active', 'payment_method' => 'cash']));
        $invoice = $this->invoice($club, '500');
        $this->actingAs($owner)->post(route('club.payments.store', $invoice), ['amount' => '500', 'method' => 'cash', 'client_key' => $this->key()])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.payroll.store'), ['month' => now()->format('Y-m')])->assertSessionHasNoErrors();
        $run = $this->asTenant($club, fn () => PayrollRun::sole());
        $this->actingAs($owner)->post(route('club.payroll.approve', $run))->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.payroll.pay', $run), ['items' => $run->items()->pluck('id')->all(), 'account_id' => $drawer->id, 'paid_on' => today()->toDateString(), 'client_key' => $this->key()])->assertSessionHasNoErrors();
        $payout = $this->asTenant($club, fn () => PayrollPayout::sole());

        $this->actingAs($owner)->post(route('club.payroll.payouts.void', $payout), ['reason' => 'بالخطأ'])->assertSessionHasNoErrors();
        $this->assertSame('500.000', $this->balance($club, 'cash'));
        $this->assertBooksBalance($club);
    }

    public function test_an_edited_expense_moves_in_its_own_month(): void
    {
        $club = $this->club();
        $this->fundAccounts($club);
        $owner = $this->owner($club);
        $date = CarbonImmutable::today()->subMonthNoOverflow()->startOfMonth()->addDays(19);
        $this->actingAs($owner)->post(route('club.expenses.store'), ['description' => 'صيانة', 'amount' => '100', 'expense_date' => $date->toDateString(), 'payment_method' => 'cash', 'client_key' => $this->key()])->assertSessionHasNoErrors();
        $expense = $this->asTenant($club, fn () => Expense::sole());

        $this->actingAs($owner)->put(route('club.expenses.update', $expense), ['description' => 'صيانة', 'amount' => '80', 'expense_date' => $date->toDateString(), 'payment_method' => 'cash'])->assertSessionHasNoErrors();

        $this->asTenant($club, function () use ($date) {
            $reports = app(FinanceReports::class);
            $month = fn (CarbonImmutable $d) => $reports->incomeStatement($d->startOfMonth()->toDateString(), $d->endOfMonth()->toDateString())['total_expenses'];
            // Its month carries it once, as it now stands; this month carries nothing of it.
            $this->assertSame('80.000', $month($date));
            $this->assertSame('0.000', $month(CarbonImmutable::today()));
            $this->assertSame(0, JournalEntry::where('source_type', 'expense')->whereDate('entry_date', '!=', $date->toDateString())->count());
        });
        $this->assertBooksBalance($club);
    }

    public function test_an_expense_categorys_account_is_not_stopped(): void
    {
        $club = $this->club();
        $this->fundAccounts($club);
        $owner = $this->owner($club);
        $category = $this->asTenant($club, fn () => ExpenseCategory::create(['name' => 'تنظيف']));
        $this->actingAs($owner)->post(route('club.expenses.store'), ['description' => 'منظفات', 'amount' => '12', 'expense_date' => today()->toDateString(), 'payment_method' => 'cash', 'expense_category_id' => $category->id, 'client_key' => $this->key()])->assertSessionHasNoErrors();
        $account = $this->asTenant($club, fn () => Account::where('expense_category_id', $category->id)->sole());

        $this->actingAs($owner)->put(route('club.finance.accounts.update', $account), ['name' => 'تنظيف وتعقيم', 'is_active' => false])->assertSessionHasErrors('is_active');
        $this->actingAs($owner)->put(route('club.finance.accounts.update', $account), ['name' => 'تنظيف وتعقيم', 'is_active' => true])->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame([true, 'تنظيف وتعقيم'], [(bool) $account->fresh()->is_active, $account->fresh()->name]));
    }
}
