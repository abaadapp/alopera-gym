<?php

namespace Tests\Feature;

use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Accounting\Services\Ledger;
use App\Modules\Inventory\Models\Supplier;
use App\Modules\Inventory\Models\SupplierBill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * What the club owes its suppliers, on the financial summary: the balance of the suppliers account (2103) the bills and
 * their payments already post to — read, never posted again — equal to the supplier dues report through an unpaid bill,
 * a part payment, the rest, and a bill voided.
 */
class FinanceSummarySuppliersTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    public function test_the_summary_shows_what_is_owed_to_suppliers_as_the_ledger_and_the_dues_report_do(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        [$supplier, $bank] = $this->asTenant($club, function () {
            $chart = app(ChartOfAccounts::class);
            app(Ledger::class)->post(['date' => today()->toDateString(), 'description' => 'رصيد افتتاحي'], [
                ['account' => $chart->account('bank'), 'debit' => '500.000'], ['account' => $chart->account('opening_equity'), 'credit' => '500.000'],
            ]);

            return [Supplier::create(['name' => 'مورد المياه']), $chart->account('bank')];
        });
        $owed = function () use ($club, $owner) {
            $entries = $this->asTenant($club, fn () => JournalEntry::count());
            $summary = $this->actingAs($owner)->get(route('club.finance.summary', ['range' => 'all']))->viewData('page')['props']['summary']['suppliers_payable'];
            $this->assertSame($entries, $this->asTenant($club, fn () => JournalEntry::count()), 'showing it posts nothing');
            $ledger = $this->asTenant($club, fn () => app(FinanceReports::class)->balanceOf('suppliers_payable'));
            $report = $this->actingAs($owner)->get(route('club.reports.show', ['report' => 'supplier-dues']))->viewData('page')['props']['data']['figures'][0]['value'];
            $this->assertSame($ledger, $summary);
            $this->assertEqualsWithDelta((float) $ledger, (float) $report, 0.0005);

            return $summary;
        };
        $bill = fn (string $ref, string $amount) => $this->actingAs($owner)->post(route('club.purchases.bills.store'), ['supplier_id' => $supplier->id, 'reference' => $ref, 'issue_date' => today()->toDateString(), 'subtotal' => $amount, 'approve' => true])->assertSessionHasNoErrors();
        $pay = fn (SupplierBill $b, string $amount) => $this->actingAs($owner)->post(route('club.purchases.bills.pay', $b), ['account_id' => $bank->id, 'amount' => $amount, 'paid_on' => today()->toDateString()])->assertSessionHasNoErrors();

        $this->assertSame('0.000', $owed());
        $bill('B-1', '100');
        $first = $this->asTenant($club, fn () => SupplierBill::where('reference', 'B-1')->sole());
        $this->assertSame('100.000', $owed(), 'unpaid');
        $pay($first, '40');
        $this->assertSame('60.000', $owed(), 'part paid');
        $pay($first, '60');
        $this->assertSame('0.000', $owed(), 'paid in full');
        $bill('B-2', '25');
        $second = $this->asTenant($club, fn () => SupplierBill::where('reference', 'B-2')->sole());
        $this->assertSame('25.000', $owed());
        $this->actingAs($owner)->post(route('club.purchases.bills.decide', [$second, 'void']), ['reason' => 'أُدخل خطأ'])->assertSessionHasNoErrors();
        $this->assertSame('0.000', $owed(), 'voided');
    }
}
