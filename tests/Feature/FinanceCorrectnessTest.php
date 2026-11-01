<?php

namespace Tests\Feature;

use App\Core\Papers\Papers;
use App\Core\Support\BusinessRuleException;
use App\Models\Branch;
use App\Models\Tenant;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\ChartOfAccounts;
use App\Modules\Accounting\Services\FinanceReports;
use App\Modules\Accounting\Services\Ledger;
use App\Modules\Accounting\Services\LedgerProjector;
use App\Modules\Finance\Models\CreditNote;
use App\Modules\Finance\Models\Invoice;
use App\Modules\Finance\Models\Payment;
use App\Modules\Finance\Services\CreditNoteService;
use App\Modules\Finance\Services\InvoiceService;
use App\Modules\Finance\Services\PaymentService;
use App\Modules\Gym\Models\Membership;
use App\Modules\Gym\Models\PtPackage;
use App\Modules\Gym\Services\MembershipService;
use App\Modules\Reports\Services\ReportService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsClubs;
use Tests\TestCase;

/**
 * The money is told right: a credit note gives back its share of the tax (and an older one is put right once); the
 * finance report's profit is the books' result, salaries included; a package sold in a branch is that branch's; a
 * second sale awaiting payment is refused; and a credit note or a refund sent twice happens once.
 */
class FinanceCorrectnessTest extends TestCase
{
    use BuildsClubs, RefreshDatabase;

    private function vat(Tenant $club): void
    {
        $this->asTenant($club, fn () => $club->forceFill(['settings' => array_merge($club->settings ?? [], ['vat_rate' => 5])])->save());
    }

    private function balance(Tenant $club, string $key): string
    {
        return $this->asTenant($club, fn () => app(FinanceReports::class)->balanceOf($key));
    }

    /** 100.000 + 5% VAT, paid in cash. */
    private function paidInvoice(Tenant $club): Invoice
    {
        return $this->asTenant($club, function () {
            $invoice = app(InvoiceService::class)->issue(null, [['description' => 'خدمة', 'unit_price' => 100]], ['type' => 'other'], 'شركة الواحة');
            app(PaymentService::class)->record($invoice, $invoice->total, 'cash');

            return $invoice->fresh();
        });
    }

    public function test_a_credit_note_gives_back_its_share_of_the_tax_and_an_older_one_is_put_right_once(): void
    {
        $club = $this->club();
        $this->vat($club);
        $invoice = $this->paidInvoice($club);
        $this->assertSame(['105.000', '5.000'], [$invoice->total, $this->balance($club, 'vat_payable')]);

        // Half: half the tax back; the rest: exactly what is left of it.
        $first = $this->asTenant($club, fn () => app(CreditNoteService::class)->issue($invoice, '52.500', 'خصم متفق عليه'));
        $this->assertSame('2.500', $first->tax_amount);
        $this->assertSame(['2.500', '50.000'], [$this->balance($club, 'vat_payable'), $this->balance($club, 'sales_returns')]);
        $second = $this->asTenant($club, fn () => app(CreditNoteService::class)->issue($invoice->fresh(), '52.500', 'إلغاء الباقي'));
        $this->assertSame(['2.500', '0.000', '100.000'], [$second->tax_amount, $this->balance($club, 'vat_payable'), $this->balance($club, 'sales_returns')]);
        $this->assertStringContainsString('منها ضريبة القيمة المضافة', $this->asTenant($club, fn () => Papers::for('credit_note', $first->fresh())->html()));

        // A note posted before the tax was split out (the whole amount to returns): the sync moves its tax once.
        $old = $this->paidInvoice($club);
        $note = $this->asTenant($club, function () use ($old) {
            $note = app(CreditNoteService::class)->issue($old, '21.000', 'قديم');
            JournalEntry::where(['source_type' => 'credit_note', 'source_id' => $note->id])->each(function (JournalEntry $e) {
                $e->lines()->delete();
                $e->delete();
            });
            CreditNote::whereKey($note->id)->update(['tax_amount' => 0]);
            app(LedgerProjector::class)->creditNote($note->fresh());
            // As the column's backfill wrote it: straight to the table, no event.
            CreditNote::whereKey($note->id)->update(['tax_amount' => '1.000']);

            return $note;
        });
        $this->assertSame('5.000', $this->balance($club, 'vat_payable'), 'the old note left all its tax owed');
        Artisan::call('accounting:sync');
        Artisan::call('accounting:sync');
        $this->assertSame('4.000', $this->balance($club, 'vat_payable'));
        $this->asTenant($club, fn () => $this->assertSame(1, JournalEntry::where(['source_type' => 'credit_note', 'source_id' => $note->id, 'source_event' => 'tax'])->count()));
    }

    public function test_the_finance_reports_profit_is_the_books_result_salaries_included(): void
    {
        $club = $this->club();
        $this->asTenant($club, function () {
            $invoice = app(InvoiceService::class)->issue(null, [['description' => 'خدمة', 'unit_price' => 50]], ['type' => 'other'], 'عميل');
            app(PaymentService::class)->record($invoice, '50', 'cash');
            // Salaries reach only the books (not the expenses register).
            $chart = app(ChartOfAccounts::class);
            app(Ledger::class)->post(['date' => today(), 'description' => 'رواتب'], [['account' => $chart->account('salaries_expense'), 'debit' => 20], ['account' => $chart->account('cash'), 'credit' => 20]]);
        });

        $today = CarbonImmutable::today();
        $report = $this->asTenant($club, fn () => app(ReportService::class)->period($today->startOfMonth(), $today, true, $today->subMonth()->startOfMonth(), $today->subMonth()));
        $this->assertSame([50.0, 50.0, 20.0, 30.0], [$report['comparison']['revenue']['current'], $report['comparison']['earned']['current'], $report['comparison']['expenses']['current'], $report['comparison']['net']['current']]);
    }

    public function test_a_package_sold_in_a_branch_is_that_branchs_and_its_seller_opens_it(): void
    {
        $club = $this->club();
        $khoud = $this->asTenant($club, fn () => Branch::create(['name' => 'فرع الخوض', 'is_active' => true]));
        $seller = $this->staff($club, 'manager', ['branch_id' => $khoud->id]);
        $member = $this->member($club, ['branch_id' => $khoud->id]);
        $package = $this->asTenant($club, fn () => PtPackage::create(['name' => 'PT 4', 'sessions_count' => 4, 'price' => 40, 'validity_days' => 30]));

        $this->actingAs($seller)->post(route('club.pt.store', $member), ['pt_package_id' => $package->id])->assertRedirect();
        $invoice = $this->asTenant($club, fn () => Invoice::where('type', 'pt')->sole());
        $this->assertSame($khoud->id, $invoice->branch_id);
        $this->actingAs($seller)->get(route('club.invoices.show', $invoice))->assertOk();
    }

    public function test_a_second_sale_awaiting_payment_is_refused(): void
    {
        $club = $this->club();
        $member = $this->member($club);
        $plan = $this->plan($club, ['price' => 30]);
        $better = $this->plan($club, ['price' => 60]);

        $this->asTenant($club, function () use ($member, $plan, $better) {
            $service = app(MembershipService::class);
            $service->sell($member, $plan);
            try {
                $service->renew($member->fresh(), $plan);
                $this->fail('a renewal on top of an unpaid sale');
            } catch (BusinessRuleException $e) {
                $this->assertSame('membership.pending', $e->rule);
            }

            // Paid and running: one upgrade may wait for payment, not two carrying the same credit.
            $first = Membership::where('member_id', $member->id)->sole();
            app(PaymentService::class)->record($first->invoice, $first->invoice->total, 'cash');
            $service->upgrade($first->fresh(), $better);
            $this->expectException(BusinessRuleException::class);
            $service->upgrade($first->fresh(), $better);
        });
    }

    public function test_a_credit_note_and_its_refund_sent_twice_happen_once(): void
    {
        $club = $this->club();
        $owner = $this->owner($club);
        $invoice = $this->paidInvoice($club);

        $note = ['amount' => '40', 'reason' => 'إرجاع', 'refund_now' => true, 'method' => 'cash', 'client_key' => (string) Str::uuid()];
        $this->actingAs($owner)->post(route('club.credit-notes.store', $invoice), $note)->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.credit-notes.store', $invoice), $note)->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame([1, 1], [CreditNote::count(), Payment::where('kind', Payment::KIND_REFUND)->count()]));

        // A second note, refunded later: the refund sent twice pays back once.
        $later = $this->asTenant($club, fn () => app(CreditNoteService::class)->issue($invoice->fresh(), '20', 'خصم'));
        $refund = ['amount' => '20', 'method' => 'cash', 'client_key' => (string) Str::uuid()];
        $this->actingAs($owner)->post(route('club.credit-notes.refund', $later), $refund)->assertSessionHasNoErrors();
        $this->actingAs($owner)->post(route('club.credit-notes.refund', $later), $refund)->assertSessionHasNoErrors();
        $this->asTenant($club, fn () => $this->assertSame(2, Payment::where('kind', Payment::KIND_REFUND)->count()));
    }
}
