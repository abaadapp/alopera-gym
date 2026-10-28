<?php

namespace Tests\Feature;

use App\Core\Support\BusinessRuleException;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Models\JournalLine;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Accounting\Services\Ledger;
use App\Modules\Accounting\Services\LedgerProjector;
use App\Modules\Finance\Models\Expense;
use App\Modules\Finance\Models\ExpenseCategory;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Services\CreditNoteService;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/** The books: what each finance record posts, that it posts once, balances, reversals and club isolation. */
class AccountingTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function invoice($club, string $price = '100', array $options = ['type' => 'membership']): Invoice
    {
        $member = $this->member($club);

        return $this->asTenant($club, fn () => app(InvoiceService::class)->issue($member, [['description' => 'Monthly', 'quantity' => 1, 'unit_price' => $price]], $options));
    }

    private function balance($club, string $key): string
    {
        return $this->asTenant($club, fn () => app(FinanceReports::class)->balanceOf($key));
    }

    private function assertBooksBalance($club): void
    {
        $this->asTenant($club, function () {
            $this->assertSame((string) JournalLine::sum('debit'), (string) JournalLine::sum('credit'), 'debits equal credits');
        });
    }

    public function test_an_issued_membership_invoice_and_its_cash_payment_post_balanced_entries(): void
    {
        $club = $this->club();
        $invoice = $this->invoice($club, '100');

        $this->assertSame('100.000', $this->balance($club, 'receivable'));
        $this->assertSame('100.000', $this->balance($club, 'revenue_membership'));

        $this->asTenant($club, fn () => app(PaymentService::class)->record($invoice, 60, 'cash'));
        $this->assertSame('40.000', $this->balance($club, 'receivable'));
        $this->assertSame('60.000', $this->balance($club, 'cash'));
        $this->assertBooksBalance($club);
    }

    public function test_vat_is_owed_separately_from_revenue(): void
    {
        $club = $this->club();
        $this->invoice($club, '100', ['type' => 'pt', 'tax_rate' => 5]);

        $this->assertSame('105.000', $this->balance($club, 'receivable'));
        $this->assertSame('100.000', $this->balance($club, 'revenue_pt'));
        $this->assertSame('5.000', $this->balance($club, 'vat_payable'));
    }

    public function test_each_record_posts_once_however_often_it_is_projected(): void
    {
        $club = $this->club();
        $invoice = $this->invoice($club);
        $this->asTenant($club, fn () => app(PaymentService::class)->record($invoice, 100, 'cash'));

        $this->asTenant($club, function () use ($invoice) {
            $count = JournalEntry::count();
            app(LedgerProjector::class)->project($invoice->fresh());
            app(LedgerProjector::class)->syncClub();
            app(LedgerProjector::class)->syncClub();
            $this->assertSame($count, JournalEntry::count());
        });
        $this->assertSame('100.000', $this->balance($club, 'cash'));
    }

    public function test_a_voided_invoice_is_reversed_and_leaves_nothing_owed(): void
    {
        $club = $this->club();
        $invoice = $this->invoice($club, '80');
        $this->asTenant($club, fn () => app(InvoiceService::class)->void($invoice, 'Entered twice'));

        $this->assertSame('0.000', $this->balance($club, 'receivable'));
        $this->assertSame('0.000', $this->balance($club, 'revenue_membership'));
        $this->asTenant($club, function () {
            $issue = JournalEntry::where(['source_type' => 'invoice', 'source_event' => 'issue'])->firstOrFail();
            $this->assertNotNull($issue->reversed_at);
            $this->assertSame($issue->id, JournalEntry::where('source_event', 'void')->value('reverses_id'));
        });
    }

    public function test_a_credit_note_and_its_refund_clear_the_member_balance(): void
    {
        $club = $this->club();
        $invoice = $this->invoice($club, '100');
        $this->asTenant($club, function () use ($invoice) {
            app(PaymentService::class)->record($invoice, 100, 'cash');
            $note = app(CreditNoteService::class)->issue($invoice->fresh(), 30, 'Agreed discount');
            app(PaymentService::class)->refund($note, 30, 'cash');
        });

        $this->assertSame('0.000', $this->balance($club, 'receivable'));
        $this->assertSame('70.000', $this->balance($club, 'cash'));
        $this->assertSame('30.000', $this->balance($club, 'sales_returns'));
        $this->assertBooksBalance($club);
    }

    public function test_card_and_paymob_receipts_wait_in_their_own_clearing_accounts(): void
    {
        $club = $this->club();
        $a = $this->invoice($club, '20');
        $b = $this->invoice($club, '30');
        $this->asTenant($club, function () use ($a, $b) {
            app(PaymentService::class)->record($a, 20, 'card');
            app(PaymentService::class)->recordGatewayPayment($b, 30, 'paymob', 'TX-1');
            app(PaymentService::class)->recordGatewayPayment($b->fresh(), 30, 'paymob', 'TX-1'); // webhook retried
        });

        $this->assertSame('20.000', $this->balance($club, 'card_clearing'));
        $this->assertSame('30.000', $this->balance($club, 'paymob_clearing'));
        $this->assertSame('0.000', $this->balance($club, 'bank'));
    }

    public function test_a_bank_transfer_posts_only_when_it_is_confirmed(): void
    {
        $club = $this->club();
        $invoice = $this->invoice($club, '50');
        $payment = $this->asTenant($club, fn () => app(PaymentService::class)->record($invoice, 50, 'bank_transfer'));
        $this->assertSame('0.000', $this->balance($club, 'bank'));

        $this->asTenant($club, fn () => app(PaymentService::class)->confirm($payment));
        $this->assertSame('50.000', $this->balance($club, 'bank'));
    }

    public function test_an_edited_or_deleted_expense_reverses_its_old_posting(): void
    {
        $club = $this->club();
        $expense = $this->asTenant($club, function () {
            $rent = ExpenseCategory::firstOrCreate(['name' => 'إيجار']);

            return Expense::create(['expense_category_id' => $rent->id, 'number' => 'EX-1', 'description' => 'Rent', 'amount' => 500, 'expense_date' => today(), 'payment_method' => 'bank_transfer']);
        });
        $this->assertSame('-500.000', $this->balance($club, 'bank'));

        $this->asTenant($club, fn () => $expense->update(['amount' => 450, 'payment_method' => 'cash']));
        $this->assertSame('0.000', $this->balance($club, 'bank'));
        $this->assertSame('-450.000', $this->balance($club, 'cash'));
        $this->asTenant($club, fn () => $this->assertSame('450.000', app(FinanceReports::class)->balance(Account::whereNotNull('expense_category_id')->firstOrFail())));

        $this->asTenant($club, fn () => $expense->delete());
        $this->assertSame('0.000', $this->balance($club, 'cash'));
        $this->assertBooksBalance($club);
    }

    public function test_the_ledger_refuses_unbalanced_one_sided_or_heading_entries(): void
    {
        $club = $this->club();
        $this->asTenant($club, function () {
            $chart = app(ChartOfAccounts::class);
            $ledger = app(Ledger::class);
            $cash = $chart->account('cash');
            $bank = $chart->account('bank');

            foreach ([
                [['account' => $cash, 'debit' => 10], ['account' => $bank, 'credit' => 9]],
                [['account' => $cash, 'debit' => 10]],
                [['account' => $chart->account('assets'), 'debit' => 10], ['account' => $bank, 'credit' => 10]],
                [['account' => $cash, 'debit' => 10, 'credit' => 10], ['account' => $bank, 'credit' => 0]],
            ] as $lines) {
                try {
                    $ledger->post(['date' => today(), 'description' => 'x'], $lines);
                    $this->fail('accepted an invalid entry');
                } catch (BusinessRuleException) {
                }
            }
            $this->assertSame(0, JournalEntry::count());
        });
    }

    public function test_an_entry_is_reversed_once_and_never_edited(): void
    {
        $club = $this->club();
        $this->asTenant($club, function () {
            $chart = app(ChartOfAccounts::class);
            $entry = app(Ledger::class)->post(['date' => today(), 'description' => 'Owner deposit'], [
                ['account' => $chart->account('cash'), 'debit' => '250.500'],
                ['account' => $chart->account('owner_equity'), 'credit' => '250.500'],
            ]);
            app(Ledger::class)->reverse($entry, 'Mistake');

            $this->expectException(BusinessRuleException::class);
            app(Ledger::class)->reverse($entry->fresh(), 'Again');
        });
        $this->assertSame('0.000', $this->balance($club, 'cash'));
    }

    public function test_records_from_before_the_books_are_posted_by_the_sync_command_once(): void
    {
        $club = $this->club();
        $invoice = $this->invoice($club, '120');
        $this->asTenant($club, fn () => app(PaymentService::class)->record($invoice, 120, 'cash'));
        // As if the books had not existed yet.
        $this->asTenant($club, function () {
            JournalLine::query()->delete();
            JournalEntry::query()->delete();
        });

        Artisan::call('accounting:sync');
        Artisan::call('accounting:sync');

        $this->assertSame('120.000', $this->balance($club, 'cash'));
        $this->assertSame('0.000', $this->balance($club, 'receivable'));
        $this->asTenant($club, fn () => $this->assertSame(2, JournalEntry::count()));
    }

    public function test_every_finance_screen_opens_with_real_books(): void
    {
        $club = $this->club();
        $invoice = $this->invoice($club, '75');
        $this->asTenant($club, fn () => app(PaymentService::class)->record($invoice, 75, 'cash'));
        $cash = $this->asTenant($club, fn () => app(ChartOfAccounts::class)->account('cash'));
        $owner = $this->owner($club);

        foreach (['club.finance.summary', 'club.finance.movement', 'club.finance.banks', 'club.finance.accounts', 'club.finance.journal', 'club.finance.statements', 'club.settings.finance'] as $name) {
            $this->actingAs($owner)->get(route($name))->assertOk();
        }
        $this->actingAs($owner)->get(route('club.finance.movement', ['account_id' => $cash->id, 'direction' => 'in']))->assertOk()
            ->assertInertia(fn ($page) => $page->where('stats.in', '75.000')->where('closing', '75.000'));
        $this->actingAs($owner)->get(route('club.finance.statements', ['view' => 'trial']))->assertOk();
        $this->actingAs($owner)->get(route('club.finance.statements.export', ['format' => 'csv']))->assertOk();
    }

    public function test_one_clubs_books_are_invisible_and_unusable_to_another(): void
    {
        $a = $this->club('A');
        $b = $this->club('B');
        $this->invoice($a, '100');
        $foreignCash = $this->asTenant($a, fn () => app(ChartOfAccounts::class)->account('cash'));

        $this->asTenant($b, function () use ($foreignCash) {
            $this->assertSame(0, JournalEntry::count());
            $this->assertNull(Account::find($foreignCash->id));
            $this->expectException(BusinessRuleException::class);
            app(Ledger::class)->post(['date' => today(), 'description' => 'x'], [
                ['account' => $foreignCash->id, 'debit' => 5],
                ['account' => app(ChartOfAccounts::class)->account('bank'), 'credit' => 5],
            ]);
        });
    }
}
