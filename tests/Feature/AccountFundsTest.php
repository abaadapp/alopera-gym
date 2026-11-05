<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Accounting\Services\Ledger;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Inventory\Models\Product;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\SupplierBill;
use App\Modules\Inventory\Models\SupplierPayment;
use App\Modules\Payroll\Models\Employee;
use App\Modules\Payroll\Models\PayrollPayout;
use App\Modules\Payroll\Models\PayrollRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * Money leaves a cash box or bank account only while the account holds it: an expense, a supplier payment, a payroll
 * payout and a refund at the desk are refused above the balance — with the figures for whoever may see the books — and
 * leave nothing behind (no record, no entry, no status changed); the same amount goes through once money comes in.
 */
class AccountFundsTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function fund(Tenant $club, string $key, string $amount): Account
    {
        return $this->asTenant($club, function () use ($key, $amount) {
            $chart = app(ChartOfAccounts::class);
            $account = $chart->account($key);
            $lines = str_starts_with($amount, '-')
                ? [['account' => $account, 'credit' => ltrim($amount, '-')], ['account' => $chart->account('opening_equity'), 'debit' => ltrim($amount, '-')]]
                : [['account' => $account, 'debit' => $amount], ['account' => $chart->account('opening_equity'), 'credit' => $amount]];
            app(Ledger::class)->post(['date' => today()->toDateString(), 'description' => 'رصيد'], $lines);

            return $account;
        });
    }

    private function balance(Tenant $club, Account $account): string
    {
        return $this->asTenant($club, fn () => app(FinanceReports::class)->balance($account));
    }

    private function expense(Account $account, string $amount): array
    {
        return ['description' => 'كهرباء', 'amount' => $amount, 'expense_date' => today()->toDateString(), 'payment_method' => $account->kind === 'cash' ? 'cash' : 'bank_transfer', 'account_id' => $account->id];
    }

    public function test_an_expense_up_to_the_balance_goes_through_and_one_above_it_leaves_nothing(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $cash = $this->fund($club, 'cash', '100.000');

        $this->actingAs($owner)->post(route('club.expenses.store'), $this->expense($cash, '60'))->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.expenses.store'), $this->expense($cash, '40'))->assertSessionHasNoErrors(); // exactly what is left
        $this->assertSame('0.000', $this->balance($club, $cash));
        $entries = $this->asTenant($club, fn () => JournalEntry::count());

        $this->actingAs($owner)->post(route('club.expenses.store'), $this->expense($cash, '0.001'))
            ->assertSessionHasErrors(['account_id' => 'رصيد «'.$cash->name.'» لا يكفي: المتاح 0.000 والمطلوب 0.001.']);
        $this->assertSame([2, $entries], $this->asTenant($club, fn () => [Expense::count(), JournalEntry::count()]));

        // Raising an expense spends only the difference; the difference must be there.
        $expense = $this->asTenant($club, fn () => Expense::where('amount', '60.000')->sole());
        $this->actingAs($owner)->put(route('club.expenses.update', $expense), $this->expense($cash, '61'))->assertSessionHasErrors('account_id');
        $this->assertSame('60.000', $expense->fresh()->amount);

        $this->fund($club, 'cash', '1.000');
        $this->actingAs($owner)->put(route('club.expenses.update', $expense), $this->expense($cash, '61'))->assertSessionHasNoErrors();
        $this->assertSame('0.000', $this->balance($club, $cash));
    }

    public function test_a_supplier_is_not_paid_from_an_account_below_zero_until_money_comes_in(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $bank = $this->fund($club, 'bank', '-10.000');
        $supplier = $this->asTenant($club, fn () => Supplier::create(['name' => 'مورد']));
        $this->actingAs($owner)->post(route('club.purchases.bills.store'), ['supplier_id' => $supplier->id, 'reference' => 'B-1', 'issue_date' => today()->toDateString(), 'subtotal' => '5', 'approve' => true])->assertSessionHasNoErrors();
        $bill = $this->asTenant($club, fn () => SupplierBill::sole());
        $pay = fn () => $this->actingAs($owner)->post(route('club.purchases.bills.pay', $bill), ['account_id' => $bank->id, 'amount' => '5', 'paid_on' => today()->toDateString()]);

        $pay()->assertSessionHasErrors('account_id');
        $this->assertSame([0, '0.000', '-10.000'], [$this->asTenant($club, fn () => SupplierPayment::count()), $bill->fresh()->paid_total, $this->balance($club, $bank)], 'the old negative balance is left as it was');

        $this->fund($club, 'bank', '15.000');
        $pay()->assertSessionHasNoErrors();
        $this->assertSame(['5.000', '0.000'], [$bill->fresh()->paid_total, $this->balance($club, $bank)]);
    }

    public function test_salaries_are_not_paid_beyond_the_cash_box(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $this->asTenant($club, fn () => Employee::create(['name' => 'سارة', 'basic_salary' => '400.000', 'status' => 'active', 'payment_method' => 'cash']));
        $cash = $this->fund($club, 'cash', '399.999');
        $this->actingAs($owner)->post(route('club.payroll.store'), ['month' => now()->format('Y-m')])->assertSessionHasNoErrors();
        $run = $this->asTenant($club, fn () => PayrollRun::sole());
        $this->actingAs($owner)->post(route('club.payroll.approve', $run))->assertSessionHasNoErrors();
        $pay = fn () => $this->actingAs($owner)->post(route('club.payroll.pay', $run), ['items' => $run->items()->pluck('id')->all(), 'account_id' => $cash->id, 'paid_on' => today()->toDateString()]);

        $pay()->assertSessionHasErrors('account_id');
        $this->assertSame([0, 0], $this->asTenant($club, fn () => [PayrollPayout::count(), $run->items()->whereNotNull('paid_at')->count()]));
        $this->assertSame(PayrollRun::APPROVED, $run->fresh()->status);

        $this->fund($club, 'cash', '0.001');
        $pay()->assertSessionHasNoErrors();
        $this->assertSame('0.000', $this->balance($club, $cash));
    }

    public function test_a_refund_at_the_desk_is_not_paid_beyond_the_cash_box_and_the_figures_are_for_the_books_only(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $product = $this->asTenant($club, fn () => Product::create(['name' => 'Bottle', 'price' => 30, 'track_stock' => false]));
        $this->actingAs($owner)->post(route('club.pos.checkout'), ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $invoice = $this->asTenant($club, fn () => Invoice::sole());
        $cash = $this->asTenant($club, fn () => Account::findOrFail(Payment::where('invoice_id', $invoice->id)->value('account_id')));
        $this->actingAs($owner)->post(route('club.expenses.store'), $this->expense($cash, '10'))->assertSessionHasNoErrors(); // 20 left
        $this->actingAs($owner)->post(route('club.credit-notes.store', $invoice), ['amount' => '30', 'reason' => 'مرتجع', 'refund_now' => false])->assertSessionHasNoErrors();
        $note = $this->asTenant($club, fn () => CreditNote::sole());
        $refund = ['amount' => '30', 'method' => 'cash', 'account_id' => $cash->id];

        $refunder = $this->staff($club, 'reception');
        $refunder->forceFill(['custom_permissions' => ['payments.refund']])->save(); // refunds, without the books
        $this->actingAs($refunder)->post(route('club.credit-notes.refund', $note), $refund)->assertSessionHasErrors(['account_id' => 'رصيد «'.$cash->name.'» لا يكفي لهذا المبلغ.']);
        $this->actingAs($owner)->post(route('club.credit-notes.refund', $note), $refund)->assertSessionHasErrors(['account_id' => 'رصيد «'.$cash->name.'» لا يكفي: المتاح 20.000 والمطلوب 30.000.']);
        $this->assertSame([0, '0.000'], [$this->asTenant($club, fn () => Payment::where('kind', Payment::KIND_REFUND)->count()), $note->fresh()->refunded_total]);

        $this->actingAs($owner)->post(route('club.credit-notes.refund', $note), ['amount' => '20'] + $refund)->assertSessionHasNoErrors();
        $this->assertSame('0.000', $this->balance($club, $cash));
    }

    public function test_a_bank_transfer_refund_waits_without_money_and_is_confirmed_only_once_the_bank_holds_it(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $product = $this->asTenant($club, fn () => Product::create(['name' => 'Bottle', 'price' => 30, 'track_stock' => false]));
        $this->actingAs($owner)->post(route('club.pos.checkout'), ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'payment_method' => 'cash'])->assertSessionHasNoErrors();
        $invoice = $this->asTenant($club, fn () => Invoice::sole());
        $this->actingAs($owner)->post(route('club.credit-notes.store', $invoice), ['amount' => '30', 'reason' => 'مرتجع', 'refund_now' => false])->assertSessionHasNoErrors();
        $note = $this->asTenant($club, fn () => CreditNote::sole());
        $bank = $this->asTenant($club, fn () => app(ChartOfAccounts::class)->account('bank'));

        // Asked for: nothing has left the bank yet, so nothing is checked and nothing is posted.
        $this->actingAs($owner)->post(route('club.credit-notes.refund', $note), ['amount' => '30', 'method' => 'bank_transfer', 'account_id' => $bank->id])->assertSessionHasNoErrors();
        $refund = $this->asTenant($club, fn () => Payment::where('kind', Payment::KIND_REFUND)->sole());
        $this->assertSame(Payment::PENDING, $refund->status);

        $this->actingAs($owner)->post(route('club.payments.confirm', $refund), ['reference' => 'TR-1'])->assertSessionHasErrors('account_id');
        $this->assertSame([Payment::PENDING, '0.000'], [$refund->fresh()->status, $this->balance($club, $bank)]);

        $this->fund($club, 'bank', '30.000');
        $this->actingAs($owner)->post(route('club.payments.confirm', $refund), ['reference' => 'TR-1'])->assertSessionHasNoErrors();
        $this->assertSame([Payment::COMPLETED, '0.000'], [$refund->fresh()->status, $this->balance($club, $bank)]);
    }
}
